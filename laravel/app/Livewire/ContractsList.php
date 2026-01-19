<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class ContractsList extends Component
{
    /**
     * Current consumption value in kWh.
     */
    public int $consumption = 5000;

    /**
     * Available consumption presets.
     *
     * @var array<string, int>
     */
    public array $presets = [
        'Yksiö' => 2000,
        'Kerrostalo' => 5000,
        'Pieni talo' => 10000,
        'Suuri talo' => 18000,
    ];

    /**
     * Set the consumption to a preset value.
     */
    public function setConsumption(int $value): void
    {
        $this->consumption = $value;
    }

    /**
     * Get contracts with calculated costs.
     */
    public function getContractsProperty(): Collection
    {
        $calculator = app(ContractPriceCalculator::class);

        $contracts = ElectricityContract::query()
            ->with(['company', 'priceComponents', 'electricitySource'])
            ->get();

        // Calculate cost for each contract and sort by cost
        $contracts = $contracts->map(function ($contract) use ($calculator) {
            $priceComponents = $contract->priceComponents
                ->sortByDesc('price_date')
                ->groupBy('price_component_type')
                ->map(fn ($group) => $group->first())
                ->values()
                ->map(fn ($pc) => [
                    'price_component_type' => $pc->price_component_type,
                    'price' => $pc->price,
                ])
                ->toArray();

            $usage = new EnergyUsage(
                total: $this->consumption,
                basicLiving: $this->consumption,
            );

            $contractData = [
                'contract_type' => $contract->contract_type,
                'metering' => $contract->metering,
            ];

            $result = $calculator->calculate($priceComponents, $contractData, $usage);
            $contract->calculated_cost = $result->toArray();

            return $contract;
        });

        // Sort by total cost (ascending)
        return $contracts->sortBy(fn ($c) => $c->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX)->values();
    }

    /**
     * Get the latest price components for a contract.
     */
    public function getLatestPrices(ElectricityContract $contract): array
    {
        $prices = [];

        foreach ($contract->priceComponents->sortByDesc('price_date')->groupBy('price_component_type') as $type => $components) {
            $latest = $components->first();
            $prices[$type] = [
                'price' => $latest->price,
                'unit' => $latest->payment_unit,
            ];
        }

        return $prices;
    }

    public function render()
    {
        return view('livewire.contracts-list', [
            'contracts' => $this->contracts,
        ])->layout('layouts.app');
    }
}
