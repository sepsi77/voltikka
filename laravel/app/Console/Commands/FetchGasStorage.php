<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\GasStorage\GasStorageImporter;
use App\Services\GasStorage\GieAgsiService;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Collect daily EU and German gas storage levels from GIE AGSI+ for market reviews.
 *
 * Without --from: the last `gas_storage.lookback_days` gas days, because GIE confirms estimated
 * days later. With --from/--to: a backfill (AGSI paginates, 300 days per call). Read-only
 * towards GIE; upserts locally. Needs GIE_API_KEY. The scheduled run passes --if-configured, so a
 * missing optional key skips the run (in every mode, without a payload file) instead of creating
 * a daily failure.
 *
 * Staged import (StagedImport): the fetch step records every page, the store step upserts all
 * areas in one transaction. A failed page fails its whole area; the other areas are stored and
 * the run exits 1 (fetch_incomplete). Not date-bound (S9): a same-day older payload is stored.
 */
class FetchGasStorage extends Command
{
    use StagedImport {
        stagedDataOptions as private allDataOptions;
    }

    protected $signature = 'gas-storage:fetch
                            {--from= : Backfill start gas day (YYYY-MM-DD)}
                            {--to= : Backfill end gas day, inclusive (YYYY-MM-DD; default today)}
                            {--area=* : Only these configured areas, for example eu or de}
                            {--if-configured : Skip with success when GIE_API_KEY is not set}';

    protected $description = 'Fetch GIE AGSI+ gas storage levels for market reviews';

    public function __construct(
        private readonly GieAgsiService $client,
        private readonly GasStorageImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ((string) config('gas_storage.api_key') === '') {
            if ($this->option('if-configured')) {
                $this->info('GIE_API_KEY is not set; gas storage collection is not enabled. Skipped.');

                return $this->stagedResult(['status' => 'skipped', 'step' => 'fetch', 'reason' => 'not_configured']);
            }
            // A store from a saved payload needs no key.
            if ($this->option('from-payload') === null) {
                $this->error('GIE_API_KEY is not set. Register for a free key at https://agsi.gie.eu/account.');

                return $this->stagedFailure('fetch', 'not_configured');
            }
        }

        $configured = (array) config('gas_storage.areas', []);
        $requested = array_map('strtolower', (array) $this->option('area'));
        $unknown = array_diff($requested, array_keys($configured));

        if ($unknown !== []) {
            $this->error('Unknown area(s): '.implode(', ', $unknown));

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        try {
            $to = $this->option('to') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('to'), 'UTC')
                : Carbon::now('UTC')->startOfDay();
            $from = $this->option('from') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('from'), 'UTC')
                : $to->copy()->subDays((int) config('gas_storage.lookback_days', 14));
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        if ($from->greaterThan($to)) {
            $this->error('--from must not be after --to.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        $areas = $requested !== [] ? $requested : array_keys($configured);

        return $this->runStaged(
            function (ImportPayloadWriter $payload) use ($areas, $configured, $from, $to): void {
                // The gas-day range is fixed here (S5), so a later store keeps the fetch's window.
                $payload->setFact('from', $from->toDateString());
                $payload->setFact('to', $to->toDateString());
                $payload->setFact('areas', $areas);
                foreach ($areas as $area) {
                    $this->fetchArea($payload, (string) $area, (array) $configured[$area]['params'], $from->toDateString(), $to->toDateString());
                }
            },
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload),
        );
    }

    protected function stagedImportSource(): string
    {
        return 'gie-agsi';
    }

    /** --if-configured changes no row, so a replay need not repeat it. */
    protected function stagedDataOptions(): array
    {
        return array_diff_key($this->allDataOptions(), ['if-configured' => true]);
    }

    /**
     * Record every page of one area. An HTTP failure, an AGSI error body (HTTP 200 with `error`)
     * or an unexpected body is recorded as a failed unit and ends the area.
     *
     * @param  array<string, string>  $areaParams
     */
    private function fetchArea(ImportPayloadWriter $payload, string $area, array $areaParams, string $from, string $to): void
    {
        $page = 1;

        do {
            $unit = ['area' => $area, 'page' => $page];
            $query = $this->client->pageQuery($areaParams, $from, $to, $page);

            try {
                $response = $this->client->requestPage($query);
            } catch (Throwable $e) {
                $payload->recordFailure($unit, 'GET', $this->client->url(), $query, $e);
                $this->reportAreaFailure($area, $e);

                return;
            }

            try {
                $lastPage = GieAgsiService::parsePage($response->body())['last_page'];
            } catch (Throwable $e) {
                $payload->record($unit, $response, failed: true);
                $this->reportAreaFailure($area, $e);

                return;
            }

            $payload->record($unit, $response);
            $page++;
        } while ($page <= $lastPage && $page <= GieAgsiService::MAX_PAGES);
    }

    /** Runs inside the StagedImport store transaction. */
    private function store(ImportPayloadReader $payload): ImportStoreResult
    {
        $failedAreas = array_values(array_unique(array_map(fn (array $unit) => (string) $unit['area'], $payload->end()['failed_units'])));
        $rows = [];

        foreach ($payload->requests() as $request) {
            $area = (string) $request['unit']['area'];
            if (in_array($area, $failedAreas, true)) {
                continue;
            }
            $rows[$area] = [...($rows[$area] ?? []), ...GieAgsiService::parsePage((string) ImportPayloadReader::body($request))['rows']];
        }

        $written = [];
        foreach ($rows as $area => $areaRows) {
            $written[$area] = $this->importer->import((string) $area, $areaRows, $payload->fetchedAt());
        }

        return new ImportStoreResult(
            ['gas_days' => array_sum($written), 'failed_areas' => count($failedAreas)],
            [function () use ($written): void {
                foreach ($written as $area => $count) {
                    $this->info("Area {$area}: {$count} gas days.");
                }
            }],
            $failedAreas === [] ? null : 'fetch_incomplete',
            'fetch',
        );
    }

    private function reportAreaFailure(string $area, Throwable $e): void
    {
        // The exception message can contain the request URL; log only the class.
        logger()->error('GIE gas storage fetch failed', [
            'area' => $area,
            'exception_class' => $e::class,
        ]);
        $this->error("Area {$area}: fetch failed (".$e::class.').');
    }
}
