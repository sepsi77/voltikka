<?php

namespace App\Console\Commands;

use App\Models\SpotPriceHour;
use App\Services\EntsoeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class FetchSpot extends Command
{
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
        $this->info('Fetching spot prices from ENTSO-E API...');

        try {
            // Fetch today and tomorrow
            $startDate = Carbon::today('UTC');
            $endDate = Carbon::tomorrow('UTC')->addDay();

            $spotPrices = $this->entsoeService->fetchDayAheadPrices($startDate, $endDate);
        } catch (RequestException $e) {
            $this->error('Failed to fetch spot prices: ' . $e->getMessage());
            Log::error('FetchSpot command failed', ['exception' => $e->getMessage()]);
            return Command::FAILURE;
        }

        if (empty($spotPrices)) {
            $this->warn('No spot prices fetched from API.');
            return Command::SUCCESS;
        }

        $this->info("Fetched " . count($spotPrices) . " hourly prices. Processing...");

        try {
            $this->saveSpotPrices($spotPrices);
            $this->info('Spot prices fetched successfully! Processed ' . count($spotPrices) . ' records.');
            Log::info('Successfully fetched spot prices', ['count' => count($spotPrices)]);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error saving spot prices: ' . $e->getMessage());
            Log::error('FetchSpot command failed during save', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
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
