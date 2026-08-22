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
    private const FIXED_TERM_COMPARISON_PAYLOAD_SCHEMA = 'fixed-term-offered-price-comparison-v1';

    private const FIXED_TERM_COMPARISON_SEGMENTS = [
        6 => 'fixed_term_6',
        12 => 'fixed_term_12',
        24 => 'fixed_term_24',
    ];

    public function __construct(private readonly PricingMode $pricingMode) {}

    public function fingerprint(): string
    {
        $pricingBasis = $this->pricingMode->expectedContractPriceBasis()->value;
        $canonicalEnabled = $this->pricingMode->enabled();

        return Cache::remember(
            'contract-market-insight:fingerprint:v6:'.ContractPriceDailyStatistic::activeAnnualMethodVersion()->value.':'.($canonicalEnabled ? 'c1' : 'c0').':'.$pricingBasis,
            now()->addMinutes(10),
            function () use ($pricingBasis, $canonicalEnabled) {
                $expected = ContractPriceDailyStatistic::query()
                    ->activeAnnualMethod()
                    ->where('pricing_basis', $pricingBasis);
                $observed = ContractPriceDailyStatistic::query()
                    ->activeAnnualMethod()
                    ->where('pricing_basis', ContractPriceBasis::ObservedSellerData->value);
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

                $commonDate = $baseQuery()
                    ->select('stat_date')
                    ->groupBy('stat_date')
                    ->havingRaw('COUNT(DISTINCT segment_key) = ?', [count(self::FIXED_TERM_COMPARISON_SEGMENTS)])
                    ->orderByDesc('stat_date')
                    ->value('stat_date');

                if ($commonDate === null) {
                    return $this->emptyFixedTermComparison();
                }

                $statistics = $baseQuery()
                    ->whereDate('stat_date', Carbon::parse($commonDate)->toDateString())
                    ->get(['stat_date', 'segment_key', 'p20_value', 'median_value', 'p80_value', 'contract_count']);

                if ($statistics->count() !== count(self::FIXED_TERM_COMPARISON_SEGMENTS)) {
                    return $this->emptyFixedTermComparison();
                }

                $rows = [];
                foreach (self::FIXED_TERM_COMPARISON_SEGMENTS as $durationMonths => $segmentKey) {
                    $statistic = $statistics->firstWhere('segment_key', $segmentKey);
                    if ($statistic === null) {
                        return $this->emptyFixedTermComparison();
                    }

                    $p20 = (float) $statistic->p20_value;
                    $median = (float) $statistic->median_value;
                    $p80 = (float) $statistic->p80_value;
                    $contractCount = (int) $statistic->contract_count;

                    if (! is_finite($p20) || ! is_finite($median) || ! is_finite($p80)
                        || $p20 > $median || $median > $p80 || $contractCount < 10
                    ) {
                        return $this->emptyFixedTermComparison();
                    }

                    $rows[] = [
                        'duration_months' => $durationMonths,
                        'p20' => $p20,
                        'median' => $median,
                        'p80' => $p80,
                        'contract_count' => $contractCount,
                    ];
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
