<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\Postcode;
use App\Models\SpotPriceAverage;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
     * Pricing type filter (Spot, FixedPrice).
     */
    public ?string $pricingType = null;

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
     * Pricing type display names in Finnish.
     */
    protected array $pricingTypeNames = [
        'Spot' => 'Pörssisähkö',
        'FixedPrice' => 'Kiinteähintainen',
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
     * Housing type to preset mapping.
     */
    protected array $housingTypePresetMapping = [
        'omakotitalo' => 'large_house_electric',
        'kerrostalo' => 'large_apartment',
        'rivitalo' => 'row_house',
    ];

    /**
     * Mount the component with optional filter parameters.
     */
    public function mount(
        ?string $housingType = null,
        ?string $energySource = null,
        ?string $city = null,
        ?string $pricingType = null
    ): void {
        $this->housingType = $housingType;
        $this->energySource = $energySource;
        $this->city = $city;
        $this->pricingType = $pricingType;

        // Set consumption and preset based on housing type
        if ($housingType && isset($this->housingTypeConsumption[$housingType])) {
            $this->consumption = $this->housingTypeConsumption[$housingType];

            // Also select the appropriate preset
            if (isset($this->housingTypePresetMapping[$housingType])) {
                $this->selectedPreset = $this->housingTypePresetMapping[$housingType];
            }
        }
    }

    /**
     * Get contracts with SEO-specific filtering applied.
     *
     * Overrides parent method to apply city filtering at database level
     * for memory optimization - avoids loading all pivot records.
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

        // Apply pricing model filter from parent
        if ($this->pricingModelFilter !== '') {
            $query->where('pricing_model', $this->pricingModelFilter);
        }

        // Apply SEO pricing type filter (overrides pricingModelFilter if set)
        if ($this->pricingType) {
            $query->where('pricing_model', $this->pricingType);
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

        // Apply city filter at database level (memory optimization)
        // This avoids loading all pivot records into memory
        if ($this->city) {
            $cityData = $this->getCityData($this->city);
            $cityName = $cityData['name'];

            $query->where(function ($q) use ($cityName) {
                $q->where('availability_is_national', true)
                  ->orWhereExists(function ($subquery) use ($cityName) {
                      $subquery->select(DB::raw(1))
                               ->from('contract_postcode')
                               ->join('postcodes', 'contract_postcode.postcode', '=', 'postcodes.postcode')
                               ->whereColumn('contract_postcode.contract_id', 'electricity_contracts.id')
                               ->where('postcodes.municipal_name_fi', $cityName);
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

        // Apply SEO energy source filters
        if ($this->energySource) {
            $contracts = $this->filterByEnergySource($contracts);
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
        $contracts = $contracts->map(function ($contract) use ($calculator, $emissionsCalculator, $spotPriceDay, $spotPriceNight, $consumption) {
            $priceComponents = $contract->priceComponents
                ->sortByDesc('price_date')
                ->groupBy('price_component_type')
                ->map(fn ($group) => $group->sortByDesc('price')->first()) // Prefer non-zero prices
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

        if ($this->pricingType && isset($this->pricingTypeNames[$this->pricingType])) {
            return "{$this->pricingTypeNames[$this->pricingType]}sopimukset | Voltikka";
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

        if ($this->pricingType && isset($this->pricingTypeNames[$this->pricingType])) {
            $pricingName = mb_strtolower($this->pricingTypeNames[$this->pricingType]);
            if ($this->pricingType === 'Spot') {
                return "Vertaile pörssisähkösopimuksia. Pörssisähkö seuraa tuntikohtaista sähkön pörssihintaa. Löydä paras pörssisähkösopimus.";
            }
            return "Vertaile kiinteähintaisia sähkösopimuksia. Kiinteä hinta tuo ennustettavuutta sähkölaskuun. Löydä paras kiinteähintainen sopimus.";
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

        if ($this->pricingType) {
            $slug = $this->pricingType === 'Spot' ? 'porssisahko' : 'kiintea-hinta';
            return "{$baseUrl}/sahkosopimus/{$slug}";
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

        if ($this->pricingType && isset($this->pricingTypeNames[$this->pricingType])) {
            return "{$this->pricingTypeNames[$this->pricingType]}sopimukset";
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
            return $this->getEnergySourceIntroText($this->energySource);
        }

        if ($this->pricingType && isset($this->pricingTypeNames[$this->pricingType])) {
            return $this->getPricingTypeIntroText($this->pricingType);
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
     * Get detailed intro text for energy source pages.
     */
    protected function getEnergySourceIntroText(string $energySource): string
    {
        return match ($energySource) {
            'tuulisahko' => 'Tuulisähkö on yksi puhtaimmista energiamuodoista. Tuulivoimalla tuotettu sähkö on täysin päästötöntä käytössä eikä aiheuta hiilidioksidipäästöjä. Suomen tuulivoimakapasiteetti kasvaa jatkuvasti, ja tuulisähkö on yhä edullisempi vaihtoehto. Vertaile tuulisähkösopimuksia ja tue kotimaista tuulivoimatuotantoa.',
            'aurinkosahko' => 'Aurinkosähkö on uusiutuvaa energiaa, joka hyödyntää aurinkoenergiaa suoraan. Aurinkopaneelit tuottavat sähköä ilman päästöjä tai melua. Vaikka Suomen talvet ovat pimeitä, kesällä aurinkoenergiaa on runsaasti saatavilla. Aurinkosähkösopimuksella tuet puhtaan energian tuotantoa ja vähennät hiilijalanjälkeäsi.',
            'vihrea-sahko' => 'Vihreä sähkö tuotetaan uusiutuvilla energialähteillä kuten tuuli-, aurinko- ja vesivoimalla. Vihreän sähkön valitsemalla vähennät hiilidioksidipäästöjä ja tuet kestävää energiantuotantoa. Vertaile vihreän sähkön sopimuksia ja tee ympäristöystävällinen valinta.',
            default => 'Vertaile sähkösopimuksia ja löydä ympäristöystävällinen vaihtoehto.',
        };
    }

    /**
     * Get detailed intro text for pricing type pages.
     */
    protected function getPricingTypeIntroText(string $pricingType): string
    {
        return match ($pricingType) {
            'Spot' => 'Pörssisähkösopimuksessa sähkön hinta vaihtelee tunneittain Nord Pool -sähköpörssin hinnan mukaan. Pörssisähkö voi olla edullinen vaihtoehto, jos pystyt ajoittamaan kulutustasi edullisempiin tunteihin. Vertaile pörssisähkösopimuksia ja löydä sopimus, jossa marginaali ja kuukausimaksu sopivat sinulle.',
            'FixedPrice' => 'Kiinteähintaisessa sähkösopimuksessa maksat saman hinnan jokaisesta kilowattitunnista sopimuskauden ajan. Kiinteä hinta tuo ennustettavuutta sähkölaskuun ja suojaa markkinaheilahteluilta. Vertaile kiinteähintaisia sopimuksia ja löydä paras tarjous.',
            default => 'Vertaile sähkösopimuksia ja löydä edullisin vaihtoehto.',
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
            || $this->pricingType !== null
            || $this->city !== null;
    }

    /**
     * Get aggregated energy source statistics for the current contracts.
     */
    public function getEnergySourceStatsProperty(): array
    {
        if (!$this->energySource) {
            return [];
        }

        $contracts = $this->contracts;
        $totalContracts = $contracts->count();

        if ($totalContracts === 0) {
            return [];
        }

        $stats = [
            'total_contracts' => $totalContracts,
            'avg_renewable' => 0,
            'avg_wind' => 0,
            'avg_solar' => 0,
            'avg_hydro' => 0,
            'avg_nuclear' => 0,
            'fully_renewable_count' => 0,
            'fossil_free_count' => 0,
        ];

        $sumRenewable = 0;
        $sumWind = 0;
        $sumSolar = 0;
        $sumHydro = 0;
        $sumNuclear = 0;

        foreach ($contracts as $contract) {
            $source = $contract->electricitySource;
            if ($source) {
                $sumRenewable += $source->renewable_total ?? 0;
                $sumWind += $source->renewable_wind ?? 0;
                $sumSolar += $source->renewable_solar ?? 0;
                $sumHydro += $source->renewable_hydro ?? 0;
                $sumNuclear += $source->nuclear_total ?? 0;

                if ($source->isFullyRenewable()) {
                    $stats['fully_renewable_count']++;
                }
                if ($source->isFossilFree()) {
                    $stats['fossil_free_count']++;
                }
            }
        }

        $stats['avg_renewable'] = round($sumRenewable / $totalContracts, 1);
        $stats['avg_wind'] = round($sumWind / $totalContracts, 1);
        $stats['avg_solar'] = round($sumSolar / $totalContracts, 1);
        $stats['avg_hydro'] = round($sumHydro / $totalContracts, 1);
        $stats['avg_nuclear'] = round($sumNuclear / $totalContracts, 1);

        return $stats;
    }

    /**
     * Get environmental impact description for energy source.
     */
    public function getEnvironmentalInfoProperty(): ?string
    {
        if (!$this->energySource) {
            return null;
        }

        return match ($this->energySource) {
            'tuulisahko' => 'Tuulivoima on yksi vähäpäästöisimmistä sähköntuotantomuodoista. Tuulivoimalan elinkaaren aikaiset CO₂-päästöt ovat noin 7-15 g/kWh, kun fossiilisilla polttoaineilla tuotetun sähkön päästöt ovat 400-1000 g/kWh. Valitsemalla tuulisähkön vähennät merkittävästi hiilijalanjälkeäsi.',
            'aurinkosahko' => 'Aurinkosähkön tuotannon elinkaaren aikaiset CO₂-päästöt ovat noin 20-50 g/kWh, mikä on murto-osa fossiilisiin polttoaineisiin verrattuna. Aurinkopaneelit eivät tuota käytön aikana päästöjä, melua tai jätettä. Aurinkosähkö on erityisen puhdas vaihtoehto.',
            'vihrea-sahko' => 'Vihreä sähkö tuotetaan ilman merkittäviä hiilidioksidipäästöjä. Uusiutuvien energialähteiden keskimääräiset elinkaaren päästöt ovat alle 50 g/kWh, kun fossiilisten polttoaineiden päästöt ovat moninkertaiset. Vihreän sähkön valinta on tehokas tapa pienentää kotitaloutesi ilmastovaikutusta.',
            default => null,
        };
    }

    /**
     * Get city-specific information.
     */
    public function getCityInfoProperty(): ?array
    {
        if (!$this->city) {
            return null;
        }

        $cityData = $this->getCityData($this->city);
        $contracts = $this->contracts;

        // Get unique providers
        $providers = $contracts->pluck('company_name')->unique();

        return [
            'name' => $cityData['name'],
            'locative' => $cityData['locative'],
            'contracts_count' => $contracts->count(),
            'providers_count' => $providers->count(),
            'providers' => $providers->values()->toArray(),
        ];
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
            'energySourceStats' => $this->energySourceStats,
            'environmentalInfo' => $this->environmentalInfo,
            'isEnergySourcePage' => $this->energySource !== null,
            'isPricingTypePage' => $this->pricingType !== null,
            'isCityPage' => $this->city !== null,
            'cityInfo' => $this->cityInfo,
            'basePath' => $this->basePath,
            'showSeoFilterLinks' => $this->showSeoFilterLinks,
        ])->layout('layouts.app', [
            'title' => $this->seoData['title'],
            'metaDescription' => $this->seoData['description'],
            'canonical' => $this->seoData['canonical'],
        ]);
    }
}
