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
 */
class SahkosopimusIndex extends SeoContractsList
{
    /**
     * Generate SEO-optimized title for the comparison index page.
     */
    protected function generateSeoTitle(): string
    {
        return 'Sähkösopimusten vertailu ' . date('Y') . ' | Voltikka';
    }

    /**
     * Generate meta description for the comparison index page.
     */
    protected function generateMetaDescription(): string
    {
        return 'Vertaile sähkösopimuksia helposti. Näe hinnat, marginaalit, perusmaksut ja energialähteet yhdestä paikasta. Löydä edullisin sähkösopimus pörssisähköstä ja kiinteähintaisista sopimuksista.';
    }

    /**
     * Generate canonical URL for the comparison index page.
     */
    protected function generateCanonicalUrl(): string
    {
        return config('app.url') . '/sahkosopimus';
    }

    /**
     * Get the page heading (H1) for the comparison index page.
     */
    public function getPageHeadingProperty(): string
    {
        return 'Sähkösopimusten vertailu';
    }

    /**
     * Get SEO intro text for the comparison index page.
     */
    public function getSeoIntroTextProperty(): string
    {
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
