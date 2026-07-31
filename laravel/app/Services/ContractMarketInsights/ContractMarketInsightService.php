<?php

namespace App\Services\ContractMarketInsights;

use App\Models\ContractPriceDailyStatistic;
use App\Models\FixedContractPriceForecast;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractStatistics\ContractPriceBasis;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ContractMarketInsightService
{
    private const TREND_METRIC = 'annual_cost';

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
            'contract-market-insight:fingerprint:v3:'.($canonicalEnabled ? 'c1' : 'c0').':'.$pricingBasis,
            now()->addMinutes(10),
            function () use ($pricingBasis, $canonicalEnabled) {
                $expected = ContractPriceDailyStatistic::query()->where('pricing_basis', $pricingBasis);
                $observed = ContractPriceDailyStatistic::query()
                    ->where('pricing_basis', ContractPriceBasis::ObservedSellerData->value);

                return md5(json_encode([
                    'canonical_enabled' => $canonicalEnabled,
                    'pricing_basis' => $pricingBasis,
                    'statistics_latest_date' => $expected->max('stat_date'),
                    'statistics_latest_updated' => $expected->max('updated_at'),
                    'observed_latest_date' => $observed->max('stat_date'),
                    'observed_latest_updated' => $observed->max('updated_at'),
                    'forecast_latest_date' => FixedContractPriceForecast::max('forecast_date'),
                    'forecast_latest_updated' => FixedContractPriceForecast::max('updated_at'),
                ]));
            },
        );
    }

    /**
     * @return array{trend:?array<string,mixed>,forecast:?array<string,mixed>,has_items:bool}
     */
    public function insight(?string $segmentKey, int $consumption, bool $includeForecast = false): array
    {
        $consumption = $this->statisticsConsumptionLevel($consumption);
        $segmentKey = $segmentKey ?: 'aggregate';

        return Cache::remember(
            'contract-market-insight:v6:'.md5(json_encode([
                $segmentKey,
                $consumption,
                $includeForecast,
                $this->fingerprint(),
                $this->pricingMode->enabled(),
                (string) config('price_forecasting.fixed_term.model_version', 'fixed_term_ewma_gap_v2'),
            ])),
            Carbon::tomorrow(),
            function () use ($segmentKey, $consumption, $includeForecast) {
                $trend = $segmentKey === 'aggregate'
                    ? $this->aggregateTrend($consumption)
                    : $this->segmentTrend($segmentKey, $consumption);

                $forecast = $includeForecast
                    ? $this->fixedTermForecast()
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
            ->where('metric_key', self::TREND_METRIC)
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
            ->where('metric_key', self::TREND_METRIC)
            ->where('pricing_basis', $previousBasis)
            ->where('consumption_kwh', $consumption)
            ->where('contract_count', '>', 0)
            ->whereDate('stat_date', '<=', Carbon::parse($latest->stat_date)->subDays(30)->toDateString())
            ->orderByDesc('stat_date')
            ->first();

        if ($previous === null || $previous->median_value === null) {
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
            ->where('metric_key', self::TREND_METRIC)
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
            ->where('metric_key', self::TREND_METRIC)
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
            ->where('metric_key', self::TREND_METRIC)
            ->where('pricing_basis', $pricingBasis)
            ->where('consumption_kwh', $consumption)
            ->where('contract_count', '>', 0)
            ->get(['median_value', 'contract_count']);
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
            'eyebrow' => '30 päivän trendi',
            'change_label' => $changeLabel,
            'period_label' => '30 päivää',
            'supporting' => match (true) {
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
    private function fixedTermForecast(): ?array
    {
        $row = FixedContractPriceForecast::query()
            ->eligibleForPublicDisplay($this->pricingMode->expectedContractPriceBasis())
            ->where('duration_months', 12)
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
            'eyebrow' => '12 kk ennuste',
            'direction_label' => $signal['direction_label'],
            'period_label' => '12 kk',
            'supporting' => $signal['detail'],
            'duration_months' => 12,
            'forecast_date' => Carbon::parse($row->forecast_date)->toDateString(),
            'url' => '/sahkosopimus/sahkon-hintaennuste',
            'link_label' => 'Katso ennuste',
        ];
    }
}
