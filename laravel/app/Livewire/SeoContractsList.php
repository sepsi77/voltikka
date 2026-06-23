<?php

namespace App\Livewire;

use App\Http\Middleware\SetPublicCacheHeaders;
use App\Models\ElectricityContract;
use App\Models\Municipality;
use App\Models\Postcode;
use App\Models\SpotPriceAverage;
use App\Services\Caching\ContractPageCacheVersion;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractMarketInsights\ContractMarketInsightService;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use App\Services\LocalContractsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
     * Target group filter (Company for business pages).
     */
    public ?string $targetGroup = null;

    /**
     * Contract duration filter (FixedTerm, OpenEnded).
     */
    public ?string $contractDuration = null;

    /**
     * Consumption level filter (2000, 5000, 10000, 20000).
     */
    public ?string $consumptionLevel = null;

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
        'fossiiliton' => 'Fossiiliton sähkö',
        'uusiutuva-sahko' => 'Uusiutuva sähkö',
        'ydinvoima' => 'Ydinvoimasähkö',
    ];

    /**
     * Contract duration display names in Finnish.
     */
    protected array $contractDurationNames = [
        'FixedTerm' => 'Määräaikainen',
        'OpenEnded' => 'Toistaiseksi voimassa oleva',
    ];

    /**
     * Pricing type display names in Finnish.
     */
    protected array $pricingTypeNames = [
        'Spot' => 'Pörssisähkö',
        'FixedPrice' => 'Kiinteä hinta',
        'Quarterly' => 'Kvartaalisähkö',
        'TimeOfUse' => 'Aikasähkö',
        'Seasonal' => 'Kausisähkö',
        'Hybrid' => 'Joustosähkö',
        'GeneralElectricity' => 'Yleissähkö',
    ];

    /**
     * Cheapest annual total cost from the current contract listing.
     */
    protected ?float $cheapestTotalCost = null;

    /**
     * Cached municipality instance for city pages.
     *
     * Keep a separate loaded flag/slug so missing municipalities are also
     * memoized. Otherwise unknown slugs repeatedly query municipalities every
     * time SEO title/meta/heading/city widgets ask for city data in one render.
     */
    protected ?Municipality $municipality = null;

    protected bool $municipalityLoaded = false;

    protected ?string $municipalityLoadedSlug = null;

    /**
     * Cached city data arrays by slug for the current request/render.
     *
     * @var array<string, array{name: string, locative: string, genitive: string, municipality: ?Municipality}>
     */
    protected array $cityDataCache = [];

    /**
     * Cached city-local contract data for the current request/render.
     */
    protected ?array $localContractsDataCache = null;

    /**
     * Business consumption presets.
     */
    protected array $businessPresets = [
        'small_office' => ['label' => 'Pieni toimisto',           'description' => '5-10 hlö, 150 m²',      'icon' => 'office',     'consumption' => 20000],
        'medium_office' => ['label' => 'Keskikokoinen toimisto',   'description' => '20-50 hlö, 500 m²',     'icon' => 'office',     'consumption' => 50000],
        'large_office' => ['label' => 'Suuri toimisto',           'description' => '100+ hlö, 1500 m²',     'icon' => 'office',     'consumption' => 150000],
        'small_retail' => ['label' => 'Pieni myymälä',            'description' => '100-200 m²',             'icon' => 'retail',     'consumption' => 30000],
        'medium_retail' => ['label' => 'Keskisuuri myymälä',       'description' => '500-1000 m²',            'icon' => 'retail',     'consumption' => 100000],
        'restaurant' => ['label' => 'Ravintola',                'description' => '50-100 asiakaspaikkaa',  'icon' => 'restaurant', 'consumption' => 80000],
        'small_warehouse' => ['label' => 'Pieni varasto',            'description' => '500 m²',                 'icon' => 'warehouse',  'consumption' => 40000],
        'small_production' => ['label' => 'Pieni tuotantolaitos',     'description' => 'Kevyt teollisuus',       'icon' => 'factory',    'consumption' => 200000],
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
     * Consumption level display names.
     */
    protected array $consumptionLevelNames = [
        '2000' => "2\u{00A0}000\u{00A0}kWh",
        '5000' => "5\u{00A0}000\u{00A0}kWh",
        '10000' => "10\u{00A0}000\u{00A0}kWh",
        '18000' => "18\u{00A0}000\u{00A0}kWh",
        '20000' => "20\u{00A0}000\u{00A0}kWh",
    ];

    /**
     * Consumption level to preset mapping.
     */
    protected array $consumptionLevelPresets = [
        '2000' => 'small_apartment',
        '5000' => 'large_apartment',
        '10000' => 'row_house',
        '18000' => 'large_house_electric',
        '20000' => 'large_house_electric',
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
        ?string $location = null,
        ?string $targetGroup = null,
        ?string $contractDuration = null,
        ?string $consumptionLevel = null
    ): void {
        $this->housingType = $housingType;
        $this->energySource = $energySource;
        // Support both 'city' (legacy) and 'location' (new route param)
        $this->city = $location ?? $city;

        if ($this->city !== null && $this->getMunicipality($this->city) === null) {
            abort(404);
        }

        $this->pricingType = $pricingType;
        $this->offerType = $offerType;
        $this->targetGroup = $targetGroup;
        $this->contractDuration = $contractDuration;
        $this->consumptionLevel = $consumptionLevel;

        // Set basePath from the current request so pagination stays on this page
        $this->basePath = '/'.ltrim(request()->path(), '/');

        // Business page: override presets, consumption, and pricing models
        if ($targetGroup === 'Company') {
            $this->presets = $this->businessPresets;
            $this->consumption = 20000;
            $this->selectedPreset = 'small_office';
            $this->pricingModels = [
                'FixedPrice' => 'Kiinteä hinta',
                'Spot' => 'Pörssisähkö',
                'Hybrid' => 'Hybridi',
            ];
            $this->activeTab = 'presets';
        }

        // Set consumption and preset based on housing type
        if ($housingType && isset($this->housingTypeConsumption[$housingType])) {
            $this->consumption = $this->housingTypeConsumption[$housingType];

            // Also select the appropriate preset
            if (isset($this->housingTypePresetMapping[$housingType])) {
                $this->selectedPreset = $this->housingTypePresetMapping[$housingType];
            }
        }

        // Set consumption and preset based on consumption level
        if ($consumptionLevel && isset($this->consumptionLevelNames[$consumptionLevel])) {
            $this->consumption = (int) $consumptionLevel;

            if (isset($this->consumptionLevelPresets[$consumptionLevel])) {
                $this->selectedPreset = $this->consumptionLevelPresets[$consumptionLevel];
            }
        }

        $this->normalizePageProperty();
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
        if ($this->contractsCache !== null) {
            return $this->contractsCache;
        }

        $query = ElectricityContract::query()
            ->active()
            ->with(['electricitySource'])
            // Filter by target group
            ->where(function ($q) {
                if ($this->targetGroup === 'Company') {
                    $q->whereIn('target_group', ['Company', 'Both']);
                } else {
                    $q->whereIn('target_group', ['Household', 'Both'])
                        ->orWhereNull('target_group');
                }
            });

        // Apply contract type filter (FixedTerm, OpenEnded)
        if ($this->contractTypeFilter !== '') {
            $query->where('contract_type', $this->contractTypeFilter);
        }

        // Apply SEO contract duration filter
        if ($this->contractDuration) {
            $query->where('contract_type', $this->contractDuration);
        }

        // Apply pricing model filter from parent (or SEO pricing type)
        // Determine the effective pricing filter (pricingType takes precedence if set)
        $effectivePricingFilter = $this->pricingType ?: $this->pricingModelFilter;

        if ($effectivePricingFilter !== '' && $effectivePricingFilter !== null) {
            if ($effectivePricingFilter === 'Quarterly') {
                // Quarterly contracts are identified by name or description patterns
                $query->where(function ($q) {
                    $q->where('name', 'LIKE', '%kvartaali%')
                        ->orWhere('extra_information_fi', 'LIKE', '%kvartaali%')
                        ->orWhere('extra_information_fi', 'LIKE', '%kolmen kuukauden jaksoissa%')
                        ->orWhere('extra_information_fi', 'LIKE', '%kolmen kuukauden jaksolle%')
                        ->orWhere('extra_information_fi', 'LIKE', '%kolmen kuukauden välein%')
                        ->orWhere('extra_information_fi', 'LIKE', '%neljästi vuodessa%')
                        ->orWhere('extra_information_fi', 'LIKE', '%neljä kertaa vuodessa%');
                });
            } elseif ($effectivePricingFilter === 'TimeOfUse') {
                // Time-of-use (aikasähkö) contracts have day/night pricing
                $query->where(function ($q) {
                    $q->where('metering', 'Time')
                        ->orWhere('name', 'LIKE', '%aikasähkö%')
                        ->orWhere('name', 'LIKE', '%Aikasähkö%')
                        ->orWhere('extra_information_fi', 'LIKE', '%aikasähkö%');
                });
            } elseif ($effectivePricingFilter === 'Seasonal') {
                // Seasonal (kausisähkö) contracts have seasonal pricing
                $query->where(function ($q) {
                    $q->where('metering', 'Season')
                        ->orWhere('name', 'LIKE', '%kausisähkö%')
                        ->orWhere('name', 'LIKE', '%Kausisähkö%')
                        ->orWhere('extra_information_fi', 'LIKE', '%kausisähkö%');
                });
            } elseif ($effectivePricingFilter === 'GeneralElectricity') {
                // Yleissähkö: fixed-price contracts with general (single-tariff) metering
                $query->where('pricing_model', 'FixedPrice')
                    ->where('metering', 'General');
            } else {
                // Standard pricing models (Spot, FixedPrice, Hybrid)
                $query->where('pricing_model', $effectivePricingFilter);
            }
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
        $consumption = $this->selectedConsumptionValue();
        $contracts = $contracts->filter(function ($contract) use ($consumption) {
            return $contract->isConsumptionInRange($consumption);
        });

        $sorted = $this->applyCachedMetricsToContracts($contracts, $consumption);

        if ($sorted === null) {
            $calculator = app(ContractPriceCalculator::class);
            $emissionsCalculator = app(CO2EmissionsCalculator::class);

            // Do not eager load full price history here. Load only the latest
            // calculation components in bulk, avoiding both N+1 queries and
            // 50k+ historical price-component models in memory.

            // Get spot price averages for calculations
            $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
            $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
            $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

            $priceComponentsByContractId = ElectricityContract::getLatestPriceComponentsForCalculationByContractIds(
                $contracts->pluck('id')
            );

            // Calculate cost and emissions for each contract and sort by cost
            $contracts = $contracts->map(function ($contract) use ($calculator, $emissionsCalculator, $spotPriceDay, $spotPriceNight, $consumption, $priceComponentsByContractId) {
                $priceComponents = $priceComponentsByContractId[$contract->id] ?? [];

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
        }

        // Store cheapest total cost for SEO titles
        $this->cheapestTotalCost = $sorted->first()?->calculated_cost['total_cost'] ?? null;

        // For city pages, exclude contracts already shown in local/regional sections
        if ($this->city) {
            $localData = $this->localContractsData;
            $excludedIds = $localData['excluded_ids'] ?? [];
            if (! empty($excludedIds)) {
                $sorted = $sorted->filter(fn ($c) => ! in_array($c->id, $excludedIds))->values();
            }
        }

        // Create a manual paginator from the sorted collection
        $total = $sorted->count();
        $page = max(1, $this->page);
        $perPage = $this->perPage;

        // Calculate offset and get the slice for current page
        $offset = ($page - 1) * $perPage;
        $items = $this->loadVisibleContracts($sorted, $offset, $perPage);

        // Create and return a LengthAwarePaginator
        return $this->contractsCache = new \Illuminate\Pagination\LengthAwarePaginator(
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
            'fossiiliton' => $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;

                return $source && $source->isFossilFree();
            }),
            'uusiutuva-sahko' => $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;

                return $source && $source->renewable_total >= 50;
            }),
            'ydinvoima' => $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;

                return $source && $source->hasNuclear();
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
        // Get contract count for title
        $contracts = $this->contracts;
        $count = $contracts->total();
        $countSuffix = $count > 0 ? " ({$count} sopimusta)" : '';

        // Page suffix for paginated pages (sivu N/Y)
        $pageSuffix = '';
        if ($this->page > 1) {
            $lastPage = $contracts->lastPage();
            $pageSuffix = " – Sivu {$this->page}/{$lastPage}";
        }

        if ($this->targetGroup === 'Company') {
            return "Sähkösopimukset yrityksille{$countSuffix}{$pageSuffix} | Voltikka";
        }

        if ($this->offerType === 'promotion') {
            return "Sähkötarjoukset ja alennukset{$countSuffix}{$pageSuffix} | Voltikka";
        }

        if ($this->housingType && isset($this->housingTypeNames[$this->housingType])) {
            return "Sähkösopimukset {$this->housingTypeNames[$this->housingType]}on{$countSuffix}{$pageSuffix} | Voltikka";
        }

        if ($this->energySource && isset($this->energySourceNames[$this->energySource])) {
            $baseTitle = match ($this->energySource) {
                'fossiiliton' => 'Vertaa fossiilittomia sähkösopimuksia',
                'uusiutuva-sahko' => 'Vertaa uusiutuvan sähkön sopimuksia',
                'ydinvoima' => 'Vertaa ydinvoimasähkösopimuksia',
                default => "{$this->energySourceNames[$this->energySource]}sopimukset",
            };

            return "{$baseTitle}{$countSuffix}{$pageSuffix} | Voltikka";
        }

        if ($this->contractDuration && isset($this->contractDurationNames[$this->contractDuration])) {
            $baseTitle = match ($this->contractDuration) {
                'FixedTerm' => 'Vertaa määräaikaisia sähkösopimuksia',
                'OpenEnded' => 'Vertaa toistaiseksi voimassa olevia sähkösopimuksia',
                default => 'Vertaa sähkösopimuksia',
            };

            return "{$baseTitle}{$countSuffix}{$pageSuffix} | Voltikka";
        }

        if ($this->pricingType && isset($this->pricingTypeNames[$this->pricingType])) {
            // SEO-optimized titles for each pricing type (focus on comparison)
            $baseTitle = match ($this->pricingType) {
                'Spot' => 'Vertaa pörssisähkösopimuksia',
                'FixedPrice' => 'Vertaa kiinteähintaisia sähkösopimuksia',
                'Quarterly' => 'Vertaa kvartaalisähkösopimuksia',
                'TimeOfUse' => 'Vertaa aikasähkösopimuksia',
                'Seasonal' => 'Vertaa kausisähkösopimuksia',
                'Hybrid' => 'Vertaa joustosähkö- ja hybridisähkösopimuksia',
                'GeneralElectricity' => 'Vertaa yleissähkösopimuksia – kiinteähintaiset sähkösopimukset',
                default => "Vertaa {$this->pricingTypeNames[$this->pricingType]}sopimuksia",
            };

            return "{$baseTitle}{$countSuffix}{$pageSuffix} | Voltikka";
        }

        if ($this->consumptionLevel && isset($this->consumptionLevelNames[$this->consumptionLevel])) {
            $level = $this->consumptionLevelNames[$this->consumptionLevel];
            $year = date('Y');
            $pricePart = '';
            if ($this->cheapestTotalCost !== null) {
                $price = number_format($this->cheapestTotalCost, 0, ',', ' ');
                $pricePart = " – alk. {$price} €/v";
            }

            return "{$level} sähkön hinta {$year}{$pricePart}{$pageSuffix} | Voltikka";
        }

        if ($this->city) {
            $cityData = $this->getCityData($this->city);
            $pricePart = '';
            if ($this->cheapestTotalCost !== null) {
                $price = number_format($this->cheapestTotalCost, 0, ',', ' ');
                $pricePart = " – alk. {$price} €/v";
            }
            $localCount = $this->localContractsData['local_companies']->count();
            $localPart = $localCount > 0 ? ", {$localCount} paikallista" : '';
            $countSuffix = $count > 0 ? " ({$count} sopimusta{$localPart})" : '';

            return "Sähkösopimukset {$cityData['locative']}{$pricePart}{$countSuffix}{$pageSuffix} | Voltikka";
        }

        return "Vertaa sähkösopimuksia{$countSuffix}{$pageSuffix} | Voltikka";
    }

    /**
     * Generate meta description based on filters.
     */
    protected function generateMetaDescription(): string
    {
        if ($this->targetGroup === 'Company') {
            return 'Vertaile yrityksille suunnattuja sähkösopimuksia ja löydä edullisin vaihtoehto yrityksellesi. Katso hinnat, sopimusehdot ja energialähteet yhdestä paikasta.';
        }

        if ($this->offerType === 'promotion') {
            return 'Löydä parhaat sähkötarjoukset ja alennukset. Vertaile kampanjahintaisia sähkösopimuksia ja säästä sähkölaskussa.';
        }

        if ($this->housingType && isset($this->housingTypeNames[$this->housingType])) {
            $housingName = mb_strtolower($this->housingTypeNames[$this->housingType]);
            $consumption = $this->housingTypeConsumption[$this->housingType] ?? 5000;

            return "Vertaile sähkösopimuksia {$housingName}on. Keskimääräinen kulutus {$consumption} kWh/vuosi. Löydä edullisin sähkösopimus helposti.";
        }

        if ($this->energySource && isset($this->energySourceNames[$this->energySource])) {
            return match ($this->energySource) {
                'fossiiliton' => 'Vertaile ja kilpailuta fossiilittomia sähkösopimuksia. Fossiiliton sähkö tuotetaan ydinvoimalla ja uusiutuvilla energialähteillä ilman CO₂-päästöjä.',
                'uusiutuva-sahko' => 'Vertaile uusiutuvan energian sähkösopimuksia. Tuuli-, aurinko- ja vesivoimalla tuotettua sähköä. Kilpailuta vihreä sähkösopimus ja löydä edullisin.',
                'ydinvoima' => 'Vertaile ja kilpailuta ydinvoimasähkösopimuksia. Ydinvoima on päästötöntä ja tuottaa sähköä tasaisesti ympäri vuoden. Löydä paras ydinvoimasähkösopimus.',
                default => "Vertaile {$this->energySourceNames[$this->energySource]}sopimuksia. Valitse ympäristöystävällinen sähkösopimus.",
            };
        }

        if ($this->contractDuration && isset($this->contractDurationNames[$this->contractDuration])) {
            return match ($this->contractDuration) {
                'FixedTerm' => 'Vertaile ja kilpailuta määräaikaiset sähkösopimukset. Määräaikainen sopimus takaa kiinteän hinnan sovituksi ajaksi. Löydä edullisin määräaikainen sähkösopimus.',
                'OpenEnded' => 'Vertaile ja kilpailuta toistaiseksi voimassa olevat sähkösopimukset. Vaihda sopimusta joustavasti ilman sitoutumisaikaa. Löydä paras toistaiseksi voimassa oleva sopimus.',
                default => 'Vertaile sähkösopimuksia ja löydä edullisin vaihtoehto.',
            };
        }

        if ($this->pricingType && isset($this->pricingTypeNames[$this->pricingType])) {
            if ($this->pricingType === 'Spot') {
                return 'Vertaile pörssisähkösopimuksia. Pörssisähkö seuraa tuntikohtaista sähkön pörssihintaa. Löydä paras pörssisähkösopimus.';
            }
            if ($this->pricingType === 'FixedPrice') {
                return 'Vertaile kiinteähintaisia sähkösopimuksia. Kiinteähintainen sopimus antaa ennustettavan kWh-hinnan ja suojaa pörssisähkön tuntivaihteluilta.';
            }
            if ($this->pricingType === 'Quarterly') {
                return 'Vertaile kvartaalisähkösopimuksia. Kvartaalisähkössä hinta päivittyy neljä kertaa vuodessa. Löydä paras kvartaalisähkösopimus kotitalouksille.';
            }
            if ($this->pricingType === 'TimeOfUse') {
                return 'Vertaile aikasähkösopimuksia. Aikasähkössä sähkön hinta vaihtelee vuorokaudenajan mukaan. Edullisempi yöhinta 22-07.';
            }
            if ($this->pricingType === 'Seasonal') {
                return 'Vertaile kausisähkösopimuksia. Kausisähkössä hinta vaihtelee vuodenajan mukaan. Talvella korkeampi, muulloin edullisempi.';
            }
            if ($this->pricingType === 'Hybrid') {
                return 'Vertaile joustosähkö- ja hybridisähkösopimuksia. Joustosähkössä yhdistyy kiinteän hinnan turvallisuus ja pörssisähkön edullisuus. Löydä paras joustosähkösopimus.';
            }
            if ($this->pricingType === 'GeneralElectricity') {
                return 'Vertaile yleissähkösopimuksia eli kiinteähintaisia sähkösopimuksia. Yleissähkössä maksat saman hinnan kellon ympäri. Löydä edullisin yleissähkösopimus.';
            }

            return 'Vertaile sähkösopimuksia ja löydä edullisin vaihtoehto. Vertaa hintoja, sopimusehtoja ja energialähteitä yhdestä paikasta.';
        }

        if ($this->consumptionLevel && isset($this->consumptionLevelNames[$this->consumptionLevel])) {
            $level = $this->consumptionLevelNames[$this->consumptionLevel];
            $year = date('Y');
            if ($this->cheapestTotalCost !== null) {
                $price = number_format($this->cheapestTotalCost, 0, ',', ' ');

                return "{$level} sähkön hinta vuonna {$year} alkaen {$price} €/vuosi. Vertaile sähkösopimuksia ja löydä edullisin vaihtoehto kulutuksellesi.";
            }

            return "Vertaile sähkösopimuksia {$level} vuosikulutukselle. Katso hinnat ja löydä edullisin sähkösopimus.";
        }

        if ($this->city) {
            $cityData = $this->getCityData($this->city);
            $pricePart = '';
            if ($this->cheapestTotalCost !== null) {
                $price = number_format($this->cheapestTotalCost, 0, ',', ' ');
                $pricePart = " alkaen {$price} €/vuosi";
            }
            $localCount = $this->localContractsData['local_companies']->count();
            $localPart = $localCount > 0 ? " {$localCount} paikallista sähköyhtiötä." : '';

            return "Sähkösopimukset {$cityData['locative']}{$pricePart}.{$localPart} Vertaile hintoja ja löydä paras sähkösopimus {$cityData['name']}n alueelle.";
        }

        return 'Vertaile sähkösopimuksia helposti. Löydä edullisin sähkösopimus kotiisi tai yritykseesi.';
    }

    /**
     * Generate canonical URL based on filters.
     */
    protected function generateCanonicalUrl(): string
    {
        $baseUrl = config('app.url');
        $pageSuffix = $this->page > 1 ? '?page='.$this->page : '';

        if ($this->targetGroup === 'Company') {
            return "{$baseUrl}/sahkosopimus/yritykselle{$pageSuffix}";
        }

        if ($this->offerType === 'promotion') {
            return "{$baseUrl}/sahkosopimus/sahkotarjous{$pageSuffix}";
        }

        if ($this->housingType) {
            return "{$baseUrl}/sahkosopimus/{$this->housingType}{$pageSuffix}";
        }

        if ($this->energySource) {
            return "{$baseUrl}/sahkosopimus/{$this->energySource}{$pageSuffix}";
        }

        if ($this->contractDuration) {
            $slugMap = [
                'FixedTerm' => 'maaraaikainen',
                'OpenEnded' => 'toistaiseksi',
            ];
            $slug = $slugMap[$this->contractDuration] ?? 'maaraaikainen';

            return "{$baseUrl}/sahkosopimus/{$slug}{$pageSuffix}";
        }

        if ($this->pricingType) {
            $slugMap = [
                'Spot' => 'porssisahko',
                'FixedPrice' => 'kiintea-hinta',
                'Quarterly' => 'kvartaalisahko',
                'TimeOfUse' => 'aikasahko',
                'Seasonal' => 'kausisahko',
                'Hybrid' => 'joustosahko',
                'GeneralElectricity' => 'yleissahko',
            ];
            $slug = $slugMap[$this->pricingType] ?? 'porssisahko';

            return "{$baseUrl}/sahkosopimus/{$slug}{$pageSuffix}";
        }

        if ($this->consumptionLevel) {
            return "{$baseUrl}/sahkosopimus/kulutus/{$this->consumptionLevel}-kwh{$pageSuffix}";
        }

        if ($this->city) {
            return "{$baseUrl}/sahkosopimus/paikkakunnat/{$this->city}{$pageSuffix}";
        }

        return "{$baseUrl}/sahkosopimus{$pageSuffix}";
    }

    /**
     * Generate JSON-LD structured data for SEO contract listings.
     */
    protected function generateJsonLd(): array
    {
        $contracts = $this->contracts;
        $items = [];
        $canonicalUrl = $this->generateCanonicalUrl();

        foreach ($contracts as $index => $contract) {
            $company = $contract->relationLoaded('company') ? $contract->company : null;
            $description = $contract->short_description ?? 'Sähkösopimus yritykseltä '.($company?->name ?? $contract->company_name);

            // Avoid lazy-loading price_components while building JSON-LD. The
            // visible listing query loads them in bulk; if another caller passes
            // slim models, omit detailed discount text rather than issuing one
            // query per contract.
            if ($contract->relationLoaded('priceComponents') && $contract->hasActiveDiscounts()) {
                $discountInfo = $contract->getActiveDiscountInfo();
                if ($discountInfo) {
                    $discountDesc = $contract->formatActiveDiscountValue($discountInfo);
                    if ($discountInfo['n_first_months'] && $discountDesc) {
                        $discountDesc .= ' ensimmäiset '.$discountInfo['n_first_months'].' kk';
                    }
                    if ($discountDesc) {
                        $description .= ' ('.$discountDesc.')';
                    }
                }
            }

            $product = [
                '@type' => 'Product',
                '@id' => route('contract.detail', ['contractId' => $contract->id]).'#product',
                'name' => $contract->name,
                'url' => route('contract.detail', ['contractId' => $contract->id]),
                'description' => $description,
                'category' => 'Electricity Contract',
            ];

            if ($company) {
                $product['brand'] = [
                    '@type' => 'Organization',
                    'name' => $company->name,
                ];
            }

            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => $product,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebPage',
                    '@id' => $canonicalUrl.'#webpage',
                    'url' => $canonicalUrl,
                    'name' => $this->generateSeoTitle(),
                    'description' => $this->generateMetaDescription(),
                    'mainEntity' => [
                        '@id' => $canonicalUrl.'#comparison-service',
                    ],
                ],
                [
                    '@type' => 'Service',
                    '@id' => $canonicalUrl.'#comparison-service',
                    'name' => $this->getPageHeadingProperty(),
                    'description' => 'Voltikka vertailee sähkösopimuksia, hintoja ja sopimustyyppejä Suomessa.',
                    'url' => $canonicalUrl,
                    'serviceType' => 'Electricity contract comparison',
                    'provider' => [
                        '@type' => 'Organization',
                        'name' => 'Voltikka',
                        'url' => config('app.url'),
                    ],
                ],
                [
                    '@type' => 'ItemList',
                    '@id' => $canonicalUrl.'#itemlist',
                    'name' => $this->generateSeoTitle(),
                    'itemListElement' => $items,
                ],
            ],
        ];
    }

    /**
     * Get the page heading (H1) based on filters.
     */
    public function getPageHeadingProperty(): string
    {
        if ($this->targetGroup === 'Company') {
            return 'Sähkösopimukset yrityksille';
        }

        if ($this->offerType === 'promotion') {
            return 'Sähkötarjoukset ja alennukset';
        }

        if ($this->housingType && isset($this->housingTypeNames[$this->housingType])) {
            return "Sähkösopimukset {$this->housingTypeNamesLocative()[$this->housingType]}";
        }

        if ($this->energySource && isset($this->energySourceNames[$this->energySource])) {
            return match ($this->energySource) {
                'fossiiliton' => 'Fossiiliton sähkö – vertaile päästöttömiä sähkösopimuksia',
                'uusiutuva-sahko' => 'Uusiutuva sähkö – vertaile ja kilpailuta vihreä sähkösopimus',
                'ydinvoima' => 'Ydinvoimasähkö – vertaile ydinvoimalla tuotettuja sähkösopimuksia',
                default => "{$this->energySourceNames[$this->energySource]}sopimukset",
            };
        }

        if ($this->contractDuration && isset($this->contractDurationNames[$this->contractDuration])) {
            return match ($this->contractDuration) {
                'FixedTerm' => 'Määräaikaiset sähkösopimukset – vertaile ja kilpailuta',
                'OpenEnded' => 'Toistaiseksi voimassa olevat sähkösopimukset – vertaile ja kilpailuta',
                default => 'Sähkösopimukset',
            };
        }

        if ($this->pricingType === 'FixedPrice') {
            return 'Kiinteähintaiset sähkösopimukset';
        }

        if ($this->pricingType === 'GeneralElectricity') {
            return 'Yleissähkö – kiinteähintaiset sähkösopimukset';
        }

        if ($this->pricingType && isset($this->pricingTypeNames[$this->pricingType])) {
            return "{$this->pricingTypeNames[$this->pricingType]}sopimukset";
        }

        if ($this->consumptionLevel && isset($this->consumptionLevelNames[$this->consumptionLevel])) {
            $level = $this->consumptionLevelNames[$this->consumptionLevel];

            return "Sähkösopimukset {$level} vuosikulutukselle";
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
        if ($this->targetGroup === 'Company') {
            return 'Vertaile yrityksille suunnattuja sähkösopimuksia. Löydä edullisin vaihtoehto yrityksen kulutukseen ja vastuullisuustavoitteisiin.';
        }

        if ($this->offerType === 'promotion') {
            return 'Tällä sivulla näkyvät sähkösopimukset, joissa on voimassa oleva tarjous — esimerkiksi alennettu perusmaksu ensimmäisille kuukausille tai alennettu energiahinta. Tarjoukset on järjestetty arvioidun 12 kuukauden kokonaiskustannuksen mukaan, sama laskentatapa kaikille yhtiöille, ja vuosihinta sisältää tarjouksen vaikutuksen. Sopimustiedot päivittyvät päivittäin.';
        }

        if ($this->housingType && isset($this->housingTypeNames[$this->housingType])) {
            return $this->getHousingTypeIntroText($this->housingType);
        }

        if ($this->energySource && isset($this->energySourceNames[$this->energySource])) {
            return $this->getEnergySourceIntroText($this->energySource);
        }

        if ($this->contractDuration && isset($this->contractDurationNames[$this->contractDuration])) {
            return $this->getContractDurationIntroText($this->contractDuration);
        }

        if ($this->pricingType && isset($this->pricingTypeNames[$this->pricingType])) {
            return $this->getPricingTypeIntroText($this->pricingType);
        }

        if ($this->consumptionLevel && isset($this->consumptionLevelNames[$this->consumptionLevel])) {
            return $this->getConsumptionLevelIntroText($this->consumptionLevel);
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
            'fossiiliton' => 'Fossiiliton sähkö tuotetaan ilman fossiilisia polttoaineita – ydinvoimalla ja uusiutuvilla energialähteillä kuten tuuli-, aurinko- ja vesivoimalla. Fossiiliton sähkö ei aiheuta hiilidioksidipäästöjä tuotannossa. Vertaile ja kilpailuta fossiilittomat sähkösopimukset ja löydä edullisin päästötön vaihtoehto.',
            'uusiutuva-sahko' => 'Uusiutuva sähkö tuotetaan tuuli-, aurinko- ja vesivoimalla sekä muilla uusiutuvilla energialähteillä. Uusiutuvan energian osuus Suomen sähköntuotannosta kasvaa jatkuvasti. Vertaile ja kilpailuta uusiutuvan sähkön sopimuksia ja löydä edullisin vihreä sähkösopimus.',
            'ydinvoima' => 'Ydinvoimasähkö on päästötöntä ja tuottaa sähköä tasaisesti ympäri vuoden säästä riippumatta. Ydinvoima tuottaa noin kolmanneksen Suomen sähköstä ja on merkittävä osa päästötöntä energiantuotantoa. Vertaile ja kilpailuta ydinvoimasähkösopimuksia ja löydä paras vaihtoehto.',
            default => 'Vertaile sähkösopimuksia ja löydä ympäristöystävällinen vaihtoehto.',
        };
    }

    /**
     * Get detailed intro text for contract duration pages.
     */
    protected function getContractDurationIntroText(string $contractDuration): string
    {
        return match ($contractDuration) {
            'FixedTerm' => 'Määräaikaisessa sähkösopimuksessa sitoudut sopimukseen sovituksi ajaksi, tyypillisesti 12, 24 tai 36 kuukaudeksi. Määräaikainen sopimus takaa kiinteän hinnan koko sopimuskauden ajan ja suojaa markkinaheilahteluilta. Vertaile ja kilpailuta määräaikaiset sähkösopimukset ja löydä edullisin vaihtoehto.',
            'OpenEnded' => 'Toistaiseksi voimassa oleva sähkösopimus jatkuu ilman määräaikaa, kunnes irtisanot sen. Sopimuksen voi irtisanoa milloin tahansa 14 päivän irtisanomisajalla. Tämä sopimustyyppi tarjoaa täyden joustavuuden – voit kilpailuttaa sähkösopimuksen uudelleen aina kun haluat. Vertaile toistaiseksi voimassa olevia sopimuksia ja löydä paras.',
            default => 'Vertaile sähkösopimuksia ja löydä edullisin vaihtoehto.',
        };
    }

    /**
     * Get detailed intro text for pricing type pages.
     */
    protected function getPricingTypeIntroText(string $pricingType): string
    {
        return match ($pricingType) {
            'Spot' => 'Pörssisähkösopimuksessa sähkön hinta vaihtelee tunneittain Nord Pool -sähköpörssin hinnan mukaan. Pörssisähkö voi olla edullinen vaihtoehto, jos pystyt ajoittamaan kulutustasi edullisempiin tunteihin. Vertaile pörssisähkösopimuksia ja löydä sopimus, jossa marginaali ja kuukausimaksu sopivat sinulle.',
            'FixedPrice' => 'Kiinteähintaisessa sähkösopimuksessa energian hinta on ennalta sovittu eikä muutu tunneittain pörssisähkön mukana. Kiinteä hinta sopii kotitalouksille, jotka arvostavat ennustettavaa sähkölaskua ja haluavat vertailla sopimuksia suoraan kWh-hinnan ja kuukausimaksun perusteella.',
            'Quarterly' => 'Kvartaalisähkösopimuksessa sähkön hinta päivittyy neljännesvuosittain eli neljä kertaa vuodessa. Kvartaalisähkö tarjoaa kompromissin kiinteän hinnan ennustettavuuden ja pörssisähkön markkinahinnan välillä. Hinta seuraa markkinoiden kehitystä maltillisesti ilman tuntikohtaista vaihtelua.',
            'TimeOfUse' => 'Aikasähkösopimuksessa sähkön hinta vaihtelee vuorokaudenajan mukaan. Yöllä (22-07) sähkö on edullisempaa kuin päivällä. Aikasähkö sopii erityisesti niille, jotka voivat ajoittaa suurimmat kulutuspiikkinsä yöaikaan, esimerkiksi lämminvesivaraajan tai sähköauton latauksen.',
            'Seasonal' => 'Kausisähkösopimuksessa sähkön hinta vaihtelee vuodenajan mukaan. Talvikuukausina (marras-maaliskuu) hinta on korkeampi, muulloin edullisempi. Kausisähkö heijastaa sähkön tuotantokustannusten kausivaihtelua ja sopii niille, jotka haluavat ennustettavuutta ilman tuntikohtaista vaihtelua.',
            'Hybrid' => 'Joustosähkö eli hybridisähkö yhdistää kiinteähintaisen sähkösopimuksen ennustettavuuden ja pörssisähkön edut. Joustosähkösopimuksessa osa hinnasta on kiinteä ja osa seuraa sähkön markkinahintaa. Tämä tarjoaa suojaa suurilta hintapiikeiltä, mutta mahdollistaa säästöt sähkön ollessa edullista. Vertaile hybridisähkösopimuksia ja löydä sopimus, joka sopii kulutukseesi.',
            'GeneralElectricity' => 'Yleissähkö eli perussähkö on yleisin sähkösopimustyyppi, jossa maksat saman kiinteän hinnan kilowattitunnilta vuorokauden ympäri. Yleissähkösopimus on yksinkertainen ja helppo ymmärtää – hinta ei vaihtele kellonajan tai vuodenajan mukaan. Yleissähkö sopii erityisesti kotitalouksille, joiden sähkönkulutus jakautuu tasaisesti koko vuorokaudelle. Vertaile yleissähkösopimuksia ja löydä edullisin kiinteähintainen sähkösopimus.',
            default => 'Vertaile sähkösopimuksia ja löydä edullisin vaihtoehto.',
        };
    }

    /**
     * Get detailed intro text for consumption level pages.
     */
    protected function getConsumptionLevelIntroText(string $consumptionLevel): string
    {
        return match ($consumptionLevel) {
            '2000' => 'Vertaile sähkösopimuksia 2 000 kWh vuosikulutukselle. Tämä kulutustaso on tyypillinen pienelle yksiölle tai yhden hengen kerrostaloasunnolle. Löydä edullisin sähkösopimus vertailemalla hintoja ja sopimusehtoja.',
            '5000' => 'Vertaile sähkösopimuksia 5 000 kWh vuosikulutukselle. Tämä kulutustaso on tyypillinen 2–4 hengen kerrostaloasunnolle tai pienelle omakotitalolle ilman sähkölämmitystä. Vertaile hintoja ja löydä paras sopimus.',
            '10000' => 'Vertaile sähkösopimuksia 10 000 kWh vuosikulutukselle. Tämä kulutustaso on tyypillinen rivitalolle tai omakotitalolle, jossa on ilmalämpöpumppu tai muu tukimuotoinen lämmitys. Kilpailuta sopimukset ja säästä sähkölaskussa.',
            '18000' => 'Vertaile sähkösopimuksia 18 000 kWh vuosikulutukselle. Tämä kulutustaso on tyypillinen sähkölämmitteiselle omakotitalolle. Vertaile hintoja ja löydä edullisin sähkösopimus – suurella kulutuksella pienikin hintaero tuo merkittävät säästöt.',
            '20000' => 'Vertaile sähkösopimuksia 20 000 kWh vuosikulutukselle. Tämä kulutustaso on tyypillinen sähkölämmitteiselle omakotitalolle. Suurella kulutuksella pienikin ero kilowattituntihinnassa vaikuttaa merkittävästi vuosikustannuksiin.',
            default => 'Vertaile sähkösopimuksia kulutuksesi mukaan ja löydä edullisin vaihtoehto.',
        };
    }

    /**
     * Get city data with proper Finnish name and locative form.
     */
    protected function getCityData(string $slug): array
    {
        if (array_key_exists($slug, $this->cityDataCache)) {
            return $this->cityDataCache[$slug];
        }

        // Look up municipality from database
        $municipality = $this->getMunicipality($slug);

        if ($municipality) {
            return $this->cityDataCache[$slug] = [
                'name' => $municipality->name,
                'locative' => $municipality->name_locative,
                'genitive' => $municipality->name_genitive,
                'municipality' => $municipality,
            ];
        }

        abort(404);
    }

    /**
     * Get the municipality for the current city slug.
     */
    protected function getMunicipality(string $slug): ?Municipality
    {
        if (! $this->municipalityLoaded || $this->municipalityLoadedSlug !== $slug) {
            $this->municipality = Municipality::where('slug', $slug)->first();
            $this->municipalityLoaded = true;
            $this->municipalityLoadedSlug = $slug;
        }

        return $this->municipality;
    }

    /**
     * Get the municipality property for the view.
     */
    public function getMunicipalityProperty(): ?Municipality
    {
        if (! $this->city) {
            return null;
        }

        return $this->getMunicipality($this->city);
    }

    /**
     * Get local contracts for the current city.
     * Returns array with local_companies, regional_contracts, and has_content keys.
     */
    public function getLocalContractsDataProperty(): array
    {
        if ($this->localContractsDataCache !== null) {
            return $this->localContractsDataCache;
        }

        $municipality = $this->city ? $this->getMunicipality($this->city) : null;
        if (! $municipality) {
            return $this->localContractsDataCache = [
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

        return $this->localContractsDataCache = [
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
            || $this->contractDuration !== null
            || $this->consumptionLevel !== null
            || $this->city !== null
            || $this->offerType !== null
            || $this->targetGroup !== null;
    }

    /**
     * Whether the calculator tab should be shown (hidden for business pages).
     */
    public function getShowCalculatorTabProperty(): bool
    {
        return $this->targetGroup !== 'Company';
    }

    /**
     * Whether this is a business page.
     */
    public function getIsBusinessPageProperty(): bool
    {
        return $this->targetGroup === 'Company';
    }

    /**
     * @return array{trend:?array<string,mixed>,forecast:?array<string,mixed>,has_items:bool}
     */
    public function getMarketInsightProperty(): array
    {
        if ($this->targetGroup === 'Company' || $this->housingType !== null || $this->energySource !== null || $this->consumptionLevel !== null) {
            return ['trend' => null, 'forecast' => null, 'has_items' => false];
        }

        return parent::getMarketInsightProperty();
    }

    protected function marketInsightSegmentKey(): ?string
    {
        if ($this->targetGroup === 'Company' || $this->housingType !== null || $this->energySource !== null) {
            return null;
        }

        if ($this instanceof CheapestContracts) {
            return 'aggregate';
        }

        if ($this->contractDuration === 'FixedTerm') {
            return 'fixed_term_12';
        }

        if ($this->contractDuration === 'OpenEnded') {
            return 'open_ended';
        }

        return match ($this->pricingType) {
            'Spot' => 'spot',
            'Quarterly' => 'quarterly',
            'Hybrid' => 'hybrid',
            'FixedPrice' => 'fixed_term_12',
            null => $this->consumptionLevel !== null ? null : 'aggregate',
            default => 'aggregate',
        };
    }

    protected function marketInsightIncludesForecast(): bool
    {
        return $this->targetGroup !== 'Company'
            && $this->housingType === null
            && $this->energySource === null
            && ($this->contractDuration === 'FixedTerm' || $this->pricingType === 'FixedPrice');
    }

    /**
     * Get aggregated energy source statistics for the current contracts.
     */
    public function getEnergySourceStatsProperty(): array
    {
        if (! $this->energySource) {
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
        if (! $this->energySource) {
            return null;
        }

        return match ($this->energySource) {
            'tuulisahko' => 'Tuulivoima on yksi vähäpäästöisimmistä sähköntuotantomuodoista. Tuulivoimalan elinkaaren aikaiset CO₂-päästöt ovat noin 7-15 g/kWh, kun fossiilisilla polttoaineilla tuotetun sähkön päästöt ovat 400-1000 g/kWh. Valitsemalla tuulisähkön vähennät merkittävästi hiilijalanjälkeäsi.',
            'aurinkosahko' => 'Aurinkosähkön tuotannon elinkaaren aikaiset CO₂-päästöt ovat noin 20-50 g/kWh, mikä on murto-osa fossiilisiin polttoaineisiin verrattuna. Aurinkopaneelit eivät tuota käytön aikana päästöjä, melua tai jätettä. Aurinkosähkö on erityisen puhdas vaihtoehto.',
            'vihrea-sahko' => 'Vihreä sähkö tuotetaan ilman merkittäviä hiilidioksidipäästöjä. Uusiutuvien energialähteiden keskimääräiset elinkaaren päästöt ovat alle 50 g/kWh, kun fossiilisten polttoaineiden päästöt ovat moninkertaiset. Vihreän sähkön valinta on tehokas tapa pienentää kotitaloutesi ilmastovaikutusta.',
            'fossiiliton' => 'Fossiiliton sähkö tuotetaan ilman fossiilisia polttoaineita. Ydinvoiman ja uusiutuvien energialähteiden yhdistelmä tarjoaa luotettavan ja päästöttömän sähköntuotannon. Fossiilittoman sähkön valinta vähentää merkittävästi kotitaloutesi hiilijalanjälkeä.',
            'uusiutuva-sahko' => 'Uusiutuva sähkö tuotetaan luonnonvaroista, jotka uusiutuvat jatkuvasti. Tuuli-, aurinko- ja vesivoiman elinkaaren aikaiset CO₂-päästöt ovat murto-osa fossiilisiin polttoaineisiin verrattuna. Uusiutuvan sähkön valinta tukee kestävää energiantuotantoa.',
            'ydinvoima' => 'Ydinvoima on yksi vähäpäästöisimmistä sähköntuotantomuodoista. Ydinvoimalan elinkaaren aikaiset CO₂-päästöt ovat noin 5-15 g/kWh. Ydinvoima tuottaa sähköä tasaisesti ympäri vuoden ja on merkittävä osa Suomen päästötöntä energiapalettia.',
            default => null,
        };
    }

    /**
     * Get city-specific information.
     */
    public function getCityInfoProperty(): ?array
    {
        if (! $this->city) {
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
        $this->enableBackButtonCache();
        $data = $this->seoContractsViewData();

        return view('livewire.seo-contracts-list', $data['view'])
            ->layout('layouts.app', $data['layout'])
            ->response(function ($response) {
                app(SetPublicCacheHeaders::class)->applyCacheHeaders($response);
            });
    }

    /**
     * @return array{view: array<string, mixed>, layout: array<string, mixed>}
     */
    protected function seoContractsViewData(): array
    {
        $this->normalizePageProperty();

        if (! $this->isDefaultSeoListingCacheable()) {
            return $this->buildSeoContractsViewData();
        }

        return $this->rememberDefaultListingViewData(
            $this->seoContractsViewDataCacheKey(),
            fn () => $this->buildSeoContractsViewData(),
        );
    }

    /**
     * @return array{view: array<string, mixed>, layout: array<string, mixed>}
     */
    protected function buildSeoContractsViewData(): array
    {
        $contracts = $this->contracts;
        $seoData = $this->seoData;

        return [
            'view' => [
                'contracts' => $contracts,
                'postcodeSuggestions' => $this->postcodeSuggestions,
                'seoData' => $seoData,
                'pageHeading' => $this->pageHeading,
                'seoIntroText' => $this->seoIntroText,
                'hasSeoFilter' => $this->hasSeoFilter,
                'energySourceStats' => $this->energySourceStats,
                'environmentalInfo' => $this->environmentalInfo,
                'isEnergySourcePage' => $this->energySource !== null,
                'isPricingTypePage' => $this->pricingType !== null,
                'isContractDurationPage' => $this->contractDuration !== null,
                'isCityPage' => $this->city !== null,
                'cityInfo' => $this->cityInfo,
                'citySlug' => $this->city,
                'municipality' => $this->city ? $this->getMunicipality($this->city) : null,
                'localContractsData' => $this->localContractsData,
                'basePath' => $this->basePath,
                'showCalculatorTab' => $this->showCalculatorTab,
                'isBusinessPage' => $this->isBusinessPage,
                'marketInsight' => $this->marketInsight,
            ],
            'layout' => [
                'title' => $seoData['title'],
                'metaDescription' => $seoData['description'],
                'canonical' => $seoData['canonical'],
                'prevUrl' => $this->prevUrl,
                'nextUrl' => $this->nextUrl,
            ],
        ];
    }

    protected function isDefaultSeoListingCacheable(): bool
    {
        return $this->isDefaultListingCacheable()
            && $this->postcodeSearch === ''
            // City pages are numerous long-tail URLs and include local/regional
            // sections. Avoid writing large serialized page payloads to the DB
            // cache on crawler-triggered cold renders; shared metric caches still
            // keep the expensive contract-cost work off the request path.
            && $this->city === null;
    }

    protected function marketInsightCacheVersion(): ?string
    {
        if ($this->targetGroup === 'Company' || $this->housingType !== null || $this->energySource !== null || $this->consumptionLevel !== null) {
            return null;
        }

        return app(ContractMarketInsightService::class)->fingerprint();
    }

    protected function seoContractsViewDataCacheKey(): string
    {
        return 'seo-contracts-list:view-data:v1:'.md5(json_encode([
            'class' => static::class,
            'base_path' => $this->basePath,
            'housing_type' => $this->housingType,
            'energy_source' => $this->energySource,
            'city' => $this->city,
            'pricing_type' => $this->pricingType,
            'offer_type' => $this->offerType,
            'target_group' => $this->targetGroup,
            'contract_duration' => $this->contractDuration,
            'consumption_level' => $this->consumptionLevel,
            'page' => $this->page,
            'consumption' => $this->selectedConsumptionValue(),
            'version' => app(ContractPageCacheVersion::class)->hash(),
            'market_insight_version' => $this->marketInsightCacheVersion(),
        ]));
    }
}
