<?php

namespace App\Services\PriceForecasting;

use App\Models\ContractPriceDailyStatistic;
use App\Services\CanonicalPricing\PricingMode;
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

    public function __construct(
        private readonly PricingMode $pricingMode,
    ) {}

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
        $modelVersion = $this->generationModelVersion();
        $minimumHistory = max(20, (int) config('price_forecasting.fixed_term.minimum_history_observations', 20));
        if ($horizon < 1) {
            throw new \InvalidArgumentException('Forecast horizon must be positive.');
        }
        $directionThreshold = (float) config('price_forecasting.fixed_term.direction_threshold_cents_per_kwh', 0.15);

        $forecasts = collect();
        $hedge = new FixedTermHedgeCostService;
        $curves = $hedge->loadCurvesBefore($asOf);
        $features = [];

        foreach ($durations as $durationMonths) {
            $durationMonths = (int) $durationMonths;
            foreach ($quantiles as $targetQuantile) {
                $targetQuantile = (string) $targetQuantile;
                $current = $this->retailStatistic($asOf, $durationMonths, $targetQuantile);

                if ($current === null || $current['price'] === null) {
                    continue;
                }

                $history = $this->completedChangeEvidence($asOf, $durationMonths, $targetQuantile, $current['pricing_basis'], $horizon);
                $changes = $history['changes'];

                if (count($changes) < $minimumHistory) {
                    continue;
                }

                $meanChange = array_sum($changes) / count($changes);
                $featureKey = $durationMonths.'|'.$asOf->toDateString();
                $features[$featureKey] ??= $this->futuresFeature($hedge, $curves, $asOf, $durationMonths);
                $currentFeature = $features[$featureKey];
                if ($currentFeature['value'] === null) {
                    continue;
                }
                $training = [];
                foreach ($history['pairs'] as $pair) {
                    $key = $durationMonths.'|'.$pair['start'];
                    $features[$key] ??= $this->futuresFeature($hedge, $curves, CarbonImmutable::parse($pair['start']), $durationMonths);
                    if ($features[$key]['value'] !== null) {
                        $training[] = $pair + ['feature' => $features[$key]['value']];
                    }
                }
                if (count($training) < $minimumHistory) {
                    continue;
                }
                $n = count($training);
                $featureMean = array_sum(array_column($training, 'feature')) / $n;
                $deltaMean = array_sum(array_column($training, 'change')) / $n;
                $featureStd = sqrt(array_sum(array_map(fn ($pair) => ($pair['feature'] - $featureMean) ** 2, $training)) / $n);
                $covariance = $variance = 0.0;
                foreach ($training as $pair) {
                    $z = $featureStd > 0 ? ($pair['feature'] - $featureMean) / $featureStd : 0.0;
                    $covariance += $z * ($pair['change'] - $deltaMean) / $n;
                    $variance += $z ** 2 / $n;
                }
                $slope = $covariance / ($variance + 1.0);
                $contribution = $featureStd > 0 ? $slope * (($currentFeature['value'] - $featureMean) / $featureStd) : 0.0;
                $expectedChange = $this->roundPrice($meanChange + $contribution);
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
                    'hedge_cost_cents_per_kwh' => null,
                    'retail_premium_cents_per_kwh' => null,
                    'normal_retail_premium_cents_per_kwh' => null,
                    'fair_price_cents_per_kwh' => null,
                    'gap_cents_per_kwh' => null,
                    'futures_trade_date' => null,
                    'coverage_quality' => null,
                    'confidence' => $this->confidenceLabel($history['pricing_basis_counts'][$current['pricing_basis']] ?? 0),
                    'direction' => $direction,
                    'consumer_signal' => $this->consumerSignal($direction),
                    'contract_count' => $current['contract_count'],
                    'model_version' => $modelVersion,
                    'source_metadata' => [
                        'model' => 'fixed_term_futures_adjusted_v1',
                        'futures_policy' => 'fi_base_fixed_next_month_basket_7_calendar_days_ridge1_v1',
                        'futures_lag_days' => 7,
                        'ridge' => 1.0,
                        'futures_vat_multiplier' => 1.255,
                        'current_futures_feature' => $currentFeature,
                        'feature_pair_count' => $n,
                        'feature_unique_issue_days' => $n,
                        'feature_pair_start_min' => $training[0]['start'],
                        'feature_pair_start_max' => $training[$n - 1]['start'],
                        'feature_pair_target_min' => $training[0]['target'],
                        'feature_pair_target_max' => $training[$n - 1]['target'],
                        'feature_pricing_basis_counts' => array_count_values(array_column($training, 'basis')),
                        'feature_mean' => $featureMean,
                        'feature_population_std' => $featureStd,
                        'feature_delta_mean' => $deltaMean,
                        'feature_standardized_slope' => $slope,
                        'futures_contribution_cents_per_kwh' => $contribution,
                        'fitting_policy' => 'expanding_equal_weight_completed_same_basis_pairs_v1',
                        'pair_count' => count($changes),
                        'unique_issue_days' => count($changes),
                        'mean_change_cents_per_kwh' => $meanChange,
                        'pair_start_min' => $history['source_start_date'],
                        'pair_start_max' => $history['source_end_date'],
                        'pair_target_min' => $history['target_start_date'],
                        'pair_target_max' => $history['target_end_date'],
                        'direction_threshold_cents_per_kwh' => $directionThreshold,
                        'minimum_history_observations' => $minimumHistory,
                        'history_observations' => count($changes),
                        'confidence_history_observations' => $history['pricing_basis_counts'][$current['pricing_basis']] ?? 0,
                        'historical_retail_continuity_policy' => 'observed_prefix_canonical_continuation_v1',
                        'historical_retail_transition_date' => $history['transition_date'],
                        'current_retail_pricing_basis' => $current['pricing_basis'],
                        'current_retail_method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
                        'historical_retail_method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
                        'current_retail_source_date' => $current['source_date'],
                        'current_retail_segment' => $current['segment'],
                        'current_retail_metric' => $current['metric'],
                        'current_retail_contract_count' => $current['contract_count'],
                        'historical_retail_observations' => count($changes),
                        'historical_retail_pricing_basis_counts' => $history['pricing_basis_counts'],
                        'historical_retail_source_start_date' => $history['source_start_date'],
                        'historical_retail_source_end_date' => $history['source_end_date'],
                        'historical_retail_segment' => $current['segment'],
                        'historical_retail_metric' => self::RETAIL_METRIC,
                    ],
                ]);
            }
        }

        return $forecasts;
    }

    public function generationModelVersion(): string
    {
        $modelVersion = (string) config('price_forecasting.fixed_term.model_version', 'fixed_term_futures_adjusted_v1');
        if ($modelVersion !== 'fixed_term_futures_adjusted_v1') {
            throw new \InvalidArgumentException('Generation requires fixed_term_futures_adjusted_v1. Other model names are reserved for stored forecasts.');
        }

        return $modelVersion;
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
            ->unitStatistics()
            ->whereDate('stat_date', $sourceDate)
            ->where('segment_key', $segment)
            ->where('metric_key', self::RETAIL_METRIC)
            ->where('pricing_basis', $pricingBasis)
            ->whereNull('consumption_kwh')
            ->orderByDesc('id')
            ->first();

        if ($stat === null || $stat->{$column} === null || ! is_finite((float) $stat->{$column})) {
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

    public function directionCategory(?string $direction): ?string
    {
        return ForecastOutlook::category($direction);
    }

    /**
     * Every exact-date pair must be complete before issue and stay within one basis.
     */
    private function completedChangeEvidence(CarbonInterface $asOfDate, int $durationMonths, string $targetQuantile, string $pricingBasis, int $horizon): array
    {
        $segment = self::SEGMENTS[$durationMonths] ?? null;
        $column = self::QUANTILE_COLUMNS[$targetQuantile] ?? null;

        if ($segment === null || $column === null) {
            return [
                'changes' => [],
                'target_start_date' => null,
                'target_end_date' => null,
                'pricing_basis_counts' => [],
                'source_start_date' => null,
                'source_end_date' => null,
                'transition_date' => null,
            ];
        }

        $sourceDate = CarbonImmutable::instance($asOfDate)->toDateString();
        $query = ContractPriceDailyStatistic::query()
            ->unitStatistics()
            ->where('segment_key', $segment)
            ->where('metric_key', self::RETAIL_METRIC)
            ->whereNull('consumption_kwh');
        // Presence owns the transition, even when that day's latest value is invalid.
        $transitionDate = $pricingBasis === self::CANONICAL_PRICING_BASIS
            ? (clone $query)->where('pricing_basis', self::CANONICAL_PRICING_BASIS)
                ->whereDate('stat_date', '<=', $sourceDate)->min('stat_date')
            : null;
        $transitionDate = $transitionDate === null ? null : CarbonImmutable::parse($transitionDate)->toDateString();

        $stats = $query
            ->whereDate('stat_date', '<', $sourceDate)
            ->where(function ($query) use ($pricingBasis, $transitionDate) {
                $query->where('pricing_basis', $pricingBasis);
                if ($pricingBasis === self::CANONICAL_PRICING_BASIS && $transitionDate !== null) {
                    $query->orWhere(fn ($query) => $query
                        ->where('pricing_basis', self::OBSERVED_PRICING_BASIS)
                        ->whereDate('stat_date', '<', $transitionDate));
                }
            })
            ->orderBy('stat_date')
            ->orderByDesc('id')
            ->get(['id', 'stat_date', 'pricing_basis', $column])
            ->unique(fn (ContractPriceDailyStatistic $stat) => $stat->stat_date->toDateString())
            ->sortBy('stat_date')
            ->values();

        $changes = [];
        $pairs = [];
        $basisCounts = [];
        $sourceDates = [];
        $targetDates = [];
        $byDate = $stats->keyBy(fn ($stat) => $stat->stat_date->toDateString());

        foreach ($stats as $stat) {
            if ($stat->{$column} === null || ! is_finite((float) $stat->{$column})) {
                continue;
            }

            $targetDate = $stat->stat_date->toImmutable()->addDays($horizon)->toDateString();
            $target = $byDate->get($targetDate);
            if ($target === null || $target->pricing_basis !== $stat->pricing_basis
                || $target->{$column} === null || ! is_finite((float) $target->{$column})) {
                continue;
            }

            $basis = (string) $stat->pricing_basis;
            $changes[] = (float) $target->{$column} - (float) $stat->{$column};
            $pairs[] = ['start' => $stat->stat_date->toDateString(), 'target' => $targetDate, 'basis' => $basis, 'change' => (float) $target->{$column} - (float) $stat->{$column}];
            $targetDates[] = $targetDate;
            $basisCounts[$basis] = ($basisCounts[$basis] ?? 0) + 1;
            $sourceDates[] = $stat->stat_date->toDateString();
        }

        ksort($basisCounts);

        return [
            'changes' => $changes,
            'pairs' => $pairs,
            'target_start_date' => $targetDates[0] ?? null,
            'target_end_date' => $targetDates === [] ? null : $targetDates[array_key_last($targetDates)],
            'pricing_basis_counts' => $basisCounts,
            'source_start_date' => $sourceDates[0] ?? null,
            'source_end_date' => $sourceDates === [] ? null : $sourceDates[array_key_last($sourceDates)],
            'transition_date' => $transitionDate,
        ];
    }

    private function futuresFeature(FixedTermHedgeCostService $hedge, array $curves, CarbonImmutable $issue, int $duration): array
    {
        $start = $issue->startOfMonth()->addMonth();
        $current = $hedge->calculate($issue, $duration, $start, $curves);
        $lag = $hedge->calculate($issue->subDays(7), $duration, $start, $curves);
        $currentPrice = $current['price_cents_per_kwh'] ?? null;
        $lagPrice = $lag['price_cents_per_kwh'] ?? null;

        return [
            'value' => $currentPrice !== null && $lagPrice !== null ? $currentPrice - $lagPrice : null,
            'issue_date' => $issue->toDateString(),
            'lag_date' => $issue->subDays(7)->toDateString(),
            'current_basket' => $current,
            'lag_basket' => $lag,
        ];
    }

    private function currentRetailPricingBasis(): string
    {
        return $this->pricingMode->expectedContractPriceBasis()->value;
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
