<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\EntsoeService;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Services\SpotPriceAverageService;
use App\Services\SpotPriceImport\SpotPriceImporter;
use App\Support\DataFetchFailureReporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class FetchSpot extends Command
{
    use StagedImport;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spot:fetch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch Nord Pool spot prices from ENTSO-E API and save to database';

    /**
     * Longer than a store may live (review LAR-201): Temporal kills a store activity's PHP process
     * after `STORE_TIMEOUT_MINUTES` = 60 (orchestration/src/voltikka_orchestration/staged.py), so
     * the lease cannot expire under a running store and let a second one compute averages from a
     * snapshot without its rows. Accepted cost: a store killed while it holds the lock blocks the
     * next FI store for up to 65 minutes; that store waits STORE_LOCK_WAIT_SECONDS, then fails
     * `store_busy` with its payload kept.
     */
    private const STORE_LOCK_LEASE_SECONDS = 3900;

    private const STORE_LOCK_WAIT_SECONDS = 60;

    private DataFetchFailureReporter $reporter;

    public function __construct(
        private readonly EntsoeService $entsoeService,
        private readonly SpotPriceAverageService $averageService,
        private readonly SpotPriceImporter $spotPriceImporter
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command: fetch the one FI A44 response into a payload file, then store
     * from that file (StagedImport). Not date-bound by the payload (S9): the averages are a
     * recalculation over the stored rows and their rolling rows take today's Helsinki date, so a
     * replay is meant for the same Helsinki day. Price rows are insert-only, so a same-day replay
     * inserts nothing that the first store already wrote.
     */
    public function handle(): int
    {
        $this->reporter = new DataFetchFailureReporter('spot');
        $exit = self::FAILURE;
        try {
            $exit = $this->runStaged(
                fn (ImportPayloadWriter $payload) => $this->fetch($payload),
                fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload),
                // The store opens its transaction inside its lock, so the commit precedes the release.
                transactional: false,
            );
        } finally {
            $this->reporter->report($exit === self::FAILURE);
        }

        return $exit;
    }

    protected function stagedImportSource(): string
    {
        return 'entsoe-day-ahead';
    }

    /** The Issue keeps today's stage names; the step (fetch or store) is added to its context. */
    protected function reportStagedFailure(string $step, string $error, ?Throwable $exception, array $fields): void
    {
        $this->reporter->step($step);
        $this->reporter->fail(match ($error) {
            'fetch_failed' => 'acquisition',
            'store_failed', 'after_commit_failed' => 'save_or_post_import',
            default => $error,
        }, $exception);
    }

    private function fetch(ImportPayloadWriter $payload): void
    {
        $this->info('Fetching spot prices from ENTSO-E API...');

        // Helsinki today through the day after tomorrow, fixed from the run's fetch time (S5).
        $start = Carbon::instance($payload->fetchedAt())->setTimezone('Europe/Helsinki')->startOfDay()->utc();
        $end = $start->copy()->setTimezone('Europe/Helsinki')->addDays(2)->startOfDay()->utc();
        $payload->setFact('period_start', $start->format('Y-m-d\TH:i:s\Z'));
        $payload->setFact('period_end', $end->format('Y-m-d\TH:i:s\Z'));

        try {
            $response = $this->entsoeService->fetchDayAheadResponse($start->copy(), $end->copy());
        } catch (RequestException|ConnectionException $e) {
            $this->error('Failed to fetch spot prices from ENTSO-E API after retries.');

            throw $e;
        }

        $payload->record(['region' => 'FI'], $response);
    }

    /**
     * Parses the payload, then writes the FI price rows and the averages in one transaction under
     * the FI store lock (S21): they commit together, and two stores (a replay and a scheduled
     * run) cannot each compute averages from a snapshot without the other's rows. The lock is
     * taken before the transaction and released after the commit. A lock timeout is the closed
     * error `store_busy` with no write. Only the cache warm (queue dispatch) runs after the commit.
     */
    private function store(ImportPayloadReader $payload): ImportStoreResult
    {
        $requests = iterator_to_array($payload->requests(), false);
        $status = $requests[0]['status'] ?? null;
        if (count($requests) !== 1 || ($requests[0]['unit']['region'] ?? null) !== 'FI'
            || ! is_int($status) || $status < 200 || $status >= 300) {
            throw new UnexpectedValueException('The spot payload holds no answered FI request.');
        }

        $spotPrices = $this->entsoeService->parseDayAheadPrices((string) ImportPayloadReader::body($requests[0]), 'FI');
        $this->reporter->count('fetched_records', count($spotPrices));
        if ($spotPrices === []) {
            $this->reporter->step('store');
            $this->reporter->fail('empty_response');
            $this->warn('No spot prices fetched from API.');

            return new ImportStoreResult(['records' => 0]);
        }

        $this->info('Fetched '.count($spotPrices).' hourly prices. Processing...');
        try {
            Cache::lock('spot_store_v1:FI', self::STORE_LOCK_LEASE_SECONDS)->block(
                self::STORE_LOCK_WAIT_SECONDS,
                fn () => DB::transaction(function () use ($spotPrices): void {
                    $this->spotPriceImporter->import($spotPrices);
                    $this->info('Calculating spot price averages...');
                    $this->averageService->calculateAllAverages();
                }),
            );
        } catch (LockTimeoutException) {
            // No write; the payload stays for a replay.
            $this->reportStagedFailure('store', 'store_busy', null, []);

            return new ImportStoreResult(['records' => count($spotPrices)], error: 'store_busy');
        }

        return new ImportStoreResult(['records' => count($spotPrices)], [
            function () use ($spotPrices): void {
                $this->info('Spot prices fetched successfully! Processed '.count($spotPrices).' records.');
                Log::info('Successfully fetched spot prices', ['count' => count($spotPrices)]);
                $this->info('Averages calculated successfully.');

                $this->info('Queueing contract price statistics page cache warm...');
                $this->call('contracts:warm-price-statistics-cache', [
                    '--period' => ['weekly'],
                    '--consumption' => [5000],
                ]);
            },
        ]);
    }
}
