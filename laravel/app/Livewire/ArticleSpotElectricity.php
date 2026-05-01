<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use App\Models\SpotPriceAverage;
use Carbon\Carbon;
use Livewire\Component;

class ArticleSpotElectricity extends Component
{
    /**
     * Finnish month abbreviations.
     */
    protected array $finnishMonths = [
        1 => 'Tammi',
        2 => 'Helmi',
        3 => 'Maalis',
        4 => 'Huhti',
        5 => 'Touko',
        6 => 'Kesä',
        7 => 'Heinä',
        8 => 'Elo',
        9 => 'Syys',
        10 => 'Loka',
        11 => 'Marras',
        12 => 'Joulu',
    ];

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

    /**
     * Latest market snapshot: median annual costs by segment.
     */
    public function getMarketSnapshotProperty(): array
    {
        $latestDate = ContractPriceDailyStatistic::query()
            ->where('metric_key', 'annual_cost')
            ->where('consumption_kwh', 5000)
            ->max('stat_date');

        if (!$latestDate) {
            return [];
        }

        $stats = ContractPriceDailyStatistic::query()
            ->where('metric_key', 'annual_cost')
            ->where('consumption_kwh', 5000)
            ->whereIn('segment_key', ['spot', 'fixed_term_12', 'open_ended'])
            ->whereDate('stat_date', $latestDate)
            ->get()
            ->keyBy('segment_key');

        $spot = $stats->get('spot');
        $fixed = $stats->get('fixed_term_12');
        $open = $stats->get('open_ended');

        $snapshot = [
            'date' => Carbon::parse($latestDate)->translatedFormat('j.n.Y'),
            'spot' => $spot ? round($spot->median_value, 0) : null,
            'fixed' => $fixed ? round($fixed->median_value, 0) : null,
            'openEnded' => $open ? round($open->median_value, 0) : null,
        ];

        if ($snapshot['spot'] && $snapshot['fixed']) {
            $snapshot['diff'] = $snapshot['fixed'] - $snapshot['spot'];
            $snapshot['diffPercent'] = $snapshot['fixed'] > 0
                ? round(($snapshot['diff'] / $snapshot['fixed']) * 100, 1)
                : 0;
        }

        return $snapshot;
    }

    /**
     * Monthly spot price seasonality data for the last 13 months.
     */
    public function getSeasonalityDataProperty(): array
    {
        $monthly = SpotPriceAverage::query()
            ->where('region', 'FI')
            ->where('period_type', 'monthly')
            ->where('period_start', '>=', now()->subMonths(13)->format('Y-m-d'))
            ->orderBy('period_start')
            ->get();

        if ($monthly->isEmpty()) {
            return [];
        }

        $labels = [];
        $dayPrices = [];
        $nightPrices = [];
        $avgPrices = [];

        foreach ($monthly as $m) {
            $date = Carbon::parse($m->period_start);
            $labels[] = $this->finnishMonths[$date->month] . " '" . substr((string) $date->year, 2);
            $dayPrices[] = round($m->day_avg_with_tax, 2);
            $nightPrices[] = round($m->night_avg_with_tax, 2);
            $avgPrices[] = round($m->avg_price_with_tax, 2);
        }

        return [
            'labels' => $labels,
            'day' => $dayPrices,
            'night' => $nightPrices,
            'avg' => $avgPrices,
        ];
    }

    public function render()
    {
        return view('livewire.article-spot-electricity', [
            'jsonLdSchema' => $this->jsonLdSchema,
            'marketSnapshot' => $this->marketSnapshot,
            'seasonalityData' => $this->seasonalityData,
        ])->layout('layouts.app', [
            'title' => 'Kannattaako pörssisähkö? Vertailu ja laskuri 2026 | Voltikka',
            'metaDescription' => 'Kannattaako pörssisähkö sinulle? Vertaile pörssisähköä ja kiinteähintaista sopimusta omalla kulutuksellasi. Näe todelliset säästöt ja riskit.',
            'canonical' => config('app.url') . '/sahkosopimus/kannattaako-porssisahko',
        ]);
    }
}
