<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class SeoContractsList extends ContractsList
{
    /**
     * Housing type filter (omakotitalo, kerrostalo, rivitalo).
     */
    public ?string $housingType = null;

    /**
     * Energy source filter (tuulisahko, aurinkosahko, vihrea-sahko).
     */
    public ?string $energySource = null;

    /**
     * City filter slug.
     */
    public ?string $city = null;

    /**
     * Housing type to consumption mapping.
     */
    protected array $housingTypeConsumption = [
        'omakotitalo' => 18000,
        'kerrostalo' => 5000,
        'rivitalo' => 10000,
    ];

    /**
     * Housing type display names in Finnish.
     */
    protected array $housingTypeNames = [
        'omakotitalo' => 'Omakotitalo',
        'kerrostalo' => 'Kerrostalo',
        'rivitalo' => 'Rivitalo',
    ];

    /**
     * Energy source display names in Finnish.
     */
    protected array $energySourceNames = [
        'tuulisahko' => 'Tuulisähkö',
        'aurinkosahko' => 'Aurinkosähkö',
        'vihrea-sahko' => 'Vihreä sähkö',
    ];

    /**
     * Finnish city names with locative forms (ssa/ssä).
     */
    protected array $cityLocativeForms = [
        'helsinki' => ['name' => 'Helsinki', 'locative' => 'Helsingissä'],
        'espoo' => ['name' => 'Espoo', 'locative' => 'Espoossa'],
        'tampere' => ['name' => 'Tampere', 'locative' => 'Tampereella'],
        'vantaa' => ['name' => 'Vantaa', 'locative' => 'Vantaalla'],
        'oulu' => ['name' => 'Oulu', 'locative' => 'Oulussa'],
        'turku' => ['name' => 'Turku', 'locative' => 'Turussa'],
        'jyvaskyla' => ['name' => 'Jyväskylä', 'locative' => 'Jyväskylässä'],
        'lahti' => ['name' => 'Lahti', 'locative' => 'Lahdessa'],
        'kuopio' => ['name' => 'Kuopio', 'locative' => 'Kuopiossa'],
        'pori' => ['name' => 'Pori', 'locative' => 'Porissa'],
        'kouvola' => ['name' => 'Kouvola', 'locative' => 'Kouvolassa'],
        'joensuu' => ['name' => 'Joensuu', 'locative' => 'Joensuussa'],
        'lappeenranta' => ['name' => 'Lappeenranta', 'locative' => 'Lappeenrannassa'],
        'vaasa' => ['name' => 'Vaasa', 'locative' => 'Vaasassa'],
        'hameenlinna' => ['name' => 'Hämeenlinna', 'locative' => 'Hämeenlinnassa'],
        'seinajoki' => ['name' => 'Seinäjoki', 'locative' => 'Seinäjoella'],
        'rovaniemi' => ['name' => 'Rovaniemi', 'locative' => 'Rovaniemellä'],
        'mikkeli' => ['name' => 'Mikkeli', 'locative' => 'Mikkelissä'],
        'kotka' => ['name' => 'Kotka', 'locative' => 'Kotkassa'],
        'salo' => ['name' => 'Salo', 'locative' => 'Salossa'],
    ];

    /**
     * Mount the component with optional filter parameters.
     */
    public function mount(
        ?string $housingType = null,
        ?string $energySource = null,
        ?string $city = null
    ): void {
        $this->housingType = $housingType;
        $this->energySource = $energySource;
        $this->city = $city;

        // Set consumption based on housing type
        if ($housingType && isset($this->housingTypeConsumption[$housingType])) {
            $this->consumption = $this->housingTypeConsumption[$housingType];
        }
    }

    /**
     * Get contracts with SEO-specific filtering applied.
     */
    public function getContractsProperty(): Collection
    {
        // Get base contracts from parent
        $contracts = parent::getContractsProperty();

        // Apply energy source filters based on SEO parameter
        if ($this->energySource) {
            $contracts = $this->filterByEnergySource($contracts);
        }

        return $contracts;
    }

    /**
     * Filter contracts by energy source type.
     */
    protected function filterByEnergySource(Collection $contracts): Collection
    {
        return match ($this->energySource) {
            'tuulisahko' => $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;
                return $source && $source->renewable_wind > 0;
            }),
            'aurinkosahko' => $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;
                return $source && $source->renewable_solar > 0;
            }),
            'vihrea-sahko' => $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;
                return $source
                    && $source->renewable_total >= 50
                    && ($source->fossil_peat === null || $source->fossil_peat === 0.0);
            }),
            default => $contracts,
        };
    }

    /**
     * Get SEO data for the current filter context.
     */
    public function getSeoDataProperty(): array
    {
        return [
            'title' => $this->generateSeoTitle(),
            'description' => $this->generateMetaDescription(),
            'canonical' => $this->generateCanonicalUrl(),
            'jsonLd' => $this->generateJsonLd(),
        ];
    }

    /**
     * Generate SEO-optimized title based on filters.
     */
    protected function generateSeoTitle(): string
    {
        if ($this->housingType && isset($this->housingTypeNames[$this->housingType])) {
            return "Sähkösopimukset {$this->housingTypeNames[$this->housingType]}on | Voltikka";
        }

        if ($this->energySource && isset($this->energySourceNames[$this->energySource])) {
            return "{$this->energySourceNames[$this->energySource]}sopimukset | Voltikka";
        }

        if ($this->city) {
            $cityData = $this->getCityData($this->city);
            return "Sähkösopimukset {$cityData['locative']} | Voltikka";
        }

        return 'Vertaa sähkösopimuksia | Voltikka';
    }

    /**
     * Generate meta description based on filters.
     */
    protected function generateMetaDescription(): string
    {
        if ($this->housingType && isset($this->housingTypeNames[$this->housingType])) {
            $housingName = mb_strtolower($this->housingTypeNames[$this->housingType]);
            $consumption = $this->housingTypeConsumption[$this->housingType] ?? 5000;
            return "Vertaile sähkösopimuksia {$housingName}on. Keskimääräinen kulutus {$consumption} kWh/vuosi. Löydä edullisin sähkösopimus helposti.";
        }

        if ($this->energySource && isset($this->energySourceNames[$this->energySource])) {
            $sourceName = mb_strtolower($this->energySourceNames[$this->energySource]);
            return "Vertaile tuulisähkö- ja {$sourceName}sopimuksia. Valitse ympäristöystävällinen sähkösopimus.";
        }

        if ($this->city) {
            $cityData = $this->getCityData($this->city);
            return "Sähkösopimukset {$cityData['locative']}. Vertaile hintoja ja löydä paras sähkösopimus {$cityData['name']}n alueelle.";
        }

        return 'Vertaile sähkösopimuksia helposti. Löydä edullisin sähkösopimus kotiisi tai yritykseesi.';
    }

    /**
     * Generate canonical URL based on filters.
     */
    protected function generateCanonicalUrl(): string
    {
        $baseUrl = config('app.url');

        if ($this->housingType) {
            return "{$baseUrl}/sahkosopimus/{$this->housingType}";
        }

        if ($this->energySource) {
            return "{$baseUrl}/sahkosopimus/{$this->energySource}";
        }

        if ($this->city) {
            return "{$baseUrl}/sahkosopimus/{$this->city}";
        }

        return "{$baseUrl}/sahkosopimus";
    }

    /**
     * Generate JSON-LD structured data for product listings.
     */
    protected function generateJsonLd(): array
    {
        $contracts = $this->contracts;
        $items = [];

        foreach ($contracts as $index => $contract) {
            $prices = $this->getLatestPrices($contract);
            $generalPrice = $prices['General']['price'] ?? null;
            $monthlyFee = $prices['Monthly']['price'] ?? 0;

            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => [
                    '@type' => 'Product',
                    'name' => $contract->name,
                    'description' => $contract->short_description ?? "Sähkösopimus yritykseltä {$contract->company?->name}",
                    'brand' => [
                        '@type' => 'Brand',
                        'name' => $contract->company?->name ?? 'Unknown',
                    ],
                    'offers' => [
                        '@type' => 'Offer',
                        'priceCurrency' => 'EUR',
                        'price' => $contract->calculated_cost['total_cost'] ?? 0,
                        'priceSpecification' => [
                            '@type' => 'UnitPriceSpecification',
                            'price' => $generalPrice,
                            'priceCurrency' => 'EUR',
                            'unitCode' => 'KWH',
                            'unitText' => 'c/kWh',
                        ],
                    ],
                ],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $this->generateSeoTitle(),
            'itemListElement' => $items,
        ];
    }

    /**
     * Get the page heading (H1) based on filters.
     */
    public function getPageHeadingProperty(): string
    {
        if ($this->housingType && isset($this->housingTypeNames[$this->housingType])) {
            return "Sähkösopimukset {$this->housingTypeNamesLocative()[$this->housingType]}";
        }

        if ($this->energySource && isset($this->energySourceNames[$this->energySource])) {
            return "{$this->energySourceNames[$this->energySource]}sopimukset";
        }

        if ($this->city) {
            $cityData = $this->getCityData($this->city);
            return "Sähkösopimukset {$cityData['locative']}";
        }

        return 'Vertaa sähkösopimuksia';
    }

    /**
     * Get housing type names in locative form (with -on/-oon ending).
     */
    protected function housingTypeNamesLocative(): array
    {
        return [
            'omakotitalo' => 'omakotitaloon',
            'kerrostalo' => 'kerrostaloon',
            'rivitalo' => 'rivitaloon',
        ];
    }

    /**
     * Get SEO intro text for the page.
     */
    public function getSeoIntroTextProperty(): string
    {
        if ($this->housingType && isset($this->housingTypeNames[$this->housingType])) {
            return $this->getHousingTypeIntroText($this->housingType);
        }

        if ($this->energySource && isset($this->energySourceNames[$this->energySource])) {
            return match ($this->energySource) {
                'tuulisahko' => 'Tuulisähkösopimukset tuotetaan tuulivoimalla. Vertaile tuulisähkön hintoja.',
                'aurinkosahko' => 'Aurinkosähkösopimukset hyödyntävät aurinkoenergiaa. Vertaile aurinkosähkön hintoja.',
                'vihrea-sahko' => 'Vihreä sähkö tuotetaan uusiutuvilla energialähteillä. Vertaile ympäristöystävällisiä sopimuksia.',
                default => 'Vertaile sähkösopimuksia.',
            };
        }

        if ($this->city) {
            $cityData = $this->getCityData($this->city);
            return "Vertaile sähkösopimuksia {$cityData['locative']}. Löydä paras sähkösopimus {$cityData['name']}n alueelle.";
        }

        return 'Vertaile sähkösopimuksia ja löydä edullisin vaihtoehto.';
    }

    /**
     * Get detailed intro text for housing type pages.
     */
    protected function getHousingTypeIntroText(string $housingType): string
    {
        $consumption = $this->housingTypeConsumption[$housingType] ?? 5000;
        $formattedConsumption = number_format($consumption, 0, ',', ' ');

        return match ($housingType) {
            'omakotitalo' => "Vertaile sähkösopimuksia omakotitaloon. Omakotitalon keskimääräinen sähkönkulutus on noin {$formattedConsumption} kWh vuodessa. Sähkölämmitteisessä omakotitalossa kulutus voi olla jopa 20 000–25 000 kWh. Löydä edullisin sähkösopimus vertailemalla.",
            'kerrostalo' => "Vertaile sähkösopimuksia kerrostaloon. Kerrostaloasunnon tyypillinen sähkönkulutus on noin {$formattedConsumption} kWh vuodessa. Sähkönkulutus koostuu pääasiassa kodinkoneista, valaistuksesta ja viihde-elektroniikasta. Vertaile hintoja ja säästä.",
            'rivitalo' => "Vertaile sähkösopimuksia rivitaloon. Rivitalon keskimääräinen sähkönkulutus on noin {$formattedConsumption} kWh vuodessa. Rivitalossa kulutus on tyypillisesti suurempi kuin kerrostalossa, mutta pienempi kuin omakotitalossa. Löydä sopiva sopimus.",
            default => "Vertaile sähkösopimuksia. Tyypillinen kulutus on noin {$formattedConsumption} kWh vuodessa.",
        };
    }

    /**
     * Get city data with proper Finnish name and locative form.
     */
    protected function getCityData(string $slug): array
    {
        if (isset($this->cityLocativeForms[$slug])) {
            return $this->cityLocativeForms[$slug];
        }

        // Fallback: capitalize first letter and add generic locative
        $name = Str::title(str_replace('-', ' ', $slug));
        return [
            'name' => $name,
            'locative' => "{$name}ssa", // Generic -ssa ending
        ];
    }

    /**
     * Check if we have any SEO filter active.
     */
    public function getHasSeoFilterProperty(): bool
    {
        return $this->housingType !== null
            || $this->energySource !== null
            || $this->city !== null;
    }

    public function render()
    {
        return view('livewire.seo-contracts-list', [
            'contracts' => $this->contracts,
            'postcodeSuggestions' => $this->postcodeSuggestions,
            'seoData' => $this->seoData,
            'pageHeading' => $this->pageHeading,
            'seoIntroText' => $this->seoIntroText,
            'hasSeoFilter' => $this->hasSeoFilter,
        ])->layout('layouts.app', [
            'title' => $this->seoData['title'],
        ]);
    }
}
