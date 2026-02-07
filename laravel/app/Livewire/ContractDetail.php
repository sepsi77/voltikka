<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractPriceCalculator;
use App\Services\ContractRankingService;
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
     * Default consumption presets (before filtering).
     *
     * @var array<string, int>
     */
    protected array $defaultPresets = [
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

        // Handle legacy UUID redirect
        if ($this->shouldRedirectLegacyUuid($contractId)) {
            return; // Redirect was initiated, stop further processing
        }

        // Adjust default consumption if it falls outside the contract's limits
        $contract = $this->contract;
        if ($contract) {
            $this->consumption = $this->clampConsumption($this->consumption, $contract);

            // Track contract view
            $this->dispatch('track',
                eventName: 'Contract Viewed',
                props: [
                    'contract_id' => $contract->id,
                    'company' => $contract->company?->name,
                    'pricing_model' => $contract->pricing_model,
                ]
            );
        }
    }

    /**
     * Set the consumption to a preset value.
     * Clamps the value to be within the contract's limits.
     */
    public function setConsumption(int $value): void
    {
        $contract = $this->contract;
        if ($contract) {
            $value = $this->clampConsumption($value, $contract);
        }
        $this->consumption = $value;
    }

    /**
     * Clamp consumption value to be within the contract's allowed range.
     */
    protected function clampConsumption(int $value, ElectricityContract $contract): int
    {
        $min = $contract->consumption_limitation_min_x_kwh_per_y;
        $max = $contract->consumption_limitation_max_x_kwh_per_y;

        if ($min !== null && $value < $min) {
            return (int) $min;
        }

        if ($max !== null && $value > $max) {
            return (int) $max;
        }

        return $value;
    }

    /**
     * Get filtered consumption presets based on contract limits.
     *
     * @return array<string, int>
     */
    public function getPresetsProperty(): array
    {
        $contract = $this->contract;

        if (! $contract || ! $contract->hasConsumptionLimits()) {
            return $this->defaultPresets;
        }

        return array_filter(
            $this->defaultPresets,
            fn (int $value) => $contract->isConsumptionInRange($value)
        );
    }

    /**
     * Check if this is a legacy UUID and redirect if so.
     * Returns true if redirect was initiated.
     */
    protected function shouldRedirectLegacyUuid(string $contractId): bool
    {
        // Check if this looks like a legacy UUID (36 chars with hyphens)
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $contractId)) {
            return false;
        }

        // Look up by api_id
        $contract = ElectricityContract::where('api_id', $contractId)->first();

        if ($contract) {
            // Create a proper Laravel redirect response (not Livewire's redirector)
            $url = route('contract.detail', ['contractId' => $contract->id]);
            $response = new \Illuminate\Http\RedirectResponse($url, 301);

            throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
        }

        return false;
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
     * Check if the contract is currently active (present in active_contracts table).
     */
    public function getIsActiveProperty(): bool
    {
        return $this->contract?->isActive() ?? false;
    }

    /**
     * Get the contract's price rank among all active household contracts.
     */
    public function getPriceRankProperty(): ?int
    {
        $contract = $this->contract;
        if (! $contract) {
            return null;
        }

        return app(ContractRankingService::class)->getContractRank($contract->id);
    }

    /**
     * Get total number of active household contracts.
     */
    public function getTotalContractsProperty(): int
    {
        return app(ContractRankingService::class)->getTotalActiveContracts();
    }

    /**
     * Truncate a contract name to fit within title limits.
     * Cuts at word boundary and appends ellipsis if truncated.
     */
    protected function truncateName(string $name, int $maxLength = 40): string
    {
        if (mb_strlen($name) <= $maxLength) {
            return $name;
        }

        $cut = mb_substr($name, 0, $maxLength);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace > 20) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, ' -') . '…';
    }

    /**
     * Get SEO page title.
     */
    public function getPageTitleProperty(): string
    {
        $contract = $this->contract;
        if (! $contract) {
            return 'Sähkösopimus | Voltikka';
        }

        $rank = $this->priceRank;
        $total = $this->totalContracts;
        $name = $this->truncateName($contract->name);

        if ($rank && $total) {
            return "{$name} | #{$rank} halvin — Vertaa {$total} sopimuksessa | Voltikka";
        }

        return "{$contract->name} | Voltikka";
    }

    /**
     * Get OG title (shorter version for social sharing).
     */
    public function getOgTitleProperty(): string
    {
        $contract = $this->contract;
        if (! $contract) {
            return 'Sähkösopimus | Voltikka';
        }

        $rank = $this->priceRank;
        $name = $this->truncateName($contract->name);

        if ($rank) {
            return "{$name} | #{$rank} halvin | Voltikka";
        }

        return "{$contract->name} | Voltikka";
    }

    /**
     * Get SEO meta description.
     */
    public function getMetaDescriptionProperty(): string
    {
        $contract = $this->contract;
        if (! $contract) {
            return '';
        }

        $total = $this->totalContracts;

        return "Vertaa {$contract->name} hinta ja CO₂-tiedot {$total} muuhun sopimukseen. Katso hintahistoria, sijoitus ja löydä omaan kulutukseesi paras vaihtoehto.";
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
            ->map(fn ($group) => $group->sortByDesc('price_date')->first(fn ($item) => $item->price > 0) ?? $group->sortByDesc('price_date')->first())
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
            'pricing_model' => $contract->pricing_model,
            'metering' => $contract->metering,
        ];

        // Get spot price averages for spot contract calculations
        $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

        return $calculator->calculate($priceComponents, $contractData, $usage, $spotPriceDay, $spotPriceNight)->toArray();
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
            $latest = $components->sortByDesc('price_date')->first(fn ($item) => $item->price > 0) ?? $components->sortByDesc('price_date')->first();
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

    /**
     * Get the CO2 emissions calculation for the contract.
     */
    public function getCo2EmissionsProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $calculator = app(CO2EmissionsCalculator::class);
        $result = $calculator->calculate($contract->electricitySource, $this->consumption);

        return $result->toArray();
    }

    /**
     * Get the emission factor sources for display.
     */
    public function getEmissionFactorSourcesProperty(): array
    {
        return CO2EmissionsCalculator::EMISSION_FACTOR_SOURCES;
    }

    /**
     * Get the canonical URL for this page.
     */
    public function getCanonicalUrlProperty(): string
    {
        return config('app.url') . '/sopimus/' . $this->contractId;
    }

    /**
     * Generate Service JSON-LD schema for SEO.
     */
    public function getServiceSchemaProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => $contract->name,
            'description' => $contract->short_description ?? $contract->long_description ?? "Sähkösopimus: {$contract->name}",
            'url' => $this->canonicalUrl,
            'serviceType' => 'Electricity Contract',
        ];

        // Add provider (Organization)
        if ($contract->company) {
            $provider = [
                '@type' => 'Organization',
                'name' => $contract->company->name,
            ];

            if ($contract->company->getLogoUrl()) {
                $provider['logo'] = $contract->company->getLogoUrl();
            }

            if ($contract->company->company_url) {
                $provider['url'] = $contract->company->company_url;
            }

            $schema['provider'] = $provider;
        }

        // Add pricing info as additionalProperty values (informational, not transactional)
        $additionalProperties = [];
        $latestPrices = $this->latestPrices;

        // For spot contracts, show the margin
        if ($contract->pricing_model === 'Spot' && isset($latestPrices['General'])) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'spotMargin',
                'value' => $latestPrices['General']['price'],
                'unitText' => 'c/kWh',
            ];
        }

        // Monthly fee
        if (isset($latestPrices['Monthly'])) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'monthlyFee',
                'value' => $latestPrices['Monthly']['price'],
                'unitText' => 'EUR/kk',
            ];
        }

        // General energy price (for fixed price contracts)
        if ($contract->pricing_model !== 'Spot' && isset($latestPrices['General'])) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'energyPrice',
                'value' => $latestPrices['General']['price'],
                'unitText' => 'c/kWh',
            ];
        }

        // Day/Night rates
        if (isset($latestPrices['DayTime'])) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'dayTimePrice',
                'value' => $latestPrices['DayTime']['price'],
                'unitText' => 'c/kWh',
            ];
        }

        if (isset($latestPrices['NightTime'])) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'nightTimePrice',
                'value' => $latestPrices['NightTime']['price'],
                'unitText' => 'c/kWh',
            ];
        }

        // Seasonal rates
        if (isset($latestPrices['SeasonalWinterDay'])) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'seasonalWinterPrice',
                'value' => $latestPrices['SeasonalWinterDay']['price'],
                'unitText' => 'c/kWh',
            ];
        }

        if (isset($latestPrices['SeasonalOther'])) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'seasonalOtherPrice',
                'value' => $latestPrices['SeasonalOther']['price'],
                'unitText' => 'c/kWh',
            ];
        }

        // Add additional properties for contract details

        // Pricing model
        $pricingModelLabels = [
            'Spot' => 'Pörssisähkö',
            'FixedPrice' => 'Kiinteä hinta',
            'Hybrid' => 'Hybridisähkö',
        ];
        $additionalProperties[] = [
            '@type' => 'PropertyValue',
            'name' => 'pricingModel',
            'value' => $pricingModelLabels[$contract->pricing_model] ?? $contract->pricing_model,
        ];

        // Contract type
        $contractTypeLabels = [
            'OpenEnded' => 'Toistaiseksi voimassa',
            'FixedTerm' => 'Määräaikainen',
        ];
        $additionalProperties[] = [
            '@type' => 'PropertyValue',
            'name' => 'contractType',
            'value' => $contractTypeLabels[$contract->contract_type] ?? $contract->contract_type,
        ];

        // Metering type
        $meteringLabels = [
            'General' => 'Yleissähkö',
            'Time' => 'Aikasähkö',
            'Season' => 'Kausisähkö',
        ];
        $additionalProperties[] = [
            '@type' => 'PropertyValue',
            'name' => 'meteringType',
            'value' => $meteringLabels[$contract->metering] ?? $contract->metering,
        ];

        // Energy source percentages
        $source = $contract->electricitySource;
        if ($source) {
            if ($source->renewable_total !== null) {
                $additionalProperties[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'renewablePercentage',
                    'value' => $source->renewable_total,
                    'unitCode' => 'P1',
                    'unitText' => '%',
                ];
            }

            if ($source->nuclear_total !== null) {
                $additionalProperties[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'nuclearPercentage',
                    'value' => $source->nuclear_total,
                    'unitCode' => 'P1',
                    'unitText' => '%',
                ];
            }

            if ($source->fossil_total !== null) {
                $additionalProperties[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'fossilPercentage',
                    'value' => $source->fossil_total,
                    'unitCode' => 'P1',
                    'unitText' => '%',
                ];
            }

            if ($source->renewable_wind !== null && $source->renewable_wind > 0) {
                $additionalProperties[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'windPowerPercentage',
                    'value' => $source->renewable_wind,
                    'unitCode' => 'P1',
                    'unitText' => '%',
                ];
            }

            if ($source->renewable_hydro !== null && $source->renewable_hydro > 0) {
                $additionalProperties[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'hydroPowerPercentage',
                    'value' => $source->renewable_hydro,
                    'unitCode' => 'P1',
                    'unitText' => '%',
                ];
            }
        }

        // Emission factor
        $co2Emissions = $this->co2Emissions;
        if (! empty($co2Emissions) && isset($co2Emissions['emission_factor_g_per_kwh'])) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'emissionFactor',
                'value' => $co2Emissions['emission_factor_g_per_kwh'],
                'unitCode' => 'GRM',
                'unitText' => 'gCO2/kWh',
            ];
        }

        if (! empty($additionalProperties)) {
            $schema['additionalProperty'] = $additionalProperties;
        }

        return $schema;
    }

    /**
     * Generate BreadcrumbList JSON-LD schema for SEO.
     */
    public function getBreadcrumbSchemaProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $breadcrumbs = [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Etusivu',
                'item' => config('app.url'),
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => 'Sähkösopimukset',
                'item' => config('app.url') . '/sahkosopimus',
            ],
        ];

        // Add company if available
        if ($contract->company) {
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $contract->company->name,
                'item' => config('app.url') . '/sahkosopimus/sahkoyhtiot/' . $contract->company->name_slug,
            ];
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 4,
                'name' => $contract->name,
                'item' => $this->canonicalUrl,
            ];
        } else {
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $contract->name,
                'item' => $this->canonicalUrl,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $breadcrumbs,
        ];
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
            'presets' => $this->presets,
            'co2Emissions' => $this->co2Emissions,
            'emissionFactorSources' => $this->emissionFactorSources,
            'schemas' => [
                $this->serviceSchema,
                $this->breadcrumbSchema,
            ],
        ])->layout('layouts.app', [
            'title' => $this->pageTitle,
            'ogTitle' => $this->ogTitle,
            'metaDescription' => $this->metaDescription,
            'canonical' => $this->canonicalUrl,
        ]);
    }
}
