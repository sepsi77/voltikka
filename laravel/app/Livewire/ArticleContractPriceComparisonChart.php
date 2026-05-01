<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ArticleContractPriceComparisonChart extends Component
{
    private const CONSUMPTION = 5000;

    protected array $segments = [
        'spot' => 'Pörssisähkö',
        'fixed_term_12' => '12 kk kiinteä',
        'open_ended' => 'Toistaiseksi voimassa',
        'hybrid' => 'Joustosähkö',
    ];

    protected array $primarySegments = [
        'spot',
        'fixed_term_12',
        'open_ended',
        'hybrid',
    ];

    public function getDailyStatsProperty(): Collection
    {
        return Cache::remember('article:contract-price-comparison-chart:daily-stats', now()->addHours(6), fn () =>
            ContractPriceDailyStatistic::query()
                ->where('metric_key', 'annual_cost')
                ->where('consumption_kwh', self::CONSUMPTION)
                ->whereIn('segment_key', $this->primarySegments)
                ->orderBy('stat_date')
                ->get()
        );
    }

    public function getDataWindowProperty(): array
    {
        $stats = $this->dailyStats;

        if ($stats->isEmpty()) {
            return ['from' => null, 'to' => null];
        }

        return [
            'from' => Carbon::parse($stats->min('stat_date'))->toDateString(),
            'to' => Carbon::parse($stats->max('stat_date'))->toDateString(),
        ];
    }

    public function getLeadChartPayloadProperty(): array
    {
        $aggregated = [];
        foreach ($this->primarySegments as $key) {
            $aggregated[$key] = $this->aggregatedSeries($key);
        }

        $allTimestamps = collect($aggregated)
            ->flatMap(fn ($series) => $series['x'])
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($allTimestamps === []) {
            return ['x' => [], 'series' => [], 'unit' => 'eur', 'decimals' => 0];
        }

        $series = [];
        foreach ($this->primarySegments as $key) {
            $byTs = array_combine($aggregated[$key]['x'], $aggregated[$key]['median']);
            $values = [];

            foreach ($allTimestamps as $ts) {
                $values[] = $byTs[$ts] ?? null;
            }

            $series[] = [
                'label' => $this->segments[$key] ?? $key,
                'values' => $values,
            ];
        }

        return [
            'x' => $allTimestamps,
            'series' => $series,
            'unit' => 'eur',
            'decimals' => 0,
        ];
    }

    private function aggregatedSeries(string $segmentKey): array
    {
        $rows = $this->dailyStats->where('segment_key', $segmentKey);

        if ($rows->isEmpty()) {
            return ['x' => [], 'median' => []];
        }

        $grouped = $rows
            ->groupBy(fn ($row) => $this->periodStart($row->stat_date)->toDateString())
            ->sortKeys();

        $x = [];
        $median = [];

        foreach ($grouped as $periodStart => $periodRows) {
            $values = $periodRows->pluck('median_value')->filter(fn ($value) => $value !== null);
            $x[] = Carbon::parse($periodStart)->getTimestamp();
            $median[] = $values->isEmpty() ? null : (float) $values->avg();
        }

        return ['x' => $x, 'median' => $median];
    }

    private function periodStart(CarbonInterface|string $date): CarbonInterface
    {
        $date = $date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date);

        return $date->startOfWeek();
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="rounded-2xl border border-slate-200 bg-white p-6 animate-pulse" aria-label="Ladataan sopimushintojen kuvaajaa">
                <div class="h-5 w-56 rounded bg-slate-200"></div>
                <div class="mt-6 h-64 rounded-xl bg-slate-100"></div>
            </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.article-contract-price-comparison-chart', [
            'leadChartPayload' => $this->leadChartPayload,
            'dataWindow' => $this->dataWindow,
            'consumptionLabel' => number_format(self::CONSUMPTION, 0, ',', ' '),
        ]);
    }
}
