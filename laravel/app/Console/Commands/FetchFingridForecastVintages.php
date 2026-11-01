<?php

namespace App\Console\Commands;

use App\Services\Fingrid\FingridForecastVintageImporter;
use App\Services\Fingrid\FingridOpenDataService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keep vintages of rolling Fingrid forecasts (config fingrid.forecast_vintages, e.g. dataset
 * 245 wind power) in hourly_forecast_runs. One request per dataset: the hours from the next
 * full UTC hour to the end of the forecast horizon. The current hour is not stored, because
 * some of its quarters are already in the past. Read-only towards Fingrid.
 */
class FetchFingridForecastVintages extends Command
{
    protected $signature = 'fingrid:fetch-forecast-vintages
                            {--dataset=* : Only these dataset IDs (default: all configured)}';

    protected $description = 'Store the current vintage of rolling Fingrid forecasts for own spot price models';

    public function __construct(
        private readonly FingridOpenDataService $client,
        private readonly FingridForecastVintageImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $configured = (array) config('fingrid.forecast_vintages', []);
        $requested = array_map('intval', (array) $this->option('dataset'));
        $unknown = array_diff($requested, array_keys($configured));

        if ($unknown !== []) {
            $this->error('Unknown forecast dataset ID(s): '.implode(', ', $unknown));

            return Command::FAILURE;
        }

        $failed = 0;
        foreach ($requested !== [] ? $requested : array_keys($configured) as $datasetId) {
            $hours = (int) $configured[$datasetId]['horizon_hours'];
            $firstHour = Carbon::now('UTC')->startOfHour()->addHour();

            try {
                $rows = $this->client->fetchRows($datasetId, $firstHour, $firstHour->copy()->addHours($hours));
                $stored = $this->importer->store(
                    $datasetId,
                    (string) $configured[$datasetId]['variable'],
                    $rows,
                    $firstHour,
                    $hours,
                    Carbon::now('UTC')
                );
                $this->info("Forecast dataset {$datasetId}: ".($stored ? 'new vintage stored' : 'unchanged').'.');
            } catch (Throwable $e) {
                $failed++;
                // The exception message can contain the request URL; log only the class.
                logger()->error('Fingrid forecast vintage fetch failed', [
                    'dataset_id' => $datasetId,
                    'exception_class' => $e::class,
                ]);
                $this->error("Forecast dataset {$datasetId}: fetch failed (".$e::class.').');
            }
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
