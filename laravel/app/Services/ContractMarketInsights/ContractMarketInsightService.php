<?php

namespace App\Services\ContractMarketInsights;

use App\Models\ContractPriceDailyStatistic;
use App\Models\FixedContractPriceForecast;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractStatistics\AnnualSeriesCompatibility;
use App\Services\ContractStatistics\ContractPriceBasis;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ContractMarketInsightService
{
    public function __construct(private readonly PricingMode $pricingMode) {}

    /**
     * Segments used for the broad comparison-page market summary.
     *
     * @var array<int, string>
     */
    private array $aggregateSegments = [
        'spot',
        'hybrid',
        'market_reset',
        'quarterly',
        'fixed_term_below6',
        'fixed_term_6',
        'fixed_term_7_11',
        'fixed_term_12',
        'fixed_term_13_23',
        'fixed_term_24',
        'fixed_term_over24',
        'fixed_term_other',
        'open_ended',
    ];

    public function fingerprint(): string
    {
        $pricingBasis = $this->pricingMode->expectedContractPriceBasis()->value;
        $canonicalEnabled = $this->pricingMode->enabled();

        return Cache::remember(
            'contract-market-insight:fingerprint:v5:'.ContractPriceDailyStatistic::activeAnnualMethodVersion()->value.':'.($canonicalEnabled ? 'c1' : 'c0').':'.$pricingBasis,
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
            'contract-market-insight:v9:'.md5(json_encode([
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
                        ? $this->aggregateTrend($consumption)
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
    private function aggregateTrend(int $consumption): ?array
    {
        $latestBasis = $this->pricingMode->expectedContractPriceBasis()->value;
        $previousBasis = $latestBasis === ContractPriceBasis::CanonicalCalculation->value
            ? ContractPriceBasis::ObservedSellerData->value
            : $latestBasis;
        $latestDate = ContractPriceDailyStatistic::query()
            ->whereIn('segment_key', $this->aggregateSegments)
            ->activeAnnualMethod()
            ->where('pricing_basis', $latestBasis)
            ->where('consumption_kwh', $consumption)
            ->where('contract_count', '>', 0)
            ->max('stat_date');

        if ($latestDate === null) {
            return null;
        }

        $latestDate = Carbon::parse($latestDate)
            ->setTimezone((string) config('app.timezone'))
            ->toDateString();

        $previousDate = ContractPriceDailyStatistic::query()
            ->whereIn('segment_key', $this->aggregateSegments)
            ->activeAnnualMethod()
            ->where('pricing_basis', $previousBasis)
            ->where('consumption_kwh', $consumption)
            ->where('contract_count', '>', 0)
            ->whereDate('stat_date', '<=', Carbon::parse($latestDate)->subDays(30)->toDateString())
            ->max('stat_date');

        if ($previousDate === null) {
            return null;
        }

        $previousDate = Carbon::parse($previousDate)
            ->setTimezone((string) config('app.timezone'))
            ->toDateString();

        $latestRows = $this->aggregateRowsForDate($latestDate, $consumption, $latestBasis);
        $previousRows = $this->aggregateRowsForDate($previousDate, $consumption, $previousBasis);
        [$latestRows, $previousRows] = $this->compatibleAggregateRows($latestRows, $previousRows);

        $latestWeighted = $this->weightedAverage($latestRows);
        $previousWeighted = $this->weightedAverage($previousRows);

        if ($latestWeighted === null || $previousWeighted === null) {
            return null;
        }

        return $this->formatTrend(
            $latestWeighted['value'],
            $previousWeighted['value'],
            $latestWeighted['count'],
            Carbon::parse($latestDate),
            'Sähkösopimukset',
            $latestBasis,
            $previousBasis,
        );
    }

    /**
     * @return Collection<int, ContractPriceDailyStatistic>
     */
    private function aggregateRowsForDate(string $date, int $consumption, string $pricingBasis): Collection
    {
        return ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', $date)
            ->whereIn('segment_key', $this->aggregateSegments)
            ->activeAnnualMethod()
            ->where('pricing_basis', $pricingBasis)
            ->where('consumption_kwh', $consumption)
            ->where('contract_count', '>', 0)
            ->orderBy('segment_key')
            ->get(['segment_key', 'compatibility_key', 'median_value', 'contract_count']);
    }

    /**
     * Compare the same deterministic segment set at both endpoints. Each segment
     * can have its own compatibility key, but that key must not change.
     *
     * @param  Collection<int, ContractPriceDailyStatistic>  $latestRows
     * @param  Collection<int, ContractPriceDailyStatistic>  $previousRows
     * @return array{Collection<int,ContractPriceDailyStatistic>,Collection<int,ContractPriceDailyStatistic>}
     */
    private function compatibleAggregateRows(Collection $latestRows, Collection $previousRows): array
    {
        $latestBySegment = $latestRows->keyBy('segment_key');
        $previousBySegment = $previousRows->keyBy('segment_key');
        $segments = $latestBySegment->keys()
            ->intersect($previousBySegment->keys())
            ->filter(function (string $segment) use ($latestBySegment, $previousBySegment): bool {
                return AnnualSeriesCompatibility::sameKey(
                    $latestBySegment[$segment]->compatibility_key,
                    $previousBySegment[$segment]->compatibility_key,
                );
            })
            ->sort()
            ->values();

        return [
            $segments->map(fn (string $segment) => $latestBySegment[$segment])->values(),
            $segments->map(fn (string $segment) => $previousBySegment[$segment])->values(),
        ];
    }

    /**
     * @param  Collection<int, ContractPriceDailyStatistic>  $rows
     * @return array{value:float,count:int}|null
     */
    private function weightedAverage(Collection $rows): ?array
    {
        $weightedSum = 0.0;
        $count = 0;

        foreach ($rows as $row) {
            if ($row->median_value === null || $row->contract_count <= 0) {
                continue;
            }

            $weightedSum += (float) $row->median_value * (int) $row->contract_count;
            $count += (int) $row->contract_count;
        }

        if ($count === 0) {
            return null;
        }

        return ['value' => $weightedSum / $count, 'count' => $count];
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

        if ($absPct < 1.0) {
            $direction = 'steady';
            $headline = $subject === 'Sähkösopimukset'
                ? 'Sähkösopimukset pysyneet vakaina'
                : 'Hinnat pysyneet vakaina';
            $tone = 'neutral';
        } elseif ($changePct > 0) {
            $direction = 'up';
            $headline = $subject === 'Sähkösopimukset'
                ? 'Sähkösopimukset kallistuneet'
                : 'Hinnat kallistuneet';
            $tone = 'up';
        } else {
            $direction = 'down';
            $headline = $subject === 'Sähkösopimukset'
                ? 'Sähkösopimukset halventuneet'
                : 'Hinnat halventuneet';
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
                'headline' => 'Ennuste: vakaata',
                'detail' => 'Ei selvää nousu- tai laskupainetta',
                'direction_label' => 'Vakaata',
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
            'url' => '/sahkosopimus/sahkon-hintaennuste',
            'link_label' => 'Katso ennuste',
        ];
    }
}
