<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractStatistics\AnnualSeriesCompatibility;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    public function getPreparedDataProperty(): array
    {
        $pricingBasis = app(PricingMode::class)->expectedContractPriceBasis()->value;
        $latestDate = ContractPriceDailyStatistic::query()
            ->activeAnnualMethod()
            ->where('pricing_basis', $pricingBasis)
            ->where('consumption_kwh', self::CONSUMPTION)
            ->whereIn('segment_key', $this->primarySegments)
            ->max('stat_date');

        if (! $latestDate) {
            return $this->emptyPreparedData();
        }

        $to = Carbon::parse($latestDate)->toDateString();
        $from = Carbon::parse($to)->subYear()->toDateString();

        return Cache::remember(
            'article:contract-price-comparison-chart:prepared:v5:'.ContractPriceDailyStatistic::activeAnnualMethodVersion()->value.':'.$pricingBasis.':'.$to,
            now()->addHours(6),
            function () use ($from, $pricingBasis, $to): array {
                $rows = ContractPriceDailyStatistic::query()
                    ->activeAnnualMethod()
                    ->where('consumption_kwh', self::CONSUMPTION)
                    ->whereIn('segment_key', $this->primarySegments)
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
                    ->get(['stat_date', 'segment_key', 'median_value', 'method_version', 'compatibility_key', 'basis_counts']);

                if ($rows->isEmpty()) {
                    return $this->emptyPreparedData();
                }

                $weekly = [];
                $firstDate = null;
                $lastDate = null;

                foreach ($rows as $row) {
                    $date = Carbon::parse($row->stat_date);
                    $dateString = $date->toDateString();
                    $firstDate ??= $dateString;
                    $lastDate = $dateString;

                    $timestamp = $this->periodStart($date)->getTimestamp();
                    $basisCounts = is_string($row->basis_counts)
                        ? json_decode($row->basis_counts, true)
                        : $row->basis_counts;
                    $weekly[$row->segment_key][$timestamp]['compatibility_keys'][] = AnnualSeriesCompatibility::aggregateDisplayKey(
                        $row->compatibility_key,
                        $row->method_version,
                        is_array($basisCounts) ? $basisCounts : null,
                    );
                    if ($row->median_value !== null) {
                        $weekly[$row->segment_key][$timestamp]['values'][] = (float) $row->median_value;
                    }
                }

                $timestamps = [];
                foreach ($weekly as $segmentWeeks) {
                    $timestamps = array_merge($timestamps, array_keys($segmentWeeks));
                }
                $timestamps = array_values(array_unique($timestamps));
                sort($timestamps);

                $series = [];
                foreach ($this->primarySegments as $key) {
                    $values = [];
                    $compatibility = new AnnualSeriesCompatibility;
                    foreach ($timestamps as $timestamp) {
                        $bucket = $weekly[$key][$timestamp] ?? null;
                        if ($bucket === null) {
                            $values[] = null;

                            continue;
                        }

                        $period = $compatibility->evaluatePeriod($bucket['compatibility_keys']);
                        $values[] = $period['comparable'] && ($bucket['values'] ?? []) !== []
                            ? array_sum($bucket['values']) / count($bucket['values'])
                            : null;
                    }

                    $series[] = [
                        'label' => $this->segments[$key] ?? $key,
                        'values' => $values,
                    ];
                }

                return [
                    'data_window' => ['from' => $firstDate, 'to' => $lastDate],
                    'lead_chart' => [
                        'x' => $timestamps,
                        'series' => $series,
                        'unit' => 'eur',
                        'decimals' => 0,
                    ],
                ];
            },
        );
    }

    public function getDataWindowProperty(): array
    {
        return $this->preparedData['data_window'];
    }

    public function getLeadChartPayloadProperty(): array
    {
        return $this->preparedData['lead_chart'];
    }

    private function emptyPreparedData(): array
    {
        return [
            'data_window' => ['from' => null, 'to' => null],
            'lead_chart' => ['x' => [], 'series' => [], 'unit' => 'eur', 'decimals' => 0],
        ];
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
