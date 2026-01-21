<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class CompanyDetail extends Component
{
    public ?Company $company = null;
    public string $companySlug;

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
     * Available consumption presets matching ContractsList pattern.
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

    public function mount(string $companySlug): void
    {
        $this->companySlug = $companySlug;
        $this->company = Company::where('name_slug', $companySlug)->first();
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
     * Get all contracts for this company with calculated costs and emissions.
     */
    public function getContractsProperty(): Collection
    {
        if (!$this->company) {
            return collect();
        }

        $calculator = app(ContractPriceCalculator::class);
        $emissionsCalculator = app(CO2EmissionsCalculator::class);

        // Get spot price averages for calculations
        $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

        $contracts = ElectricityContract::query()
            ->with(['priceComponents', 'electricitySource'])
            ->where('company_name', $this->company->name)
            ->get();

        // Calculate cost and emissions for each contract
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

            // Calculate annual emissions in kg CO2
            $contract->annual_emissions_kg = ($contract->emission_factor * $consumption) / 1000;

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
     * Get company statistics aggregated from all contracts.
     */
    public function getCompanyStatsProperty(): array
    {
        $contracts = $this->contracts;

        if ($contracts->isEmpty()) {
            return [
                'avg_price' => null,
                'min_price' => null,
                'max_price' => null,
                'avg_emission_factor' => null,
                'avg_renewable_percent' => null,
                'contract_count' => 0,
                'spot_contract_count' => 0,
                'fixed_price_contract_count' => 0,
            ];
        }

        // Filter contracts that are applicable for pricing (consumption within limits)
        $priceApplicableContracts = $contracts->filter(fn ($c) => !$c->exceeds_consumption_limit);

        $prices = $priceApplicableContracts->pluck('calculated_cost.total_cost')->filter();
        $emissionFactors = $contracts->pluck('emission_factor')->filter();
        $renewablePercents = $contracts->map(fn ($c) => $c->electricitySource?->renewable_total)->filter();

        return [
            'avg_price' => $prices->isNotEmpty() ? $prices->avg() : null,
            'min_price' => $prices->isNotEmpty() ? $prices->min() : null,
            'max_price' => $prices->isNotEmpty() ? $prices->max() : null,
            'avg_emission_factor' => $emissionFactors->isNotEmpty() ? $emissionFactors->avg() : null,
            'avg_renewable_percent' => $renewablePercents->isNotEmpty() ? $renewablePercents->avg() : null,
            'contract_count' => $contracts->count(),
            'spot_contract_count' => $priceApplicableContracts->where('pricing_model', 'Spot')->count(),
            'fixed_price_contract_count' => $priceApplicableContracts->where('pricing_model', 'FixedPrice')->count(),
        ];
    }

    /**
     * Generate Organization JSON-LD schema for SEO.
     */
    public function getJsonLdProperty(): array
    {
        if (!$this->company) {
            return [];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $this->company->name,
            'url' => $this->company->company_url,
        ];

        // Add address if available
        if ($this->company->street_address || $this->company->postal_code || $this->company->postal_name) {
            $schema['address'] = [
                '@type' => 'PostalAddress',
                'addressCountry' => 'FI',
            ];

            if ($this->company->street_address) {
                $schema['address']['streetAddress'] = $this->company->street_address;
            }
            if ($this->company->postal_code) {
                $schema['address']['postalCode'] = $this->company->postal_code;
            }
            if ($this->company->postal_name) {
                $schema['address']['addressLocality'] = $this->company->postal_name;
            }
        }

        // Add logo if available
        if ($this->company->getLogoUrl()) {
            $schema['logo'] = $this->company->getLogoUrl();
        }

        // Add service/product offerings info
        $contracts = $this->contracts;
        if ($contracts->isNotEmpty()) {
            $schema['makesOffer'] = $contracts->map(function ($contract) {
                return [
                    '@type' => 'Offer',
                    'itemOffered' => [
                        '@type' => 'Service',
                        'name' => $contract->name,
                        'serviceType' => 'Electricity Supply',
                    ],
                ];
            })->values()->all();
        }

        return $schema;
    }

    /**
     * Get the canonical URL for this page.
     */
    public function getCanonicalUrlProperty(): string
    {
        return config('app.url') . '/sahkosopimus/sahkoyhtiot/' . $this->companySlug;
    }

    /**
     * Get the meta description for this page.
     */
    public function getMetaDescriptionProperty(): string
    {
        if (!$this->company) {
            return 'Vertaile sähkösopimuksia Voltikassa.';
        }

        $stats = $this->companyStats;
        $parts = [];

        $parts[] = "{$this->company->name} sähkösopimukset";

        if ($stats['contract_count'] > 0) {
            $parts[] = "{$stats['contract_count']} sopimusta";
        }

        if ($stats['min_price'] !== null) {
            $parts[] = "alkaen " . number_format($stats['min_price'], 0, ',', ' ') . " €/vuosi";
        }

        if ($stats['avg_renewable_percent'] !== null && $stats['avg_renewable_percent'] >= 50) {
            $parts[] = number_format($stats['avg_renewable_percent'], 0) . "% uusiutuvaa energiaa";
        }

        $parts[] = "Vertaa hintoja ja löydä paras sopimus";

        return implode('. ', $parts) . '.';
    }

    /**
     * Get the page title optimized for SEO.
     */
    public function getPageTitleProperty(): string
    {
        if (!$this->company) {
            return 'Sähkösopimukset | Voltikka';
        }

        $stats = $this->companyStats;
        $year = date('Y');

        // Build a descriptive title
        $titleParts = ["{$this->company->name} sähkösopimukset {$year}"];

        if ($stats['contract_count'] > 0) {
            $titleParts[] = "Hinnat ja vertailu";
        }

        return implode(' - ', $titleParts) . ' | Voltikka';
    }

    /**
     * Get the H1 heading for the page.
     */
    public function getH1Property(): string
    {
        if (!$this->company) {
            return 'Sähkösopimukset';
        }

        return "{$this->company->name} sähkösopimukset";
    }

    /**
     * Get the hero subtitle/description for the page.
     */
    public function getHeroDescriptionProperty(): string
    {
        if (!$this->company) {
            return 'Vertaile sähkösopimuksia.';
        }

        $stats = $this->companyStats;
        $parts = [];

        if ($stats['contract_count'] > 0) {
            $parts[] = "Vertaile {$stats['contract_count']} sähkösopimusta";
        }

        if ($stats['min_price'] !== null) {
            $parts[] = "hinnat alkaen " . number_format($stats['min_price'], 0, ',', ' ') . " €/vuosi";
        }

        if ($stats['avg_renewable_percent'] !== null) {
            if ($stats['avg_renewable_percent'] >= 100) {
                $parts[] = "100% uusiutuvaa energiaa";
            } elseif ($stats['avg_renewable_percent'] >= 50) {
                $parts[] = "keskimäärin " . number_format($stats['avg_renewable_percent'], 0) . "% uusiutuvaa";
            }
        }

        if ($stats['spot_contract_count'] > 0) {
            $parts[] = "{$stats['spot_contract_count']} pörssisähkösopimusta";
        }

        return implode('. ', $parts) . '.';
    }

    public function render()
    {
        if (!$this->company) {
            abort(404, 'Yritystä ei löytynyt');
        }

        return view('livewire.company-detail', [
            'contracts' => $this->contracts,
            'companyStats' => $this->companyStats,
            'jsonLd' => $this->jsonLd,
            'h1' => $this->h1,
            'heroDescription' => $this->heroDescription,
        ])->layout('layouts.app', [
            'title' => $this->pageTitle,
            'metaDescription' => $this->metaDescription,
            'canonical' => $this->canonicalUrl,
        ]);
    }
}
