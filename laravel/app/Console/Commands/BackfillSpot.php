<?php

namespace App\Console\Commands;

use App\Services\EntsoeService;
use App\Services\SpotPriceAverageService;
use App\Services\SpotPriceImport\SpotPriceImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class BackfillSpot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spot:backfill
                            {--start-date= : Start date for backfill (YYYY-MM-DD, defaults to 1 year ago)}
                            {--end-date= : End date for backfill (YYYY-MM-DD, defaults to today)}
                            {--force : Force fetch even if data already exists for the period}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill historical spot prices from ENTSO-E API';

    /**
     * Delay between API calls in milliseconds to respect rate limits.
     */
    private const API_DELAY_MS = 150;

    private EntsoeService $entsoeService;

    private SpotPriceAverageService $averageService;

    private SpotPriceImporter $spotPriceImporter;

    public function __construct(
        EntsoeService $entsoeService,
        SpotPriceAverageService $averageService,
        SpotPriceImporter $spotPriceImporter
    ) {
        parent::__construct();
        $this->entsoeService = $entsoeService;
        $this->averageService = $averageService;
        $this->spotPriceImporter = $spotPriceImporter;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Parse and validate dates
        $startDate = $this->parseStartDate();
        $endDate = $this->parseEndDate();

        if ($startDate === null || $endDate === null) {
            return Command::FAILURE;
        }

        if ($startDate->greaterThan($endDate)) {
            $this->error('Invalid date range: start-date must be before end-date.');

            return Command::FAILURE;
        }

        $force = $this->option('force');

        $this->info("Backfilling spot prices from {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}...");

        // Split date range into monthly chunks
        $chunks = $this->splitIntoMonthlyChunks($startDate, $endDate);
        $totalRecords = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar(count($chunks));
        $progressBar->start();

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                // Add delay between API calls to respect rate limits
                usleep(self::API_DELAY_MS * 1000);
            }

            $chunkStart = $chunk['start'];
            $chunkEnd = $chunk['end'];

            // Check if data exists for this period (unless --force)
            if (! $force && $this->spotPriceImporter->hasCompleteHourlyCoverage($chunkStart, $chunkEnd, 'FI')) {
                $progressBar->advance();

                continue;
            }

            try {
                $spotPrices = $this->entsoeService->fetchDayAheadPrices($chunkStart, $chunkEnd);

                if (! empty($spotPrices)) {
                    $this->spotPriceImporter->import($spotPrices);
                    $totalRecords += count($spotPrices);
                }
            } catch (RequestException|ConnectionException $e) {
                $errorCount++;
                $this->newLine();
                $this->error("Error fetching {$chunkStart->format('Y-m-d')} to {$chunkEnd->format('Y-m-d')} from ENTSO-E API after retries.");
                Log::error('BackfillSpot command failed for chunk', [
                    'start' => $chunkStart->toDateString(),
                    'end' => $chunkEnd->toDateString(),
                    'exception_class' => $e::class,
                    'exception' => $this->sanitizeHttpExceptionMessage($e->getMessage()),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($totalRecords > 0) {
            $this->info("Backfill completed! Processed {$totalRecords} records.");
            Log::info('Successfully backfilled spot prices', ['count' => $totalRecords]);

            // Calculate averages after backfilling
            $this->info('Calculating spot price averages...');
            $this->averageService->calculateAllAverages();
            $this->info('Averages calculated successfully.');
        } else {
            $this->info('Backfill completed! No new records to add.');
        }

        if ($errorCount > 0) {
            $this->warn("Encountered {$errorCount} errors during backfill.");
        }

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Remove sensitive ENTSO-E query parameters before writing HTTP exception messages to logs.
     */
    private function sanitizeHttpExceptionMessage(string $message): string
    {
        return preg_replace('/securityToken=[^&\s]+/', 'securityToken=[redacted]', $message) ?? $message;
    }

    /**
     * Parse the start date option.
     */
    private function parseStartDate(): ?Carbon
    {
        $startDateOption = $this->option('start-date');

        if ($startDateOption === null) {
            // Default to 1 year ago
            return Carbon::today('UTC')->subYear();
        }

        try {
            return Carbon::parse($startDateOption, 'UTC')->startOfDay();
        } catch (\Exception $e) {
            $this->error('Invalid start-date format. Use YYYY-MM-DD.');

            return null;
        }
    }

    /**
     * Parse the end date option.
     */
    private function parseEndDate(): ?Carbon
    {
        $endDateOption = $this->option('end-date');

        if ($endDateOption === null) {
            // Default to today
            return Carbon::today('UTC');
        }

        try {
            return Carbon::parse($endDateOption, 'UTC')->startOfDay();
        } catch (\Exception $e) {
            $this->error('Invalid end-date format. Use YYYY-MM-DD.');

            return null;
        }
    }

    /**
     * Split a date range into monthly chunks.
     *
     * @return array Array of ['start' => Carbon, 'end' => Carbon]
     */
    private function splitIntoMonthlyChunks(Carbon $startDate, Carbon $endDate): array
    {
        $chunks = [];
        $current = $startDate->copy();

        while ($current->lessThanOrEqualTo($endDate)) {
            $chunkEnd = $current->copy()->addMonth()->subDay();

            // Don't exceed the overall end date
            if ($chunkEnd->greaterThan($endDate)) {
                $chunkEnd = $endDate->copy();
            }

            $chunks[] = [
                'start' => $current->copy(),
                'end' => $chunkEnd->copy()->addDay(), // API needs end to be exclusive
            ];

            $current = $chunkEnd->copy()->addDay();
        }

        return $chunks;
    }
}
