<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Services\Jao\JaoMaxBorderFlowImporter;
use App\Services\Jao\JaoNordicPublicationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Collect JAO Nordic day-ahead maxBorderFlow on the Finnish borders (config/jao.php) as hourly
 * values for market reviews.
 *
 * Without --from: UTC days today - 2 to tomorrow inclusive (tomorrow is published about
 * 10-11 CET). With --from/--to: a backfill. Each 48-hour window is one request and one unit:
 * a failed window does not stop the others, and the run then exits 1. Read-only towards JAO;
 * upserts locally.
 *
 * Staged import (StagedImport): the fetch step records every window unparsed and fixes today and
 * the range in the header (S5); the store step parses and upserts the answered windows, so a
 * window the parser rejects can be stored from the same file after a parser fix. The default run
 * stores in one transaction; a --from backfill commits once per window (S21). Not date-bound (S9).
 */
class FetchJaoMaxBorderFlows extends Command
{
    use StagedImport;

    protected $signature = 'jao:fetch
                            {--from= : Backfill start date (YYYY-MM-DD, UTC; history from 2024-10-29)}
                            {--to= : Backfill end date, inclusive (YYYY-MM-DD, UTC; default tomorrow)}';

    protected $description = 'Fetch JAO Nordic day-ahead max border flows for the Finnish borders';

    public function __construct(
        private readonly JaoNordicPublicationService $client,
        private readonly JaoMaxBorderFlowImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = Carbon::now('UTC')->startOfDay();
        $latestEnd = $today->copy()->addDays((int) config('jao.lookahead_days', 2));

        try {
            $start = $this->option('from') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('from'), 'UTC')
                : $today->copy()->subDays((int) config('jao.lookback_days', 2));
            $end = $this->option('to') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('to'), 'UTC')->addDay()
                : $latestEnd->copy();
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        if ($end->greaterThan($latestEnd)) {
            $end = $latestEnd->copy();
        }
        if (! $start->lessThan($end)) {
            $this->error('The start date must be before the end date.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        $backfill = $this->option('from') !== null;

        return $this->runStaged(
            fn (ImportPayloadWriter $payload) => $this->fetch($payload, $today, $start, $end),
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload, $backfill),
            ! $backfill,
        );
    }

    protected function stagedImportSource(): string
    {
        return 'jao';
    }

    private function fetch(ImportPayloadWriter $payload, Carbon $today, Carbon $start, Carbon $end): void
    {
        $payload->setFact('today', $today->toDateString());
        $payload->setFact('start', $this->isoTime($start));
        $payload->setFact('end', $this->isoTime($end));

        $hours = (int) config('jao.max_window_hours', 48);
        $cursor = $start->copy();

        while ($cursor->lessThan($end)) {
            $windowEnd = $cursor->copy()->addHours($hours);
            if ($windowEnd->greaterThan($end)) {
                $windowEnd = $end->copy();
            }
            $unit = ['from' => $this->isoTime($cursor), 'to' => $this->isoTime($windowEnd)];

            try {
                $response = $this->client->requestMaxBorderFlows($cursor, $windowEnd);
            } catch (Throwable $e) {
                $payload->recordFailure($unit, 'GET', $this->client->url(), $this->client->windowQuery($cursor, $windowEnd), $e);
                $this->reportWindowFailure($unit, $e);
                $cursor = $windowEnd;

                continue;
            }

            // Not parsed here: the store step owns the parser, so a parser fix can replay this body.
            $payload->record($unit, $response);
            $cursor = $windowEnd;
        }
    }

    /** Runs inside the StagedImport transaction, or one transaction per window for a backfill. */
    private function store(ImportPayloadReader $payload, bool $backfill): ImportStoreResult
    {
        $failedUnits = $payload->end()['failed_units'];
        $failed = count($failedUnits);
        $written = 0;

        foreach ($payload->requests() as $request) {
            if (in_array($request['unit'], $failedUnits, true)) {
                continue;
            }
            try {
                $rows = $this->client->parse((string) ImportPayloadReader::body($request));
            } catch (RuntimeException $e) {
                // An unexpected body fails only its window and stays in the file for a later replay.
                $failed++;
                $this->reportWindowFailure($request['unit'], $e);

                continue;
            }
            $import = fn (): int => $this->importer->import($rows, $payload->fetchedAt());
            $written += $backfill ? DB::transaction($import) : $import();
        }

        return new ImportStoreResult(
            ['hourly_rows' => $written, 'failed_windows' => $failed],
            [fn () => $this->info("JAO maxBorderFlow: {$written} hourly border rows, {$failed} failed window(s).")],
            $failed === 0 ? null : 'fetch_incomplete',
            'fetch',
        );
    }

    /** @param  array{from: string, to: string}  $unit */
    private function reportWindowFailure(array $unit, Throwable $e): void
    {
        // The exception message can contain the request URL; log only the class and window.
        logger()->error('JAO maxBorderFlow fetch failed', [
            'window_start' => $unit['from'],
            'window_end' => $unit['to'],
            'exception_class' => $e::class,
        ]);
        $this->error('Window '.substr($unit['from'], 0, 10).': fetch failed ('.$e::class.').');
    }

    private function isoTime(Carbon $time): string
    {
        return $time->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
