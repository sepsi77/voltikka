<?php

namespace App\Livewire;

use App\Http\Middleware\SetPublicCacheHeaders;
use App\Services\CompanyListCacheService;
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
     * Get all companies with cached metrics.
     */
    public function getCompaniesProperty(): Collection
    {
        if ($this->cachedCompanies !== null) {
            return $this->cachedCompanies;
        }

        return $this->cachedCompanies = app(CompanyListCacheService::class)
            ->getCachedCompanies($this->consumption);
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
            ->sortBy('avgEmissions')
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
        $listItems = $this->companies->map(function ($data, $index) {
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
            'name' => 'Sähköyhtiöiden vertailu Suomessa',
            'description' => 'Vertaile ja kilpailuta suomalaisia sähköyhtiöitä – hinnat, sopimukset ja päästötiedot',
            'numberOfItems' => $this->companyCount,
            'itemListElement' => $listItems,
        ];
    }

    /**
     * Get page title.
     */
    public function getPageTitleProperty(): string
    {
        return 'Kaikki Suomen sähköyhtiöt vertailussa – katso hinnat ja sopimukset';
    }

    /**
     * Get meta description.
     */
    public function getMetaDescriptionProperty(): string
    {
        return "Kilpailuta sähkösopimus helposti! Vertaile {$this->companyCount} sähköyhtiön hintoja, sopimuksia ja päästötietoja. Löydä edullisin sähköyhtiö vertailussa.";
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
        $this->enableBackButtonCache();

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
        ])->response(function ($response) {
            app(SetPublicCacheHeaders::class)->applyCacheHeaders($response);
        });
    }
}
