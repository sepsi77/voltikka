<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Models\SpotPriceForecast;
use App\Models\SpotPriceForecastRun;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Services\SpotForecasts\NordpoolPredictFiService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

class FetchSpotForecast extends Command
{
    use StagedImport;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spot:fetch-forecast {--source=nordpool-predict-fi : Forecast source to fetch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch third-party Finnish spot price forecasts and save them to the database';

    private const STORE_LOCK_LEASE_SECONDS = 300;

    private const STORE_LOCK_WAIT_SECONDS = 60;

    public function __construct(private readonly NordpoolPredictFiService $nordpoolPredictFiService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command: fetch the feed into a payload file, then store from that file
     * (StagedImport). Not date-bound (S9): a payload from earlier the same day is accepted; it
     * adds its run row but leaves the display rows of a newer feed in place.
     */
    public function handle(): int
    {
        $source = (string) $this->option('source');

        if (! in_array($source, ['nordpool-predict-fi', SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI], true)) {
            $this->error("Unsupported forecast source: {$source}");

            return $this->stagedFailure('fetch', 'unsupported_source');
        }

        return $this->runStaged(
            function (ImportPayloadWriter $payload): void {
                $this->info('Fetching spot price forecasts from nordpool-predict-fi...');
                $payload->record(['feed' => 'prediction'], $this->nordpoolPredictFiService->fetchFeed());
            },
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload),
            // The store opens its transaction inside its lock, so the commit precedes the release.
            transactional: false,
        );
    }

    protected function stagedImportSource(): string
    {
        return 'nordpool-predict-fi';
    }

    /**
     * Parses the payload, then writes under the source/region store lock in one transaction. The
     * display upsert is skipped when a newer feed is already stored; the cache is forgotten after
     * the commit only when the display changed. A lock timeout is the closed error `store_busy`.
     */
    private function store(ImportPayloadReader $payload): ImportStoreResult
    {
        $requests = iterator_to_array($payload->requests(), false);
        $status = $requests[0]['status'] ?? null;
        if (count($requests) !== 1 || ! is_int($status) || $status < 200 || $status >= 300) {
            throw new UnexpectedValueException('The forecast payload holds no answered feed request.');
        }

        $records = $this->nordpoolPredictFiService->parseFeed(
            (string) ImportPayloadReader::body($requests[0]),
            $payload->fetchedAt(),
            $requests[0]['url'] ?? $this->nordpoolPredictFiService->feedUrl(),
        );

        if (empty($records)) {
            $this->warn('No forecast points fetched.');

            return new ImportStoreResult(['records' => 0, 'display_rows' => 0, 'runs_inserted' => 0]);
        }

        $now = now();
        // One lock per source and region around the recency check and both writes (review
        // PRC-002), and the transaction commits before the lock is released: the check sees every
        // store that committed before it, and no newer store can commit between check and write.
        $lock = Cache::lock("spot_forecast_store_v1:{$records[0]['source']}:{$records[0]['region']}", self::STORE_LOCK_LEASE_SECONDS);
        try {
            [$displayCurrent, $runsInserted] = $lock->block(
                self::STORE_LOCK_WAIT_SECONDS,
                fn (): array => DB::transaction(fn (): array => $this->write($records, $now)),
            );
        } catch (LockTimeoutException) {
            // No write; the payload stays for a replay.
            $this->reportStagedFailure('store', 'store_busy', null, []);

            return new ImportStoreResult(['records' => count($records), 'display_rows' => 0, 'runs_inserted' => 0], error: 'store_busy');
        }

        $counts = ['records' => count($records), 'display_rows' => $displayCurrent ? count($records) : 0, 'runs_inserted' => $runsInserted];

        return new ImportStoreResult($counts, [
            ...($displayCurrent ? [fn () => Cache::forget('spot_price:forecast:nordpool_predict_fi:v1')] : []),
            function () use ($records): void {
                $this->info('Spot price forecasts fetched successfully! Processed '.count($records).' records.');
                Log::info('Successfully fetched spot price forecasts', [
                    'source' => SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI,
                    'count' => count($records),
                ]);
            },
        ]);
    }

    /**
     * The display upsert, skipped when a newer feed is already stored, and the append-only run
     * row. The caller holds the source/region store lock and the transaction.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array{0: bool, 1: int} whether the display was written, and the inserted run rows
     */
    private function write(array $records, SupportCarbon $now): array
    {
        // The display table shows the latest stored feed: an older replay must not overwrite it.
        $displayCurrent = ! $this->newerFeedStored($records[0]);
        if ($displayCurrent) {
            $upsertRows = array_map(function (array $record) use ($now) {
                return array_merge($record, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }, $records);

            foreach (array_chunk($upsertRows, 500) as $chunk) {
                SpotPriceForecast::upsert(
                    $chunk,
                    ['source', 'region', 'timestamp'],
                    [
                        'utc_datetime',
                        'price_with_tax',
                        'vat_rate',
                        'source_url',
                        'fetched_at',
                        'metadata',
                        'updated_at',
                    ]
                );
            }
        } else {
            $this->warn('A newer forecast feed is already stored; only the append-only run is kept.');
        }

        // The append-only vintage is kept for every feed, also an older one.
        return [$displayCurrent, SpotPriceForecastRun::insertOrIgnore($this->runRow($records, $now))];
    }

    /**
     * Whether a feed fetched after this payload is already stored for the same source and region:
     * as a run row, or as display rows (an unchanged feed fetched again adds no run row but
     * refreshes the display rows' fetched_at). Runs under the store lock, inside the transaction.
     *
     * @param  array<string, mixed>  $record
     */
    private function newerFeedStored(array $record): bool
    {
        foreach ([SpotPriceForecastRun::query(), SpotPriceForecast::query()] as $query) {
            if ($query->where('source', $record['source'])->where('region', $record['region'])
                ->where('fetched_at', '>', $record['fetched_at'])->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the append-only vintage row. The feed hash is the importer's SHA-256 of the raw
     * response body, so an unchanged upstream feed is stored once (unique source + hash), also
     * when the same payload is stored again.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function runRow(array $records, SupportCarbon $now): array
    {
        $metadata = json_decode((string) $records[0]['metadata'], true, flags: JSON_THROW_ON_ERROR);
        $points = array_map(fn (array $record) => [(int) $record['timestamp'], (float) $record['price_with_tax']], $records);
        usort($points, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return [
            'source' => $records[0]['source'],
            'region' => $records[0]['region'],
            'feed_hash' => (string) $metadata['feed_hash'],
            'fetched_at' => $records[0]['fetched_at'],
            'point_count' => count($points),
            'first_utc_datetime' => Carbon::createFromTimestampUTC($points[0][0])->format('Y-m-d H:i:s'),
            'last_utc_datetime' => Carbon::createFromTimestampUTC($points[array_key_last($points)][0])->format('Y-m-d H:i:s'),
            'points' => json_encode($points, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
