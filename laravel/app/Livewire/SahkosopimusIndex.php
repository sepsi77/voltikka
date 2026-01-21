<?php

namespace App\Livewire;

/**
 * Main comparison index page for all electricity contracts.
 *
 * URL: /sahkosopimus
 *
 * This component serves as the primary entry point for users wanting
 * to compare electricity contracts. It extends SeoContractsList but
 * overrides SEO-related methods to provide specific metadata for
 * the main comparison page.
 *
 * When filters are active (via query params), the page title and
 * heading are customized to reflect the selected filters for SEO.
 */
class SahkosopimusIndex extends SeoContractsList
{
    /**
     * Base path for this page (used for filter links).
     */
    public string $basePath = '/sahkosopimus';

    /**
     * Whether this page should show SEO filter links.
     */
    public bool $showSeoFilterLinks = true;

    /**
     * Generate SEO-optimized title for the comparison index page.
     * When filters are active, use filter-aware title from parent.
     */
    protected function generateSeoTitle(): string
    {
        if ($this->hasActiveFilters()) {
            return $this->pageTitle . ' | Voltikka';
        }

        return 'Sähkösopimusten vertailu ' . date('Y') . ' | Voltikka';
    }

    /**
     * Generate meta description for the comparison index page.
     * When filters are active, use filter-aware description from parent.
     */
    protected function generateMetaDescription(): string
    {
        if ($this->hasActiveFilters()) {
            return $this->metaDescription;
        }

        return 'Vertaile sähkösopimuksia helposti. Näe hinnat, marginaalit, perusmaksut ja energialähteet yhdestä paikasta. Löydä edullisin sähkösopimus pörssisähköstä ja kiinteähintaisista sopimuksista.';
    }

    /**
     * Generate canonical URL for the comparison index page.
     * Always use the base URL without query params to avoid duplicate content.
     */
    protected function generateCanonicalUrl(): string
    {
        return config('app.url') . '/sahkosopimus';
    }

    /**
     * Get the page heading (H1) for the comparison index page.
     * When filters are active, use filter-aware heading.
     */
    public function getPageHeadingProperty(): string
    {
        if ($this->hasActiveFilters()) {
            return $this->pageTitle;
        }

        return 'Sähkösopimusten vertailu';
    }

    /**
     * Get SEO intro text for the comparison index page.
     * When filters are active, use filter-aware intro text.
     */
    public function getSeoIntroTextProperty(): string
    {
        if ($this->hasActiveFilters()) {
            return $this->metaDescription;
        }

        return 'Vertaile sähkösopimuksia helposti yhdestä paikasta. Näet hinnat, marginaalit, perusmaksut ja energialähteet. Löydä edullisin sähkösopimus omiin tarpeisiisi vertailemalla pörssisähköä ja kiinteähintaisia sopimuksia.';
    }

    /**
     * This is the main index page, so always show internal links.
     */
    public function getHasSeoFilterProperty(): bool
    {
        return true;
    }
}
