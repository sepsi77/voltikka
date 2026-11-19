<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\Fingrid\FingridHourlyImporter;
use App\Services\Fingrid\FingridOpenDataService;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Collect Fingrid open-data series (config/fingrid.php) as hourly means for market reviews.
 *
 * Without --from: the daily window (real-time series: last 3 days; Datahub: last 16 days,
 * because Datahub arrives 4 days late and is corrected for 11 days). With --from/--to: a
 * backfill in 7-day pieces. Both end at the start of the current UTC hour, so a partial
 * hour is never stored.
 *
 * Staged import (StagedImport, tasks/staged-api-imports/design.md): the fetch step records every
 * page and fixes the window end (`current_hour`) from the fetch time; the store step upserts in
 * one transaction (a backfill: one per dataset window). Not date-bound (S9). Read-only towards
 * Fingrid.
 */
class FetchFingridData extends Command
{
    use StagedImport {
        stagedDataOptions as private allDataOptions;
    }

    protected $signature = 'fingrid:fetch
                            {--from= : Backfill start date (YYYY-MM-DD, UTC)}
                            {--to= : Backfill end date, inclusive (YYYY-MM-DD, UTC; default today)}
                            {--dataset=* : Only these dataset IDs (default: all configured)}';

    protected $description = 'Fetch Fingrid open data as hourly values for market reviews';

    private const BACKFILL_CHUNK_DAYS = 7;

    public function __construct(
        private readonly FingridOpenDataService $client,
        private readonly FingridHourlyImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $configured = (array) config('fingrid.datasets', []);
        $requested = $this->requestedDatasetIds();
        $unknown = array_diff($requested, array_keys($configured));

        // A replay stores the recorded datasets; only a new fetch needs them in the config.
        if ($unknown !== [] && $this->option('from-payload') === null) {
            $this->error('Unknown dataset ID(s): '.implode(', ', $unknown));

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        $datasetIds = $requested !== [] ? $requested : array_keys($configured);

        try {
            $backfillStart = $this->option('from') !== null
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('from'), 'UTC')
                : null;
            $backfillEnd = $this->option('to') !== null
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('to'), 'UTC')->addDay()
                : null;
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        return $this->runStaged(
            fn (ImportPayloadWriter $payload) => $this->fetch($payload, $datasetIds, $backfillStart, $backfillEnd),
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload, perWindow: $backfillStart !== null),
            // A backfill commits per dataset window (S21); the daily run is one transaction.
            transactional: $backfillStart === null,
        );
    }

    protected function stagedImportSource(): string
    {
        return 'fingrid';
    }

    /** The payload identity holds the dataset IDs as fetched: `0124` and `124` are one dataset. */
    protected function stagedDataOptions(): array
    {
        return [...$this->allDataOptions(), 'dataset' => $this->requestedDatasetIds()];
    }

    /**
     * Normalized, once each and sorted: a repeated ID would merge its pages into one window (double
     * samples) and its failed copy would hide the answered one.
     *
     * @return list<int>
     */
    private function requestedDatasetIds(): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) $this->option('dataset'))));
        sort($ids);

        return $ids;
    }

    /**
     * Fetch step: one range per dataset (a backfill: 7-day windows), every page recorded. A failed
     * dataset stops at its failed request and does not stop the others.
     *
     * @param  list<int>  $datasetIds
     */
    private function fetch(ImportPayloadWriter $payload, array $datasetIds, ?CarbonImmutable $backfillStart, ?CarbonImmutable $backfillEnd): void
    {
        $this->client->assertConfigured();
        $currentHour = $payload->fetchedAt()->startOfHour();
        $payload->setFact('current_hour', $currentHour->format('Y-m-d\TH:i:s\Z'));
        $end = $backfillEnd !== null && $backfillEnd->lessThan($currentHour) ? $backfillEnd : $currentHour;

        foreach ($datasetIds as $datasetId) {
            if ($backfillStart !== null) {
                $windows = $this->chunks($backfillStart, $end);
            } else {
                $isDatahub = (config("fingrid.datasets.{$datasetId}.group")) === 'datahub';
                $lookback = (int) config($isDatahub ? 'fingrid.datahub_lookback_days' : 'fingrid.realtime_lookback_days');
                $windows = [[$currentHour->subDays($lookback), $currentHour]];
            }

            foreach ($windows as $window => [$start, $windowEnd]) {
                $failure = $this->client->recordPages($payload, ['dataset' => $datasetId, 'window' => $window], $datasetId, $start, $windowEnd);
                if ($failure !== null) {
                    // The exception message can contain the request URL; log only the class.
                    logger()->error('Fingrid fetch failed', [
                        'dataset_id' => $datasetId,
                        'exception_class' => $failure::class,
                    ]);
                    $this->error("Dataset {$datasetId}: fetch failed (".$failure::class.').');

                    break;
                }
            }
        }
    }

    /**
     * Store step: aggregate and upsert each dataset window from its recorded pages. A window with
     * a failed page is left out, as before (the dataset stopped there); failed datasets make the
     * run exit 1 after the commit, and their earlier windows stay (S10).
     */
    private function store(ImportPayloadReader $payload, bool $perWindow): ImportStoreResult
    {
        $fetchedAt = $payload->fetchedAt();
        $failedWindows = [];
        foreach ($payload->end()['failed_units'] as $failed) {
            $failedWindows["{$failed['dataset']}|{$failed['window']}"] = true;
        }

        $written = [];
        $flush = function (?string $key, array $rows) use (&$written, $failedWindows, $fetchedAt, $perWindow): void {
            if ($key === null || isset($failedWindows[$key])) {
                return;
            }
            $datasetId = (int) strtok($key, '|');
            $write = fn (): int => $this->importer->import($datasetId, $rows, $fetchedAt);
            $written[$datasetId] = ($written[$datasetId] ?? 0) + ($perWindow ? DB::transaction($write) : $write());
        };

        $window = null;
        $rows = [];
        foreach ($payload->requests() as $request) {
            $key = "{$request['unit']['dataset']}|{$request['unit']['window']}";
            if ($key !== $window) {
                $flush($window, $rows);
                [$window, $rows] = [$key, []];
            }
            if (! isset($failedWindows[$key])) {
                array_push($rows, ...FingridOpenDataService::page((string) ImportPayloadReader::body($request))['rows']);
            }
        }
        $flush($window, $rows);

        foreach ($written as $datasetId => $count) {
            $this->info("Dataset {$datasetId} (".config("fingrid.datasets.{$datasetId}.name")."): {$count} hourly rows.");
        }

        return new ImportStoreResult(
            ['hourly_rows' => array_sum($written)],
            error: $payload->complete() ? null : 'fetch_incomplete',
            errorStep: 'fetch',
        );
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function chunks(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $chunks = [];
        $cursor = $start;

        while ($cursor->lessThan($end)) {
            $chunkEnd = $cursor->addDays(self::BACKFILL_CHUNK_DAYS);
            if ($chunkEnd->greaterThan($end)) {
                $chunkEnd = $end;
            }
            $chunks[] = [$cursor, $chunkEnd];
            $cursor = $chunkEnd;
        }

        return $chunks;
    }
}
