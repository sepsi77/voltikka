<?php

namespace App\Console\Commands;

use App\Models\SpotPriceForecast;
use App\Models\SpotPriceForecastRun;
use App\Services\SpotForecasts\NordpoolPredictFiService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            DB::transaction(function () use ($upsertRows, $records, $now) {
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

                SpotPriceForecastRun::insertOrIgnore($this->runRow($records, $now));
            });

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

    /**
     * Build the append-only vintage row. The feed hash is the importer's SHA-256 of the raw
     * response body, so an unchanged upstream feed is stored once (unique source + hash).
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
