<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\Municipality;
use App\Models\Postcode;
use App\Models\SpotPriceAverage;
use App\Services\CitySolarService;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use App\Services\LocalContractsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
     * Offer type filter (promotion).
     */
    public ?string $offerType = null;

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
     * Cached municipality instance for city pages.
     */
    protected ?Municipality $municipality = null;

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
        ?string $pricingType = null,
        ?string $offerType = null,
        ?string $location = null
    ): void {
        $this->housingType = $housingType;
        $this->energySource = $energySource;
        // Support both 'city' (legacy) and 'location' (new route param)
        $this->city = $location ?? $city;
        $this->pricingType = $pricingType;
        $this->offerType = $offerType;

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
     *
     * Returns a paginated result for SEO-friendly pagination.
     */
    public function getContractsProperty(): LengthAwarePaginator
    {
        $calculator = app(ContractPriceCalculator::class);

        $query = ElectricityContract::query()
            ->active()
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

        // Apply promotion filter (contracts with active discounts)
        if ($this->offerType === 'promotion') {
            $now = now();
            $query->where(function ($q) use ($now) {
                $q->where('pricing_has_discounts', true)
                  ->orWhereExists(function ($subquery) use ($now) {
                      $subquery->select(DB::raw(1))
                               ->from('price_components')
                               ->whereColumn('price_components.electricity_contract_id', 'electricity_contracts.id')
                               ->where('price_components.has_discount', true)
                               ->where(function ($dateQuery) use ($now) {
                                   $dateQuery->whereNull('price_components.discount_discount_until_date')
                                             ->orWhere('price_components.discount_discount_until_date', '>', $now);
                               });
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
        $sorted = $contracts->sort(function ($a, $b) {
            // First sort by exceeds_consumption_limit (false first, true last)
            $aExceeds = $a->exceeds_consumption_limit ? 1 : 0;
            $bExceeds = $b->exceeds_consumption_limit ? 1 : 0;
            if ($aExceeds !== $bExceeds) {
                return $aExceeds - $bExceeds;
            }
            // Then sort by total cost (ascending)
            $aCost = $a->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX;
            $bCost = $b->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX;
            return $aCost <=> $bCost;
        })->values();

        // For city pages, exclude contracts already shown in local/regional sections
        if ($this->city) {
            $localData = $this->localContractsData;
            $excludedIds = $localData['excluded_ids'] ?? [];
            if (!empty($excludedIds)) {
                $sorted = $sorted->filter(fn ($c) => !in_array($c->id, $excludedIds))->values();
            }
        }

        // Create a manual paginator from the sorted collection
        $total = $sorted->count();
        $page = max(1, $this->page);
        $perPage = $this->perPage;

        // Calculate offset and get the slice for current page
        $offset = ($page - 1) * $perPage;
        $items = $sorted->slice($offset, $perPage)->values();

        // Create and return a LengthAwarePaginator
        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => url($this->basePath),
                'pageName' => 'page',
            ]
        );
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
        if ($this->offerType === 'promotion') {
            return 'Sähkötarjoukset ja alennukset | Voltikka';
        }

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
        if ($this->offerType === 'promotion') {
            return 'Löydä parhaat sähkötarjoukset ja alennukset. Vertaile kampanjahintaisia sähkösopimuksia ja säästä sähkölaskussa.';
        }

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

        if ($this->offerType === 'promotion') {
            return "{$baseUrl}/sahkosopimus/sahkotarjous";
        }

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
            return "{$baseUrl}/sahkosopimus/paikkakunnat/{$this->city}";
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

            $offer = [
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
            ];

            // Add promotion info if contract has active discounts
            if ($contract->hasActiveDiscounts()) {
                $discountInfo = $contract->getActiveDiscountInfo();
                if ($discountInfo) {
                    // Add priceValidUntil if until_date exists
                    if ($discountInfo['until_date']) {
                        $offer['priceValidUntil'] = $discountInfo['until_date']->format('Y-m-d');
                    }

                    // Build discount description
                    $discountDesc = '';
                    if ($discountInfo['is_percentage'] && $discountInfo['value']) {
                        $discountDesc = '-' . number_format($discountInfo['value'], 0) . '%';
                    } elseif ($discountInfo['value']) {
                        $discountDesc = '-' . number_format($discountInfo['value'], 2, ',', '') . ' c/kWh';
                    }
                    if ($discountInfo['n_first_months'] && $discountDesc) {
                        $discountDesc .= ' ensimmäiset ' . $discountInfo['n_first_months'] . ' kk';
                    }
                    if ($discountDesc) {
                        $offer['description'] = $discountDesc;
                    }
                }
            }

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
                    'offers' => $offer,
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
        if ($this->offerType === 'promotion') {
            return 'Sähkötarjoukset ja alennukset';
        }

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
        if ($this->offerType === 'promotion') {
            return 'Löydä parhaat sähkötarjoukset ja kampanjat. Monet sähköyhtiöt tarjoavat alennuksia uusille asiakkaille, kuten alennettuja hintoja ensimmäisille kuukausille tai prosenttialennuksia energiahinnasta. Vertaile tarjouksia ja säästä sähkölaskussa.';
        }

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
        // Look up municipality from database
        $municipality = $this->getMunicipality($slug);

        if ($municipality) {
            return [
                'name' => $municipality->name,
                'locative' => $municipality->name_locative,
                'genitive' => $municipality->name_genitive,
                'municipality' => $municipality,
            ];
        }

        // Fallback: capitalize first letter and add generic locative
        $name = Str::title(str_replace('-', ' ', $slug));
        return [
            'name' => $name,
            'locative' => "{$name}ssa", // Generic -ssa ending
            'genitive' => "{$name}n",
            'municipality' => null,
        ];
    }

    /**
     * Get the municipality for the current city slug.
     */
    protected function getMunicipality(string $slug): ?Municipality
    {
        if ($this->municipality === null || $this->municipality->slug !== $slug) {
            $this->municipality = Municipality::where('slug', $slug)->first();
        }
        return $this->municipality;
    }

    /**
     * Get the municipality property for the view.
     */
    public function getMunicipalityProperty(): ?Municipality
    {
        if (!$this->city) {
            return null;
        }
        return $this->getMunicipality($this->city);
    }

    /**
     * Get solar estimate for the current city.
     */
    public function getSolarEstimateProperty(): ?array
    {
        $municipality = $this->municipality;
        if (!$municipality) {
            return null;
        }

        $service = app(CitySolarService::class);
        return $service->getSolarEstimate($municipality);
    }

    /**
     * Get local contracts for the current city.
     * Returns array with local_companies, regional_contracts, and has_content keys.
     */
    public function getLocalContractsDataProperty(): array
    {
        $municipality = $this->municipality;
        if (!$municipality) {
            return [
                'local_companies' => collect(),
                'regional_contracts' => collect(),
                'has_content' => false,
                'excluded_ids' => [],
            ];
        }

        $service = app(LocalContractsService::class);
        $data = $service->getLocalContracts($municipality, $this->consumption);

        // Collect IDs to exclude from main listing
        $excludedIds = $data['local_companies']->pluck('id')
            ->merge($data['regional_contracts']->pluck('id'))
            ->unique()
            ->toArray();

        return [
            'local_companies' => $data['local_companies'],
            'regional_contracts' => $data['regional_contracts'],
            'has_content' => $data['has_content'],
            'excluded_ids' => $excludedIds,
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
            || $this->city !== null
            || $this->offerType !== null;
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
            'citySlug' => $this->city,
            'municipality' => $this->municipality,
            'solarEstimate' => $this->solarEstimate,
            'localContractsData' => $this->localContractsData,
            'basePath' => $this->basePath,
            'showSeoFilterLinks' => $this->showSeoFilterLinks,
        ])->layout('layouts.app', [
            'title' => $this->seoData['title'],
            'metaDescription' => $this->seoData['description'],
            'canonical' => $this->seoData['canonical'],
        ]);
    }
}
