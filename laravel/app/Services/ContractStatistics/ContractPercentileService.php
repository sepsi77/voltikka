<?php

namespace App\Services\ContractStatistics;

use App\Models\ActiveContract;
use App\Models\ContractPercentile;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\DTO\EnergyUsage;

class ContractPercentileService
{
    public function __construct(
        private readonly CanonicalContractPricingService $canonicalPricing,
    ) {}

    public function calculate(): ContractPercentileResult
    {
        $activeIds = ActiveContract::query()->pluck('id');

        if ($activeIds->isEmpty()) {
            return new ContractPercentileResult(0, [], []);
        }

        $metrics = [
            'spot_margin' => [],
            'fixed_energy' => [],
            'seasonal_winter' => [],
            'seasonal_other' => [],
            'time_day' => [],
            'time_night' => [],
            'monthly_fee' => [],
        ];
        $useCanonical = $this->canonicalPricing->enabled();
        $percentileUsage = new EnergyUsage(total: 5000, basicLiving: 5000);

        ElectricityContract::query()
            ->whereIn('id', $activeIds)
            ->chunkById(100, function ($contracts) use (&$metrics, $useCanonical, $percentileUsage): void {
                foreach ($contracts as $contract) {
                    /** @var ElectricityContract $contract */
                    if ($useCanonical) {
                        $evaluation = $this->canonicalPricing->evaluate($contract, $percentileUsage);
                        if (! $evaluation['outcome']->isListed() || $evaluation['integrity']->detected) {
                            continue;
                        }
                    }

                    $byType = collect($contract->getLatestPriceComponentsForCalculation())
                        ->keyBy('price_component_type');

                    if ($byType->has('Monthly')) {
                        $metrics['monthly_fee'][] = (float) $byType['Monthly']['price'];
                    }

                    switch ($contract->pricing_model) {
                        case 'Spot':
                            $this->appendMetric($metrics['spot_margin'], $byType, 'General');
                            break;
                        case 'FixedPrice':
                            $this->appendMetric($metrics['fixed_energy'], $byType, 'General');
                            break;
                        case 'Seasonal':
                            $this->appendSeasonalMetrics($metrics, $byType);
                            break;
                        case 'TimeOfUse':
                            $this->appendTimeMetrics($metrics, $byType);
                            break;
                    }
                }
            });

        $now = now();
        $calculated = [];
        $skipped = [];
        ContractPercentile::query()->delete();

        foreach ($metrics as $component => $values) {
            if (count($values) < 10) {
                $skipped[$component] = count($values);

                continue;
            }

            sort($values);
            $count = count($values);
            $p15 = $this->percentile($values, 15);
            $p85 = $this->percentile($values, 85);

            ContractPercentile::create([
                'component' => $component,
                'p15' => $p15,
                'p85' => $p85,
                'count' => $count,
                'calculated_at' => $now,
            ]);
            $calculated[] = compact('component', 'count', 'p15', 'p85');
        }

        return new ContractPercentileResult($activeIds->count(), $calculated, $skipped);
    }

    private function appendMetric(array &$values, $byType, string $type): void
    {
        if ($byType->has($type)) {
            $values[] = (float) $byType[$type]['price'];
        }
    }

    private function appendSeasonalMetrics(array &$metrics, $byType): void
    {
        $this->appendMetric($metrics['seasonal_winter'], $byType, 'SeasonalWinterDay');
        $this->appendMetric($metrics['seasonal_other'], $byType, 'SeasonalOther');
    }

    private function appendTimeMetrics(array &$metrics, $byType): void
    {
        $this->appendMetric($metrics['time_day'], $byType, 'DayTime');
        $this->appendMetric($metrics['time_night'], $byType, 'NightTime');
    }

    /**
     * Calculate a percentile of a sorted array with linear interpolation.
     *
     * @param  list<float>  $sortedValues
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

        if ($upper >= $n) {
            return $sortedValues[$lower];
        }

        return $sortedValues[$lower] * (1 - $weight) + $sortedValues[$upper] * $weight;
    }
}
