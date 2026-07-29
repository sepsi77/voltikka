<?php

namespace App\Livewire;

use App\Models\SpotPriceAverage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ArticleSpotSeasonalityChart extends Component
{
    protected array $finnishMonths = [
        1 => 'Tammi', 2 => 'Helmi', 3 => 'Maalis', 4 => 'Huhti',
        5 => 'Touko', 6 => 'Kesä', 7 => 'Heinä', 8 => 'Elo',
        9 => 'Syys', 10 => 'Loka', 11 => 'Marras', 12 => 'Joulu',
    ];

    public function getChartDataProperty(): array
    {
        return Cache::remember('article:spot-seasonality-chart:' . now()->format('Y-m-d'), now()->addHours(6), function () {
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
            $entries = [];

            foreach ($monthly as $m) {
                $date = Carbon::parse($m->period_start);
                $label = $this->finnishMonths[$date->month] . " '" . substr((string) $date->year, 2);
                $labels[] = $label;
                $dayPrices[] = $m->day_avg_with_tax === null ? null : round((float) $m->day_avg_with_tax, 2);
                $nightPrices[] = $m->night_avg_with_tax === null ? null : round((float) $m->night_avg_with_tax, 2);
                $entries[] = [
                    'label' => $label,
                    'avg' => $m->avg_price_with_tax === null ? null : (float) $m->avg_price_with_tax,
                ];
            }

            return [
                'labels' => $labels,
                'day' => $dayPrices,
                'night' => $nightPrices,
                'entries' => $entries,
            ];
        });
    }

    /**
     * Headline metrics: cheapest month, most expensive month, day/night gap.
     */
    public function getMetricsProperty(): array
    {
        $data = $this->chartData;
        if (empty($data['entries'])) {
            return [];
        }

        $sorted = collect($data['entries'])
            ->filter(fn (array $entry) => is_numeric($entry['avg']))
            ->sortBy('avg')
            ->values();

        if ($sorted->isEmpty()) {
            return [];
        }

        $cheapest = $sorted->first();
        $expensive = $sorted->last();

        $avgDay = collect($data['day'])->filter(fn ($value) => is_numeric($value))->avg();
        $avgNight = collect($data['night'])->filter(fn ($value) => is_numeric($value))->avg();

        return [
            'cheapestLabel' => $cheapest['label'],
            'cheapestPrice' => round($cheapest['avg'], 2),
            'expensiveLabel' => $expensive['label'],
            'expensivePrice' => round($expensive['avg'], 2),
            'avgDay' => $avgDay !== null ? round($avgDay, 2) : null,
            'avgNight' => $avgNight !== null ? round($avgNight, 2) : null,
        ];
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="rounded-2xl border border-slate-200 bg-white p-6 animate-pulse" aria-label="Ladataan hintakausivaihtelun kuvaajaa">
                <div class="h-5 w-48 rounded bg-slate-200"></div>
                <div class="mt-6 h-56 rounded-xl bg-slate-100"></div>
            </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.article-spot-seasonality-chart', [
            'chartData' => $this->chartData,
            'metrics' => $this->metrics,
        ]);
    }
}
