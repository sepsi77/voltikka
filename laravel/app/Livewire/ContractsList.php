<?php

namespace App\Livewire;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\BuildingType;
use App\Enums\HeatingMethod;
use App\Enums\SupplementaryHeatingMethod;
use App\Models\ElectricityContract;
use App\Models\Postcode;
use App\Models\SpotPriceAverage;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyCalculatorRequest;
use App\Services\DTO\EnergyUsage;
use App\Services\EnergyCalculator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class ContractsList extends Component
{
    /**
     * Base path for filter links (used for SEO crawlable links).
     */
    public string $basePath = '/';

    /**
     * Whether this page should show SEO filter links.
     * Disabled on homepage, enabled only on /sahkosopimus.
     */
    public bool $showSeoFilterLinks = false;

    /**
     * Active tab for consumption selection ('presets' or 'calculator').
     */
    public string $activeTab = 'presets';

    /**
     * Currently selected preset key.
     */
    public ?string $selectedPreset = 'large_apartment';

    /**
     * Current consumption value in kWh.
     */
    #[Url]
    public int $consumption = 5000;

    /**
     * Available consumption presets matching ConsumptionCalculator.
     *
     * @var array<string, array{label: string, description: string, icon: string, consumption: int}>
     */
    public array $presets = [
        'small_apartment' => [
            'label' => 'Pieni yksiö',
            'description' => '1 hlö, 35 m²',
            'icon' => 'apartment',
            'consumption' => 2000,
        ],
        'medium_apartment' => [
            'label' => 'Kerrostalo 2 hlö',
            'description' => '2 hlö, 60 m²',
            'icon' => 'apartment',
            'consumption' => 3500,
        ],
        'large_apartment' => [
            'label' => 'Kerrostalo perhe',
            'description' => '4 hlö, 80 m²',
            'icon' => 'apartment',
            'consumption' => 5000,
        ],
        'small_house_no_heat' => [
            'label' => 'Pieni omakotitalo',
            'description' => 'Ei sähkölämmitystä',
            'icon' => 'house',
            'consumption' => 5000,
        ],
        'medium_house_heat_pump' => [
            'label' => 'Omakotitalo + ILP',
            'description' => 'Ilma-vesilämpöpumppu',
            'icon' => 'house',
            'consumption' => 8000,
        ],
        'row_house' => [
            'label' => 'Rivitalo',
            'description' => '4 hlö, 100 m²',
            'icon' => 'house',
            'consumption' => 10000,
        ],
        'large_house_electric' => [
            'label' => 'Suuri talo + sähkö',
            'description' => 'Suora sähkölämmitys',
            'icon' => 'house',
            'consumption' => 18000,
        ],
        'large_house_ground_pump' => [
            'label' => 'Suuri talo + MLP',
            'description' => 'Maalämpöpumppu',
            'icon' => 'house',
            'consumption' => 12000,
        ],
    ];

    // ===== Inline Calculator Fields =====

    /**
     * Living area in square meters.
     */
    public int $calcLivingArea = 80;

    /**
     * Number of people in household.
     */
    public int $calcNumPeople = 2;

    /**
     * Building type.
     */
    public string $calcBuildingType = 'apartment';

    /**
     * Whether to include heating in calculation.
     */
    public bool $calcIncludeHeating = false;

    /**
     * Heating method (only used when calcIncludeHeating is true).
     */
    public string $calcHeatingMethod = 'electricity';

    /**
     * Building region (only used when calcIncludeHeating is true).
     */
    public string $calcBuildingRegion = 'south';

    /**
     * Building energy efficiency rating (only used when calcIncludeHeating is true).
     */
    public string $calcBuildingEnergyEfficiency = '2000';

    /**
     * Supplementary heating method (only used when calcIncludeHeating is true).
     */
    public ?string $calcSupplementaryHeating = null;

    // ===== Extras Toggles =====

    /**
     * Whether underfloor/bathroom heating is enabled.
     */
    public bool $calcUnderfloorHeatingEnabled = false;

    /**
     * Whether sauna consumption is enabled.
     */
    public bool $calcSaunaEnabled = false;

    /**
     * Whether electric vehicle consumption is enabled.
     */
    public bool $calcElectricVehicleEnabled = false;

    /**
     * Whether cooling/air conditioning is enabled.
     */
    public bool $calcCooling = false;

    // ===== Extra Input Fields =====

    /**
     * Bathroom/underfloor heating area in square meters.
     */
    public int $calcBathroomHeatingArea = 0;

    /**
     * Sauna usage per week (number of sessions).
     */
    public int $calcSaunaUsagePerWeek = 0;

    /**
     * Electric vehicle kilometers driven per week.
     */
    public int $calcElectricVehicleKmsPerWeek = 0;

    /**
     * Available building types with labels.
     *
     * @var array<string, string>
     */
    public array $buildingTypes = [
        'apartment' => 'Kerrostalo',
        'row_house' => 'Rivitalo',
        'detached_house' => 'Omakotitalo',
    ];

    /**
     * Available heating methods with labels.
     *
     * @var array<string, string>
     */
    public array $heatingMethods = [
        'electricity' => 'Suora sähkölämmitys',
        'air_to_water_heat_pump' => 'Ilma-vesilämpöpumppu',
        'ground_heat_pump' => 'Maalämpö',
        'district_heating' => 'Kaukolämpö',
        'oil' => 'Öljylämmitys',
        'pellets' => 'Pelletti',
        'other' => 'Muu',
    ];

    /**
     * Available building regions with labels.
     *
     * @var array<string, string>
     */
    public array $buildingRegions = [
        'south' => 'Etelä-Suomi',
        'central' => 'Keski-Suomi',
        'north' => 'Pohjois-Suomi',
    ];

    /**
     * Available building energy efficiency ratings with labels.
     *
     * @var array<string, string>
     */
    public array $energyRatings = [
        'passive' => 'Passiivitalo',
        'low_energy' => 'Matalaenergia',
        '2010' => '2010-luku',
        '2000' => '2000-luku',
        '1990' => '1990-luku',
        '1980' => '1980-luku',
        '1970' => '1970-luku',
        '1960' => '1960-luku',
        'older' => 'Vanhempi',
    ];

    /**
     * Available supplementary heating methods with labels.
     *
     * @var array<string, string>
     */
    public array $supplementaryHeatingMethods = [
        'heat_pump' => 'Ilmalämpöpumppu',
        'exhaust_air_heat_pump' => 'Poistoilmalämpöpumppu',
        'fireplace' => 'Takka / puulämmitys',
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
     * Set the active tab.
     */
    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

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
     * Calculate consumption from inline calculator and update.
     */
    public function calculateFromInlineCalculator(): void
    {
        $calculator = app(EnergyCalculator::class);

        // Convert weekly EV kms to monthly (roughly 4.33 weeks per month)
        $evKmsPerMonth = (int) round($this->calcElectricVehicleKmsPerWeek * 4.33);

        $request = new EnergyCalculatorRequest(
            livingArea: max(10, $this->calcLivingArea),
            numPeople: max(1, $this->calcNumPeople),
            buildingType: BuildingType::from($this->calcBuildingType),
            heatingMethod: $this->calcIncludeHeating ? HeatingMethod::from($this->calcHeatingMethod) : null,
            supplementaryHeating: $this->calcIncludeHeating && $this->calcSupplementaryHeating
                ? SupplementaryHeatingMethod::from($this->calcSupplementaryHeating)
                : null,
            buildingEnergyEfficiency: $this->calcIncludeHeating ? BuildingEnergyRating::from($this->calcBuildingEnergyEfficiency) : null,
            buildingRegion: $this->calcIncludeHeating ? BuildingRegion::from($this->calcBuildingRegion) : null,
            electricVehicleKmsPerMonth: $this->calcElectricVehicleEnabled ? $evKmsPerMonth : 0,
            bathroomHeatingArea: $this->calcUnderfloorHeatingEnabled ? $this->calcBathroomHeatingArea : 0,
            saunaUsagePerWeek: $this->calcSaunaEnabled ? $this->calcSaunaUsagePerWeek : 0,
            externalHeating: !$this->calcIncludeHeating,
            externalHeatingWater: !$this->calcIncludeHeating,
            cooling: $this->calcCooling,
        );

        $result = $calculator->estimate($request);
        $this->consumption = $result->total;
        $this->selectedPreset = null;
    }

    /**
     * Hook called when any calculator field is updated.
     */
    public function updatedCalcLivingArea(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcNumPeople(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcBuildingType(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcIncludeHeating(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcHeatingMethod(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcBuildingRegion(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcBuildingEnergyEfficiency(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcSupplementaryHeating(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcUnderfloorHeatingEnabled(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcSaunaEnabled(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcElectricVehicleEnabled(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcCooling(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcBathroomHeatingArea(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcSaunaUsagePerWeek(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcElectricVehicleKmsPerWeek(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    /**
     * Select a building type (for clickable cards).
     */
    public function selectBuildingType(string $type): void
    {
        $this->calcBuildingType = $type;
        $this->selectedPreset = null;
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    /**
     * Toggle an extra option (underfloor heating, sauna, EV, cooling).
     */
    public function toggleExtra(string $extra): void
    {
        switch ($extra) {
            case 'underfloor':
                $this->calcUnderfloorHeatingEnabled = !$this->calcUnderfloorHeatingEnabled;
                if (!$this->calcUnderfloorHeatingEnabled) {
                    $this->calcBathroomHeatingArea = 0;
                }
                break;
            case 'sauna':
                $this->calcSaunaEnabled = !$this->calcSaunaEnabled;
                if (!$this->calcSaunaEnabled) {
                    $this->calcSaunaUsagePerWeek = 0;
                }
                break;
            case 'ev':
                $this->calcElectricVehicleEnabled = !$this->calcElectricVehicleEnabled;
                if (!$this->calcElectricVehicleEnabled) {
                    $this->calcElectricVehicleKmsPerWeek = 0;
                }
                break;
            case 'cooling':
                $this->calcCooling = !$this->calcCooling;
                break;
        }
        $this->selectedPreset = null;
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
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
     *
     * The title follows Finnish grammar conventions:
     * - Base: "Sähkösopimukset"
     * - With pricing model: "Pörssisähkösopimukset", "Kiinteähintaiset sähkösopimukset"
     * - With contract type: "Määräaikaiset sähkösopimukset", "Toistaiseksi voimassa olevat sähkösopimukset"
     * - Combined: "Määräaikaiset pörssisähkösopimukset"
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
            // Nuclear is a compound word prefix (ydinvoima-)
            $energySourcePrefix = 'ydinvoima';
        }

        // Contract type modifier
        if ($this->contractTypeFilter === 'FixedTerm') {
            $parts[] = 'Määräaikaiset';
        } elseif ($this->contractTypeFilter === 'OpenEnded') {
            $parts[] = 'Toistaiseksi voimassa olevat';
        }

        // Pricing model determines the base noun
        if ($this->pricingModelFilter === 'Spot') {
            $parts[] = $energySourcePrefix . 'pörssisähkösopimukset';
        } elseif ($this->pricingModelFilter === 'FixedPrice') {
            $parts[] = $energySourcePrefix . 'kiinteähintaiset sähkösopimukset';
        } elseif ($this->pricingModelFilter === 'Hybrid') {
            $parts[] = $energySourcePrefix . 'hybridisähkösopimukset';
        } else {
            $parts[] = $energySourcePrefix . 'sähkösopimukset';
        }

        // Combine parts and capitalize first letter
        $title = implode(' ', $parts);

        return mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
    }

    /**
     * Generate a dynamic meta description based on active filters.
     *
     * The description is tailored to the selected filters for better SEO.
     */
    public function getMetaDescriptionProperty(): string
    {
        $baseDescription = 'Vertaile sähkösopimuksia ja löydä edullisin vaihtoehto.';

        if ($this->pricingModelFilter === 'Spot') {
            return 'Vertaile pörssisähkösopimuksia ja löydä edullisin vaihtoehto. Katso marginaalit, perusmaksut ja arvioidut vuosikustannukset.';
        }

        if ($this->pricingModelFilter === 'FixedPrice') {
            return 'Vertaile kiinteähintaisia sähkösopimuksia ja löydä paras hinta. Kiinteä sähkön hinta takaa ennustettavat kustannukset.';
        }

        if ($this->pricingModelFilter === 'Hybrid') {
            return 'Vertaile hybridisähkösopimuksia, jotka yhdistävät kiinteän hinnan ja pörssisähkön edut.';
        }

        if ($this->contractTypeFilter === 'FixedTerm') {
            return 'Vertaile määräaikaisia sähkösopimuksia ja lukitse hinta haluamaksesi ajaksi.';
        }

        if ($this->contractTypeFilter === 'OpenEnded') {
            return 'Vertaile toistaiseksi voimassa olevia sähkösopimuksia. Joustava sopimus ilman sitoutumisaikaa.';
        }

        if ($this->renewableFilter) {
            return 'Vertaile uusiutuvalla energialla tuotettuja sähkösopimuksia. Vihreä sähkö tuuli- ja aurinkovoimasta.';
        }

        if ($this->fossilFreeFilter) {
            return 'Vertaile fossiilittomia sähkösopimuksia. Ilmastoystävällinen sähkö ilman hiilidioksidipäästöjä.';
        }

        if ($this->nuclearFilter) {
            return 'Vertaile ydinvoimalla tuotettuja sähkösopimuksia. Päästötön ja vakaa sähköntuotanto.';
        }

        return $baseDescription . ' Katso hinnat, sopimusehdot ja energialähteet yhdestä paikasta.';
    }

    /**
     * Get contracts with calculated costs.
     *
     * Memory optimization: We do NOT eager load availabilityPostcodes as that
     * relationship has 7000+ pivot records which causes memory exhaustion.
     * Instead, we filter by postcode at the database level using a subquery.
     */
    public function getContractsProperty(): Collection
    {
        $calculator = app(ContractPriceCalculator::class);

        $query = ElectricityContract::query()
            ->with(['company', 'priceComponents', 'electricitySource'])
            // Filter for household contracts only (exclude company-only contracts)
            ->where(function ($q) {
                $q->whereIn('target_group', ['Household', 'Both'])
                  ->orWhereNull('target_group');
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
        // This avoids loading all pivot records into memory
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

        // Filter by consumption range - only show contracts where the selected
        // consumption falls within the contract's allowed range
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

            // Mark contracts where consumption exceeds their limit
            $maxConsumption = $contract->consumption_limitation_max_x_kwh_per_y;
            $contract->exceeds_consumption_limit = $maxConsumption > 0 && $consumption > $maxConsumption;

            return $contract;
        });

        // Sort by total cost (ascending), but put contracts that exceed consumption limit at the end
        return $contracts->sortBy([
            fn ($c) => $c->exceeds_consumption_limit ? 1 : 0,
            fn ($c) => $c->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX,
        ])->values();
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

    /**
     * Generate canonical URL for this page.
     * Always returns the base path without query params.
     */
    public function getCanonicalUrlProperty(): string
    {
        return config('app.url') . $this->basePath;
    }

    public function render()
    {
        return view('livewire.contracts-list', [
            'contracts' => $this->contracts,
            'postcodeSuggestions' => $this->postcodeSuggestions,
            'pageTitle' => $this->pageTitle,
            'metaDescription' => $this->metaDescription,
            'basePath' => $this->basePath,
            'showSeoFilterLinks' => $this->showSeoFilterLinks,
        ])->layout('layouts.app', [
            'title' => $this->pageTitle . ' | Voltikka',
            'metaDescription' => $this->metaDescription,
            'canonical' => $this->canonicalUrl,
        ]);
    }
}
