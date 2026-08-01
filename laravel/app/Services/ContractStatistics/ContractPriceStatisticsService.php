<?php

namespace App\Services\ContractStatistics;

use App\Enums\PricingModel;
use App\Enums\TargetGroup;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use App\Models\SpotPriceHour;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContractPriceStatisticsService
{
    public const CONSUMPTION_LEVELS = [2000, 5000, 18000];

    public function __construct(
        private readonly ContractPriceCalculator $calculator,
        private readonly \App\Services\CanonicalPricing\CanonicalContractPricingService $canonicalPricing,
        private readonly ContractStatisticsSegmentClassifier $segmentClassifier,
    ) {}

    /**
     * Calculate per-contract snapshots and aggregate statistics for one date.
     *
     * @param  iterable<string>  $contractIds
     * @return array{snapshots:int, statistics:int}
     */
    public function calculateForDate(CarbonInterface|string $date, iterable $contractIds, bool $overwrite = false, ?bool $useCanonical = null): array
    {
        $date = $date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date);
        $dateString = $date->toDateString();
        $contractIds = collect($contractIds)->filter()->unique()->values();

        // Forward daily runs may use canonical pricing; historical backfills must not,
        // because canonical_pricing is today's interpretation and cannot be applied to a
        // past date. Callers pass false explicitly for backfills.
        $useCanonical ??= $this->canonicalPricing->enabled();

        if ($contractIds->isEmpty()) {
            return ['snapshots' => 0, 'statistics' => 0];
        }

        return DB::transaction(function () use ($date, $dateString, $contractIds, $overwrite, $useCanonical) {
            $pricingBasis = ContractPriceBasis::forCanonical($useCanonical)->value;
            $dateSnapshots = ContractPriceSnapshot::whereDate('snapshot_date', $dateString);

            if ($overwrite) {
                $dateSnapshots->delete();
            } else {
                // One basis owns a statistics date. Remove the other basis for the
                // complete date, then replace this run's contracts so an outcome that
                // is now excluded cannot leave its old same-basis snapshot behind.
                (clone $dateSnapshots)->where('pricing_basis', '!=', $pricingBasis)->delete();
                (clone $dateSnapshots)
                    ->where('pricing_basis', $pricingBasis)
                    ->whereIn('contract_id', $contractIds)
                    ->delete();
            }

            ContractPriceDailyStatistic::whereDate('stat_date', $dateString)->delete();

            $spotPrices = $this->spotPricesForDate($dateString);
            $snapshotCount = 0;

            ElectricityContract::query()
                ->whereIn('id', $contractIds)
                ->where(function ($q) {
                    $q->whereIn('target_group', [TargetGroup::Household->value, TargetGroup::Both->value])
                        ->orWhereNull('target_group');
                })
                ->orderBy('id')
                ->chunkById(200, function (Collection $contracts) use ($date, $dateString, $spotPrices, $useCanonical, &$snapshotCount) {
                    $canonicalOutcomes = $useCanonical
                        ? $this->canonicalPricing->outcomesForContractsAtConsumptions(
                            $contracts,
                            self::CONSUMPTION_LEVELS,
                            $this->canonicalSpotAssumptions($dateString),
                            $date,
                        )
                        : [];

                    foreach ($contracts as $contract) {
                        /** @var ElectricityContract $contract */
                        if ($useCanonical) {
                            $snapshot = $this->buildCanonicalSnapshot(
                                $contract,
                                $canonicalOutcomes[$contract->id] ?? [],
                            );

                            // An excluded or incomplete canonical result must not leave an
                            // identity-only row that a later consumer can mistake for pricing.
                            if (! $this->hasAnyNumericPrice($snapshot)) {
                                continue;
                            }
                        } else {
                            // Historical backfills and the feature-off forward path preserve
                            // observed relational prices exactly as before.
                            $components = $contract->getPriceComponentsForCalculationDate($dateString);
                            if ($components === []) {
                                continue;
                            }

                            $snapshot = $this->buildRelationalSnapshot($contract, $components, $date, $spotPrices);
                        }

                        ContractPriceSnapshot::updateOrCreate(
                            [
                                'snapshot_date' => $dateString,
                                'contract_id' => $contract->id,
                            ],
                            $snapshot,
                        );

                        $snapshotCount++;
                    }
                });

            $statisticsCount = $this->calculateDailyStatistics($dateString, $pricingBasis);

            return ['snapshots' => $snapshotCount, 'statistics' => $statisticsCount];
        });
    }

    /**
     * Return all dates that have imported contract price components.
     *
     * @return Collection<int, string>
     */
    public function availablePriceComponentDates(?string $from = null, ?string $to = null): Collection
    {
        return PriceComponent::query()
            ->select('price_date')
            ->when($from, fn ($query) => $query->whereDate('price_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('price_date', '<=', $to))
            ->distinct()
            ->orderBy('price_date')
            ->pluck('price_date')
            ->map(fn ($date) => $date instanceof CarbonInterface ? $date->toDateString() : (string) $date);
    }

    /**
     * @return Collection<int, string>
     */
    public function contractIdsWithPricesForDate(CarbonInterface|string $date): Collection
    {
        $dateString = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;

        return PriceComponent::query()
            ->whereDate('price_date', $dateString)
            ->distinct()
            ->pluck('electricity_contract_id');
    }

    /**
     * Build the forward snapshot only from typed canonical outcomes. The 5,000 kWh
     * outcome supplies current unit facts; each reference outcome supplies its own total.
     * A package has a meaningful package fee, but its excess-use rate is not an all-in
     * energy price and is therefore not stored in `energy_price_cents_per_kwh`.
     *
     * @param  array<int, CanonicalPricingOutcome>  $outcomes
     * @return array<string, mixed>
     */
    private function buildCanonicalSnapshot(ElectricityContract $contract, array $outcomes): array
    {
        $current = $outcomes[5000] ?? reset($outcomes) ?: null;
        $isListed = $current?->isListed() ?? false;
        $isPackage = $current?->energyPackage !== null;
        $spotMargin = $isListed && ! $isPackage ? $current->spotPriceMargin : null;
        $spotMarketPrice = $isListed && ! $isPackage ? $this->representativeSpotPrice($current) : null;
        $energyPrice = $isListed && ! $isPackage
            ? ($spotMargin !== null && $spotMarketPrice !== null
                ? $spotMarketPrice + $spotMargin
                : $this->representativeCanonicalEnergyPrice($current))
            : null;

        $annualCosts = [];
        foreach (self::CONSUMPTION_LEVELS as $consumption) {
            $outcome = $outcomes[$consumption] ?? null;
            $annualCosts[$consumption] = $contract->isConsumptionInRange($consumption)
                && $outcome?->isListed()
                    ? $outcome->totalCost
                    : null;
        }

        return [
            ...$this->snapshotIdentity($contract, ContractPriceBasis::CanonicalCalculation),
            'pricing_basis' => ContractPriceBasis::CanonicalCalculation->value,
            'energy_price_cents_per_kwh' => $energyPrice,
            'spot_margin_cents_per_kwh' => $spotMargin,
            'spot_total_energy_price_cents_per_kwh' => $spotMargin !== null && $spotMarketPrice !== null
                ? $spotMarketPrice + $spotMargin
                : null,
            'monthly_fee_eur' => $isListed
                ? ($current->energyPackage?->monthlyFeeEur ?? $current->monthlyFixedFee)
                : null,
            'annual_cost_2000_kwh' => $annualCosts[2000],
            'annual_cost_5000_kwh' => $annualCosts[5000],
            'annual_cost_18000_kwh' => $annualCosts[18000],
            'has_discount' => $isListed && $current->discountSavingsTotal() > 0,
            'includes_spot_price' => $spotMargin !== null && $spotMarketPrice !== null,
        ];
    }

    /**
     * The historical and feature-off calculation path. Keep this relational:
     * historical rows record what the seller published on that date and must not be
     * reinterpreted with today's canonical phases.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @param  array{avg:?float,day:?float,night:?float}  $spotPrices
     * @return array<string, mixed>
     */
    private function buildRelationalSnapshot(ElectricityContract $contract, array $components, CarbonInterface $date, array $spotPrices): array
    {
        $byType = collect($components)->keyBy('price_component_type');
        $isSpot = $contract->pricingModelType() === PricingModel::Spot;
        $monthlyFee = $byType->has('Monthly') ? (float) $byType['Monthly']['price'] : null;
        $spotMargin = $isSpot ? $this->firstEnergyComponentPrice($components) : null;
        $energyPrice = $this->representativeEnergyPrice($contract, $components, $spotPrices['avg']);
        $spotTotalEnergyPrice = $isSpot && $spotMargin !== null && $spotPrices['avg'] !== null
            ? $spotPrices['avg'] + $spotMargin
            : null;

        // The historical Spot annual estimate uses the trailing-365-day average
        // as of the observed date. Keep this legacy calculation unchanged.
        $annualSpotPrice = $isSpot
            ? $this->spotRolling365ForDate($date->toDateString())
            : ($spotPrices['avg'] ?? null);

        $annualCosts = [];
        foreach (self::CONSUMPTION_LEVELS as $consumption) {
            if (! $contract->isConsumptionInRange($consumption) || ($isSpot && $annualSpotPrice === null)) {
                $annualCosts[$consumption] = null;

                continue;
            }

            $usage = new EnergyUsage(total: $consumption, basicLiving: $consumption);
            $result = $this->calculator->calculate(
                $components,
                [
                    'contract_type' => $contract->contract_type,
                    'pricing_model' => $contract->pricing_model,
                    'metering' => $contract->metering,
                ],
                $usage,
                $isSpot ? $annualSpotPrice : ($spotPrices['day'] ?? $spotPrices['avg']),
                $isSpot ? $annualSpotPrice : ($spotPrices['night'] ?? $spotPrices['avg']),
                $date,
            );

            $annualCosts[$consumption] = $result->totalCost;
        }

        return [
            ...$this->snapshotIdentity($contract, ContractPriceBasis::ObservedSellerData),
            'pricing_basis' => ContractPriceBasis::ObservedSellerData->value,
            'energy_price_cents_per_kwh' => $energyPrice,
            'spot_margin_cents_per_kwh' => $spotMargin,
            'spot_total_energy_price_cents_per_kwh' => $spotTotalEnergyPrice,
            'monthly_fee_eur' => $monthlyFee,
            'annual_cost_2000_kwh' => $annualCosts[2000],
            'annual_cost_5000_kwh' => $annualCosts[5000],
            'annual_cost_18000_kwh' => $annualCosts[18000],
            'has_discount' => collect($components)->contains(fn ($component) => (bool) ($component['has_discount'] ?? false)),
            'includes_spot_price' => $isSpot && $spotPrices['avg'] !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotIdentity(ElectricityContract $contract, ContractPriceBasis $basis): array
    {
        return [
            'company_name' => $contract->company_name,
            'contract_name' => $contract->name,
            'pricing_model' => $contract->pricing_model,
            'contract_type' => $contract->contract_type,
            'fixed_time_range' => $contract->fixed_time_range,
            'metering' => $contract->metering,
            'segment_key' => $this->segmentClassifier->classify($contract, $basis),
        ];
    }

    private function representativeCanonicalEnergyPrice(CanonicalPricingOutcome $outcome): ?float
    {
        if ($outcome->generalKwhPrice !== null) {
            return $outcome->generalKwhPrice;
        }

        if ($outcome->daytimeKwhPrice !== null && $outcome->nighttimeKwhPrice !== null) {
            return ($outcome->daytimeKwhPrice * 15 + $outcome->nighttimeKwhPrice * 9) / 24;
        }

        if ($outcome->seasonalWinterDayKwhPrice !== null && $outcome->seasonalOtherKwhPrice !== null) {
            return ($outcome->seasonalWinterDayKwhPrice * 5 + $outcome->seasonalOtherKwhPrice * 7) / 12;
        }

        return null;
    }

    private function representativeSpotPrice(CanonicalPricingOutcome $outcome): ?float
    {
        if ($outcome->spotPriceDayAvg === null || $outcome->spotPriceNightAvg === null) {
            return null;
        }

        return ($outcome->spotPriceDayAvg * 15 + $outcome->spotPriceNightAvg * 9) / 24;
    }

    /** @param array<string, mixed> $snapshot */
    private function hasAnyNumericPrice(array $snapshot): bool
    {
        foreach ([
            'energy_price_cents_per_kwh',
            'spot_margin_cents_per_kwh',
            'spot_total_energy_price_cents_per_kwh',
            'monthly_fee_eur',
            'annual_cost_2000_kwh',
            'annual_cost_5000_kwh',
            'annual_cost_18000_kwh',
        ] as $key) {
            if ($snapshot[$key] !== null) {
                return true;
            }
        }

        return false;
    }

    private function calculateDailyStatistics(string $dateString, string $pricingBasis): int
    {
        ContractPriceDailyStatistic::whereDate('stat_date', $dateString)->delete();

        $snapshots = ContractPriceSnapshot::query()
            ->whereDate('snapshot_date', $dateString)
            ->where('pricing_basis', $pricingBasis)
            ->get();
        $count = 0;

        foreach ($snapshots->groupBy('segment_key') as $segmentKey => $segmentSnapshots) {
            $metrics = [
                ['energy_price', null, $segmentSnapshots->pluck('energy_price_cents_per_kwh')->all()],
                ['spot_margin', null, $segmentSnapshots->pluck('spot_margin_cents_per_kwh')->all()],
                ['spot_total_energy_price', null, $segmentSnapshots->pluck('spot_total_energy_price_cents_per_kwh')->all()],
                ['monthly_fee', null, $segmentSnapshots->pluck('monthly_fee_eur')->all()],
                ['annual_cost', 2000, $segmentSnapshots->pluck('annual_cost_2000_kwh')->all()],
                ['annual_cost', 5000, $segmentSnapshots->pluck('annual_cost_5000_kwh')->all()],
                ['annual_cost', 18000, $segmentSnapshots->pluck('annual_cost_18000_kwh')->all()],
            ];

            foreach ($metrics as [$metricKey, $consumption, $values]) {
                $values = $this->cleanValues($values, $metricKey);
                if ($values === []) {
                    continue;
                }

                $stats = $this->stats($values);
                ContractPriceDailyStatistic::create([
                    'stat_date' => $dateString,
                    'segment_key' => $segmentKey,
                    'metric_key' => $metricKey,
                    'pricing_basis' => $pricingBasis,
                    'consumption_kwh' => $consumption,
                    'min_value' => $stats['min'],
                    'p20_value' => $stats['p20'],
                    'avg_value' => $stats['avg'],
                    'median_value' => $stats['median'],
                    'p80_value' => $stats['p80'],
                    'max_value' => $stats['max'],
                    'contract_count' => count($values),
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Drop nulls, values that round to zero (for cost-bearing metrics), and
     * values above a per-metric sanity ceiling. Both indicate broken or
     * mis-imported source data rather than a real Finnish retail price.
     *
     * Ceilings are deliberately generous: real Finnish retail energy never
     * exceeds 50 c/kWh, daily-average spot-totals never exceed 100 c/kWh,
     * supplier margins never exceed 5 c/kWh, monthly fees never exceed 30 €/kk,
     * annual costs at the published consumption levels never exceed 50 000 €/v.
     * Anything past these bounds has been a unit-import error in practice.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, float>
     */
    private function cleanValues(array $values, string $metricKey): array
    {
        $treatZeroAsMissing = $metricKey !== 'monthly_fee';
        $upperBound = match ($metricKey) {
            'energy_price' => 50.0,
            'spot_margin' => 5.0,
            'spot_total_energy_price' => 100.0,
            'monthly_fee' => 30.0,
            'annual_cost' => 50000.0,
            default => INF,
        };

        return array_values(array_filter(array_map(
            fn ($value) => $value === null ? null : (float) $value,
            $values
        ), function ($value) use ($treatZeroAsMissing, $upperBound) {
            if ($value === null) {
                return false;
            }
            if ($treatZeroAsMissing && $value < 0.005) {
                return false;
            }
            if ($value > $upperBound) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param  array<int, float>  $values
     * @return array{min:float,p20:float,median:float,avg:float,p80:float,max:float}
     */
    private function stats(array $values): array
    {
        sort($values);

        return [
            'min' => $values[0],
            'p20' => $this->percentile($values, 20),
            'median' => $this->percentile($values, 50),
            'avg' => array_sum($values) / count($values),
            'p80' => $this->percentile($values, 80),
            'max' => $values[array_key_last($values)],
        ];
    }

    /**
     * @param  array<int, float>  $sortedValues
     */
    private function percentile(array $sortedValues, float $percentile): float
    {
        $n = count($sortedValues);
        if ($n === 1) {
            return $sortedValues[0];
        }

        $index = ($percentile / 100) * ($n - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;

        return $sortedValues[$lower] * (1 - $weight) + $sortedValues[$upper] * $weight;
    }

    /**
     * Trailing-365-day spot price average as of the given snapshot date.
     * Used to project a realistic annual cost for spot contracts (rather than
     * extrapolating a single peak/trough day to a full year).
     *
     * Prefers a stored `rolling_365d` row whose `period_start <= $dateString`,
     * falls back to averaging the last 365 days of `SpotPriceHour.price_with_tax`.
     */
    private function spotRolling365ForDate(string $dateString): ?float
    {
        if (isset($this->rolling365Cache[$dateString])) {
            return $this->rolling365Cache[$dateString];
        }

        $stored = SpotPriceAverage::forRegion('FI')
            ->ofType(SpotPriceAverage::PERIOD_ROLLING_365D)
            ->where('period_start', '<=', $dateString)
            ->orderByDesc('period_start')
            ->first();

        if ($stored && $stored->avg_price_with_tax !== null) {
            return $this->rolling365Cache[$dateString] = (float) $stored->avg_price_with_tax;
        }

        $end = Carbon::parse($dateString)->endOfDay();
        $start = $end->copy()->subDays(365)->startOfDay();

        $rows = SpotPriceHour::forRegion('FI')
            ->whereBetween('utc_datetime', [$start, $end])
            ->get(['price_without_tax', 'vat_rate']);

        if ($rows->isEmpty()) {
            return $this->rolling365Cache[$dateString] = null;
        }

        $avg = (float) $rows->avg(fn ($hour) => $hour->price_with_tax);

        return $this->rolling365Cache[$dateString] = $avg;
    }

    /** @var array<string, ?float> */
    private array $rolling365Cache = [];

    private function canonicalSpotAssumptions(string $dateString): SpotAssumptions
    {
        $rollingAverage = $this->spotRolling365ForDate($dateString);

        return new SpotAssumptions(
            dayAvgWithTax: $rollingAverage,
            nightAvgWithTax: $rollingAverage,
        );
    }

    /**
     * @return array{avg:?float,day:?float,night:?float}
     */
    private function spotPricesForDate(string $dateString): array
    {
        $average = SpotPriceAverage::forDate($dateString);
        if ($average) {
            return [
                'avg' => $average->avg_price_with_tax,
                'day' => $average->day_avg_with_tax,
                'night' => $average->night_avg_with_tax,
            ];
        }

        $hours = SpotPriceHour::forRegion('FI')->whereDate('utc_datetime', $dateString)->get();
        if ($hours->isEmpty()) {
            return ['avg' => null, 'day' => null, 'night' => null];
        }

        return [
            'avg' => $hours->avg(fn ($hour) => $hour->price_with_tax),
            'day' => null,
            'night' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     */
    private function representativeEnergyPrice(ElectricityContract $contract, array $components, ?float $spotAverage): ?float
    {
        $byType = collect($components)->keyBy('price_component_type');

        if ($contract->pricingModelType() === PricingModel::Spot) {
            $margin = $this->firstEnergyComponentPrice($components);

            return $margin !== null && $spotAverage !== null ? $spotAverage + $margin : null;
        }

        if ($byType->has('General')) {
            return (float) $byType['General']['price'];
        }

        if ($byType->has('DayTime') || $byType->has('NightTime')) {
            $day = (float) ($byType['DayTime']['price'] ?? 0);
            $night = (float) ($byType['NightTime']['price'] ?? $day);

            return ($day * 15 + $night * 9) / 24;
        }

        if ($byType->has('SeasonalWinterDay') || $byType->has('SeasonalOther')) {
            $winter = (float) ($byType['SeasonalWinterDay']['price'] ?? 0);
            $other = (float) ($byType['SeasonalOther']['price'] ?? $winter);

            return ($winter * 5 + $other * 7) / 12;
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     */
    private function firstEnergyComponentPrice(array $components): ?float
    {
        foreach ($components as $component) {
            if (($component['price_component_type'] ?? '') !== 'Monthly') {
                return (float) ($component['price'] ?? 0);
            }
        }

        return null;
    }
}
