<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Livewire\Component;

class ContractDetail extends Component
{
    /**
     * The contract ID.
     */
    public string $contractId;

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
     * Mount the component.
     */
    public function mount(string $contractId): void
    {
        $this->contractId = $contractId;
    }

    /**
     * Set the consumption to a preset value.
     */
    public function setConsumption(int $value): void
    {
        $this->consumption = $value;
    }

    /**
     * Get the contract with all relations.
     */
    public function getContractProperty(): ?ElectricityContract
    {
        return ElectricityContract::query()
            ->with(['company', 'priceComponents', 'electricitySource'])
            ->find($this->contractId);
    }

    /**
     * Get the calculated cost for the contract.
     */
    public function getCalculatedCostProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $calculator = app(ContractPriceCalculator::class);

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

        return $calculator->calculate($priceComponents, $contractData, $usage)->toArray();
    }

    /**
     * Get the latest price components for the contract.
     *
     * @return array<string, array{price: float, unit: string}>
     */
    public function getLatestPricesProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

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

    /**
     * Get the price history for the contract.
     *
     * @return array<string, array<array{date: string, price: float}>>
     */
    public function getPriceHistoryProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $history = [];

        foreach ($contract->priceComponents->sortByDesc('price_date')->groupBy('price_component_type') as $type => $components) {
            $history[$type] = $components->map(fn ($pc) => [
                'date' => $pc->price_date->format('Y-m-d'),
                'price' => $pc->price,
            ])->values()->toArray();
        }

        return $history;
    }

    public function render()
    {
        $contract = $this->contract;

        if (! $contract) {
            abort(404);
        }

        return view('livewire.contract-detail', [
            'contract' => $contract,
            'latestPrices' => $this->latestPrices,
            'calculatedCost' => $this->calculatedCost,
            'priceHistory' => $this->priceHistory,
        ])->layout('layouts.app');
    }
}
