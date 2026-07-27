<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\CanonicalOfferFacts;
use App\Services\CO2EmissionsCalculator;
use App\Services\CompanyStatistics\CompanyMarketComparisonService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractRankingService;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class CompanyDetail extends Component
{
    public ?Company $company = null;

    public string $companySlug;

    protected ?Collection $contractsCache = null;

    protected ?array $companyStatsCache = null;

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
    }

    /**
     * Select a preset and update consumption.
     */
    public function selectPreset(string $preset): void
    {
        $this->selectedPreset = $preset;

        if (isset($this->presets[$preset])) {
            $this->consumption = $this->presets[$preset]['consumption'];
            $this->clearComputedCaches();
        }
    }

    /**
     * Set the consumption to a specific value (clears preset selection).
     */
    public function setConsumption(int $value): void
    {
        $this->consumption = $value;
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
     * Get company statistics aggregated from all contracts.
     */
    public function getCompanyStatsProperty(): array
    {
        if ($this->companyStatsCache !== null) {
            return $this->companyStatsCache;
        }

        $contracts = $this->contracts;

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
            'spot_contract_count' => $priceApplicableContracts->where('pricing_model', 'Spot')->count(),
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
            return $this->contracts
                ->map(function (ElectricityContract $contract) {
                    $contract->offer_fact = CanonicalOfferFacts::fromCalculatedCost(
                        is_array($contract->calculated_cost ?? null) ? $contract->calculated_cost : [],
                    );

                    return $contract;
                })
                ->filter(fn (ElectricityContract $contract) => $contract->offer_fact !== null)
                ->values();
        }

        return $this->contracts
            ->filter(fn (ElectricityContract $contract) => $contract->hasActiveDiscounts())
            ->map(function (ElectricityContract $contract) {
                $benefit = $contract->calculated_cost['discount_savings_total'] ?? null;
                $benefit = is_numeric($benefit) && (float) $benefit > 0.005 ? (float) $benefit : null;
                $contract->offer_fact = [
                    'label' => $contract->formatActiveDiscountValue() ?? 'Kampanjahinta',
                    'benefit_eur' => $benefit,
                    'benefit_text' => $benefit !== null ? number_format($benefit, 0, ',', ' ').' € / 12 kk' : null,
                    'basis_months' => 12,
                    'basis_label' => '12 kk vertailujakso',
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
        return $this->contracts->where('pricing_model', 'Spot')->values();
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
     * FAQ items, generated from typed fields only.
     *
     * This is the single source for the visible list and for the FAQPage
     * schema, the same rule as `ConsumptionCalculator` and `ContractDetail`.
     * Do not hand-write a second FAQ block in the Blade.
     *
     * Questions use the colon form on purpose. Finnish inflection of arbitrary
     * company names is unsafe, and many sellers are already named "... Sähkö"
     * or "... Energia".
     *
     * @return list<array{question:string,answer:string}>
     */
    public function getFaqItemsProperty(): array
    {
        if (! $this->company) {
            return [];
        }

        $name = $this->company->name;
        $stats = $this->companyStats;
        $consumption = number_format($this->consumption, 0, ',', ' ');
        $items = [];

        if ($stats['min_price'] !== null) {
            $answer = "{$name} tarjoaa tällä hetkellä {$stats['contract_count']} sähkösopimusta. "
                .'Halvin niistä maksaa noin '.$this->euros($stats['min_price'])." vuodessa {$consumption} kWh kulutuksella";

            if ($stats['max_price'] !== null && $stats['max_price'] > $stats['min_price']) {
                $answer .= ', ja kallein noin '.$this->euros($stats['max_price']).' vuodessa';
            }

            $answer .= '. Hinnat sisältävät sähköenergian ja perusmaksun sekä arvonlisäveron 25,5 %. '
                .'Siirtomaksu ei sisälly, koska sen perii verkkoyhtiö.';

            $items[] = [
                'question' => "{$name}: paljonko sähkö maksaa?",
                'answer' => $answer,
            ];
        }

        $spot = $this->spotContracts;

        if ($spot->isNotEmpty()) {
            $margins = $spot
                ->map(fn ($contract) => $contract->calculated_cost['spot_price_margin'] ?? null)
                ->filter(fn ($value) => $value !== null)
                ->map(fn ($value) => (float) $value);

            $answer = "Kyllä. {$name} myy {$spot->count()} pörssisähkösopimusta";

            if ($margins->isNotEmpty()) {
                $answer .= ', ja pienin marginaali on '.$this->cents($margins->min()).' c/kWh';
            }

            $answer .= '. Pörssisähkössä maksat sähkön tuntikohtaisen markkinahinnan, '
                .'yhtiön marginaalin ja kuukausittaisen perusmaksun.';

            $items[] = [
                'question' => "{$name}: onko yhtiöllä pörssisähköä?",
                'answer' => $answer,
            ];
        }

        $promotions = $this->promotionContracts;

        $items[] = [
            'question' => "{$name}: onko yhtiöllä voimassa olevia tarjouksia?",
            'answer' => $promotions->isEmpty()
                ? "{$name} ei tarjoa juuri nyt kampanjahintaisia sopimuksia. "
                    .'Voltikka tarkistaa sopimukset päivittäin, joten uusi tarjous tulee tälle sivulle automaattisesti.'
                : "Kyllä. {$name} tarjoaa juuri nyt {$promotions->count()} kampanjahintaista sopimusta. "
                    .'Voltikka tarkistaa sopimukset päivittäin, joten tarjoukset pysyvät ajan tasalla.',
        ];

        $comparison = $this->marketComparison;

        if ($comparison !== null && $comparison['rows'] !== []) {
            $reference = number_format($comparison['reference_consumption'], 0, ',', ' ');
            $sentences = [];

            foreach (array_slice($comparison['rows'], 0, 3) as $row) {
                $sentences[] = mb_strtolower($row['label']).': '.$this->euros($row['company_value']).' vuodessa, '
                    .'kun markkinan mediaani on '.$this->euros($row['market_median']).'.';
            }

            $items[] = [
                'question' => "{$name}: ovatko hinnat markkinan keskitasoa halvempia?",
                'answer' => "Vertailu {$reference} kWh kulutuksella: ".implode(' ', $sentences)
                    .' Mediaani tarkoittaa, että puolet markkinan sopimuksista on halvempia ja puolet kalliimpia.',
            ];
        }

        return array_slice($items, 0, 5);
    }

    /**
     * FAQPage schema built from the same items the page renders.
     */
    public function getFaqSchemaProperty(): array
    {
        $items = $this->faqItems;

        if ($items === []) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => $this->canonicalUrl.'#faq',
            'mainEntity' => array_map(fn (array $item) => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ], $items),
        ];
    }

    private function euros(float $value): string
    {
        return number_format($value, 0, ',', ' ').' €';
    }

    private function cents(float $value): string
    {
        return number_format($value, 2, ',', ' ');
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

        // Add area served (Finland)
        $schema['areaServed'] = [
            '@type' => 'Country',
            'name' => 'Finland',
        ];

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

        return [
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
    }

    /**
     * Generate ItemList JSON-LD schema for company contract products.
     */
    public function getItemListSchemaProperty(): array
    {
        $contracts = $this->contracts;

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
     * Get the company's price rank among all companies.
     */
    public function getCompanyRankProperty(): ?int
    {
        if (! $this->company) {
            return null;
        }

        return app(ContractRankingService::class)->getCompanyRank($this->company->name);
    }

    /**
     * Get total number of active household contracts across all companies.
     */
    public function getTotalContractsProperty(): int
    {
        return app(ContractRankingService::class)->getTotalActiveContracts();
    }

    /**
     * Get the meta description for this page.
     */
    public function getMetaDescriptionProperty(): string
    {
        if (! $this->company) {
            return 'Vertaile sähkösopimuksia Voltikassa.';
        }

        $stats = $this->companyStats;
        $total = $this->totalContracts;

        return "{$this->company->name}: {$stats['contract_count']} sopimusta vertailussa — katso hinnat ja CO₂-tiedot. Löydä yhtiön edullisin {$total} vaihtoehdosta.";
    }

    /**
     * Get the page title optimized for SEO.
     */
    public function getPageTitleProperty(): string
    {
        if (! $this->company) {
            return 'Sähkösopimukset | Voltikka';
        }

        $stats = $this->companyStats;
        $rank = $this->companyRank;

        if ($rank && $stats['contract_count'] > 0) {
            return "{$this->company->name} | #{$rank} halvin yhtiö — Vertaa {$stats['contract_count']} sopimusta | Voltikka";
        }

        return "{$this->company->name} sähkösopimukset | Voltikka";
    }

    /**
     * Get the H1 heading for the page.
     */
    public function getH1Property(): string
    {
        if (! $this->company) {
            return 'Sähkösopimukset';
        }

        $stats = $this->companyStats;

        return "{$this->company->name} — Kaikki {$stats['contract_count']} sopimusta vertailussa";
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
        $parts = [];

        if ($stats['contract_count'] > 0) {
            $parts[] = "Vertaile {$stats['contract_count']} sähkösopimusta";
        }

        if ($stats['min_price'] !== null) {
            $parts[] = 'hinnat alkaen '.number_format($stats['min_price'], 0, ',', ' ').' €/vuosi';
        }

        if ($stats['avg_renewable_percent'] !== null) {
            if ($stats['avg_renewable_percent'] >= 100) {
                $parts[] = '100% uusiutuvaa energiaa';
            } elseif ($stats['avg_renewable_percent'] >= 50) {
                $parts[] = 'keskimäärin '.number_format($stats['avg_renewable_percent'], 0).'% uusiutuvaa';
            }
        }

        if ($stats['spot_contract_count'] > 0) {
            $parts[] = "{$stats['spot_contract_count']} pörssisähkösopimusta";
        }

        return implode('. ', $parts).'.';
    }

    public function render()
    {
        if (! $this->company) {
            abort(404, 'Yritystä ei löytynyt');
        }

        return view('livewire.company-detail', [
            'contracts' => $this->contracts,
            'companyStats' => $this->companyStats,
            'promotionContracts' => $this->promotionContracts,
            'spotContracts' => $this->spotContracts,
            'marketComparison' => $this->marketComparison,
            'faqItems' => $this->faqItems,
            'schemas' => array_values(array_filter([
                $this->webPageSchema,
                $this->organizationSchema,
                $this->itemListSchema,
                $this->breadcrumbSchema,
                $this->faqSchema,
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
