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
        $this->info('Fetching spot prices from ENTSO-E API...');

        try {
            // Fetch today and tomorrow (using Helsinki timezone to ensure we get all Finnish hours)
            // Helsinki is UTC+2 in winter (UTC+3 in summer), so we need to start from yesterday 22:00 UTC
            // to capture midnight Helsinki time
            $startDate = Carbon::today('Europe/Helsinki')->setTimezone('UTC');
            $endDate = Carbon::tomorrow('Europe/Helsinki')->addDay()->setTimezone('UTC');

            $spotPrices = $this->entsoeService->fetchDayAheadPrices($startDate, $endDate);
        } catch (RequestException|ConnectionException $e) {
            $this->error('Failed to fetch spot prices from ENTSO-E API after retries.');
            Log::error('FetchSpot command failed while fetching prices', [
                'exception_class' => $e::class,
                'exception' => $this->sanitizeHttpExceptionMessage($e->getMessage()),
            ]);

            return Command::FAILURE;
        }

        if (empty($spotPrices)) {
            $this->warn('No spot prices fetched from API.');

            return Command::SUCCESS;
        }

        $this->info('Fetched '.count($spotPrices).' hourly prices. Processing...');

        try {
            $this->spotPriceImporter->import($spotPrices);
            $this->info('Spot prices fetched successfully! Processed '.count($spotPrices).' records.');
            Log::info('Successfully fetched spot prices', ['count' => count($spotPrices)]);

            // Calculate averages after fetching new data
            $this->info('Calculating spot price averages...');
            $this->averageService->calculateAllAverages();
            $this->info('Averages calculated successfully.');

            $this->info('Queueing contract price statistics page cache warm...');
            $this->call('contracts:warm-price-statistics-cache', [
                '--period' => ['weekly'],
                '--consumption' => [5000],
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error saving spot prices: '.$e->getMessage());
            Log::error('FetchSpot command failed during save', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Remove sensitive ENTSO-E query parameters before writing HTTP exception messages to logs.
     */
    private function sanitizeHttpExceptionMessage(string $message): string
    {
        return preg_replace('/securityToken=[^&\s]+/', 'securityToken=[redacted]', $message) ?? $message;
    }
}
