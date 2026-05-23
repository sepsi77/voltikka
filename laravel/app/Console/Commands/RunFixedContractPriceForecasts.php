<?php

namespace App\Console\Commands;

use App\Models\FixedContractPriceForecast;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RunFixedContractPriceForecasts extends Command
{
    protected $signature = 'forecasting:run-fixed-contracts
        {--as-of= : Forecast date, defaults to today in Europe/Helsinki.}
        {--horizon= : Forecast horizon in days, defaults to config value.}
        {--duration=* : Duration months to forecast, e.g. 6, 12, 24. Defaults to config values.}
        {--quantile=* : Target quantile to forecast: median, p20, p80. Defaults to config values.}
        {--overwrite : Replace an existing forecast for the same date/horizon/duration/quantile/model version.}
        {--dry-run : Calculate and print forecasts without writing to the database.}';

    protected $description = 'Calculate and persist fixed-term contract price forecasts';

    public function handle(FixedTermPriceForecastService $forecastService): int
    {
        $asOf = $this->option('as-of')
            ? CarbonImmutable::parse($this->option('as-of'))->startOfDay()
            : CarbonImmutable::now('Europe/Helsinki')->startOfDay();

        $horizon = $this->option('horizon') !== null
            ? (int) $this->option('horizon')
            : (int) config('price_forecasting.fixed_term.default_horizon_days', 30);

        $durations = $this->option('duration') ?: null;
        $quantiles = $this->option('quantile') ?: null;

        $forecasts = $forecastService->buildForecasts($asOf, $horizon, $durations, $quantiles);

        if ($forecasts->isEmpty()) {
            $this->warn('No forecasts were produced. Check retail statistics, futures coverage, and minimum history settings.');

            return self::SUCCESS;
        }

        $saved = 0;
        $skipped = 0;

        foreach ($forecasts as $forecast) {
            $this->line(sprintf(
                '%s %dm %s: current %.4f, forecast %.4f, move %+.4f c/kWh, %s (%s)',
                $forecast['forecast_date'],
                $forecast['duration_months'],
                $forecast['target_quantile'],
                $forecast['current_price_cents_per_kwh'],
                $forecast['forecast_price_cents_per_kwh'],
                $forecast['expected_change_cents_per_kwh'],
                $forecast['direction'],
                $forecast['confidence'],
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            $identity = [
                'forecast_date' => $forecast['forecast_date'],
                'horizon_days' => $forecast['horizon_days'],
                'duration_months' => $forecast['duration_months'],
                'target_quantile' => $forecast['target_quantile'],
                'model_version' => $forecast['model_version'],
            ];

            $existing = FixedContractPriceForecast::query()->where($identity)->first();

            if ($existing !== null && ! $this->option('overwrite')) {
                $skipped++;

                continue;
            }

            FixedContractPriceForecast::query()->updateOrCreate($identity, $forecast);
            $saved++;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run complete. Calculated %d forecasts.', $forecasts->count()));

            return self::SUCCESS;
        }

        $this->info(sprintf('Done. Saved %d forecasts, skipped %d existing forecasts.', $saved, $skipped));

        return self::SUCCESS;
    }
}
