<?php

namespace App\Services\PriceForecasting;

use App\Models\ContractPriceDailyStatistic;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FixedTermPriceForecastService
{
    public const CANONICAL_PRICING_BASIS = 'canonical_calculation';

    public const OBSERVED_PRICING_BASIS = 'observed_seller_data';

    private const RETAIL_METRIC = 'energy_price';

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
        $modelVersion = (string) config('price_forecasting.fixed_term.model_version', 'fixed_term_ewma_gap_v2');
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

                $history = $this->historyPremiumEvidence($asOf, $durationMonths, $targetQuantile);
                $historyPremiums = $history['premiums'];

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
                        'current_retail_pricing_basis' => $current['pricing_basis'],
                        'current_retail_source_date' => $current['source_date'],
                        'current_retail_segment' => $current['segment'],
                        'current_retail_metric' => $current['metric'],
                        'current_retail_contract_count' => $current['contract_count'],
                        'historical_retail_observations' => count($historyPremiums),
                        'historical_retail_pricing_basis_counts' => $history['pricing_basis_counts'],
                        'historical_retail_source_start_date' => $history['source_start_date'],
                        'historical_retail_source_end_date' => $history['source_end_date'],
                        'historical_retail_segment' => $current['segment'],
                        'historical_retail_metric' => self::RETAIL_METRIC,
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

    public function retailStatistic(
        CarbonInterface $date,
        int $durationMonths,
        string $targetQuantile,
        ?string $pricingBasis = null,
    ): ?array {
        $segment = self::SEGMENTS[$durationMonths] ?? null;
        $column = self::QUANTILE_COLUMNS[$targetQuantile] ?? null;

        if ($segment === null || $column === null) {
            return null;
        }

        $sourceDate = CarbonImmutable::instance($date)->toDateString();
        $pricingBasis ??= $this->currentRetailPricingBasis();
        $stat = ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', $sourceDate)
            ->where('segment_key', $segment)
            ->where('metric_key', self::RETAIL_METRIC)
            ->where('pricing_basis', $pricingBasis)
            ->whereNull('consumption_kwh')
            ->orderByDesc('id')
            ->first();

        if ($stat === null || $stat->{$column} === null) {
            return null;
        }

        return [
            'price' => (float) $stat->{$column},
            'contract_count' => (int) $stat->contract_count,
            'pricing_basis' => (string) $stat->pricing_basis,
            'source_date' => $stat->stat_date->toDateString(),
            'segment' => (string) $stat->segment_key,
            'metric' => (string) $stat->metric_key,
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

    /**
     * Historical model evidence stays date-scoped observed seller data. The current
     * canonical input is selected separately by retailStatistic().
     *
     * @return array{premiums:array<int,float>,pricing_basis_counts:array<string,int>,source_start_date:?string,source_end_date:?string}
     */
    private function historyPremiumEvidence(CarbonInterface $asOfDate, int $durationMonths, string $targetQuantile): array
    {
        $segment = self::SEGMENTS[$durationMonths] ?? null;
        $column = self::QUANTILE_COLUMNS[$targetQuantile] ?? null;

        if ($segment === null || $column === null) {
            return [
                'premiums' => [],
                'pricing_basis_counts' => [],
                'source_start_date' => null,
                'source_end_date' => null,
            ];
        }

        $stats = ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', '<', CarbonImmutable::instance($asOfDate)->toDateString())
            ->where('segment_key', $segment)
            ->where('metric_key', self::RETAIL_METRIC)
            ->where('pricing_basis', self::OBSERVED_PRICING_BASIS)
            ->whereNull('consumption_kwh')
            ->whereNotNull($column)
            ->orderBy('stat_date')
            ->orderByDesc('id')
            ->get(['id', 'stat_date', 'pricing_basis', $column])
            ->unique(fn (ContractPriceDailyStatistic $stat) => $stat->stat_date->toDateString().'|'.$stat->pricing_basis)
            ->sortBy('stat_date')
            ->values();

        $premiums = [];
        $basisCounts = [];
        $sourceDates = [];

        foreach ($stats as $stat) {
            $hedgeCost = $this->hedgeCostService->calculate($stat->stat_date, $durationMonths);

            if ($hedgeCost === null || $hedgeCost['price_cents_per_kwh'] === null) {
                continue;
            }

            $basis = (string) $stat->pricing_basis;
            $premiums[] = (float) $stat->{$column} - $hedgeCost['price_cents_per_kwh'];
            $basisCounts[$basis] = ($basisCounts[$basis] ?? 0) + 1;
            $sourceDates[] = $stat->stat_date->toDateString();
        }

        ksort($basisCounts);

        return [
            'premiums' => $premiums,
            'pricing_basis_counts' => $basisCounts,
            'source_start_date' => $sourceDates[0] ?? null,
            'source_end_date' => $sourceDates === [] ? null : $sourceDates[array_key_last($sourceDates)],
        ];
    }

    private function currentRetailPricingBasis(): string
    {
        return (bool) config('canonical_pricing.enabled', false)
            ? self::CANONICAL_PRICING_BASIS
            : self::OBSERVED_PRICING_BASIS;
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
