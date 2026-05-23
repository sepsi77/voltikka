<?php

namespace App\Services\PriceForecasting;

use App\Models\ContractPriceDailyStatistic;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FixedTermPriceForecastService
{
    public const SEGMENTS = [
        6 => 'fixed_term_6',
        12 => 'fixed_term_12',
        24 => 'fixed_term_24',
    ];

    public const QUANTILE_COLUMNS = [
        'p20' => 'p20_value',
        'median' => 'median_value',
        'p80' => 'p80_value',
    ];

    public function __construct(private readonly FixedTermHedgeCostService $hedgeCostService) {}

    public function buildForecasts(
        CarbonInterface $asOfDate,
        ?int $horizonDays = null,
        ?array $durationsMonths = null,
        ?array $targetQuantiles = null,
    ): Collection {
        $asOf = CarbonImmutable::instance($asOfDate)->startOfDay();
        $horizon = $horizonDays ?? (int) config('price_forecasting.fixed_term.default_horizon_days', 30);
        $durations = $durationsMonths ?: config('price_forecasting.fixed_term.durations_months', [6, 12, 24]);
        $quantiles = $targetQuantiles ?: config('price_forecasting.fixed_term.target_quantiles', ['median', 'p20', 'p80']);
        $modelVersion = (string) config('price_forecasting.fixed_term.model_version', 'fixed_term_ewma_gap_v1');
        $alpha = (float) config('price_forecasting.fixed_term.ewma_alpha', 0.25);
        $lambda = (float) config('price_forecasting.fixed_term.gap_closure_lambda', 0.30);
        $minimumHistory = (int) config('price_forecasting.fixed_term.minimum_history_observations', 10);
        $directionThreshold = (float) config('price_forecasting.fixed_term.direction_threshold_cents_per_kwh', 0.15);

        $forecasts = collect();

        foreach ($durations as $durationMonths) {
            $durationMonths = (int) $durationMonths;
            $hedgeCost = $this->hedgeCostService->calculate($asOf, $durationMonths);

            if ($hedgeCost === null || $hedgeCost['price_cents_per_kwh'] === null) {
                continue;
            }

            foreach ($quantiles as $targetQuantile) {
                $targetQuantile = (string) $targetQuantile;
                $current = $this->retailStatistic($asOf, $durationMonths, $targetQuantile);

                if ($current === null || $current['price'] === null) {
                    continue;
                }

                $historyPremiums = $this->historyPremiums($asOf, $durationMonths, $targetQuantile);

                if (count($historyPremiums) < $minimumHistory) {
                    continue;
                }

                $normalPremium = $this->ewma($historyPremiums, $alpha);
                $retailPremium = $current['price'] - $hedgeCost['price_cents_per_kwh'];
                $fairPrice = $hedgeCost['price_cents_per_kwh'] + $normalPremium;
                $gap = $fairPrice - $current['price'];
                $expectedChange = $lambda * $gap;
                $forecastPrice = $current['price'] + $expectedChange;
                $direction = $this->directionLabel($expectedChange, $directionThreshold);

                $forecasts->push([
                    'forecast_date' => $asOf->toDateString(),
                    'target_date' => $asOf->addDays($horizon)->toDateString(),
                    'horizon_days' => $horizon,
                    'duration_months' => $durationMonths,
                    'target_quantile' => $targetQuantile,
                    'current_price_cents_per_kwh' => $this->roundPrice($current['price']),
                    'forecast_price_cents_per_kwh' => $this->roundPrice($forecastPrice),
                    'expected_change_cents_per_kwh' => $this->roundPrice($expectedChange),
                    'interval_low_cents_per_kwh' => null,
                    'interval_high_cents_per_kwh' => null,
                    'hedge_cost_cents_per_kwh' => $this->roundPrice($hedgeCost['price_cents_per_kwh']),
                    'retail_premium_cents_per_kwh' => $this->roundPrice($retailPremium),
                    'normal_retail_premium_cents_per_kwh' => $this->roundPrice($normalPremium),
                    'fair_price_cents_per_kwh' => $this->roundPrice($fairPrice),
                    'gap_cents_per_kwh' => $this->roundPrice($gap),
                    'futures_trade_date' => $hedgeCost['trade_date'],
                    'coverage_quality' => $hedgeCost['coverage_quality'],
                    'confidence' => $this->confidenceLabel(count($historyPremiums)),
                    'direction' => $direction,
                    'consumer_signal' => $this->consumerSignal($direction),
                    'contract_count' => $current['contract_count'],
                    'model_version' => $modelVersion,
                    'source_metadata' => [
                        'model' => 'ewma_retail_premium_gap_closure',
                        'area' => config('price_forecasting.fixed_term.area', 'FI'),
                        'ewma_alpha' => $alpha,
                        'gap_closure_lambda' => $lambda,
                        'direction_threshold_cents_per_kwh' => $directionThreshold,
                        'minimum_history_observations' => $minimumHistory,
                        'history_observations' => count($historyPremiums),
                        'vat_multiplier' => (float) config('price_forecasting.fixed_term.vat_multiplier', 1.255),
                        'monthly_futures_months' => $hedgeCost['monthly_futures_months'],
                        'quarter_futures_months' => $hedgeCost['quarter_futures_months'],
                        'year_futures_months' => $hedgeCost['year_futures_months'],
                        'missing_delivery_months' => $hedgeCost['missing_delivery_months'],
                        'delivery_start_month' => $hedgeCost['delivery_start_month'],
                        'delivery_end_month' => $hedgeCost['delivery_end_month'],
                    ],
                ]);
            }
        }

        return $forecasts;
    }

    public function retailStatistic(CarbonInterface $date, int $durationMonths, string $targetQuantile): ?array
    {
        $segment = self::SEGMENTS[$durationMonths] ?? null;
        $column = self::QUANTILE_COLUMNS[$targetQuantile] ?? null;

        if ($segment === null || $column === null) {
            return null;
        }

        $stat = ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', CarbonImmutable::instance($date)->toDateString())
            ->where('segment_key', $segment)
            ->where('metric_key', 'energy_price')
            ->whereNull('consumption_kwh')
            ->first();

        if ($stat === null || $stat->{$column} === null) {
            return null;
        }

        return [
            'price' => (float) $stat->{$column},
            'contract_count' => (int) $stat->contract_count,
        ];
    }

    public function directionLabel(float $expectedChange, ?float $threshold = null): string
    {
        $threshold ??= (float) config('price_forecasting.fixed_term.direction_threshold_cents_per_kwh', 0.15);

        if ($expectedChange >= $threshold) {
            return 'rising';
        }

        if ($expectedChange <= -$threshold) {
            return 'falling';
        }

        if ($expectedChange > 0.0) {
            return 'slightly_rising';
        }

        if ($expectedChange < 0.0) {
            return 'slightly_falling';
        }

        return 'flat';
    }

    public function directionCategory(string $direction): string
    {
        return match ($direction) {
            'rising' => 'rising',
            'falling' => 'falling',
            default => 'flat',
        };
    }

    private function historyPremiums(CarbonInterface $asOfDate, int $durationMonths, string $targetQuantile): array
    {
        $segment = self::SEGMENTS[$durationMonths] ?? null;
        $column = self::QUANTILE_COLUMNS[$targetQuantile] ?? null;

        if ($segment === null || $column === null) {
            return [];
        }

        $stats = ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', '<=', CarbonImmutable::instance($asOfDate)->toDateString())
            ->where('segment_key', $segment)
            ->where('metric_key', 'energy_price')
            ->whereNull('consumption_kwh')
            ->whereNotNull($column)
            ->orderBy('stat_date')
            ->get(['stat_date', $column]);

        $premiums = [];

        foreach ($stats as $stat) {
            $hedgeCost = $this->hedgeCostService->calculate($stat->stat_date, $durationMonths);

            if ($hedgeCost === null || $hedgeCost['price_cents_per_kwh'] === null) {
                continue;
            }

            $premiums[] = (float) $stat->{$column} - $hedgeCost['price_cents_per_kwh'];
        }

        return $premiums;
    }

    private function ewma(array $values, float $alpha): float
    {
        $current = null;

        foreach ($values as $value) {
            $current = $current === null
                ? (float) $value
                : $alpha * (float) $value + (1.0 - $alpha) * $current;
        }

        return (float) $current;
    }

    private function confidenceLabel(int $completeHistoryObservations): string
    {
        if ($completeHistoryObservations >= 365) {
            return 'high';
        }

        if ($completeHistoryObservations >= 120) {
            return 'medium';
        }

        return 'low';
    }

    private function consumerSignal(string $direction): string
    {
        return match ($direction) {
            'rising' => 'lock_sooner',
            'falling' => 'wait_if_flexible',
            default => 'neutral',
        };
    }

    private function roundPrice(float $value): float
    {
        return round($value, 4);
    }
}
