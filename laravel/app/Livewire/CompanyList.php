<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Collection;
use Livewire\Component;

class CompanyList extends Component
{
    /**
     * Search filter for company names.
     */
    public string $search = '';

    /**
     * Reference consumption for price calculations (kWh/year).
     */
    public int $consumption = 5000;

    /**
     * Cache for company data with metrics.
     *
     * @var Collection|null
     */
    protected ?Collection $cachedCompanies = null;

    /**
     * Get all companies with their contracts and calculated metrics.
     *
     * @return Collection Collection of arrays with 'company', 'contracts', and metrics
     */
    public function getCompaniesProperty(): Collection
    {
        if ($this->cachedCompanies !== null) {
            return $this->cachedCompanies;
        }

        $calculator = app(ContractPriceCalculator::class);
        $emissionsCalculator = app(CO2EmissionsCalculator::class);

        // Get spot price averages for calculations
        $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

        // Load all contracts with their relationships
        $contracts = ElectricityContract::query()
            ->with(['company', 'priceComponents', 'electricitySource'])
            ->whereHas('company')
            ->get();

        // Group contracts by company
        $contractsByCompany = $contracts->groupBy('company_name');

        // Build company data with metrics
        $companies = collect();

        foreach ($contractsByCompany as $companyName => $companyContracts) {
            $company = $companyContracts->first()->company;
            if (!$company) {
                continue;
            }

            // Calculate metrics for each contract
            $contractsWithMetrics = $companyContracts->map(function ($contract) use ($calculator, $emissionsCalculator, $spotPriceDay, $spotPriceNight) {
                $priceComponents = $contract->priceComponents
                    ->sortByDesc('price_date')
                    ->groupBy('price_component_type')
                    ->map(fn ($group) => $group->sortByDesc('price')->first())
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

                $result = $calculator->calculate($priceComponents, $contractData, $usage, $spotPriceDay, $spotPriceNight);
                $contract->calculated_cost = $result->toArray();
                $contract->emission_factor = $emissionsCalculator->calculateEmissionFactor($contract->electricitySource);

                return $contract;
            });

            // Calculate company-level metrics
            $avgPrice = $contractsWithMetrics->avg(fn ($c) => $c->calculated_cost['total_cost'] ?? 0);
            $lowestPrice = $contractsWithMetrics->min(fn ($c) => $c->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX);
            $avgEmissions = $contractsWithMetrics->avg(fn ($c) => $c->emission_factor ?? 0);
            $lowestEmissions = $contractsWithMetrics->min(fn ($c) => $c->emission_factor ?? PHP_FLOAT_MAX);
            $avgRenewable = $contractsWithMetrics->avg(fn ($c) => $c->electricitySource?->renewable_total ?? 0);
            $maxRenewable = $contractsWithMetrics->max(fn ($c) => $c->electricitySource?->renewable_total ?? 0);

            // Get monthly fee (lowest available)
            $lowestMonthlyFee = $contractsWithMetrics
                ->map(fn ($c) => $c->calculated_cost['monthly_fixed_fee'] ?? PHP_FLOAT_MAX)
                ->filter(fn ($fee) => $fee < PHP_FLOAT_MAX)
                ->min() ?? null;

            // Get spot margin (lowest from spot contracts only)
            $spotContracts = $contractsWithMetrics->filter(fn ($c) => $c->pricing_model === 'Spot');
            $lowestSpotMargin = $spotContracts
                ->map(fn ($c) => $c->calculated_cost['spot_price_margin'] ?? PHP_FLOAT_MAX)
                ->filter(fn ($margin) => $margin !== null && $margin < PHP_FLOAT_MAX)
                ->min();

            // Check if company has 100% renewable contracts
            $hasFullyRenewable = $contractsWithMetrics->contains(fn ($c) =>
                $c->electricitySource && $c->electricitySource->isFullyRenewable()
            );

            $companies->push([
                'company' => $company,
                'contracts' => $contractsWithMetrics,
                'contractCount' => $contractsWithMetrics->count(),
                'avgPrice' => $avgPrice,
                'lowestPrice' => $lowestPrice,
                'avgEmissions' => $avgEmissions,
                'lowestEmissions' => $lowestEmissions,
                'avgRenewable' => $avgRenewable,
                'maxRenewable' => $maxRenewable,
                'lowestMonthlyFee' => $lowestMonthlyFee,
                'lowestSpotMargin' => $lowestSpotMargin,
                'hasSpotContracts' => $spotContracts->isNotEmpty(),
                'hasFullyRenewable' => $hasFullyRenewable,
            ]);
        }

        $this->cachedCompanies = $companies;

        return $companies;
    }

