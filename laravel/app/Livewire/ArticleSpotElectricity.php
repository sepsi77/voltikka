<?php

namespace App\Livewire;

use Livewire\Component;

class ArticleSpotElectricity extends Component
{
    /**
     * Get the JSON-LD structured data for this article.
     */
    public function getJsonLdSchemaProperty(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => 'Kannattaako pörssisähkö? Vertailu ja laskuri 2026',
            'description' => 'Kannattaako pörssisähkö sinulle? Vertaile pörssisähköä ja kiinteähintaista sopimusta omalla kulutuksellasi.',
            'author' => [
                '@type' => 'Organization',
                'name' => 'Voltikka',
                'url' => config('app.url'),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'Voltikka',
                'url' => config('app.url'),
            ],
            'datePublished' => '2026-01-31',
            'dateModified' => now()->format('Y-m-d'),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => config('app.url') . '/sahkosopimus/kannattaako-porssisahko',
            ],
        ];
    }

    public function render()
    {
        return view('livewire.article-spot-electricity', [
            'jsonLdSchema' => $this->jsonLdSchema,
        ])->layout('layouts.app', [
            'title' => 'Kannattaako pörssisähkö? Vertailu ja laskuri 2026 | Voltikka',
            'metaDescription' => 'Kannattaako pörssisähkö sinulle? Vertaile pörssisähköä ja kiinteähintaista sopimusta omalla kulutuksellasi. Näe todelliset säästöt ja riskit.',
            'canonical' => config('app.url') . '/sahkosopimus/kannattaako-porssisahko',
        ]);
    }
}
