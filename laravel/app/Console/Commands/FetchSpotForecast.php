<?php

namespace App\Console\Commands;

use App\Models\SpotPriceForecast;
use App\Services\SpotForecasts\NordpoolPredictFiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchSpotForecast extends Command
{
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

    public function __construct(private readonly NordpoolPredictFiService $nordpoolPredictFiService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = (string) $this->option('source');

        if (!in_array($source, ['nordpool-predict-fi', SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI], true)) {
            $this->error("Unsupported forecast source: {$source}");
            return Command::FAILURE;
        }

        $this->info('Fetching spot price forecasts from nordpool-predict-fi...');

        try {
            $records = $this->nordpoolPredictFiService->fetchForecasts();
        } catch (Throwable $e) {
            $this->error('Failed to fetch spot price forecasts.');
            Log::error('FetchSpotForecast command failed while fetching forecasts', [
                'source' => SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI,
                'exception_class' => $e::class,
                'exception' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        if (empty($records)) {
            $this->warn('No forecast points fetched.');
            return Command::SUCCESS;
        }

        $now = now();
        $upsertRows = array_map(function (array $record) use ($now) {
            return array_merge($record, [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, $records);

        try {
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

            Cache::forget('spot_price:forecast:nordpool_predict_fi:v1');

            $this->info('Spot price forecasts fetched successfully! Processed ' . count($records) . ' records.');
            Log::info('Successfully fetched spot price forecasts', [
                'source' => SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI,
                'count' => count($records),
            ]);

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Error saving spot price forecasts: ' . $e->getMessage());
            Log::error('FetchSpotForecast command failed while saving forecasts', [
                'source' => SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI,
                'exception_class' => $e::class,
                'exception' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
