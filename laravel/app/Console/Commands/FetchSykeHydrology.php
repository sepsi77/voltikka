<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\ImportPayload\ImportPayloadInvalid;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Services\Syke\SykeHydrologyImporter;
use App\Services\Syke\SykeHydrologyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Collect Syke areal hydrology series (config/syke.php) as slow spot model features.
 *
 * Without --from/--to: the daily window, the last syke.daily_lookback_days days through today.
 * With --from and/or --to: a backfill (--from defaults to syke.backfill_start, --to to today).
 * Dates are Finnish calendar dates (the Syke Aika value). One paged OData query per series;
 * read-only towards Syke; upserts locally.
 *
 * Staged import (StagedImport): the fetch step follows `odata.nextLink` and records every page;
 * the header fixes `today`, the window, the API base URL and each series' request plan (S5,
 * S19). The store step checks every saved nextLink against that base URL, not the config, and
 * upserts all series in one transaction. A failed page fails its whole series; the other series
 * are stored and the run exits 1 (fetch_incomplete). Not date-bound (S9).
 */
class FetchSykeHydrology extends Command
{
    use StagedImport;

    protected $signature = 'syke:fetch
                            {--from= : Backfill start date (YYYY-MM-DD; default config syke.backfill_start)}
                            {--to= : Backfill end date, inclusive (YYYY-MM-DD; default today)}
                            {--series=* : Only these series (default: all configured)}';

    protected $description = 'Fetch Syke areal precipitation and snow water equivalent for spot model features';

    public function __construct(
        private readonly SykeHydrologyService $client,
        private readonly SykeHydrologyImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $configured = (array) config('syke.series', []);
        $requested = array_map('strval', (array) $this->option('series'));
        $unknown = array_diff($requested, array_keys($configured));

        if ($unknown !== []) {
            $this->error('Unknown series: '.implode(', ', $unknown));

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        $seriesKeys = $requested !== [] ? $requested : array_keys($configured);
        $today = Carbon::now('Europe/Helsinki')->startOfDay();
        $isBackfill = $this->option('from') !== null || $this->option('to') !== null;

        $from = $isBackfill
            ? $this->date((string) ($this->option('from') ?? config('syke.backfill_start')))
            : $today->copy()->subDays((int) config('syke.daily_lookback_days', 90));
        $to = $this->option('to') !== null ? $this->date((string) $this->option('to')) : $today->copy();

        if ($from === null || $to === null) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        // Exclusive end: the day after --to (or after today).
        $to->addDay();

        if (! $from->lessThan($to)) {
            $this->error('--from must not be after --to.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        $plan = [];
        foreach ($seriesKeys as $series) {
            $definition = $configured[$series];
            $plan[$series] = [
                'entity' => (string) $definition['entity'],
                'jakso_id' => isset($definition['jakso_id']) ? (int) $definition['jakso_id'] : null,
                'place_ids' => array_map('intval', array_keys((array) $definition['places'])),
            ];
        }

        return $this->runStaged(
            function (ImportPayloadWriter $payload) use ($plan, $today, $from, $to): void {
                $payload->setFact('today', $today->toDateString());
                $payload->setFact('from', $from->toDateString());
                $payload->setFact('to_exclusive', $to->toDateString());
                $payload->setFact('base_url', $this->client->baseUrl());
                $payload->setFact('series', $plan);
                foreach ($plan as $series => $seriesPlan) {
                    $this->fetchSeries($payload, (string) $series, $seriesPlan, $from, $to);
                }
            },
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload),
        );
    }

    protected function stagedImportSource(): string
    {
        return 'syke';
    }

    /**
     * Record every page of one series. An HTTP failure, an unexpected body, a nextLink outside
     * the API or paging beyond syke.max_pages is a failed unit and ends the series.
     *
     * @param  array{entity: string, jakso_id: int|null, place_ids: list<int>}  $plan
     */
    private function fetchSeries(ImportPayloadWriter $payload, string $series, array $plan, Carbon $from, Carbon $to): void
    {
        [$url, $query] = $this->client->firstPage($plan['entity'], $plan['jakso_id'], $plan['place_ids'], $from, $to);
        $page = 0;

        while ($url !== null) {
            $unit = ['series' => $series, 'page' => ++$page];

            try {
                $response = $this->client->requestPage($url, $query);
            } catch (Throwable $e) {
                $payload->recordFailure($unit, 'GET', $url, $query ?? [], $e);
                $this->reportSeriesFailure($series, $e);

                return;
            }

            try {
                $next = $this->client->parsePage($plan['entity'], $response->body(), $this->client->baseUrl())['next'];
                if ($next !== null && $page >= $this->client->maxPages()) {
                    throw new RuntimeException("Syke {$plan['entity']} paging did not end.");
                }
            } catch (Throwable $e) {
                $payload->record($unit, $response, failed: true);
                $this->reportSeriesFailure($series, $e);

                return;
            }

            $payload->record($unit, $response);
            [$url, $query] = [$next, null];
        }
    }

    /**
     * Runs inside the StagedImport store transaction.
     *
     * @throws ImportPayloadInvalid incomplete: a header without the base URL (a payload written
     *                              before it was recorded)
     */
    private function store(ImportPayloadReader $payload): ImportStoreResult
    {
        $baseUrl = $payload->fact('base_url');
        if (! is_string($baseUrl) || $baseUrl === '') {
            throw ImportPayloadInvalid::because('incomplete');
        }
        $plan = (array) $payload->fact('series', []);
        $failedSeries = array_values(array_unique(array_map(fn (array $unit) => (string) $unit['series'], $payload->end()['failed_units'])));
        $rows = [];

        foreach ($payload->requests() as $request) {
            $series = (string) $request['unit']['series'];
            if (in_array($series, $failedSeries, true)) {
                continue;
            }
            $page = $this->client->parsePage((string) $plan[$series]['entity'], (string) ImportPayloadReader::body($request), $baseUrl);
            $rows[$series] = [...($rows[$series] ?? []), ...$page['rows']];
        }

        $messages = [];
        $written = 0;
        foreach ($rows as $series => $seriesRows) {
            $seriesPlan = $plan[$series];
            $count = $this->importer->import((string) $series, $seriesPlan['jakso_id'], $seriesPlan['place_ids'], $seriesRows, $payload->fetchedAt());
            $written += $count;
            $messages[] = "Series {$series}: {$count} rows (".count($seriesRows).' received).';
        }

        return new ImportStoreResult(
            ['rows' => $written, 'failed_series' => count($failedSeries)],
            [function () use ($messages): void {
                foreach ($messages as $message) {
                    $this->info($message);
                }
            }],
            $failedSeries === [] ? null : 'fetch_incomplete',
            'fetch',
        );
    }

    private function reportSeriesFailure(string $series, Throwable $e): void
    {
        // The exception message can contain the request URL; log only the class.
        logger()->error('Syke fetch failed', [
            'series' => $series,
            'exception_class' => $e::class,
        ]);
        $this->error("Series {$series}: fetch failed (".$e::class.').');
    }

    /** Strict YYYY-MM-DD: Carbon would roll 2026-13-01 over to 2027-01-01. */
    private function date(string $value): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, 'Europe/Helsinki');
        } catch (Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }
}
