<?php

namespace App\Console\Commands;

use App\Models\SpotPriceHour;
use App\Services\EntsoeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
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

    public function __construct(EntsoeService $entsoeService)
    {
        parent::__construct();
        $this->entsoeService = $entsoeService;
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
            if (!$force && $this->hasDataForPeriod($chunkStart, $chunkEnd)) {
                $progressBar->advance();
                continue;
            }

            try {
                $spotPrices = $this->entsoeService->fetchDayAheadPrices($chunkStart, $chunkEnd);

                if (!empty($spotPrices)) {
                    $this->saveSpotPrices($spotPrices);
                    $totalRecords += count($spotPrices);
                }
            } catch (RequestException $e) {
                $errorCount++;
                $this->newLine();
                $this->error("Error fetching {$chunkStart->format('Y-m-d')} to {$chunkEnd->format('Y-m-d')}: " . $e->getMessage());
                Log::error('BackfillSpot command failed for chunk', [
                    'start' => $chunkStart->toDateString(),
                    'end' => $chunkEnd->toDateString(),
                    'exception' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($totalRecords > 0) {
            $this->info("Backfill completed! Processed {$totalRecords} records.");
            Log::info('Successfully backfilled spot prices', ['count' => $totalRecords]);
        } else {
            $this->info('Backfill completed! No new records to add.');
        }

        if ($errorCount > 0) {
            $this->warn("Encountered {$errorCount} errors during backfill.");
        }

        return Command::SUCCESS;
    }

    /**
     * Parse the start date option.
     *
     * @return Carbon|null
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
     *
     * @return Carbon|null
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
     * @param Carbon $startDate
     * @param Carbon $endDate
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

    /**
     * Check if we already have data for a period.
     *
     * @param Carbon $start
     * @param Carbon $end
     * @return bool
     */
    private function hasDataForPeriod(Carbon $start, Carbon $end): bool
    {
        return SpotPriceHour::where('region', 'FI')
            ->whereBetween('utc_datetime', [$start, $end])
            ->exists();
    }

    /**
     * Save spot prices to database.
     *
     * Uses INSERT ... ON CONFLICT DO NOTHING to skip existing records.
     *
     * @param array $spotPrices Array of spot price data from EntsoeService
     */
    private function saveSpotPrices(array $spotPrices): void
    {
        // Prepare data for insertion with VAT rates
        $insertData = array_map(function ($item) {
            $vatRate = $this->getVatRate($item['utc_datetime']);

            return [
                'region' => $item['region'],
                'timestamp' => $item['timestamp'],
                'utc_datetime' => $item['utc_datetime'],
                'price_without_tax' => $item['price_without_tax'],
                'vat_rate' => $vatRate,
            ];
        }, $spotPrices);

        // Insert in chunks to avoid memory issues with large datasets
        foreach (array_chunk($insertData, 500) as $chunk) {
            SpotPriceHour::insertOrIgnore($chunk);
        }
    }

    /**
     * Get the VAT rate for a given date.
     *
     * Finland VAT rate history for electricity:
     * - Dec 1, 2022 to Apr 30, 2023: 10% (temporary reduction)
     * - May 1, 2023 to Aug 31, 2024: 24% (standard rate)
     * - Sep 1, 2024 onwards: 25.5% (increased rate)
     *
     * @param Carbon $priceDate The date to get VAT rate for
     * @return float VAT rate (0.10, 0.24, or 0.255)
     */
    private function getVatRate(Carbon $priceDate): float
    {
        // Convert to Helsinki timezone for VAT determination
        $helsinkiDate = $priceDate->copy()->setTimezone('Europe/Helsinki');

        // Temporary reduced VAT period: Dec 1, 2022 - Apr 30, 2023
        $reducedVatStart = Carbon::create(2022, 12, 1, 0, 0, 0, 'Europe/Helsinki');
        $reducedVatEnd = Carbon::create(2023, 5, 1, 0, 0, 0, 'Europe/Helsinki');

        if ($helsinkiDate >= $reducedVatStart && $helsinkiDate < $reducedVatEnd) {
            return 0.10; // Temporary reduced VAT rate
        }

        // VAT increase to 25.5%: Sep 1, 2024 onwards
        $increasedVatStart = Carbon::create(2024, 9, 1, 0, 0, 0, 'Europe/Helsinki');

        if ($helsinkiDate >= $increasedVatStart) {
            return 0.255; // Current VAT rate (from Sep 2024)
        }

        return 0.24; // Standard VAT rate (May 2023 - Aug 2024)
    }
}
