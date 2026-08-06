<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractStatistics\AnnualSeriesCompatibility;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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
        $pricingBasis = app(PricingMode::class)->expectedContractPriceBasis()->value;
        $latestDate = ContractPriceDailyStatistic::query()
            ->activeAnnualMethod()
            ->where('pricing_basis', $pricingBasis)
            ->where('consumption_kwh', 5000)
            ->whereIn('segment_key', $segments)
            ->max('stat_date');

        if (! $latestDate) {
            return [];
        }

        $to = Carbon::parse($latestDate)->toDateString();
        $from = Carbon::parse($to)->subYear()->toDateString();

        return Cache::remember('article:spot-win-rate-chart:v3:'.ContractPriceDailyStatistic::activeAnnualMethodVersion()->value.':'.$pricingBasis.':'.$to, now()->addHours(6), function () use ($from, $pricingBasis, $segments, $to) {
            $stats = ContractPriceDailyStatistic::query()
                ->activeAnnualMethod()
                ->where('consumption_kwh', 5000)
                ->whereIn('segment_key', $segments)
                ->whereBetween('stat_date', [$from, $to])
                ->where(function ($query) use ($pricingBasis, $to) {
                    $query->where('stat_date', '<', $to)
                        ->orWhere(function ($query) use ($pricingBasis, $to) {
                            $query->where('stat_date', $to)
                                ->where('pricing_basis', $pricingBasis);
                        });
                })
                ->orderBy('stat_date')
                ->toBase()
                ->get(['stat_date', 'segment_key', 'p20_value', 'compatibility_key']);

            $bySegment = [];
            foreach ($stats as $row) {
                $date = Carbon::parse($row->stat_date)->toDateString();
                $bySegment[$row->segment_key][$date] = $row;
            }

            $spotRows = $this->latestCompatibleDailyRegime($bySegment['spot'] ?? []);

            $byComparison = [];
            foreach ($this->comparisonSegments as $key => $_label) {
                $byComparison[$key] = $this->latestCompatibleDailyRegime($bySegment[$key] ?? []);
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
                if (! $hasAny) {
                    continue;
                }

                $labels[] = Carbon::parse($date)->format('j.n.');
                $spotValues[] = $spot->p20_value === null ? null : round((float) $spot->p20_value, 0);
                $firstDate = $firstDate ?? $date;
                $lastDate = $date;
                $totalDays++;

                foreach ($byComparison as $key => $rows) {
                    $row = $rows[$date] ?? null;
                    if ($row === null || $row->p20_value === null) {
                        $series[$key][] = null;

                        continue;
                    }

                    $series[$key][] = round((float) $row->p20_value, 0);
                    if ($spot->p20_value === null) {
                        continue;
                    }

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
                        if ($row === null || $row->p20_value === null || $spot->p20_value === null) {
                            continue;
                        }
                        if ($spot->p20_value < $row->p20_value) {
                            $segmentSpotWins++;
                        }
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
        });
    }

    /**
     * Keep only comparable dates in the segment's newest calculation regime.
     * The compatibility state still sees the complete window, so the first
     * date after a transition remains a chart gap.
     *
     * @param  array<string,object>  $rows
     * @return array<string,object>
     */
    private function latestCompatibleDailyRegime(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $latest = $rows[array_key_last($rows)];
        $latestKey = $latest->compatibility_key;
        $compatibility = new AnnualSeriesCompatibility;
        $compatibleRows = [];

        foreach ($rows as $date => $row) {
            $period = $compatibility->evaluatePeriod([$row->compatibility_key]);
            if ($period['comparable'] && AnnualSeriesCompatibility::sameKey($row->compatibility_key, $latestKey)) {
                $compatibleRows[$date] = $row;
            }
        }

        return $compatibleRows;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="rounded-2xl border border-slate-200 bg-white p-6 animate-pulse" aria-label="Ladataan voittoprosentin kuvaajaa">
                <div class="h-5 w-48 rounded bg-slate-200"></div>
                <div class="mt-6 h-56 rounded-xl bg-slate-100"></div>
            </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.article-spot-win-rate-chart', [
            'chartData' => $this->chartData,
        ]);
    }
}
