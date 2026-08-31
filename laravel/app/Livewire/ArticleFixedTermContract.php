<?php

namespace App\Livewire;

use App\Services\ContractMarketInsights\ContractMarketInsightService;
use Livewire\Component;

class ArticleFixedTermContract extends Component
{
    public const DATE_PUBLISHED = '2026-01-31';

    public const EDITORIAL_REVIEW_DATE = '2026-08-31';

    public function render()
    {
        $articleData = app(ContractMarketInsightService::class)->fixedTermArticle();

        return view('livewire.article-fixed-term-contract', [
            'articleData' => $articleData,
            'jsonLdSchema' => $this->jsonLdSchema($articleData),
            'editorialReviewDate' => self::EDITORIAL_REVIEW_DATE,
        ])->layout('layouts.app', [
            'title' => 'Kannattaako määräaikainen sähkösopimus? Markkinavertailu | Voltikka',
            'metaDescription' => 'Kannattaako määräaikainen sähkösopimus? Vertaa 12 kuukauden ja toistaiseksi voimassa olevan kiinteähintaisen sopimuksen tasoa, historiaa ja ennustetta.',
            'canonical' => config('app.url').'/sahkosopimus/kannattaako-maaraaikainen',
        ]);
    }

    /**
     * @param  array<string,mixed>  $articleData
     * @return array<string,mixed>
     */
    private function jsonLdSchema(array $articleData): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => 'Kannattaako määräaikainen sähkösopimus? Markkinavertailu',
            'description' => 'Toistaiseksi voimassa olevan kiinteähintaisen sopimuksen nykyinen vertailutaso sekä täysin kiinteiden 6, 12 ja 24 kuukauden sähkösopimusten hinnat, historia ja 30 päivän ennuste.',
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
            'datePublished' => self::DATE_PUBLISHED,
            'dateModified' => self::EDITORIAL_REVIEW_DATE,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => config('app.url').'/sahkosopimus/kannattaako-maaraaikainen',
            ],
        ];

        if (! empty($articleData['data_date'])) {
            $schema['temporalCoverage'] = $articleData['data_date'];
        }

        return $schema;
    }
}
