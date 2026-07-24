<?php

namespace App\Services\ContractStatistics;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use App\Models\SpotPriceHour;
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
    ) {
    }

    /**
     * Calculate per-contract snapshots and aggregate statistics for one date.
     *
     * @param iterable<string> $contractIds
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
            if ($overwrite) {
                ContractPriceSnapshot::whereDate('snapshot_date', $dateString)->delete();
                ContractPriceDailyStatistic::whereDate('stat_date', $dateString)->delete();
            }

            $spotPrices = $this->spotPricesForDate($dateString);
            $snapshotCount = 0;

            ElectricityContract::query()
                ->whereIn('id', $contractIds)
                ->where(function ($q) {
                    $q->whereIn('target_group', ['Household', 'Both'])
                        ->orWhereNull('target_group');
                })
                ->orderBy('id')
                ->chunkById(200, function (Collection $contracts) use ($date, $dateString, $spotPrices, $useCanonical, &$snapshotCount) {
                    foreach ($contracts as $contract) {
                        /** @var ElectricityContract $contract */
                        $components = $contract->getPriceComponentsForCalculationDate($dateString);

                        if ($components === []) {
                            continue;
                        }

                        $snapshot = $this->buildSnapshot($contract, $components, $date, $spotPrices, $useCanonical);

                        ContractPriceSnapshot::updateOrCreate(
                            [
                                'snapshot_date' => $dateString,
                                'contract_id' => $contract->id,
                            ],
                            $snapshot
                        );

                        $snapshotCount++;
                    }
                });

            $statisticsCount = $this->calculateDailyStatistics($dateString);

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
     * @param array<int, array<string, mixed>> $components
     * @param array{avg:?float,day:?float,night:?float} $spotPrices
     * @return array<string, mixed>
     */
    private function buildSnapshot(ElectricityContract $contract, array $components, CarbonInterface $date, array $spotPrices, bool $useCanonical = false): array
    {
        $byType = collect($components)->keyBy('price_component_type');
        $segmentKey = $this->segmentKey($contract);
        $isSpot = $contract->pricing_model === 'Spot';
        $monthlyFee = $byType->has('Monthly') ? (float) $byType['Monthly']['price'] : null;
        $spotMargin = $isSpot ? $this->firstEnergyComponentPrice($components) : null;
        $energyPrice = $this->representativeEnergyPrice($contract, $components, $spotPrices['avg']);
        $spotTotalEnergyPrice = $isSpot && $spotMargin !== null && $spotPrices['avg'] !== null
            ? $spotPrices['avg'] + $spotMargin
            : null;

        // For spot annual-cost projection use the trailing-365-day spot
        // average as of the snapshot date, not that day's spot avg. A real
        // spot customer's yearly bill is smoothed across the full year, not
        // determined by a single peak day. The c/kWh spot_total field above
        // remains today's price + margin so the deep-dive c/kWh chart still
        // shows real-time market movement.
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

            if ($useCanonical) {
                $spot = new \App\Services\CanonicalPricing\DTO\SpotAssumptions(
                    dayAvgWithTax: $isSpot ? $annualSpotPrice : ($spotPrices['day'] ?? $spotPrices['avg']),
                    nightAvgWithTax: $isSpot ? $annualSpotPrice : ($spotPrices['night'] ?? $spotPrices['avg']),
                );
                $outcome = $this->canonicalPricing->evaluate($contract, $usage, $spot, $date)['outcome'];
                // Excluded contracts contribute no annual cost, mirroring spot-missing handling.
                $annualCosts[$consumption] = $outcome->isListed() ? $outcome->totalCost : null;

                continue;
            }

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
            'company_name' => $contract->company_name,
            'contract_name' => $contract->name,
            'pricing_model' => $contract->pricing_model,
            'contract_type' => $contract->contract_type,
            'fixed_time_range' => $contract->fixed_time_range,
            'metering' => $contract->metering,
            'segment_key' => $segmentKey,
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

    private function calculateDailyStatistics(string $dateString): int
    {
        ContractPriceDailyStatistic::whereDate('stat_date', $dateString)->delete();

        $snapshots = ContractPriceSnapshot::whereDate('snapshot_date', $dateString)->get();
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
     * @param array<int, mixed> $values
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
     * @param array<int, float> $values
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
     * @param array<int, float> $sortedValues
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
     * @param array<int, array<string, mixed>> $components
     */
    private function representativeEnergyPrice(ElectricityContract $contract, array $components, ?float $spotAverage): ?float
    {
        $byType = collect($components)->keyBy('price_component_type');

        if ($contract->pricing_model === 'Spot') {
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
     * @param array<int, array<string, mixed>> $components
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

    private function segmentKey(ElectricityContract $contract): string
    {
        if ($contract->pricing_model === 'Spot') {
            return 'spot';
        }

        if ($contract->pricing_model === 'Hybrid') {
            return 'hybrid';
        }

        if ($this->isQuarterly($contract)) {
            return 'quarterly';
        }

        if ($contract->contract_type === 'FixedTerm') {
            return 'fixed_term_' . match ($contract->fixed_time_range) {
                'Below6' => 'below6',
                'Fixed6' => '6',
                'Between711' => '7_11',
                'Fixed12' => '12',
                'Between1323' => '13_23',
                'Fixed24' => '24',
                'Over24' => 'over24',
                default => 'other',
            };
        }

        if ($contract->contract_type === 'OpenEnded') {
            return 'open_ended';
        }

        return 'other';
    }

    private function isQuarterly(ElectricityContract $contract): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $contract->name,
            $contract->extra_information_fi,
            $contract->short_description,
            $contract->long_description,
        ])));

        return str_contains($haystack, 'kvartaali')
            || str_contains($haystack, 'kolmen kuukauden jaksoissa')
            || str_contains($haystack, 'kolmen kuukauden jaksolle')
            || str_contains($haystack, 'kolmen kuukauden välein');
    }
}
