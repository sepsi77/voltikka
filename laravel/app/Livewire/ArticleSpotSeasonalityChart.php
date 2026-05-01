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

        foreach ($monthly as $m) {
            $date = Carbon::parse($m->period_start);
            $labels[] = $this->finnishMonths[$date->month] . " '" . substr((string) $date->year, 2);
            $dayPrices[] = round($m->day_avg_with_tax, 2);
            $nightPrices[] = round($m->night_avg_with_tax, 2);
        }

        return [
            'labels' => $labels,
            'day' => $dayPrices,
            'night' => $nightPrices,
        ];
    }

    public function render()
    {
        return view('livewire.article-spot-seasonality-chart', [
            'chartData' => $this->chartData,
        ]);
    }
}
