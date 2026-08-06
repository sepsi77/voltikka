<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\PricingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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
            'headline' => 'Kannattaako pörssisähkö? Markkinavertailu 2026',
            'description' => 'Kannattaako pörssisähkö? Katso pörssisähkön ja kiinteiden 12 kuukauden sopimusten mediaanivuosikustannukset 5 000 kWh kulutuksella sekä hintavaihtelun riskit.',
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
                '@id' => config('app.url').'/sahkosopimus/kannattaako-porssisahko',
            ],
        ];
    }

    /**
     * Latest market snapshot: median annual costs by segment.
     */
    public function getMarketSnapshotProperty(): array
    {
        $mode = app(PricingMode::class);
        $pricingBasis = $mode->expectedContractPriceBasis()->value;
        $canonicalEnabled = $mode->enabled();
        $source = ContractPriceDailyStatistic::query()
            ->activeAnnualMethod()
            ->where('pricing_basis', $pricingBasis)
            ->where('consumption_kwh', 5000);
        $fingerprint = md5(json_encode([
            'canonical_enabled' => $canonicalEnabled,
            'pricing_basis' => $pricingBasis,
            'annual_method' => ContractPriceDailyStatistic::activeAnnualMethodVersion()->value,
            'latest_date' => $source->max('stat_date'),
            'latest_updated' => $source->max('updated_at'),
        ]));

        return Cache::remember(
            'article:spot-electricity:market-snapshot:v3:'.$fingerprint,
            now()->addHours(6),
            function () use ($pricingBasis) {
                $latestDate = ContractPriceDailyStatistic::query()
                    ->activeAnnualMethod()
                    ->where('pricing_basis', $pricingBasis)
                    ->where('consumption_kwh', 5000)
                    ->max('stat_date');

                if (! $latestDate) {
                    return [];
                }

                $latestDate = Carbon::parse($latestDate)
                    ->setTimezone((string) config('app.timezone'))
                    ->toDateString();

                $stats = ContractPriceDailyStatistic::query()
                    ->activeAnnualMethod()
                    ->where('pricing_basis', $pricingBasis)
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
                    'pricing_basis' => $pricingBasis,
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
            },
        );
    }

    /**
     * Monthly spot price seasonality data for the last 13 months.
     */
    public function getSeasonalityDataProperty(): array
    {
        return Cache::remember('article:spot-electricity:seasonality:'.now()->format('Y-m-d'), now()->addHours(6), function () {
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
                $labels[] = $this->finnishMonths[$date->month]." '".substr((string) $date->year, 2);
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
        });
    }

    public function render()
    {
        return view('livewire.article-spot-electricity', [
            'jsonLdSchema' => $this->jsonLdSchema,
            'marketSnapshot' => $this->marketSnapshot,
            'seasonalityData' => $this->seasonalityData,
        ])->layout('layouts.app', [
            'title' => 'Kannattaako pörssisähkö? Markkinavertailu 2026 | Voltikka',
            'metaDescription' => 'Kannattaako pörssisähkö? Katso pörssisähkön ja kiinteiden 12 kuukauden sopimusten mediaanivuosikustannukset 5 000 kWh kulutuksella sekä hintavaihtelun riskit.',
            'canonical' => config('app.url').'/sahkosopimus/kannattaako-porssisahko',
        ]);
    }
}
