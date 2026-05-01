<?php

namespace App\Livewire;

use App\Models\SpotPriceAverage;
use Carbon\Carbon;
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
            $dayPrices[] = round($m->day_avg_with_tax, 2);
            $nightPrices[] = round($m->night_avg_with_tax, 2);
            $entries[] = [
                'label' => $label,
                'avg' => (float) $m->avg_price_with_tax,
            ];
        }

        return [
            'labels' => $labels,
            'day' => $dayPrices,
            'night' => $nightPrices,
            'entries' => $entries,
        ];
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

        $sorted = collect($data['entries'])->sortBy('avg')->values();
        $cheapest = $sorted->first();
        $expensive = $sorted->last();

        $avgDay = collect($data['day'])->avg();
        $avgNight = collect($data['night'])->avg();

        return [
            'cheapestLabel' => $cheapest['label'],
            'cheapestPrice' => round($cheapest['avg'], 2),
            'expensiveLabel' => $expensive['label'],
            'expensivePrice' => round($expensive['avg'], 2),
            'avgDay' => $avgDay !== null ? round($avgDay, 2) : null,
            'avgNight' => $avgNight !== null ? round($avgNight, 2) : null,
        ];
    }

    public function render()
    {
        return view('livewire.article-spot-seasonality-chart', [
            'chartData' => $this->chartData,
            'metrics' => $this->metrics,
        ]);
    }
}
