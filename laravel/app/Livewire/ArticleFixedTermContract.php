<?php

namespace App\Livewire;

use Livewire\Component;

class ArticleFixedTermContract extends Component
{
    /**
     * Get the JSON-LD structured data for this article.
     */
    public function getJsonLdSchemaProperty(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => 'Kannattaako määräaikainen sähkösopimus? Vertailu 2026',
            'description' => 'Kannattaako määräaikainen sähkösopimus vai toistaiseksi voimassa oleva? Vertaile sopimustyyppejä omalla kulutuksellasi.',
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
                '@id' => config('app.url') . '/sahkosopimus/kannattaako-maaraaikainen',
            ],
        ];
    }

    public function render()
    {
        return view('livewire.article-fixed-term-contract', [
            'jsonLdSchema' => $this->jsonLdSchema,
        ])->layout('layouts.app', [
            'title' => 'Kannattaako määräaikainen sähkösopimus? Vertailu 2026 | Voltikka',
            'metaDescription' => 'Kannattaako määräaikainen sähkösopimus vai toistaiseksi voimassa oleva? Vertaile sopimustyyppejä omalla kulutuksellasi ja näe hintaerot.',
            'canonical' => config('app.url') . '/sahkosopimus/kannattaako-maaraaikainen',
        ]);
    }
}
