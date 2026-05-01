<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use Carbon\Carbon;
use Livewire\Component;

class ArticleSpotWinRateChart extends Component
{
    /**
     * Comparison segments rendered as separate lines against pörssisähkö.
     * Order is significant: it controls legend / tooltip ordering.
     */
    protected array $comparisonSegments = [
        'fixed_term_12' => 'Määräaikainen 12 kk',
        'fixed_term_24' => 'Määräaikainen 24 kk',
        'open_ended' => 'Toistaiseksi voimassa',
    ];

    public function getChartDataProperty(): array
    {
        $segments = array_merge(['spot'], array_keys($this->comparisonSegments));

        $stats = ContractPriceDailyStatistic::query()
            ->where('metric_key', 'annual_cost')
            ->where('consumption_kwh', 5000)
            ->whereIn('segment_key', $segments)
            ->orderBy('stat_date')
            ->get();

        $spotRows = $stats->where('segment_key', 'spot')->keyBy(fn ($r) => (string) $r->stat_date->format('Y-m-d'));

        $byComparison = [];
        foreach ($this->comparisonSegments as $key => $_label) {
            $byComparison[$key] = $stats->where('segment_key', $key)
                ->keyBy(fn ($r) => (string) $r->stat_date->format('Y-m-d'));
        }

        $labels = [];
        $spotValues = [];
        $series = [];
        $wins = ['spot' => 0];

        foreach ($this->comparisonSegments as $key => $_label) {
            $series[$key] = [];
            $wins[$key] = 0;
        }

        $totalDays = 0;
        $perSegmentTotals = array_fill_keys(array_keys($this->comparisonSegments), 0);

        $firstDate = null;
        $lastDate = null;

        foreach ($spotRows as $date => $spot) {
            // require at least one comparison segment to have data on this date
            $hasAny = false;
            foreach ($byComparison as $rows) {
                if (isset($rows[$date])) {
                    $hasAny = true;
                    break;
                }
            }
            if (!$hasAny) {
                continue;
            }

            $labels[] = Carbon::parse($date)->format('j.n.');
            $spotValues[] = round($spot->p20_value, 0);
            $firstDate = $firstDate ?? $date;
            $lastDate = $date;
            $totalDays++;

            foreach ($byComparison as $key => $rows) {
                $row = $rows[$date] ?? null;
                if ($row === null) {
                    $series[$key][] = null;
                    continue;
                }
                $series[$key][] = round($row->p20_value, 0);
                $perSegmentTotals[$key]++;
                if ($spot->p20_value < $row->p20_value) {
                    $wins['spot']++;
                } elseif ($row->p20_value < $spot->p20_value) {
                    $wins[$key]++;
                }
            }
        }

        // Per-segment win rates: pörssisähkö wins out of overlapping days only.
        $segmentRates = [];
        foreach ($this->comparisonSegments as $key => $label) {
            $overlap = $perSegmentTotals[$key];
            $segmentRates[$key] = [
                'label' => $label,
                'overlap_days' => $overlap,
                'spot_wins' => null,
                'spot_win_pct' => null,
            ];
            if ($overlap > 0) {
                $segmentSpotWins = 0;
                foreach ($spotRows as $date => $spot) {
                    $row = $byComparison[$key][$date] ?? null;
                    if ($row === null) continue;
                    if ($spot->p20_value < $row->p20_value) $segmentSpotWins++;
                }
                $segmentRates[$key]['spot_wins'] = $segmentSpotWins;
                $segmentRates[$key]['spot_win_pct'] = round($segmentSpotWins / $overlap * 100, 1);
            }
        }

        return [
            'labels' => $labels,
            'spot' => $spotValues,
            'series' => $series,
            'segmentMeta' => $this->comparisonSegments,
            'segmentRates' => $segmentRates,
            'total' => $totalDays,
            'from' => $firstDate ? Carbon::parse($firstDate)->translatedFormat('j.n.Y') : null,
            'to' => $lastDate ? Carbon::parse($lastDate)->translatedFormat('j.n.Y') : null,
        ];
    }

    public function render()
    {
        return view('livewire.article-spot-win-rate-chart', [
            'chartData' => $this->chartData,
        ]);
    }
}
