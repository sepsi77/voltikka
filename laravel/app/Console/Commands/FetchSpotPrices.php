<?php

namespace App\Console\Commands;

use App\Models\SpotPriceHour;
use App\Services\EleringApiClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchSpotPrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spotprices:fetch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch Nord Pool spot prices from Elering API and save to database';

    private EleringApiClient $apiClient;

    public function __construct(EleringApiClient $apiClient)
    {
        parent::__construct();
        $this->apiClient = $apiClient;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fetching spot prices from Elering API...');

        try {
            $spotPrices = $this->apiClient->fetchSpotPrices();
        } catch (RequestException $e) {
            $this->error('Failed to fetch spot prices: ' . $e->getMessage());
            Log::error('FetchSpotPrices command failed', ['exception' => $e->getMessage()]);
            return Command::FAILURE;
        }

        if (empty($spotPrices)) {
            $this->warn('No spot prices fetched from API.');
            return Command::SUCCESS;
        }

        $this->info("Fetched " . count($spotPrices) . " hourly prices. Processing...");

        try {
            $this->saveSpotPrices($spotPrices);
            $this->info('Spot prices fetched successfully!');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error saving spot prices: ' . $e->getMessage());
            Log::error('FetchSpotPrices command failed during save', [
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
     * @param array $spotPrices Array of spot price data
     */
    private function saveSpotPrices(array $spotPrices): void
    {
        // Prepare data for insertion
        $insertData = array_map(function ($item) {
            return [
                'region' => $item['region'],
                'timestamp' => $item['timestamp'],
                'utc_datetime' => $item['utc_datetime'],
                'price_without_tax' => $item['price_without_tax'],
                'vat_rate' => $item['vat_rate'],
            ];
        }, $spotPrices);

        // Insert in chunks to avoid memory issues with large datasets
        foreach (array_chunk($insertData, 500) as $chunk) {
            SpotPriceHour::insertOrIgnore($chunk);
        }

        $this->info("Processed " . count($spotPrices) . " spot price records.");
    }
}
