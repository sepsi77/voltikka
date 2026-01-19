<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use App\Models\Postcode;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class ContractsList extends Component
{
    /**
     * Current consumption value in kWh.
     */
    #[Url]
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
     * Contract type filter (Fixed, Spot, OpenEnded).
     */
    #[Url]
    public string $contractTypeFilter = '';

    /**
     * Metering type filter (General, Time, Seasonal).
     */
    #[Url]
    public string $meteringFilter = '';

    /**
     * Postcode filter for availability.
     */
    #[Url]
    public string $postcodeFilter = '';

    /**
     * Postcode search input for suggestions.
     */
    public string $postcodeSearch = '';

    /**
     * Filter for renewable energy (>= 50%).
     */
    #[Url]
    public bool $renewableFilter = false;

    /**
     * Filter for contracts with nuclear energy.
     */
    #[Url]
    public bool $nuclearFilter = false;

    /**
     * Filter for fossil-free contracts.
     */
    #[Url]
    public bool $fossilFreeFilter = false;

    /**
     * Available contract types.
     *
     * @var array<string, string>
     */
    public array $contractTypes = [
        'Fixed' => 'Määräaikainen',
        'Spot' => 'Pörssisähkö',
        'OpenEnded' => 'Toistaiseksi voimassa',
    ];

    /**
     * Available metering types.
     *
     * @var array<string, string>
     */
    public array $meteringTypes = [
        'General' => 'Yleismittarointi',
        'Time' => 'Aikamittarointi',
        'Seasonal' => 'Kausimittarointi',
    ];

    /**
     * Set the consumption to a preset value.
     */
    public function setConsumption(int $value): void
    {
        $this->consumption = $value;
    }

    /**
     * Set the contract type filter.
     */
    public function setContractTypeFilter(string $type): void
    {
        $this->contractTypeFilter = $this->contractTypeFilter === $type ? '' : $type;
    }

    /**
     * Set the metering type filter.
     */
    public function setMeteringFilter(string $type): void
    {
        $this->meteringFilter = $this->meteringFilter === $type ? '' : $type;
    }

    /**
     * Set the postcode filter and clear search.
     */
    public function selectPostcode(string $postcode): void
    {
        $this->postcodeFilter = $postcode;
        $this->postcodeSearch = '';
    }

    /**
     * Clear the postcode filter.
     */
    public function clearPostcodeFilter(): void
    {
        $this->postcodeFilter = '';
        $this->postcodeSearch = '';
    }

    /**
     * Toggle renewable filter.
     */
    public function toggleRenewableFilter(): void
    {
        $this->renewableFilter = !$this->renewableFilter;
    }

    /**
     * Toggle nuclear filter.
     */
    public function toggleNuclearFilter(): void
    {
        $this->nuclearFilter = !$this->nuclearFilter;
    }

    /**
     * Toggle fossil-free filter.
     */
    public function toggleFossilFreeFilter(): void
    {
        $this->fossilFreeFilter = !$this->fossilFreeFilter;
    }

    /**
     * Reset all filters to their default values.
     */
    public function resetFilters(): void
    {
        $this->contractTypeFilter = '';
        $this->meteringFilter = '';
        $this->postcodeFilter = '';
        $this->postcodeSearch = '';
        $this->renewableFilter = false;
        $this->nuclearFilter = false;
        $this->fossilFreeFilter = false;
    }

    /**
     * Get postcode suggestions based on search input.
     */
    public function getPostcodeSuggestionsProperty(): Collection
    {
        if (strlen($this->postcodeSearch) < 2) {
            return new Collection();
        }

        return Postcode::query()
            ->search($this->postcodeSearch)
            ->limit(10)
            ->get();
    }

    /**
     * Check if any filters are active.
     */
    public function hasActiveFilters(): bool
    {
        return $this->contractTypeFilter !== ''
            || $this->meteringFilter !== ''
            || $this->postcodeFilter !== ''
            || $this->renewableFilter
            || $this->nuclearFilter
            || $this->fossilFreeFilter;
    }

    /**
     * Get contracts with calculated costs.
     */
    public function getContractsProperty(): Collection
    {
        $calculator = app(ContractPriceCalculator::class);

        $query = ElectricityContract::query()
            ->with(['company', 'priceComponents', 'electricitySource', 'availabilityPostcodes']);

        // Apply contract type filter
        if ($this->contractTypeFilter !== '') {
            $query->where('contract_type', $this->contractTypeFilter);
        }

        // Apply metering type filter
        if ($this->meteringFilter !== '') {
            $query->where('metering', $this->meteringFilter);
        }

        $contracts = $query->get();

        // Apply postcode filter (needs to check availability_is_national or postcode match)
        if ($this->postcodeFilter !== '') {
            $contracts = $contracts->filter(function ($contract) {
                if ($contract->availability_is_national) {
                    return true;
                }
                return $contract->availabilityPostcodes->contains('postcode', $this->postcodeFilter);
            });
        }

        // Apply energy source filters
        if ($this->renewableFilter) {
            $contracts = $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;
                return $source && $source->renewable_total >= 50;
            });
        }

        if ($this->nuclearFilter) {
            $contracts = $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;
                return $source && $source->hasNuclear();
            });
        }

        if ($this->fossilFreeFilter) {
            $contracts = $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;
                return $source && $source->isFossilFree();
            });
        }

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
            'postcodeSuggestions' => $this->postcodeSuggestions,
        ])->layout('layouts.app');
    }
}
