<?php

namespace App\Console\Commands;

use App\Models\ActiveContract;
use App\Models\ContractPercentile;
use App\Models\ElectricityContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateContractPercentiles extends Command
{
    protected $signature = 'contracts:calculate-percentiles';

    protected $description = 'Calculate P15/P85 percentiles for contract pricing components';

    public function handle(): int
    {
        $this->info('Calculating contract pricing percentiles...');

        $activeIds = ActiveContract::pluck('id');

        if ($activeIds->isEmpty()) {
            $this->warn('No active contracts found.');
            return self::SUCCESS;
        }

        // Load all active contracts with their latest price components
        $contracts = ElectricityContract::whereIn('id', $activeIds)
            ->with(['priceComponents'])
            ->get();

        $metrics = [
            'spot_margin' => [],
            'fixed_energy' => [],
            'seasonal_winter' => [],
            'seasonal_other' => [],
            'time_day' => [],
            'time_night' => [],
            'monthly_fee' => [],
        ];

        foreach ($contracts as $contract) {
            $latest = $contract->getLatestPriceComponentsForCalculation();
            $byType = collect($latest)->keyBy('price_component_type');

            // Monthly fee is collected for all contracts
            if ($byType->has('Monthly')) {
                $metrics['monthly_fee'][] = (float) $byType['Monthly']['price'];
            }

            switch ($contract->pricing_model) {
                case 'Spot':
                    if ($byType->has('General')) {
                        $metrics['spot_margin'][] = (float) $byType['General']['price'];
                    }
                    break;

                case 'FixedPrice':
                    if ($byType->has('General')) {
                        $metrics['fixed_energy'][] = (float) $byType['General']['price'];
                    }
                    break;

                case 'Seasonal':
                    if ($byType->has('SeasonalWinterDay')) {
                        $metrics['seasonal_winter'][] = (float) $byType['SeasonalWinterDay']['price'];
                    }
                    if ($byType->has('SeasonalOther')) {
                        $metrics['seasonal_other'][] = (float) $byType['SeasonalOther']['price'];
                    }
                    break;

                case 'TimeOfUse':
                    if ($byType->has('DayTime')) {
                        $metrics['time_day'][] = (float) $byType['DayTime']['price'];
                    }
                    if ($byType->has('NightTime')) {
                        $metrics['time_night'][] = (float) $byType['NightTime']['price'];
                    }
                    break;
            }
        }

        $now = now();
        ContractPercentile::query()->delete();

        foreach ($metrics as $component => $values) {
            if (count($values) < 10) {
                $this->warn("Skipping {$component}: only " . count($values) . " values (need >= 10)");
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

            $this->info(sprintf(
                '%s: n=%d, P15=%.4f, P85=%.4f',
                $component,
                $count,
                $p15,
                $p85
            ));
        }

        $this->info('Done.');
        return self::SUCCESS;
    }

    /**
     * Calculate the percentile of a sorted array using linear interpolation.
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
