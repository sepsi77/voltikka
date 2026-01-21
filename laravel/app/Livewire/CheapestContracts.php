<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cheapest electricity contracts page.
 *
 * URL: /sahkosopimus/halvin-sahkosopimus
 *
 * This component displays the cheapest contracts with the #1 cheapest
 * featured prominently. It overrides the parent's contracts property
 * to get the top 11 cheapest, then splits them into:
 * - Featured contract (#1 cheapest)
 * - Remaining contracts (#2-11)
 *
 * Note: This page is not paginated - it only shows top 11 contracts.
 */
class CheapestContracts extends SeoContractsList
{
    /**
     * Cache for all contracts from parent (as paginator).
     */
    private ?LengthAwarePaginator $allContractsCache = null;

    /**
     * Get all contracts sorted by price from parent (cached).
     */
    protected function getAllSortedContracts(): Collection
    {
        if ($this->allContractsCache === null) {
            $this->allContractsCache = parent::getContractsProperty();
        }

        // Since parent now returns a paginator, get the items as a collection
        // The parent's paginator returns the first page (25 items), which is enough for top 11
        return collect($this->allContractsCache->items());
    }

    /**
     * Get the featured contract (rank #1 cheapest).
     */
    public function getFeaturedContractProperty(): ?ElectricityContract
    {
        return $this->getAllSortedContracts()->first();
    }

    /**
     * Get contracts ranked #2-11 (next 10 cheapest after the featured).
     *
     * Returns a paginator to maintain compatibility with parent class,
     * but effectively acts as a simple collection with 10 items.
     */
    public function getContractsProperty(): LengthAwarePaginator
    {
        $items = $this->getAllSortedContracts()->slice(1, 10)->values();

        // Return a paginator that shows all items on one page
        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $items->count(),
            100, // Large perPage to show all on one page
            1,
            [
                'path' => url('/sahkosopimus/halvin-sahkosopimus'),
                'pageName' => 'page',
            ]
        );
    }

    /**
     * Generate SEO-optimized title for the cheapest contracts page.
     */
    protected function generateSeoTitle(): string
    {
        return 'Halvin sähkösopimus ' . date('Y') . ' | Voltikka';
    }

    /**
     * Generate dynamic meta description mentioning the cheapest company.
     */
    protected function generateMetaDescription(): string
    {
        $featured = $this->featuredContract;
        $companyName = $featured?->company?->name ?? 'Suomen';

        return "Halvin sähkösopimus juuri nyt on {$companyName}. Vertaile edullisimmat sähkösopimukset ja löydä paras hinta omaan kulutukseesi.";
    }

    /**
     * Generate canonical URL for the cheapest contracts page.
     */
    protected function generateCanonicalUrl(): string
    {
        return config('app.url') . '/sahkosopimus/halvin-sahkosopimus';
    }

    /**
     * Get the page heading (H1) for the cheapest contracts page.
     */
    public function getPageHeadingProperty(): string
    {
        return 'Halvin sähkösopimus';
    }

    /**
     * Get SEO intro text with dynamic company mention.
     */
    public function getSeoIntroTextProperty(): string
    {
        $featured = $this->featuredContract;
        $companyName = $featured?->company?->name;
        $formattedConsumption = number_format($this->consumption, 0, ',', ' ');

        if ($companyName) {
            return "Halvin sähkösopimus {$formattedConsumption} kWh vuosikulutuksella on tällä hetkellä {$companyName}. Alta löydät edullisimmat sähkösopimukset järjestyksessä. Voit muuttaa kulutustasoa nähdäksesi parhaat vaihtoehdot juuri sinun tarpeisiisi.";
        }

        return "Löydä halvin sähkösopimus omaan kulutukseesi. Vertailemme kaikki sähkösopimukset ja näytämme edullisimmat vaihtoehdot hinnan mukaan järjestettynä.";
    }

    /**
     * Always show internal links on this page.
     */
    public function getHasSeoFilterProperty(): bool
    {
        return true;
    }

    /**
     * Render the component with its custom view.
     */
    public function render()
    {
        return view('livewire.cheapest-contracts', [
            'contracts' => $this->contracts,
            'featuredContract' => $this->featuredContract,
            'postcodeSuggestions' => $this->postcodeSuggestions,
            'seoData' => $this->seoData,
            'pageHeading' => $this->pageHeading,
            'seoIntroText' => $this->seoIntroText,
            'hasSeoFilter' => $this->hasSeoFilter,
        ])->layout('layouts.app', [
            'title' => $this->seoData['title'],
            'metaDescription' => $this->seoData['description'],
            'canonical' => $this->seoData['canonical'],
        ]);
    }
}