    /**
     * Get companies filtered by search term.
     */
    public function getFilteredCompaniesProperty(): Collection
    {
        $companies = $this->companies;

        if ($this->search !== '') {
            $search = mb_strtolower($this->search);
            $companies = $companies->filter(function ($data) use ($search) {
                return str_contains(mb_strtolower($data['company']->name), $search);
            });
        }

        return $companies;
    }

    /**
     * Get total count of companies with contracts.
     */
    public function getCompanyCountProperty(): int
    {
        return $this->companies->count();
    }

    /**
     * Get top 5 cheapest companies by lowest price.
     */
    public function getCheapestCompaniesProperty(): Collection
    {
        return $this->companies
            ->sortBy('lowestPrice')
            ->take(5)
            ->values();
    }

    /**
     * Get top 5 greenest companies by highest average renewable percentage.
     */
    public function getGreenestCompaniesProperty(): Collection
    {
        return $this->companies
            ->sortByDesc('avgRenewable')
            ->take(5)
            ->values();
    }

    /**
     * Get top 5 companies with cleanest emissions (lowest average emission factor).
     */
    public function getCleanestEmissionsCompaniesProperty(): Collection
    {
        return $this->companies
            ->sortBy('lowestEmissions')
            ->take(5)
            ->values();
    }

    /**
     * Get top 5 companies with most contracts.
     */
    public function getMostContractsCompaniesProperty(): Collection
    {
        return $this->companies
            ->sortByDesc('contractCount')
            ->take(5)
            ->values();
    }

    /**
     * Get top 5 companies with best (lowest) spot margins.
     */
    public function getBestSpotMarginsCompaniesProperty(): Collection
    {
        return $this->companies
            ->filter(fn ($data) => $data['hasSpotContracts'] && $data['lowestSpotMargin'] !== null)
            ->sortBy('lowestSpotMargin')
            ->take(5)
            ->values();
    }

    /**
     * Get top 5 companies with lowest monthly fees.
     */
    public function getLowestMonthlyFeesCompaniesProperty(): Collection
    {
        return $this->companies
            ->filter(fn ($data) => $data['lowestMonthlyFee'] !== null)
            ->sortBy('lowestMonthlyFee')
            ->take(5)
            ->values();
    }

    /**
     * Get companies that offer 100% renewable contracts.
     */
    public function getFullyRenewableCompaniesProperty(): Collection
    {
        return $this->companies
            ->filter(fn ($data) => $data['hasFullyRenewable'])
            ->sortByDesc('maxRenewable')
            ->values();
    }

    /**
     * Generate JSON-LD ItemList schema for SEO.
     */
    public function getJsonLdProperty(): array
    {
        $listItems = $this->companies->take(20)->map(function ($data, $index) {
            return [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => [
                    '@type' => 'Organization',
                    'name' => $data['company']->name,
                    'url' => config('app.url') . '/sahkosopimus/sahkoyhtiot/' . $data['company']->name_slug,
                ],
            ];
        })->values()->toArray();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Sähköyhtiöt Suomessa',
            'description' => 'Lista suomalaisista sähköyhtiöistä ja niiden sähkösopimuksista',
            'numberOfItems' => $this->companyCount,
            'itemListElement' => $listItems,
        ];
    }

    /**
     * Get page title.
     */
    public function getPageTitleProperty(): string
    {
        return 'Sähköyhtiöt Suomessa';
    }

    /**
     * Get meta description.
     */
    public function getMetaDescriptionProperty(): string
    {
        return "Vertaile {$this->companyCount} suomalaista sähköyhtiötä. Katso hinnat, uusiutuvan energian osuudet ja päästötiedot yhdestä paikasta.";
    }

    /**
     * Get canonical URL.
     */
    public function getCanonicalUrlProperty(): string
    {
        return config('app.url') . '/sahkosopimus/sahkoyhtiot';
    }

    public function render()
    {
        return view('livewire.company-list', [
            'companies' => $this->companies,
            'filteredCompanies' => $this->filteredCompanies,
            'companyCount' => $this->companyCount,
            'cheapestCompanies' => $this->cheapestCompanies,
            'greenestCompanies' => $this->greenestCompanies,
            'cleanestEmissionsCompanies' => $this->cleanestEmissionsCompanies,
            'mostContractsCompanies' => $this->mostContractsCompanies,
            'bestSpotMarginsCompanies' => $this->bestSpotMarginsCompanies,
            'lowestMonthlyFeesCompanies' => $this->lowestMonthlyFeesCompanies,
            'fullyRenewableCompanies' => $this->fullyRenewableCompanies,
            'jsonLd' => $this->jsonLd,
            'pageTitle' => $this->pageTitle,
            'metaDescription' => $this->metaDescription,
        ])->layout('layouts.app', [
            'title' => $this->pageTitle . ' | Voltikka',
            'metaDescription' => $this->metaDescription,
            'canonical' => $this->canonicalUrl,
        ]);
    }
}
