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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
     * from that file (StagedImport). Not date-bound by the payload (S9), but the after-commit
     * averages use today's date, so a replay is meant for the same Helsinki day. Rows are
     * insert-only, so a same-day replay inserts nothing that the first store already wrote.
     */
    public function handle(): int
    {
        $this->reporter = new DataFetchFailureReporter('spot');
        $exit = self::FAILURE;
        try {
            $exit = $this->runStaged(
                fn (ImportPayloadWriter $payload) => $this->fetch($payload),
                fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload),
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

    /** Runs inside the StagedImport store transaction; averages and cache warming run after the commit. */
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
        $this->spotPriceImporter->import($spotPrices);

        return new ImportStoreResult(['records' => count($spotPrices)], [
            function () use ($spotPrices): void {
                $this->info('Spot prices fetched successfully! Processed '.count($spotPrices).' records.');
                Log::info('Successfully fetched spot prices', ['count' => count($spotPrices)]);

                $this->info('Calculating spot price averages...');
                $this->averageService->calculateAllAverages();
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
