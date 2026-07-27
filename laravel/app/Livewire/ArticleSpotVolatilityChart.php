<?php

namespace App\Livewire;

use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ArticleSpotVolatilityChart extends Component
{
    protected array $finnishMonths = [
        1 => 'tam', 2 => 'hel', 3 => 'maa', 4 => 'huh',
        5 => 'tou', 6 => 'kes', 7 => 'hei', 8 => 'elo',
        9 => 'syy', 10 => 'lok', 11 => 'mar', 12 => 'jou',
    ];

    /**
     * Build weekly volatility series for the last 12 months.
     *
     * Each week reports min, p20, median, p80 and max across all hourly
     * spot prices observed in that ISO week. The p20–p80 band shows the
     * typical-hour range, while min/max capture spikes and negative hours.
     */
    public function getChartDataProperty(): array
    {
        return Cache::remember('article:spot-volatility-chart:data:'.now()->format('Y-m-d-H'), now()->addHours(6), function () {
            $start = CarbonImmutable::now()->subYear()->startOfWeek();

            $rows = SpotPriceHour::query()
                ->forRegion('FI')
                ->where('utc_datetime', '>=', $start)
                ->orderBy('utc_datetime')
                ->select(['utc_datetime', 'price_without_tax', 'vat_rate'])
                ->toBase()
                ->cursor();

            $byWeek = [];
            foreach ($rows as $row) {
                $price = (float) $row->price_without_tax * (1 + (float) $row->vat_rate);
                $local = Carbon::parse($row->utc_datetime)->setTimezone('Europe/Helsinki');
                $weekStart = $local->copy()->startOfWeek()->format('Y-m-d');
                $byWeek[$weekStart][] = $price;
            }

            if ($byWeek === []) {
                return [];
            }

            ksort($byWeek);

            $labels = [];
            $median = [];
            $p20 = [];
            $p80 = [];
            $min = [];
            $max = [];

            foreach ($byWeek as $weekStart => $prices) {
                sort($prices);
                $count = count($prices);
                $date = Carbon::parse($weekStart);
                $labels[] = $date->day.'.'.$this->finnishMonths[$date->month];
                $median[] = round($this->percentile($prices, 0.5), 2);
                $p20[] = round($this->percentile($prices, 0.2), 2);
                $p80[] = round($this->percentile($prices, 0.8), 2);
                $min[] = round($prices[0], 2);
                $max[] = round($prices[$count - 1], 2);
            }

            return [
                'labels' => $labels,
                'median' => $median,
                'p20' => $p20,
                'p80' => $p80,
                'min' => $min,
                'max' => $max,
            ];
        });
    }

    /**
     * Linear-interpolation percentile over a pre-sorted ascending array.
     */
    protected function percentile(array $sorted, float $q): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        if ($count === 1) {
            return $sorted[0];
        }
        $rank = $q * ($count - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $sorted[$low];
        }
        $weight = $rank - $low;

        return $sorted[$low] * (1 - $weight) + $sorted[$high] * $weight;
    }

    /**
     * Headline metrics shown above the chart.
     */
    public function getMetricsProperty(): array
    {
        return Cache::remember('article:spot-volatility-chart:metrics:'.now()->format('Y-m-d-H'), now()->addHours(6), function () {
            $yearAgo = now()->subYear();

            $agg = SpotPriceHour::query()
                ->forRegion('FI')
                ->where('utc_datetime', '>=', $yearAgo)
                ->selectRaw('
                    MIN(price_without_tax * (1 + vat_rate)) as min_price,
                    MAX(price_without_tax * (1 + vat_rate)) as max_price,
                    AVG(price_without_tax * (1 + vat_rate)) as avg_price
                ')
                ->first();

            $spikeDays = SpotPriceHour::query()
                ->forRegion('FI')
                ->where('utc_datetime', '>=', $yearAgo)
                ->whereRaw('price_without_tax * (1 + vat_rate) > 20')
                ->selectRaw('COUNT(DISTINCT DATE(utc_datetime)) as spike_days')
                ->value('spike_days');

            $negativeDays = SpotPriceHour::query()
                ->forRegion('FI')
                ->where('utc_datetime', '>=', $yearAgo)
                ->whereRaw('price_without_tax * (1 + vat_rate) < 0')
                ->selectRaw('COUNT(DISTINCT DATE(utc_datetime)) as negative_days')
                ->value('negative_days');

            return [
                'min' => $agg && $agg->min_price !== null ? round((float) $agg->min_price, 2) : null,
                'max' => $agg && $agg->max_price !== null ? round((float) $agg->max_price, 2) : null,
                'avg' => $agg && $agg->avg_price !== null ? round((float) $agg->avg_price, 2) : null,
                'spikeDays' => (int) ($spikeDays ?? 0),
                'negativeDays' => (int) ($negativeDays ?? 0),
            ];
        });
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="rounded-2xl border border-slate-200 bg-white p-6 animate-pulse" aria-label="Ladataan tuntihintojen vaihtelua">
                <div class="h-5 w-48 rounded bg-slate-200"></div>
                <div class="mt-6 h-56 rounded-xl bg-slate-100"></div>
            </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.article-spot-volatility-chart', [
            'chartData' => $this->chartData,
            'metrics' => $this->metrics,
        ]);
    }
}
