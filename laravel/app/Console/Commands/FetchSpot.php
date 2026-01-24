<?php

namespace App\Console\Commands;

use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use App\Services\EntsoeService;
use App\Services\SpotPriceAverageService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
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
    private SpotPriceAverageService $averageService;

    public function __construct(EntsoeService $entsoeService, SpotPriceAverageService $averageService)
    {
        parent::__construct();
        $this->entsoeService = $entsoeService;
        $this->averageService = $averageService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fetching spot prices from ENTSO-E API...');

        // Check if tomorrow's prices already exist before fetching
        $tomorrowDate = Carbon::tomorrow('Europe/Helsinki')->format('Y-m-d');
        $hadTomorrowPrices = $this->hasPricesForDate($tomorrowDate);

        try {
            // Fetch today and tomorrow (using Helsinki timezone to ensure we get all Finnish hours)
            // Helsinki is UTC+2 in winter (UTC+3 in summer), so we need to start from yesterday 22:00 UTC
            // to capture midnight Helsinki time
            $startDate = Carbon::today('Europe/Helsinki')->setTimezone('UTC');
            $endDate = Carbon::tomorrow('Europe/Helsinki')->addDay()->setTimezone('UTC');

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

            // Calculate averages after fetching new data
            $this->info('Calculating spot price averages...');
            $this->averageService->calculateAllAverages();
            $this->info('Averages calculated successfully.');

            // Check if tomorrow's prices are newly available and trigger social media pipeline
            $hasTomorrowPrices = $this->hasPricesForDate($tomorrowDate);
            if (!$hadTomorrowPrices && $hasTomorrowPrices) {
                $this->triggerSocialMediaPipeline($tomorrowDate);
            }

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
     * Check if we have prices for a given date (Helsinki timezone).
     */
    private function hasPricesForDate(string $date): bool
    {
        $startOfDay = Carbon::parse($date, 'Europe/Helsinki')->startOfDay()->setTimezone('UTC');
        $endOfDay = Carbon::parse($date, 'Europe/Helsinki')->endOfDay()->setTimezone('UTC');

        return SpotPriceHour::forRegion('FI')
            ->whereBetween('utc_datetime', [$startOfDay, $endOfDay])
            ->exists();
    }

    /**
     * Trigger the social media pipeline when tomorrow's prices become available.
     */
    private function triggerSocialMediaPipeline(string $tomorrowDate): void
    {
        $this->info("Tomorrow's prices ({$tomorrowDate}) are now available!");
        $this->info('Triggering social media pipeline...');

        Log::info('Tomorrow prices available, triggering social media pipeline', [
            'date' => $tomorrowDate,
        ]);

        try {
            Artisan::call('social:daily-video', [], $this->output);
            $this->info('Social media pipeline completed.');
        } catch (\Exception $e) {
            $this->error('Social media pipeline failed: ' . $e->getMessage());
            Log::error('Social media pipeline failed after spot fetch', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Save spot prices to database.
     *
     * Stores 15-minute data in spot_prices_quarter table and
     * calculates hourly averages for spot_prices_hour table.
     *
     * @param array $spotPrices Array of spot price data from EntsoeService
     */
    private function saveSpotPrices(array $spotPrices): void
    {
        // Separate 15-minute and hourly data
        $quarterData = [];
        $hourlyData = [];

        foreach ($spotPrices as $item) {
            $vatRate = $this->getVatRate($item['utc_datetime']);
            $resolutionMinutes = $item['resolution_minutes'] ?? 60;

            $record = [
                'region' => $item['region'],
                'timestamp' => $item['timestamp'],
                'utc_datetime' => $item['utc_datetime'],
                'price_without_tax' => $item['price_without_tax'],
                'vat_rate' => $vatRate,
            ];

            if ($resolutionMinutes === 15) {
                $quarterData[] = $record;
            } else {
                // Already hourly data, save directly
                $hourlyData[] = $record;
            }
        }

        // Save 15-minute data to quarter table
        if (!empty($quarterData)) {
            foreach (array_chunk($quarterData, 500) as $chunk) {
                SpotPriceQuarter::insertOrIgnore($chunk);
            }

            // Calculate hourly averages from 15-minute data
            $hourlyAverages = $this->calculateHourlyAverages($quarterData);
            foreach (array_chunk($hourlyAverages, 500) as $chunk) {
                SpotPriceHour::insertOrIgnore($chunk);
            }
        }

        // Save any existing hourly data directly
        if (!empty($hourlyData)) {
            foreach (array_chunk($hourlyData, 500) as $chunk) {
                SpotPriceHour::insertOrIgnore($chunk);
            }
        }
    }

    /**
     * Calculate hourly averages from 15-minute data.
     *
     * @param array $quarterData Array of 15-minute price records
     * @return array Array of hourly average records
     */
    private function calculateHourlyAverages(array $quarterData): array
    {
        // Group by hour
        $hourGroups = [];

        foreach ($quarterData as $item) {
            $utcDatetime = $item['utc_datetime'];
            if ($utcDatetime instanceof Carbon) {
                $hourKey = $utcDatetime->copy()->startOfHour()->timestamp;
            } else {
                $hourKey = Carbon::parse($utcDatetime)->startOfHour()->timestamp;
            }

            if (!isset($hourGroups[$hourKey])) {
                $hourGroups[$hourKey] = [
                    'prices' => [],
                    'region' => $item['region'],
                    'vat_rate' => $item['vat_rate'],
                    'utc_datetime' => $utcDatetime instanceof Carbon
                        ? $utcDatetime->copy()->startOfHour()
                        : Carbon::parse($utcDatetime)->startOfHour(),
                ];
            }
            $hourGroups[$hourKey]['prices'][] = $item['price_without_tax'];
        }

        // Calculate averages
        $hourlyData = [];
        foreach ($hourGroups as $timestamp => $group) {
            $avgPrice = array_sum($group['prices']) / count($group['prices']);

            $hourlyData[] = [
                'region' => $group['region'],
                'timestamp' => $timestamp,
                'utc_datetime' => $group['utc_datetime'],
                'price_without_tax' => $avgPrice,
                'vat_rate' => $group['vat_rate'],
            ];
        }

        return $hourlyData;
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
