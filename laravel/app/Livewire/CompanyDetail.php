<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\CanonicalOfferFacts;
use App\Services\CO2EmissionsCalculator;
use App\Services\CompanyStatistics\CompanyMarketComparisonService;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class CompanyDetail extends Component
{
    public ?Company $company = null;

    public string $companySlug;

    protected ?Collection $contractsCache = null;

    protected ?array $companyStatsCache = null;

    protected ?CarbonInterface $updatedAtCache = null;

    protected bool $updatedAtResolved = false;

    /**
     * Market comparison payload. Protected, never public: it is a derived
     * array and would only bloat the Livewire snapshot.
     *
     * @var array<string,mixed>|null
     */
    protected ?array $marketComparisonCache = null;

    protected bool $marketComparisonResolved = false;

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
     * Free annual-consumption input. Blank values are valid while editing.
     */
    public int|string|null $directConsumption = null;

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
        'large_apartment' => [
            'label' => 'Kerrostalo perhe',
            'description' => '4 hlö, 80 m²',
            'icon' => 'apartment',
            'consumption' => 5000,
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
    ];

    public function mount(string $companySlug): void
    {
        $this->companySlug = $companySlug;
        $this->company = Company::where('name_slug', $companySlug)->first();

        $matchingPreset = collect($this->presets)
            ->search(fn (array $preset) => $preset['consumption'] === $this->consumption);
        $this->selectedPreset = $matchingPreset === false ? null : $matchingPreset;
        $this->directConsumption = $matchingPreset === false ? $this->consumption : null;
    }

    /**
     * Select a preset and update consumption.
     */
    public function selectPreset(string $preset): void
    {
        $this->selectedPreset = $preset;

        if (isset($this->presets[$preset])) {
            $this->consumption = $this->presets[$preset]['consumption'];
            $this->directConsumption = null;
            $this->clearComputedCaches();
        }
    }

    /**
     * Set the consumption to a specific value (clears preset selection).
     */
    public function setConsumption(int $value): void
    {
        $this->consumption = $value;
        $this->directConsumption = $value;
        $this->selectedPreset = null;
        $this->clearComputedCaches();
    }

    /**
     * Apply a positive free-text annual consumption value.
     */
    public function updatedDirectConsumption($value): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $consumption = max(0, (int) $value);
        if ($consumption <= 0) {
            return;
        }

        $this->consumption = $consumption;
        $this->selectedPreset = null;
        $this->clearComputedCaches();
    }

    protected function clearComputedCaches(): void
    {
        $this->contractsCache = null;
        $this->companyStatsCache = null;
        $this->marketComparisonCache = null;
        $this->marketComparisonResolved = false;
    }

    /**
     * Get all contracts for this company with calculated costs and emissions.
     */
    public function getContractsProperty(): Collection
    {
        if ($this->contractsCache !== null) {
            return $this->contractsCache;
        }

        if (! $this->company) {
            return $this->contractsCache = collect();
        }

        $calculator = app(ContractPriceCalculator::class);
        $emissionsCalculator = app(CO2EmissionsCalculator::class);
        $canonicalPricing = app(CanonicalContractPricingService::class);
        $useCanonical = $canonicalPricing->enabled();

        // Get spot price averages for calculations
        $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

        $relations = ['company', 'electricitySource'];
        if (! $useCanonical) {
            $relations[] = 'priceComponents';
        }

        $contracts = ElectricityContract::query()
            ->active()
            ->with($relations)
            ->where('company_name', $this->company->name)
            ->get();

        // Calculate cost and emissions for each contract
        $consumption = $this->consumption;
        $contracts = $contracts->map(function ($contract) use ($calculator, $emissionsCalculator, $canonicalPricing, $useCanonical, $spotPriceDay, $spotPriceNight, $consumption) {
            $usage = new EnergyUsage(
                total: $consumption,
                basicLiving: $consumption,
            );

            if ($useCanonical) {
                $evaluation = $canonicalPricing->evaluate($contract, $usage);
                $contract->calculated_cost = $evaluation['outcome']->toCalculatedCostArray();
                $contract->pricing_integrity = $evaluation['integrity']->toArray();
                $contract->comparability = $evaluation['outcome']->comparability->value;
                $contract->is_listed = $evaluation['outcome']->isListed();
            } else {
                $priceComponents = $contract->getLatestPriceComponentsForCalculation();
                $contractData = [
                    'contract_type' => $contract->contract_type,
                    'pricing_model' => $contract->pricing_model,
                    'metering' => $contract->metering,
                ];

                $result = $calculator->calculate($priceComponents, $contractData, $usage, $spotPriceDay, $spotPriceNight);
                $contract->calculated_cost = $result->toArray();
                $contract->pricing_integrity = null;
                $contract->comparability = null;
                $contract->is_listed = true;
            }

            // Calculate emission factor for this contract
            $contract->emission_factor = $emissionsCalculator->calculateEmissionFactor($contract->electricitySource);

            // Calculate annual emissions in kg CO2
            $contract->annual_emissions_kg = ($contract->emission_factor * $consumption) / 1000;

            // Mark contracts where consumption exceeds their limit
            $maxConsumption = $contract->consumption_limitation_max_x_kwh_per_y;
            $contract->exceeds_consumption_limit = $maxConsumption > 0 && $consumption > $maxConsumption;

            return $contract;
        });

        // Sort listed contracts first, then consumption-limit exceeders, then by total cost.
        // Excluded contracts stay visible on the company's own page but sink to the bottom.
        return $this->contractsCache = $contracts->sort(function ($a, $b) {
            $aListed = ($a->is_listed ?? true) ? 0 : 1;
            $bListed = ($b->is_listed ?? true) ? 0 : 1;
            if ($aListed !== $bListed) {
                return $aListed - $bListed;
            }

            $aExceeds = $a->exceeds_consumption_limit ? 1 : 0;
            $bExceeds = $b->exceeds_consumption_limit ? 1 : 0;
            if ($aExceeds !== $bExceeds) {
                return $aExceeds - $bExceeds;
            }

            $aCost = $a->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX;
            $bCost = $b->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX;

            return $aCost <=> $bCost;
        })->values();
    }

    /**
     * Active contracts that are available to households.
     */
    public function getHouseholdContractsProperty(): Collection
    {
        return $this->contracts
            ->filter(fn (ElectricityContract $contract) => in_array($contract->target_group, ['Household', 'Both', null], true))
            ->values();
    }

    /**
     * Active contracts that are available to businesses.
     */
    public function getBusinessContractsProperty(): Collection
    {
        return $this->contracts
            ->filter(fn (ElectricityContract $contract) => in_array($contract->target_group, ['Company', 'Both'], true))
            ->values();
    }

    /**
     * Get company statistics aggregated from household contracts.
     */
    public function getCompanyStatsProperty(): array
    {
        if ($this->companyStatsCache !== null) {
            return $this->companyStatsCache;
        }

        $contracts = $this->householdContracts;

        if ($contracts->isEmpty()) {
            return $this->companyStatsCache = [
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
        $priceApplicableContracts = $contracts->filter(fn ($c) => ! $c->exceeds_consumption_limit);

        $prices = $priceApplicableContracts->pluck('calculated_cost.total_cost')->filter();
        // Keep 0 emission factors (100% renewable), only exclude null (missing data uses residual mix)
        $emissionFactors = $contracts->pluck('emission_factor')->filter(fn ($v) => $v !== null);
        // Contracts without source data are treated as 0% renewable (unverified source)
        $renewablePercents = $contracts->map(fn ($c) => $c->electricitySource?->renewable_total ?? 0);

        return $this->companyStatsCache = [
            'avg_price' => $prices->isNotEmpty() ? $prices->avg() : null,
            'min_price' => $prices->isNotEmpty() ? $prices->min() : null,
            'max_price' => $prices->isNotEmpty() ? $prices->max() : null,
            'avg_emission_factor' => $emissionFactors->isNotEmpty() ? $emissionFactors->avg() : null,
            'avg_renewable_percent' => $renewablePercents->isNotEmpty() ? $renewablePercents->avg() : null,
            'contract_count' => $contracts->count(),
            'spot_contract_count' => $contracts->where('pricing_model', 'Spot')->count(),
            'fixed_price_contract_count' => $priceApplicableContracts->where('pricing_model', 'FixedPrice')->count(),
        ];
    }

    /**
     * Contracts with a measurable current offer, cheapest first.
     *
     * Canonical mode uses only the calculated canonical outcome already attached
     * by getContractsProperty(). Feature-off mode keeps the relational behavior.
     */
    public function getPromotionContractsProperty(): Collection
    {
        if (app(CanonicalContractPricingService::class)->enabled()) {
            return $this->householdContracts
                ->map(function (ElectricityContract $contract) {
                    $contract->offer_fact = CanonicalOfferFacts::fromCalculatedCost(
                        is_array($contract->calculated_cost ?? null) ? $contract->calculated_cost : [],
                    );

                    return $contract;
                })
                ->filter(fn (ElectricityContract $contract) => $contract->offer_fact !== null)
                ->values();
        }

        return $this->householdContracts
            ->filter(fn (ElectricityContract $contract) => $contract->hasActiveDiscounts())
            ->map(function (ElectricityContract $contract) {
                $benefit = $contract->calculated_cost['discount_savings_total'] ?? null;
                $benefit = is_numeric($benefit) && (float) $benefit > 0.005 ? (float) $benefit : null;
                $contract->offer_fact = [
                    'label' => $contract->formatActiveDiscountValue() ?? 'Kampanjahinta',
                    'benefit_eur' => $benefit,
                    'benefit_text' => $benefit !== null ? number_format($benefit, 0, ',', ' ').' €' : null,
                    'basis_months' => 12,
                    'basis_label' => '12 kuukauden vertailussa',
                ];

                return $contract;
            })
            ->values();
    }

    /**
     * The seller's spot contracts, cheapest first. Answers the
     * "[company] pörssisähkö" query cluster.
     */
    public function getSpotContractsProperty(): Collection
    {
        return $this->householdContracts->where('pricing_model', 'Spot')->values();
    }

    /**
     * How this seller's contract types sit against the market p20/median/p80.
     *
     * @return array<string,mixed>|null
     */
    public function getMarketComparisonProperty(): ?array
    {
        if ($this->marketComparisonResolved) {
            return $this->marketComparisonCache;
        }

        $this->marketComparisonResolved = true;

        if (! $this->company) {
            return $this->marketComparisonCache = null;
        }

        return $this->marketComparisonCache = app(CompanyMarketComparisonService::class)
            ->forCompany($this->company->name, $this->consumption);
    }

    /**
     * The newest stored source observation for any active company contract.
     * Falls back to a stored relational price date for legacy contracts.
     */
    public function getUpdatedAtProperty(): ?CarbonInterface
    {
        if ($this->updatedAtResolved) {
            return $this->updatedAtCache;
        }

        $this->updatedAtResolved = true;
        $contractIds = $this->contracts->pluck('id')->all();

        if ($contractIds === []) {
            return null;
        }

        $lastObservedAt = ContractSourceSnapshot::query()
            ->whereIn('contract_id', $contractIds)
            ->max('last_observed_at');

        if ($lastObservedAt !== null) {
            return $this->updatedAtCache = Carbon::parse($lastObservedAt);
        }

        $priceDate = PriceComponent::query()
            ->whereIn('electricity_contract_id', $contractIds)
            ->max('price_date');

        return $this->updatedAtCache = $priceDate !== null ? Carbon::parse($priceDate) : null;
    }

    /**
     * Generate Organization JSON-LD schema for SEO.
     */
    public function getOrganizationSchemaProperty(): array
    {
        if (! $this->company) {
            return [];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => $this->canonicalUrl.'#organization',
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

        if ($this->contracts->contains(fn (ElectricityContract $contract) => $contract->availability_is_national === true)) {
            $schema['areaServed'] = [
                '@type' => 'Country',
                'name' => 'Finland',
            ];
        }

        return $schema;
    }

    /**
     * Generate WebPage JSON-LD schema for SEO.
     */
    public function getWebPageSchemaProperty(): array
    {
        if (! $this->company) {
            return [];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $this->canonicalUrl.'#webpage',
            'url' => $this->canonicalUrl,
            'name' => $this->pageTitle,
            'description' => $this->metaDescription,
            'mainEntity' => [
                '@id' => $this->canonicalUrl.'#organization',
            ],
        ];

        if ($this->updatedAt !== null) {
            $schema['dateModified'] = $this->updatedAt->toAtomString();
        }

        return $schema;
    }

    /**
     * Generate ItemList JSON-LD schema for company contract products.
     */
    public function getItemListSchemaProperty(): array
    {
        $contracts = $this->householdContracts;

        if ($contracts->isEmpty() || ! $this->company) {
            return [];
        }

        $items = [];

        foreach ($contracts as $index => $contract) {
            $product = [
                '@type' => 'Product',
                '@id' => route('contract.detail', ['contractId' => $contract->id]).'#product',
                'name' => $contract->name,
                'url' => route('contract.detail', ['contractId' => $contract->id]),
                'category' => 'Electricity Contract',
                'brand' => [
                    '@id' => $this->canonicalUrl.'#organization',
                ],
            ];

            if ($contract->short_description) {
                $product['description'] = $contract->short_description;
            }

            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => $product,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            '@id' => $this->canonicalUrl.'#itemlist',
            'name' => $this->company->name.' sähkösopimukset',
            'itemListElement' => $items,
        ];
    }

    /**
     * Generate BreadcrumbList JSON-LD schema for SEO.
     */
    public function getBreadcrumbSchemaProperty(): array
    {
        if (! $this->company) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Etusivu',
                    'item' => config('app.url'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => 'Sähköyhtiöt',
                    'item' => config('app.url').'/sahkosopimus/sahkoyhtiot',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => $this->company->name,
                    'item' => $this->canonicalUrl,
                ],
            ],
        ];
    }

    /**
     * Get the canonical URL for this page.
     */
    public function getCanonicalUrlProperty(): string
    {
        return config('app.url').'/sahkosopimus/sahkoyhtiot/'.$this->companySlug;
    }

    /**
     * Get the meta description for this page.
     */
    public function getMetaDescriptionProperty(): string
    {
        if (! $this->company) {
            return 'Vertaile sähkösopimuksia Voltikassa.';
        }

        $count = $this->companyStats['contract_count'];
        if ($count === 0) {
            return "{$this->company->name}: kotitalouksille sopivia sähkösopimuksia ei ole nyt vertailussa. Katso sopimustilanne, tarjoukset, markkinavertailu ja pörssisähkön kulut.";
        }

        $contracts = $count === 1
            ? 'yhtä kotitalouksille sopivaa sähkösopimusta'
            : "{$count} kotitalouksille sopivaa sähkösopimusta";

        return "{$this->company->name}: vertaa {$contracts}. Katso hinnat, tarjoukset, markkinavertailu ja pörssisähkön kulut.";
    }

    /**
     * Get the page title optimized for SEO.
     */
    public function getPageTitleProperty(): string
    {
        if (! $this->company) {
            return 'Sähkösopimukset | Voltikka';
        }

        return "{$this->company->name}: sähkön hinta verrattuna markkinaan | Voltikka";
    }

    /**
     * Get the H1 heading for the page.
     */
    public function getH1Property(): string
    {
        if (! $this->company) {
            return 'Sähkösopimukset';
        }

        return "{$this->company->name}: sähkön hinta ja sähkösopimukset";
    }

    /**
     * Get the hero subtitle/description for the page.
     */
    public function getHeroDescriptionProperty(): string
    {
        if (! $this->company) {
            return 'Vertaile sähkösopimuksia.';
        }

        $stats = $this->companyStats;

        if ($stats['contract_count'] === 0) {
            return 'Voltikan vertailussa ei ole tällä hetkellä yhtiön kotitalouksille sopivia sähkösopimuksia.';
        }

        $parts = [
            $stats['contract_count'] === 1
                ? 'Voltikka vertaa yhtä kotitalouksille sopivaa sähkösopimusta.'
                : "Voltikka vertaa {$stats['contract_count']} kotitalouksille sopivaa sähkösopimusta.",
        ];

        if ($stats['min_price'] !== null) {
            $parts[] = 'Hinnat alkavat '
                .number_format($stats['min_price'], 0, ',', ' ')
                .' eurosta vuodessa '
                .number_format($this->consumption, 0, ',', ' ')
                .' kWh:n kulutuksella.';
        }

        if ($stats['spot_contract_count'] > 0) {
            $spotContracts = $stats['spot_contract_count'] === 1
                ? '1 pörssisähkösopimus'
                : "{$stats['spot_contract_count']} pörssisähkösopimusta";
            $parts[] = "Mukana on {$spotContracts}.";
        }

        return implode(' ', $parts);
    }

    public function render()
    {
        if (! $this->company) {
            abort(404, 'Yritystä ei löytynyt');
        }

        $marketComparison = $this->marketComparison;

        return view('livewire.company-detail', [
            'contracts' => $this->householdContracts,
            'businessContracts' => $this->businessContracts,
            'companyStats' => $this->companyStats,
            'updatedAt' => $this->updatedAt,
            'promotionContracts' => $this->promotionContracts,
            'spotContracts' => $this->spotContracts,
            'marketComparison' => $marketComparison,
            'spotBenchmarks' => $marketComparison['spot_benchmarks'] ?? null,
            'schemas' => array_values(array_filter([
                $this->webPageSchema,
                $this->organizationSchema,
                $this->itemListSchema,
                $this->breadcrumbSchema,
            ])),
            'h1' => $this->h1,
            'heroDescription' => $this->heroDescription,
        ])->layout('layouts.app', [
            'title' => $this->pageTitle,
            'metaDescription' => $this->metaDescription,
            'canonical' => $this->canonicalUrl,
        ]);
    }
}
