<?php

namespace App\Console\Commands;

use App\Jobs\WarmContractPriceStatisticsCache;
use App\Models\ActiveContract;
use App\Models\FixedContractPriceForecast;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use App\Services\MorningFreshness\MorningConsumerExecution;
use App\Services\MorningFreshness\MorningFreshnessResult;
use App\Services\MorningFreshness\MorningJobFreshnessService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunFixedContractPriceForecasts extends Command
{
    protected $signature = 'forecasting:run-fixed-contracts
        {--as-of= : Forecast date, defaults to today in Europe/Helsinki.}
        {--horizon= : Forecast horizon in days, defaults to config value.}
        {--duration=* : Duration months to forecast, e.g. 6, 12, 24. Defaults to config values.}
        {--quantile=* : Target quantile to forecast: median, p20, p80. Defaults to config values.}
        {--overwrite : Replace an unevaluated forecast for the same date/horizon/duration/quantile/model version.}
        {--dry-run : Calculate and print forecasts without writing to the database.}
        {--require-freshness : Require current morning import checkpoints before forecasting.}
        {--scheduled : Run the bounded current-day default morning scope.}';

    protected $description = 'Calculate and persist fixed-term contract price forecasts';

    private ?\Closure $writerFence = null;

    public function handle(
        FixedTermPriceForecastService $forecastService,
        MorningJobFreshnessService $freshness,
        ContractPriceStatisticsService $statistics,
        MorningConsumerExecution $execution,
    ): int {
        $this->writerFence = null;
        $scheduled = (bool) $this->option('scheduled');
        if ($scheduled) {
            foreach (['as-of', 'horizon', 'duration', 'quantile', 'overwrite', 'dry-run'] as $option) {
                if ($this->option($option) !== null && $this->option($option) !== false && $this->option($option) !== []) {
                    $this->error('Scheduled mode accepts only the current-day default scope.');

                    return self::INVALID;
                }
            }
            $this->input->setOption('require-freshness', true);
        }
        if (! $scheduled) {
            try {
                $forecastService->generationModelVersion();
            } catch (\InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }
        try {
            $asOf = CarbonImmutable::parse($this->option('as-of') ?: 'today', 'Europe/Helsinki')->startOfDay();
            $horizon = $this->option('horizon') !== null
                ? filter_var($this->option('horizon'), FILTER_VALIDATE_INT)
                : (int) config('price_forecasting.fixed_term.default_horizon_days', 30);
            if (! $scheduled && ($horizon === false || $horizon < 1)) {
                throw new \InvalidArgumentException('Forecast horizon must be a positive integer.');
            }
            $durations = $this->option('duration') ?: null;
            $quantiles = $this->option('quantile') ?: null;
            foreach ($durations ?? [] as $duration) {
                if (filter_var($duration, FILTER_VALIDATE_INT) === false || (int) $duration < 1) {
                    throw new \InvalidArgumentException('Forecast durations must be positive integers.');
                }
            }
            foreach ($quantiles ?? [] as $quantile) {
                if (! array_key_exists($quantile, FixedTermPriceForecastService::QUANTILE_COLUMNS)) {
                    throw new \InvalidArgumentException('Forecast quantiles must be median, p20, or p80.');
                }
            }
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        if ($this->option('dry-run')) {
            return $this->executeForecasts($forecastService, $freshness, $statistics, $asOf, $horizon, $durations, $quantiles);
        }

        $preflight = null;

        return $execution->run('forecast', $asOf, $scheduled, function () use ($scheduled, $freshness, $asOf, &$preflight) {
            if (! $this->option('require-freshness')) {
                return true;
            }
            $preflight = $freshness->checkFixedTermForecast($asOf);
            $ready = $preflight->ready() || array_keys($preflight->failures) === ['statistics_publication_order'];
            if (! $ready && ! $scheduled) {
                $this->defer($freshness, $asOf, $preflight);
            }

            return $ready;
        }, function (\Closure $fence) use ($forecastService, $freshness, $statistics, $asOf, $horizon, $durations, $quantiles, &$preflight): int {
            $this->writerFence = $fence;

            return $this->executeForecasts($forecastService, $freshness, $statistics, $asOf, $horizon, $durations, $quantiles, $preflight);
        });
    }

    private function executeForecasts(
        FixedTermPriceForecastService $forecastService,
        MorningJobFreshnessService $freshness,
        ContractPriceStatisticsService $statistics,
        CarbonImmutable $asOf,
        int $horizon,
        ?array $durations,
        ?array $quantiles,
        ?MorningFreshnessResult $initialFreshness = null,
    ): int {
        // Reject historical model pins before freshness recovery can write statistics.
        try {
            $forecastService->generationModelVersion();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('scheduled') && FixedContractPriceForecast::query()->whereDate('forecast_date', $asOf)->exists()) {
            $this->error('Existing forecasts require inspection before scheduled completion.');

            return self::FAILURE;
        }

        if ((bool) $this->option('require-freshness')) {
            $result = $initialFreshness ?? $freshness->checkFixedTermForecast($asOf);

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
                $statistics->calculateForDate($asOf, $activeContractIds, overwrite: true, transactionFence: $this->writerFence);

                $result = $freshness->checkFixedTermForecast($asOf, $statisticsStartedAt);

                if (! $result->ready()) {
                    return $this->defer($freshness, $asOf, $result);
                }

                WarmContractPriceStatisticsCache::dispatch('weekly', 5000);
            }
        }

        $forecasts = $forecastService->buildForecasts($asOf, $horizon, $durations, $quantiles);

        if ($forecasts->isEmpty()) {
            $this->warn('No forecasts were produced. Check current retail statistics, complete current/lag futures baskets, and at least 20 full and futures-feature history pairs.');

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
                $metadata = $forecast['source_metadata'];
                $this->line(sprintf('Pairs %d (minimum %d), mean %+.8f; starts %s–%s; targets %s–%s; current-basis pairs %d.',
                    $metadata['pair_count'], $metadata['minimum_history_observations'],
                    $metadata['mean_change_cents_per_kwh'], $metadata['pair_start_min'], $metadata['pair_start_max'],
                    $metadata['pair_target_min'], $metadata['pair_target_max'], $metadata['confidence_history_observations'],
                ));
                $this->line(sprintf('Futures pairs %d; feature mean %.8f, population std %.8f, ridge-1 slope %+.8f, contribution %+.8f; current/lag trades %s / %s.',
                    $metadata['feature_pair_count'], $metadata['feature_mean'], $metadata['feature_population_std'],
                    $metadata['feature_standardized_slope'], $metadata['futures_contribution_cents_per_kwh'],
                    $metadata['current_futures_feature']['current_basket']['trade_date'],
                    $metadata['current_futures_feature']['lag_basket']['trade_date'],
                ));

                continue;
            }

            $identity = [
                'horizon_days' => $forecast['horizon_days'],
                'duration_months' => $forecast['duration_months'],
                'target_quantile' => $forecast['target_quantile'],
                'model_version' => $forecast['model_version'],
            ];

            $written = DB::transaction(function () use ($identity, $forecast): bool {
                ($this->writerFence)();
                $existing = FixedContractPriceForecast::query()->where($identity)
                    ->whereDate('forecast_date', $forecast['forecast_date'])->first();

                if ($existing !== null && $this->option('scheduled')) {
                    throw new \RuntimeException('Unexpected existing forecast requires inspection.');
                }
                if ($existing !== null && (! $this->option('overwrite') || $existing->actual_price_cents_per_kwh !== null)) {
                    return false;
                }

                if ($existing !== null) {
                    $existing->update($forecast);
                } else {
                    FixedContractPriceForecast::create($forecast);
                }
                if ($this->option('scheduled') && ! FixedContractPriceForecast::query()->where($identity)
                    ->whereDate('forecast_date', $forecast['forecast_date'])->exists()) {
                    throw new \RuntimeException('Forecast output was not saved.');
                }

                return true;
            });
            $saved += $written ? 1 : 0;
            $skipped += $written ? 0 : 1;
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

        if (! $this->option('scheduled')) {
            $freshness->reportDeferred('forecasting:run-fixed-contracts', $asOf, $result);
        }

        return self::FAILURE;
    }
}
