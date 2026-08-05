<?php

namespace App\Console\Commands;

use App\Jobs\WarmContractPriceStatisticsCache;
use App\Models\ActiveContract;
use App\Models\FixedContractPriceForecast;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use App\Services\MorningFreshness\MorningFreshnessResult;
use App\Services\MorningFreshness\MorningJobFreshnessService;
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
        {--dry-run : Calculate and print forecasts without writing to the database.}
        {--require-freshness : Require current morning import checkpoints before forecasting.}';

    protected $description = 'Calculate and persist fixed-term contract price forecasts';

    public function handle(
        FixedTermPriceForecastService $forecastService,
        MorningJobFreshnessService $freshness,
        ContractPriceStatisticsService $statistics,
    ): int {
        $asOf = $this->option('as-of')
            ? CarbonImmutable::parse($this->option('as-of'), 'Europe/Helsinki')->startOfDay()
            : CarbonImmutable::now('Europe/Helsinki')->startOfDay();

        if ((bool) $this->option('require-freshness')) {
            $result = $freshness->checkFixedTermForecast($asOf);

            if (! $result->ready()) {
                $canRefreshStatistics = ! (bool) $this->option('dry-run')
                    && array_keys($result->failures) === ['statistics_publication_order'];

                if (! $canRefreshStatistics) {
                    return $this->defer($freshness, $asOf, $result);
                }

                $activeContractIds = ActiveContract::query()->orderBy('id')->pluck('id')->all();

                if ($activeContractIds === []) {
                    return $this->defer(
                        $freshness,
                        $asOf,
                        new MorningFreshnessResult([
                            'statistics_refresh' => 'No active contracts are available for the statistics refresh.',
                        ]),
                    );
                }

                $statisticsStartedAt = CarbonImmutable::now('Europe/Helsinki');
                $statistics->calculateForDate($asOf, $activeContractIds, overwrite: true);

                $result = $freshness->checkFixedTermForecast($asOf, $statisticsStartedAt);

                if (! $result->ready()) {
                    return $this->defer($freshness, $asOf, $result);
                }

                WarmContractPriceStatisticsCache::dispatch('weekly', 5000);
            }
        }

        $horizon = $this->option('horizon') !== null
            ? (int) $this->option('horizon')
            : (int) config('price_forecasting.fixed_term.default_horizon_days', 30);

        $durations = $this->option('duration') ?: null;
        $quantiles = $this->option('quantile') ?: null;

        $forecasts = $forecastService->buildForecasts($asOf, $horizon, $durations, $quantiles);

        if ($forecasts->isEmpty()) {
            $this->warn('No forecasts were produced. Check retail statistics, futures coverage, and minimum history settings.');

            if ((bool) $this->option('require-freshness')) {
                return $this->defer(
                    $freshness,
                    $asOf,
                    new MorningFreshnessResult([
                        'forecast_output' => 'No current fixed-term forecasts were produced.',
                    ]),
                );
            }

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

    private function defer(
        MorningJobFreshnessService $freshness,
        CarbonImmutable $asOf,
        MorningFreshnessResult $result,
    ): int {
        foreach ($result->messages() as $message) {
            $this->error("Morning job deferred: {$message}");
        }

        $freshness->reportDeferred('forecasting:run-fixed-contracts', $asOf, $result);

        return self::FAILURE;
    }
}
