<?php

namespace App\Services\ContractMarketInsights;

use App\Models\ContractPriceDailyStatistic;
use App\Models\FixedContractPriceForecast;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractStatistics\AnnualSeriesCompatibility;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\SellerSetEnergyPriceIndexService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ContractMarketInsightService
{
    private const FIXED_TERM_COMPARISON_PAYLOAD_SCHEMA = 'fixed-term-offered-price-comparison-v2';

    private const FIXED_TERM_ARTICLE_PAYLOAD_SCHEMA = 'fixed-term-decision-article-v7';

    private const FIXED_TERM_COMPARISON_SEGMENTS = [
        6 => 'fixed_term_6',
        12 => 'fixed_term_12',
        24 => 'fixed_term_24',
    ];

    private const FIXED_TERM_ARTICLE_CURRENT_SEGMENTS = [
        'open_ended',
        'fixed_term_6',
        'fixed_term_12',
        'fixed_term_24',
    ];

    private const ANNUAL_COMPARISON_CORE_SEGMENTS = [
        'fixed_term_12',
        'spot',
        'open_ended',
    ];

    private const ANNUAL_COMPARISON_OPTIONAL_SEGMENTS = [
        'market_reset',
        'quarterly',
        'fixed_term_6',
        'fixed_term_24',
        'hybrid',
    ];

    private const ANNUAL_COMPARISON_LABELS = [
        'fixed_term_12' => 'Kiinteä hinta, 12 kk',
        'spot' => 'Pörssisähkö',
        'open_ended' => 'Toistaiseksi voimassa oleva',
        'market_reset' => 'Jaksoittain vaihtuva hinta',
        'quarterly' => 'Kvartaalisähkö',
        'fixed_term_6' => 'Kiinteä hinta, 6 kk',
        'fixed_term_24' => 'Kiinteä hinta, 24 kk',
        'hybrid' => 'Kulutusvaikutus',
    ];

    private const ANNUAL_COMPARISON_CAVEATS = [
        'fixed_term_12' => 'clean_benchmark',
        'spot' => 'spot_forward_estimate',
        'open_ended' => 'seller_can_change_price',
        'market_reset' => 'future_periods_estimated',
        'quarterly' => 'future_periods_estimated',
        'fixed_term_6' => 'annualized_equivalent',
        'fixed_term_24' => 'next_twelve_months_only',
        'hybrid' => 'consumption_effect_ignored',
    ];

    public function __construct(private readonly PricingMode $pricingMode) {}

    public function fingerprint(): string
    {
        $pricingBasis = $this->pricingMode->expectedContractPriceBasis()->value;
        $canonicalEnabled = $this->pricingMode->enabled();

        return Cache::remember(
            'contract-market-insight:fingerprint:v7:'.ContractPriceDailyStatistic::activeAnnualMethodVersion()->value.':'.($canonicalEnabled ? 'c1' : 'c0').':'.$pricingBasis,
            now()->addMinutes(10),
            function () use ($pricingBasis, $canonicalEnabled) {
                $expected = ContractPriceDailyStatistic::query()
                    ->activeAnnualMethod()
                    ->where('pricing_basis', $pricingBasis);
                $observed = ContractPriceDailyStatistic::query()
                    ->activeAnnualMethod()
                    ->where('pricing_basis', ContractPriceBasis::ObservedSellerData->value);
                $mixedAnnual = ContractPriceDailyStatistic::query()
                    ->activeAnnualMethod()
                    ->where('pricing_basis', 'mixed_evidence');
                $expectedUnit = ContractPriceDailyStatistic::query()
                    ->unitStatistics()
                    ->where('metric_key', 'energy_price')
                    ->whereNull('consumption_kwh')
                    ->where('pricing_basis', $pricingBasis);
                $observedUnit = ContractPriceDailyStatistic::query()
                    ->unitStatistics()
                    ->where('metric_key', 'energy_price')
                    ->whereNull('consumption_kwh')
                    ->where('pricing_basis', ContractPriceBasis::ObservedSellerData->value);
                $sellerSetIndex = ContractPriceDailyStatistic::query()
                    ->unitStatistics()
                    ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
                    ->where('segment_key', SellerSetEnergyPriceIndexService::SEGMENT_OVERALL)
                    ->where('pricing_basis', ContractPriceBasis::CanonicalCalculation->value)
                    ->where('compatibility_key', SellerSetEnergyPriceIndexService::COMPATIBILITY_KEY);

                return md5(json_encode([
                    'canonical_enabled' => $canonicalEnabled,
                    'pricing_basis' => $pricingBasis,
                    'statistics_latest_date' => $expected->max('stat_date'),
                    'statistics_latest_updated' => $expected->max('updated_at'),
                    'observed_latest_date' => $observed->max('stat_date'),
                    'observed_latest_updated' => $observed->max('updated_at'),
                    'mixed_annual_latest_date' => $mixedAnnual->max('stat_date'),
                    'mixed_annual_latest_updated' => $mixedAnnual->max('updated_at'),
                    'unit_statistics_latest_date' => $expectedUnit->max('stat_date'),
                    'unit_statistics_latest_updated' => $expectedUnit->max('updated_at'),
                    'observed_unit_latest_date' => $observedUnit->max('stat_date'),
                    'observed_unit_latest_updated' => $observedUnit->max('updated_at'),
                    'seller_set_index_latest_date' => $sellerSetIndex->max('stat_date'),
                    'seller_set_index_latest_updated' => $sellerSetIndex->max('updated_at'),
                    'forecast_latest_date' => FixedContractPriceForecast::max('forecast_date'),
                    'forecast_latest_updated' => FixedContractPriceForecast::max('updated_at'),
                ]));
            },
        );
    }

    /**
     * @return array{trend:?array<string,mixed>,forecast:?array<string,mixed>,has_items:bool}
     */
    public function insight(
        ?string $segmentKey,
        int $consumption,
        bool $includeForecast = false,
        ?int $fixedTermDuration = null,
    ): array {
        $consumption = $this->statisticsConsumptionLevel($consumption);
        $segmentKey = $segmentKey ?: 'aggregate';
        $fixedTermDuration = in_array($fixedTermDuration, [6, 12, 24], true)
            ? $fixedTermDuration
            : null;
        $forecastDuration = $includeForecast ? ($fixedTermDuration ?? 12) : null;

        return Cache::remember(
            'contract-market-insight:v13:'.md5(json_encode([
                $segmentKey,
                $consumption,
                $includeForecast,
                $fixedTermDuration,
                $this->fingerprint(),
                $this->pricingMode->enabled(),
                (string) config('price_forecasting.fixed_term.model_version', 'fixed_term_ewma_gap_v2'),
            ])),
            Carbon::tomorrow(),
            function () use ($segmentKey, $consumption, $forecastDuration, $fixedTermDuration) {
                $trend = $fixedTermDuration !== null
                    ? $this->fixedTermDurationTrend($fixedTermDuration)
                    : ($segmentKey === 'aggregate'
                        ? $this->sellerSetEnergyPriceTrend()
                        : $this->segmentTrend($segmentKey, $consumption));

                $forecast = $forecastDuration !== null
                    ? $this->fixedTermForecast($forecastDuration)
                    : null;

                return [
                    'trend' => $trend,
                    'forecast' => $forecast,
                    'has_items' => $trend !== null || $forecast !== null,
                ];
            }
        );
    }

    /**
     * Prepare all aggregate evidence used by the fixed-term decision article.
     *
     * @return array<string,mixed>
     */
    public function fixedTermArticle(): array
    {
        $pricingBasis = $this->pricingMode->expectedContractPriceBasis();

        return Cache::remember(
            'contract-market-insight:fixed-term-article:'.md5(json_encode([
                'payload_schema' => self::FIXED_TERM_ARTICLE_PAYLOAD_SCHEMA,
                'fingerprint' => $this->fingerprint(),
                'pricing_basis' => $pricingBasis->value,
                'forecast_model' => (string) config('price_forecasting.fixed_term.model_version', 'fixed_term_ewma_gap_v2'),
            ])),
            Carbon::tomorrow(),
            function () use ($pricingBasis): array {
                $current = $this->fixedTermArticleCurrentComparison();
                $annualComparison = $this->annualComparison();
                $history = $this->fixedTermHistory();
                $forecast = $this->fixedTermArticleForecast($pricingBasis);
                $dates = array_values(array_filter([
                    $current['date'] ?? null,
                    $annualComparison['date'] ?? null,
                    $history['end_date'] ?? null,
                    $forecast['date'] ?? null,
                ]));

                return [
                    'current' => $current,
                    'annual_comparison' => $annualComparison,
                    'history' => $history,
                    'forecast' => $forecast,
                    'data_date' => $dates === [] ? null : max($dates),
                    'pricing_basis' => $pricingBasis->value,
                ];
            },
        );
    }

    /**
     * @return array{
     *     date:?string,
     *     basis:string,
     *     scale_min:?float,
     *     scale_max:?float,
     *     rows:list<array{
     *         duration_months:int,
     *         p20:float,
     *         median:float,
     *         p80:float,
     *         contract_count:int,
     *         p20_percent:float,
     *         median_percent:float,
     *         p80_percent:float
     *     }>
     * }|array{}
     */
    public function fixedTermComparison(): array
    {
        $pricingBasis = $this->pricingMode->expectedContractPriceBasis()->value;

        return Cache::remember(
            'contract-market-insight:fixed-term-comparison:'.md5(json_encode([
                'payload_schema' => self::FIXED_TERM_COMPARISON_PAYLOAD_SCHEMA,
                'fingerprint' => $this->fingerprint(),
                'pricing_basis' => $pricingBasis,
            ])),
            Carbon::tomorrow(),
            function () use ($pricingBasis): array {
                $baseQuery = fn () => ContractPriceDailyStatistic::query()
                    ->unitStatistics()
                    ->where('metric_key', 'energy_price')
                    ->whereNull('consumption_kwh')
                    ->where('pricing_basis', $pricingBasis)
                    ->whereIn('segment_key', array_values(self::FIXED_TERM_COMPARISON_SEGMENTS))
                    ->whereNotNull('p20_value')
                    ->whereNotNull('median_value')
                    ->whereNotNull('p80_value')
                    ->where('contract_count', '>=', 10);

                $candidateDates = $baseQuery()
                    ->select('stat_date')
                    ->groupBy('stat_date')
                    ->havingRaw('COUNT(DISTINCT segment_key) = ?', [count(self::FIXED_TERM_COMPARISON_SEGMENTS)])
                    ->orderByDesc('stat_date')
                    ->pluck('stat_date');

                $commonDate = null;
                $rows = [];
                foreach ($candidateDates as $candidateDate) {
                    $statistics = $baseQuery()
                        ->whereDate('stat_date', Carbon::parse($candidateDate)->toDateString())
                        ->get(['stat_date', 'segment_key', 'p20_value', 'median_value', 'p80_value', 'contract_count']);

                    if ($statistics->count() !== count(self::FIXED_TERM_COMPARISON_SEGMENTS)) {
                        continue;
                    }

                    $candidateRows = [];
                    foreach (self::FIXED_TERM_COMPARISON_SEGMENTS as $durationMonths => $segmentKey) {
                        $statistic = $statistics->firstWhere('segment_key', $segmentKey);
                        if ($statistic === null) {
                            $candidateRows = [];
                            break;
                        }

                        $p20 = (float) $statistic->p20_value;
                        $median = (float) $statistic->median_value;
                        $p80 = (float) $statistic->p80_value;
                        $contractCount = (int) $statistic->contract_count;

                        if (! is_finite($p20) || ! is_finite($median) || ! is_finite($p80)
                            || $p20 > $median || $median > $p80 || $contractCount < 10
                        ) {
                            $candidateRows = [];
                            break;
                        }

                        $candidateRows[] = [
                            'duration_months' => $durationMonths,
                            'p20' => $p20,
                            'median' => $median,
                            'p80' => $p80,
                            'contract_count' => $contractCount,
                        ];
                    }

                    if (count($candidateRows) === count(self::FIXED_TERM_COMPARISON_SEGMENTS)) {
                        $commonDate = $candidateDate;
                        $rows = $candidateRows;
                        break;
                    }
                }

                if ($commonDate === null) {
                    return $this->emptyFixedTermComparison();
                }

                $scaleMin = min(array_column($rows, 'p20'));
                $scaleMax = max(array_column($rows, 'p80'));
                if (! is_finite($scaleMin) || ! is_finite($scaleMax)) {
                    return $this->emptyFixedTermComparison();
                }
                $scaleSpan = $scaleMax - $scaleMin;
                foreach ($rows as &$row) {
                    $row['p20_percent'] = $this->scalePercent($row['p20'], $scaleMin, $scaleSpan);
                    $row['median_percent'] = $this->scalePercent($row['median'], $scaleMin, $scaleSpan);
                    $row['p80_percent'] = $this->scalePercent($row['p80'], $scaleMin, $scaleSpan);
                }
                unset($row);

                return [
                    'date' => Carbon::parse($commonDate)->toDateString(),
                    'basis' => $pricingBasis,
                    'scale_min' => $scaleMin,
                    'scale_max' => $scaleMax,
                    'rows' => $rows,
                ];
            },
        );
    }

    /**
     * The article can use the open-ended segment as a published current
     * fixed-rate baseline only after canonical classification removes Spot,
     * reset, and Hybrid rows.
     *
     * @return array<string,mixed>
     */
    private function fixedTermArticleCurrentComparison(): array
    {
        if (! $this->pricingMode->enabled()) {
            return [];
        }

        $pricingBasis = $this->pricingMode->expectedContractPriceBasis()->value;
        $baseQuery = fn () => ContractPriceDailyStatistic::query()
            ->unitStatistics()
            ->where('metric_key', 'energy_price')
            ->whereNull('consumption_kwh')
            ->where('pricing_basis', $pricingBasis)
            ->whereIn('segment_key', self::FIXED_TERM_ARTICLE_CURRENT_SEGMENTS)
            ->whereNotNull('p20_value')
            ->whereNotNull('median_value')
            ->whereNotNull('p80_value')
            ->where('contract_count', '>=', 10);
        $candidateDates = $baseQuery()
            ->select('stat_date')
            ->groupBy('stat_date')
            ->havingRaw('COUNT(DISTINCT segment_key) = ?', [count(self::FIXED_TERM_ARTICLE_CURRENT_SEGMENTS)])
            ->orderByDesc('stat_date')
            ->pluck('stat_date');

        foreach ($candidateDates as $candidateDate) {
            $date = Carbon::parse($candidateDate)->toDateString();
            $statistics = $baseQuery()
                ->whereDate('stat_date', $date)
                ->get(['segment_key', 'p20_value', 'median_value', 'p80_value', 'contract_count']);

            if ($statistics->count() !== count(self::FIXED_TERM_ARTICLE_CURRENT_SEGMENTS)) {
                continue;
            }

            $rows = [];
            foreach (self::FIXED_TERM_ARTICLE_CURRENT_SEGMENTS as $segmentKey) {
                $statistic = $statistics->firstWhere('segment_key', $segmentKey);
                if ($statistic === null) {
                    $rows = [];
                    break;
                }

                $p20 = (float) $statistic->p20_value;
                $median = (float) $statistic->median_value;
                $p80 = (float) $statistic->p80_value;
                if (! is_finite($p20) || ! is_finite($median) || ! is_finite($p80)
                    || $p20 > $median || $median > $p80 || (int) $statistic->contract_count < 10
                ) {
                    $rows = [];
                    break;
                }

                $rows[] = [
                    'segment_key' => $segmentKey,
                    'duration_months' => match ($segmentKey) {
                        'fixed_term_6' => 6,
                        'fixed_term_12' => 12,
                        'fixed_term_24' => 24,
                        default => null,
                    },
                    'p20' => $p20,
                    'median' => $median,
                    'p80' => $p80,
                    'contract_count' => (int) $statistic->contract_count,
                ];
            }

            if (count($rows) === count(self::FIXED_TERM_ARTICLE_CURRENT_SEGMENTS)) {
                $fixedRows = collect($rows)
                    ->whereNotNull('duration_months')
                    ->sortBy('median')
                    ->values();

                return [
                    'date' => $date,
                    'basis' => $pricingBasis,
                    'rows' => $rows,
                    'fixed_ranking' => $fixedRows->pluck('duration_months')->all(),
                    'lowest_fixed_duration_months' => $fixedRows->first()['duration_months'],
                    'highest_fixed_duration_months' => $fixedRows->last()['duration_months'],
                ];
            }
        }

        return [];
    }

    /**
     * Compare precomputed annual distributions with a fully fixed 12-month
     * contract. Estimator methods can differ between segments by design.
     *
     * @return array<string,mixed>
     */
    private function annualComparison(): array
    {
        if (! $this->pricingMode->enabled()) {
            return [];
        }

        $expectedBasis = $this->pricingMode->expectedContractPriceBasis()->value;
        $allSegments = array_merge(
            self::ANNUAL_COMPARISON_CORE_SEGMENTS,
            self::ANNUAL_COMPARISON_OPTIONAL_SEGMENTS,
        );
        $baseQuery = fn () => ContractPriceDailyStatistic::query()
            ->activeAnnualMethod()
            ->where('consumption_kwh', 5000)
            ->where('pricing_basis', $expectedBasis)
            ->whereIn('segment_key', $allSegments)
            ->whereNotNull('p20_value')
            ->whereNotNull('median_value')
            ->whereNotNull('p80_value')
            ->where('contract_count', '>', 0);
        $candidateDates = $baseQuery()
            ->whereIn('segment_key', self::ANNUAL_COMPARISON_CORE_SEGMENTS)
            ->select('stat_date')
            ->groupBy('stat_date')
            ->havingRaw('COUNT(DISTINCT segment_key) = ?', [count(self::ANNUAL_COMPARISON_CORE_SEGMENTS)])
            ->orderByDesc('stat_date')
            ->pluck('stat_date');

        foreach ($candidateDates as $candidateDate) {
            $date = Carbon::parse($candidateDate)->toDateString();
            $statistics = $baseQuery()
                ->whereDate('stat_date', $date)
                ->get([
                    'segment_key',
                    'pricing_basis',
                    'basis_counts',
                    'p20_value',
                    'median_value',
                    'p80_value',
                    'contract_count',
                ]);
            $statisticsBySegment = $statistics->groupBy('segment_key');
            $rows = [];

            foreach (self::ANNUAL_COMPARISON_CORE_SEGMENTS as $segmentKey) {
                $segmentRows = $statisticsBySegment->get($segmentKey, collect());
                if ($segmentRows->count() !== 1) {
                    $rows = [];
                    break;
                }

                $row = $this->completeAnnualRow($segmentRows->first());
                if ($row === null) {
                    $rows = [];
                    break;
                }

                $rows[$segmentKey] = $row;
            }

            if (count($rows) !== count(self::ANNUAL_COMPARISON_CORE_SEGMENTS)) {
                continue;
            }

            $comparisons = [];
            foreach (['spot', 'open_ended'] as $segmentKey) {
                $comparisons[$segmentKey] = $this->annualDifference($rows['fixed_term_12'], $rows[$segmentKey]);
            }

            foreach (self::ANNUAL_COMPARISON_OPTIONAL_SEGMENTS as $segmentKey) {
                $segmentRows = $statisticsBySegment->get($segmentKey, collect());
                $statistic = $segmentRows->count() === 1 ? $segmentRows->first() : null;

                if ($statistic === null) {
                    $comparisons[$segmentKey] = [
                        'segment_key' => $segmentKey,
                        'state' => 'unavailable',
                    ];

                    continue;
                }

                $estimateMethods = $this->estimateMethodCounts($statistic->basis_counts);
                if (in_array($segmentKey, ['market_reset', 'quarterly'], true) && $estimateMethods === []) {
                    $comparisons[$segmentKey] = [
                        'segment_key' => $segmentKey,
                        'state' => 'unavailable',
                    ];

                    continue;
                }

                $row = $this->completeAnnualRow($statistic);
                if ($row === null) {
                    $comparisons[$segmentKey] = [
                        'segment_key' => $segmentKey,
                        'state' => 'unavailable',
                    ];

                    continue;
                }

                $baseOnlyCount = $segmentKey === 'hybrid'
                    ? ($estimateMethods['hybrid_base_only'] ?? (int) $statistic->contract_count)
                    : ($estimateMethods['hybrid_base_only'] ?? 0);
                $consumptionEffectIgnored = $segmentKey === 'hybrid' || $baseOnlyCount > 0;
                if ($consumptionEffectIgnored) {
                    $row['consumption_effect_ignored'] = true;
                    $row['base_only_count'] = $baseOnlyCount;
                    $row['complete_estimate_count'] = max(array_sum($estimateMethods) - $baseOnlyCount, 0);
                }

                $rows[$segmentKey] = $row;
                $comparison = $this->annualDifference($rows['fixed_term_12'], $row);
                if ($consumptionEffectIgnored) {
                    $comparison['consumption_effect_ignored'] = true;
                    $comparison['base_only_count'] = $baseOnlyCount;
                    $comparison['complete_estimate_count'] = $row['complete_estimate_count'];
                }
                $comparisons[$segmentKey] = $comparison;
            }

            $orderedRows = collect(array_keys(self::ANNUAL_COMPARISON_LABELS))
                ->map(fn (string $segmentKey) => $rows[$segmentKey] ?? null)
                ->filter()
                ->values()
                ->all();

            return [
                'date' => $date,
                'method_version' => ContractPriceDailyStatistic::activeAnnualMethodVersion()->value,
                'pricing_basis' => $expectedBasis,
                'consumption_kwh' => 5000,
                'benchmark_segment_key' => 'fixed_term_12',
                'rows' => $orderedRows,
                'comparisons' => $comparisons,
                'chart' => [
                    'labels' => array_map(fn (array $row) => $row['label'], $orderedRows),
                    'medians' => array_map(fn (array $row) => $row['median'], $orderedRows),
                    'segment_keys' => array_map(fn (array $row) => $row['segment_key'], $orderedRows),
                    'benchmark_segment_key' => 'fixed_term_12',
                ],
            ];
        }

        return [];
    }

    /** @return array<string,mixed>|null */
    private function completeAnnualRow(ContractPriceDailyStatistic $statistic): ?array
    {
        if ($statistic->p20_value === null || $statistic->median_value === null || $statistic->p80_value === null) {
            return null;
        }

        $p20 = (float) $statistic->p20_value;
        $median = (float) $statistic->median_value;
        $p80 = (float) $statistic->p80_value;
        $contractCount = (int) $statistic->contract_count;
        if (! is_finite($p20) || ! is_finite($median) || ! is_finite($p80)
            || $p20 > $median || $median > $p80 || $contractCount <= 0
        ) {
            return null;
        }

        $segmentKey = (string) $statistic->segment_key;

        return [
            'segment_key' => $segmentKey,
            'label' => self::ANNUAL_COMPARISON_LABELS[$segmentKey],
            'p20' => $p20,
            'median' => $median,
            'p80' => $p80,
            'contract_count' => $contractCount,
            'low_sample' => $segmentKey === 'market_reset' && $contractCount < 10,
            'caveat' => self::ANNUAL_COMPARISON_CAVEATS[$segmentKey],
        ];
    }

    /** @return array<string,mixed> */
    private function annualDifference(array $benchmark, array $alternative): array
    {
        $difference = $benchmark['median'] - $alternative['median'];
        $monthlyDifference = $difference / 12;
        $lowerMedian = min($benchmark['median'], $alternative['median']);
        $relativeDifference = $lowerMedian > 0 ? abs($difference) / $lowerMedian : null;

        return [
            'segment_key' => $alternative['segment_key'],
            'state' => 'complete',
            'median_difference_eur' => $difference,
            'median_difference_monthly_eur' => $monthlyDifference,
            'cheaper_direction' => match (true) {
                $difference < 0 => 'fixed_12_cheaper',
                $difference > 0 => 'alternative_cheaper',
                default => 'equal',
            },
            'difference_is_small' => abs($monthlyDifference) <= 5.0
                || ($relativeDifference !== null && $relativeDifference <= 0.05),
            'low_sample' => (bool) ($alternative['low_sample'] ?? false),
        ];
    }

    /** @return array<string,int> */
    private function estimateMethodCounts(mixed $basisCounts): array
    {
        if (! is_array($basisCounts) || ! is_array($basisCounts['estimate_method'] ?? null)) {
            return [];
        }

        $counts = [];
        foreach ($basisCounts['estimate_method'] as $method => $count) {
            if (is_string($method) && is_numeric($count) && (int) $count > 0) {
                $counts[$method] = (int) $count;
            }
        }

        return $counts;
    }

    /**
     * @return array<string,mixed>
     */
    private function fixedTermHistory(): array
    {
        $basisPriority = [
            ContractPriceBasis::ObservedSellerData->value => 1,
        ];
        if ($this->pricingMode->enabled()) {
            $basisPriority[ContractPriceBasis::CanonicalCalculation->value] = 2;
        }
        $query = fn () => ContractPriceDailyStatistic::query()
            ->unitStatistics()
            ->where('metric_key', 'energy_price')
            ->whereNull('consumption_kwh')
            ->whereIn('segment_key', array_values(self::FIXED_TERM_COMPARISON_SEGMENTS))
            ->whereIn('pricing_basis', array_keys($basisPriority));
        $latestDate = $query()->max('stat_date');

        if ($latestDate === null) {
            return [
                'start_date' => null,
                'end_date' => null,
                'series' => [],
                'chart' => ['labels' => [], 'datasets' => []],
            ];
        }

        $endDate = Carbon::parse($latestDate)->toDateString();
        $startDate = Carbon::parse($endDate)->subMonthsNoOverflow(12)->toDateString();
        $rows = $query()
            ->whereDate('stat_date', '>=', $startDate)
            ->whereDate('stat_date', '<=', $endDate)
            ->orderBy('stat_date')
            ->get([
                'stat_date',
                'segment_key',
                'pricing_basis',
                'p20_value',
                'median_value',
                'p80_value',
                'contract_count',
            ]);

        $selectedDaily = [];
        foreach ($rows as $row) {
            $date = $row->stat_date->toDateString();
            $existing = $selectedDaily[$row->segment_key][$date] ?? null;
            if ($existing === null
                || $basisPriority[$row->pricing_basis] > $basisPriority[$existing->pricing_basis]
            ) {
                $selectedDaily[$row->segment_key][$date] = $row;
            }
        }

        $daily = [];
        foreach ($selectedDaily as $segmentKey => $dateRows) {
            foreach ($dateRows as $date => $row) {
                $p20 = (float) $row->p20_value;
                $median = (float) $row->median_value;
                $p80 = (float) $row->p80_value;
                if ($row->p20_value === null || $row->median_value === null || $row->p80_value === null
                    || ! is_finite($p20) || ! is_finite($median) || ! is_finite($p80)
                    || $p20 > $median || $median > $p80 || (int) $row->contract_count <= 0
                ) {
                    continue;
                }

                $daily[$segmentKey][$date] = $row;
            }
        }

        $series = [];
        foreach (self::FIXED_TERM_COMPARISON_SEGMENTS as $durationMonths => $segmentKey) {
            $weeks = [];
            foreach ($daily[$segmentKey] ?? [] as $date => $row) {
                $week = Carbon::parse($date)->startOfWeek()->toDateString();
                $weeks[$week][] = $row;
            }

            $points = [];
            foreach ($weeks as $week => $weekRows) {
                $count = count($weekRows);
                $point = [
                    'date' => $week,
                    'p20' => array_sum(array_map(fn ($row) => (float) $row->p20_value, $weekRows)) / $count,
                    'median' => array_sum(array_map(fn ($row) => (float) $row->median_value, $weekRows)) / $count,
                    'p80' => array_sum(array_map(fn ($row) => (float) $row->p80_value, $weekRows)) / $count,
                    'contract_count' => (int) round(array_sum(array_map(fn ($row) => (int) $row->contract_count, $weekRows)) / $count),
                ];
                $points[] = $point;
            }

            $firstPoint = $points[0] ?? null;
            $lastPoint = $points === [] ? null : $points[array_key_last($points)];
            $medianChange = $firstPoint === null || $lastPoint === null
                ? null
                : $lastPoint['median'] - $firstPoint['median'];

            $series[] = [
                'duration_months' => $durationMonths,
                'points' => $points,
                'summary' => $medianChange === null ? null : [
                    'start_date' => $firstPoint['date'],
                    'end_date' => $lastPoint['date'],
                    'start_median' => $firstPoint['median'],
                    'end_median' => $lastPoint['median'],
                    'change' => $medianChange,
                    'direction' => match (true) {
                        $medianChange > 0.05 => 'rose',
                        $medianChange < -0.05 => 'fell',
                        default => 'stable',
                    },
                ],
            ];
        }

        $chartDates = collect($series)
            ->flatMap(fn (array $durationSeries) => array_column($durationSeries['points'], 'date'))
            ->unique()
            ->sort()
            ->values();
        $chartDatasets = collect($series)->map(function (array $durationSeries) use ($chartDates): array {
            $mediansByDate = collect($durationSeries['points'])->pluck('median', 'date');

            return [
                'duration_months' => $durationSeries['duration_months'],
                'segment_key' => 'fixed_term_'.$durationSeries['duration_months'],
                'label' => $durationSeries['duration_months'].' kk',
                'values' => $chartDates->map(fn (string $date) => $mediansByDate->get($date))->all(),
            ];
        })->all();

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'series' => $series,
            'chart' => [
                'labels' => $chartDates->map(fn (string $date) => Carbon::parse($date)->translatedFormat('j.n.Y'))->all(),
                'dates' => $chartDates->all(),
                'datasets' => $chartDatasets,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fixedTermArticleForecast(ContractPriceBasis $pricingBasis): array
    {
        $baseQuery = fn () => FixedContractPriceForecast::query()
            ->eligibleForPublicDisplay($pricingBasis)
            ->where('horizon_days', 30)
            ->whereIn('duration_months', array_keys(self::FIXED_TERM_COMPARISON_SEGMENTS))
            ->whereIn('target_quantile', ['p20', 'median', 'p80']);
        $latestDate = $baseQuery()->max('forecast_date');

        if ($latestDate === null) {
            return ['date' => null, 'horizon_days' => 30, 'direction_summary' => 'none', 'durations' => []];
        }

        $date = Carbon::parse($latestDate)->toDateString();
        $rows = $baseQuery()
            ->whereDate('forecast_date', $date)
            ->get([
                'forecast_date',
                'target_date',
                'horizon_days',
                'duration_months',
                'target_quantile',
                'current_price_cents_per_kwh',
                'forecast_price_cents_per_kwh',
                'contract_count',
                'confidence',
            ]);
        $durations = [];

        foreach (array_keys(self::FIXED_TERM_COMPARISON_SEGMENTS) as $durationMonths) {
            $durationRows = $rows->where('duration_months', $durationMonths);
            $byQuantile = $durationRows->keyBy('target_quantile');
            $p20 = $byQuantile->get('p20');
            $median = $byQuantile->get('median');
            $p80 = $byQuantile->get('p80');
            $orderedRows = [$p20, $median, $p80];
            $current = array_map(
                fn ($row) => $row === null || $row->current_price_cents_per_kwh === null
                    ? null
                    : (float) $row->current_price_cents_per_kwh,
                $orderedRows,
            );
            $forecast = array_map(
                fn ($row) => $row === null || $row->forecast_price_cents_per_kwh === null
                    ? null
                    : (float) $row->forecast_price_cents_per_kwh,
                $orderedRows,
            );
            $targetDates = $durationRows->pluck('target_date')->filter()->map(
                fn ($targetDate) => Carbon::parse($targetDate)->toDateString(),
            )->unique();
            $complete = $durationRows->count() === 3
                && ! in_array(null, $orderedRows, true)
                && count(array_filter($current, fn ($value) => $value !== null && is_finite($value))) === 3
                && count(array_filter($forecast, fn ($value) => $value !== null && is_finite($value))) === 3
                && $current[0] <= $current[1] && $current[1] <= $current[2]
                && $forecast[0] <= $forecast[1] && $forecast[1] <= $forecast[2]
                && $durationRows->every(fn ($row) => (int) $row->contract_count > 0 && (int) $row->horizon_days === 30)
                && $targetDates->count() === 1;

            if (! $complete) {
                $durations[] = ['duration_months' => $durationMonths, 'available' => false];

                continue;
            }

            $durations[] = [
                'duration_months' => $durationMonths,
                'available' => true,
                'forecast_date' => $date,
                'target_date' => $targetDates->first(),
                'horizon_days' => 30,
                'contract_count' => (int) $median->contract_count,
                'confidence' => $median->confidence,
                'current' => ['p20' => $current[0], 'median' => $current[1], 'p80' => $current[2]],
                'forecast' => ['p20' => $forecast[0], 'median' => $forecast[1], 'p80' => $forecast[2]],
                'median_change' => $forecast[1] - $current[1],
            ];
        }

        $availableChanges = collect($durations)
            ->where('available', true)
            ->pluck('median_change')
            ->map(fn ($change) => (float) $change)
            ->values();
        $directionSummary = match (true) {
            $availableChanges->isEmpty() => 'none',
            $availableChanges->every(fn (float $change) => abs($change) < 0.005) => 'stable',
            $availableChanges->every(fn (float $change) => $change < 0) => 'down',
            $availableChanges->every(fn (float $change) => $change > 0) => 'up',
            default => 'mixed',
        };

        return [
            'date' => $date,
            'horizon_days' => 30,
            'direction_summary' => $directionSummary,
            'durations' => $durations,
        ];
    }

    /** @return array{} */
    private function emptyFixedTermComparison(): array
    {
        return [];
    }

    private function scalePercent(float $value, float $scaleMin, float $scaleSpan): float
    {
        if ($scaleSpan === 0.0) {
            return 50.0;
        }

        return max(0.0, min(100.0, (($value - $scaleMin) / $scaleSpan) * 100.0));
    }

    private function statisticsConsumptionLevel(int $consumption): int
    {
        $levels = [2000, 5000, 18000];
        $closest = 5000;
        $closestDistance = PHP_INT_MAX;

        foreach ($levels as $level) {
            $distance = abs($consumption - $level);
            if ($distance < $closestDistance) {
                $closest = $level;
                $closestDistance = $distance;
            }
        }

        return $closest;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fixedTermDurationTrend(int $durationMonths): ?array
    {
        $latestBasis = $this->pricingMode->expectedContractPriceBasis()->value;
        $previousBasis = $latestBasis === ContractPriceBasis::CanonicalCalculation->value
            ? ContractPriceBasis::ObservedSellerData->value
            : $latestBasis;
        $segmentKey = "fixed_term_{$durationMonths}";

        $latest = ContractPriceDailyStatistic::query()
            ->unitStatistics()
            ->where('segment_key', $segmentKey)
            ->where('metric_key', 'energy_price')
            ->whereNull('consumption_kwh')
            ->where('pricing_basis', $latestBasis)
            ->where('contract_count', '>', 0)
            ->whereNotNull('median_value')
            ->orderByDesc('stat_date')
            ->first();

        if ($latest === null) {
            return null;
        }

        $previous = ContractPriceDailyStatistic::query()
            ->unitStatistics()
            ->where('segment_key', $segmentKey)
            ->where('metric_key', 'energy_price')
            ->whereNull('consumption_kwh')
            ->where('pricing_basis', $previousBasis)
            ->where('contract_count', '>', 0)
            ->whereNotNull('median_value')
            ->whereDate('stat_date', '<=', Carbon::parse($latest->stat_date)->subDays(30)->toDateString())
            ->orderByDesc('stat_date')
            ->first();

        if ($previous === null) {
            return null;
        }

        $trend = $this->formatTrend(
            (float) $latest->median_value,
            (float) $previous->median_value,
            (int) $latest->contract_count,
            Carbon::parse($latest->stat_date),
            "{$durationMonths} kk tarjoushinnat",
            $latestBasis,
            $previousBasis,
            "{$durationMonths} kk hintakehitys",
            "{$durationMonths} kk sopimusten tarjottujen energiahintojen mediaani 30 päivän välein",
        );
        $trend['previous_as_of'] = Carbon::parse($previous->stat_date)->toDateString();
        $trend['segment_key'] = $segmentKey;
        $trend['duration_months'] = $durationMonths;

        return $trend;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function segmentTrend(string $segmentKey, int $consumption): ?array
    {
        $latestBasis = $this->pricingMode->expectedContractPriceBasis()->value;
        // `market_reset` starts at canonical rollout. Observed history keeps its
        // original quarterly/open-ended keys, so this segment can compare only
        // with an earlier canonical point instead of relabelling old evidence.
        $previousBasis = $segmentKey === 'market_reset'
            ? $latestBasis
            : ($latestBasis === ContractPriceBasis::CanonicalCalculation->value
                ? ContractPriceBasis::ObservedSellerData->value
                : $latestBasis);
        $latest = ContractPriceDailyStatistic::query()
            ->where('segment_key', $segmentKey)
            ->activeAnnualMethod()
            ->where('pricing_basis', $latestBasis)
            ->where('consumption_kwh', $consumption)
            ->where('contract_count', '>', 0)
            ->orderByDesc('stat_date')
            ->first();

        if ($latest === null || $latest->median_value === null) {
            return null;
        }

        $previous = ContractPriceDailyStatistic::query()
            ->where('segment_key', $segmentKey)
            ->activeAnnualMethod()
            ->where('pricing_basis', $previousBasis)
            ->where('compatibility_key', $latest->compatibility_key)
            ->where('consumption_kwh', $consumption)
            ->where('contract_count', '>', 0)
            ->whereDate('stat_date', '<=', Carbon::parse($latest->stat_date)->subDays(30)->toDateString())
            ->orderByDesc('stat_date')
            ->first();

        if ($previous === null
            || $previous->median_value === null
            || ! AnnualSeriesCompatibility::sameKey($previous->compatibility_key, $latest->compatibility_key)
        ) {
            return null;
        }

        return $this->formatTrend(
            (float) $latest->median_value,
            (float) $previous->median_value,
            (int) $latest->contract_count,
            Carbon::parse($latest->stat_date),
            'Sopimustyypin hinnat',
            $latestBasis,
            $previousBasis,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sellerSetEnergyPriceTrend(): ?array
    {
        if (! $this->pricingMode->enabled()) {
            return null;
        }

        $baseQuery = fn () => ContractPriceDailyStatistic::query()
            ->unitStatistics()
            ->where('metric_key', SellerSetEnergyPriceIndexService::METRIC_KEY)
            ->where('segment_key', SellerSetEnergyPriceIndexService::SEGMENT_OVERALL)
            ->where('pricing_basis', ContractPriceBasis::CanonicalCalculation->value)
            ->where('calculation_basis', SellerSetEnergyPriceIndexService::CALCULATION_BASIS)
            ->where('estimate_basis', SellerSetEnergyPriceIndexService::ESTIMATE_BASIS)
            ->where('compatibility_key', SellerSetEnergyPriceIndexService::COMPATIBILITY_KEY)
            ->whereNull('consumption_kwh')
            ->where('contract_count', '>', 0)
            ->whereNotNull('avg_value');

        $latest = $baseQuery()->orderByDesc('stat_date')->first();
        if ($latest === null || ! is_finite((float) $latest->avg_value) || (float) $latest->avg_value <= 0.0) {
            return null;
        }

        $comparisonDate = Carbon::parse($latest->stat_date, 'Europe/Helsinki')
            ->subDays(30)
            ->toDateString();
        $previous = $baseQuery()->whereDate('stat_date', $comparisonDate)->first();
        if ($previous === null || ! is_finite((float) $previous->avg_value) || (float) $previous->avg_value <= 0.0) {
            return null;
        }

        $metadata = is_array($latest->basis_counts) ? $latest->basis_counts : [];
        $contractCount = filter_var($metadata['contract_count'] ?? null, FILTER_VALIDATE_INT);
        $supplierCount = filter_var($metadata['supplier_count'] ?? null, FILTER_VALIDATE_INT);
        if ($contractCount === false || $contractCount <= 0 || $supplierCount === false || $supplierCount <= 0) {
            return null;
        }

        $trend = $this->formatTrend(
            (float) $latest->avg_value,
            (float) $previous->avg_value,
            $contractCount,
            Carbon::parse($latest->stat_date, 'Europe/Helsinki'),
            'Energianhintaindeksi',
            ContractPriceBasis::CanonicalCalculation->value,
            ContractPriceBasis::CanonicalCalculation->value,
            'Sähkösopimusten energianhintaindeksi',
            'Indeksi seuraa sähkösopimusten energiahintoja Suomessa. Pörssisähkö ei ole mukana',
        );
        $trend['previous_as_of'] = $comparisonDate;
        $trend['supplier_count'] = $supplierCount;
        $trend['metric_key'] = SellerSetEnergyPriceIndexService::METRIC_KEY;
        $trend['segment_key'] = SellerSetEnergyPriceIndexService::SEGMENT_OVERALL;

        return $trend;
    }

    /**
     * @return array<string,mixed>
     */
    private function formatTrend(
        float $latest,
        float $previous,
        int $contractCount,
        Carbon $latestDate,
        string $subject,
        string $latestBasis,
        string $previousBasis,
        string $eyebrow = '30 päivän trendi',
        ?string $supporting = null,
    ): array {
        $change = $latest - $previous;
        $changePct = $previous != 0.0 ? ($change / $previous) * 100.0 : 0.0;
        $absPct = abs($changePct);

        $isIndex = $subject === 'Energianhintaindeksi';
        $headlineSubject = in_array($subject, ['Sähkösopimukset', 'Energianhintaindeksi'], true)
            ? $subject
            : 'Hinnat';

        if ($absPct < 1.0) {
            $direction = 'steady';
            $headline = $isIndex
                ? "{$headlineSubject} pysynyt vakaana"
                : "{$headlineSubject} pysyneet vakaina";
            $tone = 'neutral';
        } elseif ($changePct > 0) {
            $direction = 'up';
            $headline = $isIndex
                ? "{$headlineSubject} noussut"
                : "{$headlineSubject} kallistuneet";
            $tone = 'up';
        } else {
            $direction = 'down';
            $headline = $isIndex
                ? "{$headlineSubject} laskenut"
                : "{$headlineSubject} halventuneet";
            $tone = 'down';
        }

        $changeLabel = $direction === 'steady'
            ? '±0 %'
            : sprintf('%s%s %%', $changePct > 0 ? '+' : '−', number_format($absPct, 1, ',', ' '));

        return [
            'type' => 'trend',
            'tone' => $tone,
            'direction' => $direction,
            'headline' => $headline,
            'detail' => $direction === 'steady'
                ? '30 pv muutos alle 1 %'
                : sprintf('%s%.1f %% 30 päivässä', $changePct > 0 ? '+' : '−', $absPct),
            'eyebrow' => $eyebrow,
            'change_label' => $changeLabel,
            'period_label' => '30 päivää',
            'supporting' => $supporting ?? match (true) {
                $latestBasis === ContractPriceBasis::CanonicalCalculation->value
                    && $previousBasis === ContractPriceBasis::CanonicalCalculation->value => 'Kanoninen nykyarvio verrattuna 30 päivää aiempaan kanoniseen arvioon',
                $latestBasis === ContractPriceBasis::CanonicalCalculation->value => 'Kanoninen nykyarvio verrattuna 30 päivää aiemmin havaittuun myyjädataan',
                default => 'Havaitut myyjähinnat 30 päivän välein',
            },
            'latest_value' => $latest,
            'previous_value' => $previous,
            'change_percent' => $changePct,
            'contract_count' => $contractCount,
            'latest_pricing_basis' => $latestBasis,
            'previous_pricing_basis' => $previousBasis,
            'as_of' => $latestDate->toDateString(),
            'url' => '/sahkosopimus/tilastot',
            'link_label' => 'Katso hintakehitys',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fixedTermForecast(int $durationMonths): ?array
    {
        $row = FixedContractPriceForecast::query()
            ->eligibleForPublicDisplay($this->pricingMode->expectedContractPriceBasis())
            ->where('duration_months', $durationMonths)
            ->where('target_quantile', 'median')
            ->orderByDesc('forecast_date')
            ->first();

        if ($row === null) {
            return null;
        }

        $signal = match ($row->consumer_signal) {
            'lock_sooner' => [
                'tone' => 'up',
                'headline' => 'Ennuste: hinnat nousussa',
                'detail' => 'Määräaikaiset voivat kallistua',
                'direction_label' => 'Nousussa',
            ],
            'wait_if_flexible' => [
                'tone' => 'down',
                'headline' => 'Ennuste: hinnat laskussa',
                'detail' => 'Määräaikaiset voivat halventua',
                'direction_label' => 'Laskussa',
            ],
            default => [
                'tone' => 'neutral',
                'headline' => 'Ennuste: vakaa hintataso',
                'detail' => 'Ei selvää nousu- tai laskupainetta',
                'direction_label' => 'Vakaa',
            ],
        };

        return [
            'type' => 'forecast',
            'tone' => $signal['tone'],
            'headline' => $signal['headline'],
            'detail' => $signal['detail'],
            'eyebrow' => "{$durationMonths} kk ennuste",
            'direction_label' => $signal['direction_label'],
            'period_label' => "{$durationMonths} kk",
            'supporting' => $signal['detail'],
            'duration_months' => $durationMonths,
            'forecast_date' => Carbon::parse($row->forecast_date)->toDateString(),
            'current_price_cents_per_kwh' => (float) $row->current_price_cents_per_kwh,
            'forecast_price_cents_per_kwh' => (float) $row->forecast_price_cents_per_kwh,
            'expected_change_cents_per_kwh' => (float) $row->expected_change_cents_per_kwh,
            'horizon_days' => (int) $row->horizon_days,
            'contract_count' => (int) $row->contract_count,
            'url' => '/sahkosopimus/sahkon-hintaennuste',
            'link_label' => 'Katso ennuste',
        ];
    }
}
