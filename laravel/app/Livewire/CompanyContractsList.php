<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use App\Models\Postcode;
use App\Models\SpotPriceAverage;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class CompanyContractsList extends Component
{
    /**
     * Currently selected preset key.
     */
    public ?string $selectedPreset = 'small_office';

    /**
     * Current consumption value in kWh.
     */
    #[Url]
    public int $consumption = 20000;

    /**
     * Available consumption presets for businesses.
     *
     * @var array<string, array{label: string, description: string, icon: string, consumption: int}>
     */
    public array $presets = [
        'small_office' => [
            'label' => 'Pieni toimisto',
            'description' => '5-10 hlö, 150 m²',
            'icon' => 'office',
            'consumption' => 20000,
        ],
        'medium_office' => [
            'label' => 'Keskikokoinen toimisto',
            'description' => '20-50 hlö, 500 m²',
            'icon' => 'office',
            'consumption' => 50000,
        ],
        'large_office' => [
            'label' => 'Suuri toimisto',
            'description' => '100+ hlö, 1500 m²',
            'icon' => 'office',
            'consumption' => 150000,
        ],
        'small_retail' => [
            'label' => 'Pieni myymälä',
            'description' => '100-200 m²',
            'icon' => 'retail',
            'consumption' => 30000,
        ],
        'medium_retail' => [
            'label' => 'Keskisuuri myymälä',
            'description' => '500-1000 m²',
            'icon' => 'retail',
            'consumption' => 100000,
        ],
        'restaurant' => [
            'label' => 'Ravintola',
            'description' => '50-100 asiakaspaikkaa',
            'icon' => 'restaurant',
            'consumption' => 80000,
        ],
        'small_warehouse' => [
            'label' => 'Pieni varasto',
            'description' => '500 m²',
            'icon' => 'warehouse',
            'consumption' => 40000,
        ],
        'small_production' => [
            'label' => 'Pieni tuotantolaitos',
            'description' => 'Kevyt teollisuus',
            'icon' => 'factory',
            'consumption' => 200000,
        ],
    ];

    /**
     * Contract type filter (FixedTerm, OpenEnded).
     */
    #[Url]
    public string $contractTypeFilter = '';

    /**
     * Pricing model filter (Spot, FixedPrice, Hybrid).
     */
    #[Url]
    public string $pricingModelFilter = '';

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
     * Available contract types (duration).
     *
     * @var array<string, string>
     */
    public array $contractTypes = [
        'FixedTerm' => 'Määräaikainen',
        'OpenEnded' => 'Toistaiseksi',
    ];

    /**
     * Available pricing models.
     *
     * @var array<string, string>
     */
    public array $pricingModels = [
        'FixedPrice' => 'Kiinteä hinta',
        'Spot' => 'Pörssisähkö',
        'Hybrid' => 'Hybridi',
    ];

    /**
     * Available metering types.
     *
     * @var array<string, string>
     */
    public array $meteringTypes = [
        'General' => 'Yleismittarointi',
        'Time' => 'Aikamittarointi',
        'Season' => 'Kausimittarointi',
    ];

    /**
     * Select a preset and update consumption.
     */
    public function selectPreset(string $preset): void
    {
        $this->selectedPreset = $preset;

        if (isset($this->presets[$preset])) {
            $this->consumption = $this->presets[$preset]['consumption'];
        }
    }

    /**
     * Set the consumption to a specific value (clears preset selection).
     */
    public function setConsumption(int $value): void
    {
        $this->consumption = $value;
        $this->selectedPreset = null;
    }

    /**
     * Set the contract type filter.
     */
    public function setContractTypeFilter(string $type): void
    {
        $this->contractTypeFilter = $this->contractTypeFilter === $type ? '' : $type;
    }

    /**
     * Set the pricing model filter.
     */
    public function setPricingModelFilter(string $model): void
    {
        $this->pricingModelFilter = $this->pricingModelFilter === $model ? '' : $model;
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
        $this->pricingModelFilter = '';
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
            || $this->pricingModelFilter !== ''
            || $this->meteringFilter !== ''
            || $this->postcodeFilter !== ''
            || $this->renewableFilter
            || $this->nuclearFilter
            || $this->fossilFreeFilter;
    }

    /**
     * Get the count of unique companies in the current contract list.
     */
    public function getUniqueCompanyCount(): int
    {
        return $this->contracts->pluck('company_name')->unique()->count();
    }

    /**
     * Get the count of zero-emission contracts in the current list.
     */
    public function getZeroEmissionCount(): int
    {
        return $this->contracts->filter(function ($contract) {
            return $contract->electricitySource
                && ($contract->electricitySource->fossil_total ?? 0) == 0
                && (($contract->electricitySource->renewable_total ?? 0) > 0
                    || ($contract->electricitySource->nuclear_total ?? 0) > 0);
        })->count();
    }

    /**
     * Generate a dynamic page title based on active filters.
     */
    public function getPageTitleProperty(): string
    {
        $parts = [];

        // Energy source modifiers (come first as adjectives)
        $energySourcePrefix = '';
        if ($this->fossilFreeFilter) {
            $parts[] = 'Fossiilittomat';
        } elseif ($this->renewableFilter) {
            $parts[] = 'Uusiutuvat';
        } elseif ($this->nuclearFilter) {
            $energySourcePrefix = 'ydinvoima';
        }

        // Contract type modifier
        if ($this->contractTypeFilter === 'FixedTerm') {
            $parts[] = 'Määräaikaiset';
        } elseif ($this->contractTypeFilter === 'OpenEnded') {
            $parts[] = 'Toistaiseksi voimassa olevat';
        }

        // Pricing model determines the base noun - for business
        if ($this->pricingModelFilter === 'Spot') {
            $parts[] = $energySourcePrefix . 'pörssisähkösopimukset yrityksille';
        } elseif ($this->pricingModelFilter === 'FixedPrice') {
            $parts[] = $energySourcePrefix . 'kiinteähintaiset sähkösopimukset yrityksille';
        } elseif ($this->pricingModelFilter === 'Hybrid') {
            $parts[] = $energySourcePrefix . 'hybridisähkösopimukset yrityksille';
        } else {
            $parts[] = $energySourcePrefix . 'sähkösopimukset yrityksille';
        }

        // Combine parts and capitalize first letter
        $title = implode(' ', $parts);

        return mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
    }

    /**
     * Generate a dynamic meta description based on active filters.
     */
    public function getMetaDescriptionProperty(): string
    {
        $baseDescription = 'Vertaile yrityksille suunnattuja sähkösopimuksia ja löydä edullisin vaihtoehto yrityksellesi.';

        if ($this->pricingModelFilter === 'Spot') {
            return 'Vertaile pörssisähkösopimuksia yrityksille. Katso marginaalit, perusmaksut ja arvioidut vuosikustannukset yrityksen kulutuksella.';
        }

        if ($this->pricingModelFilter === 'FixedPrice') {
            return 'Vertaile kiinteähintaisia sähkösopimuksia yrityksille. Kiinteä sähkön hinta takaa ennustettavat kustannukset liiketoiminnalle.';
        }

        if ($this->pricingModelFilter === 'Hybrid') {
            return 'Vertaile hybridisähkösopimuksia yrityksille. Yhdistä kiinteän hinnan vakaus ja pörssisähkön säästöpotentiaali.';
        }

        if ($this->renewableFilter) {
            return 'Vertaile uusiutuvalla energialla tuotettuja sähkösopimuksia yrityksille. Vihreä sähkö yrityksesi vastuullisuustavoitteisiin.';
        }

        if ($this->fossilFreeFilter) {
            return 'Vertaile fossiilittomia sähkösopimuksia yrityksille. Ilmastoystävällinen sähkö yrityksen hiilijalanjäljen pienentämiseen.';
        }

        return $baseDescription . ' Katso hinnat, sopimusehdot ja energialähteet yhdestä paikasta.';
    }

    /**
     * Get contracts with calculated costs.
     * Filters for company contracts (target_group = 'Company' or 'Both').
     */
    public function getContractsProperty(): Collection
    {
        $calculator = app(ContractPriceCalculator::class);

        $query = ElectricityContract::query()
            ->with(['company', 'priceComponents', 'electricitySource'])
            // Filter for company contracts only
            ->where(function ($q) {
                $q->whereIn('target_group', ['Company', 'Both']);
            });

        // Apply contract type filter (FixedTerm, OpenEnded)
        if ($this->contractTypeFilter !== '') {
            $query->where('contract_type', $this->contractTypeFilter);
        }

        // Apply pricing model filter (Spot, FixedPrice, Hybrid)
        if ($this->pricingModelFilter !== '') {
            $query->where('pricing_model', $this->pricingModelFilter);
        }

        // Apply metering type filter
        if ($this->meteringFilter !== '') {
            $query->where('metering', $this->meteringFilter);
        }

        // Apply postcode filter at database level (memory optimization)
        if ($this->postcodeFilter !== '') {
            $postcode = $this->postcodeFilter;
            $query->where(function ($q) use ($postcode) {
                $q->where('availability_is_national', true)
                  ->orWhereExists(function ($subquery) use ($postcode) {
                      $subquery->select(DB::raw(1))
                               ->from('contract_postcode')
                               ->whereColumn('contract_postcode.contract_id', 'electricity_contracts.id')
                               ->where('contract_postcode.postcode', $postcode);
                  });
            });
        }

        $contracts = $query->get();

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

        // Filter by consumption range
        $consumption = $this->consumption;
        $contracts = $contracts->filter(function ($contract) use ($consumption) {
            return $contract->isConsumptionInRange($consumption);
        });

        // Get spot price averages for calculations
        $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

        // Get emission calculator
        $emissionsCalculator = app(CO2EmissionsCalculator::class);

        // Calculate cost and emissions for each contract and sort by cost
        $consumption = $this->consumption;
        $contracts = $contracts->map(function ($contract) use ($calculator, $emissionsCalculator, $spotPriceDay, $spotPriceNight, $consumption) {
            $priceComponents = $contract->priceComponents
                ->sortByDesc('price_date')
                ->groupBy('price_component_type')
                ->map(fn ($group) => $group->sortByDesc('price_date')->first(fn ($item) => $item->price > 0) ?? $group->sortByDesc('price_date')->first())
                ->values()
                ->map(fn ($pc) => [
                    'price_component_type' => $pc->price_component_type,
                    'price' => $pc->price,
                ])
                ->toArray();

            $usage = new EnergyUsage(
                total: $consumption,
                basicLiving: $consumption,
            );

            $contractData = [
                'contract_type' => $contract->contract_type,
                'pricing_model' => $contract->pricing_model,
                'metering' => $contract->metering,
            ];

            $result = $calculator->calculate($priceComponents, $contractData, $usage, $spotPriceDay, $spotPriceNight);
            $contract->calculated_cost = $result->toArray();

            // Calculate emission factor for this contract
            $contract->emission_factor = $emissionsCalculator->calculateEmissionFactor($contract->electricitySource);

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
            $latest = $components->sortByDesc('price_date')->first(fn ($item) => $item->price > 0) ?? $components->sortByDesc('price_date')->first();
            $prices[$type] = [
                'price' => $latest->price,
                'unit' => $latest->payment_unit,
            ];
        }

        return $prices;
    }

    public function render()
    {
        return view('livewire.company-contracts-list', [
            'contracts' => $this->contracts,
            'postcodeSuggestions' => $this->postcodeSuggestions,
            'pageTitle' => $this->pageTitle,
            'metaDescription' => $this->metaDescription,
        ])->layout('layouts.app', [
            'title' => $this->pageTitle . ' | Voltikka',
            'metaDescription' => $this->metaDescription,
        ]);
    }
}
