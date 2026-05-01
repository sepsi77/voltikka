<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use Carbon\Carbon;
use Livewire\Component;

class ArticleSpotWinRateChart extends Component
{
    public function getChartDataProperty(): array
    {
        $stats = ContractPriceDailyStatistic::query()
            ->where('metric_key', 'annual_cost')
            ->where('consumption_kwh', 5000)
            ->whereIn('segment_key', ['spot', 'fixed_term_12'])
            ->orderBy('stat_date')
            ->get();

        $spotRows = $stats->where('segment_key', 'spot')->keyBy('stat_date');
        $fixedRows = $stats->where('segment_key', 'fixed_term_12')->keyBy('stat_date');

        $labels = [];
        $spotValues = [];
        $fixedValues = [];
        $spotWins = 0;
        $fixedWins = 0;
        $total = 0;

        foreach ($spotRows as $date => $spot) {
            if (!isset($fixedRows[$date])) {
                continue;
            }

            $fixed = $fixedRows[$date];
            $total++;

            if ($spot->median_value < $fixed->median_value) {
                $spotWins++;
            } else {
                $fixedWins++;
            }

            $labels[] = Carbon::parse($date)->format('j.n.');
            $spotValues[] = round($spot->median_value, 0);
            $fixedValues[] = round($fixed->median_value, 0);
        }

        return [
            'labels' => $labels,
            'spot' => $spotValues,
            'fixed' => $fixedValues,
            'spotWins' => $spotWins,
            'fixedWins' => $fixedWins,
            'total' => $total,
            'from' => $total > 0 ? Carbon::parse($spotRows->keys()->first())->translatedFormat('j.n.Y') : null,
            'to' => $total > 0 ? Carbon::parse($spotRows->keys()->last())->translatedFormat('j.n.Y') : null,
        ];
    }

    public function render()
    {
        return view('livewire.article-spot-win-rate-chart', [
            'chartData' => $this->chartData,
        ]);
    }
}
