<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class ContractPriceStatistics extends Component
{
    /** Compact period switcher for the lead chart and sparklines. */
    #[Url(as: 'jakso', except: 'weekly')]
    public string $period = 'weekly';

    /** Consumption level selector for the consumption table. */
    #[Url(as: 'kulutus', except: '5000')]
    public int $consumption = 5000;

    public array $periods = [
        'monthly' => 'Kuukausi',
        'weekly' => 'Viikko',
        'daily' => 'Päivä',
    ];

    public array $consumptionLevels = [2000, 5000, 18000];

    public array $segments = [
        'spot' => 'Pörssisähkö',
        'hybrid' => 'Joustosähkö',
        'quarterly' => 'Kvartaalisähkö',
        'fixed_term_below6' => 'Määräaikainen alle 6 kk',
        'fixed_term_6' => 'Määräaikainen 6 kk',
        'fixed_term_7_11' => 'Määräaikainen 7–11 kk',
        'fixed_term_12' => 'Määräaikainen 12 kk',
        'fixed_term_13_23' => 'Määräaikainen 13–23 kk',
        'fixed_term_24' => 'Määräaikainen 24 kk',
        'fixed_term_over24' => 'Määräaikainen yli 24 kk',
        'fixed_term_other' => 'Määräaikainen muu',
        'open_ended' => 'Toistaiseksi voimassa oleva',
    ];

    /** Segments that get a line in the lead chart and a callout. Order matters: index 0 is the coral lead. */
    public array $primarySegments = [
        'spot',
        'fixed_term_12',
        'open_ended',
        'hybrid',
    ];

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, $this->periods)) {
            $this->period = $period;
        }
    }

    public function setConsumption(int $consumption): void
    {
        if (in_array($consumption, $this->consumptionLevels, true)) {
            $this->consumption = $consumption;
        }
    }

    /**
     * @return Collection<int, ContractPriceDailyStatistic>
     */
    public function getDailyStatsProperty(): Collection
    {
        return ContractPriceDailyStatistic::query()
            ->orderBy('stat_date')
            ->get();
    }

    /**
     * @return array{from:?string, to:?string, days:int, dayCount:int}
     */
    public function getDataWindowProperty(): array
    {
        $stats = $this->dailyStats;

        if ($stats->isEmpty()) {
            return ['from' => null, 'to' => null, 'days' => 0, 'dayCount' => 0];
        }

        $dates = $stats->pluck('stat_date')->map(fn ($d) => Carbon::parse($d));
        $from = $dates->min();
        $to = $dates->max();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $from->diffInDays($to) + 1,
            'dayCount' => $dates->unique(fn ($d) => $d->toDateString())->count(),
        ];
    }

    public function getLatestSnapshotCountProperty(): int
    {
        $latestDate = ContractPriceSnapshot::max('snapshot_date');

        return $latestDate
            ? ContractPriceSnapshot::whereDate('snapshot_date', $latestDate)->count()
            : 0;
    }

    public function getLatestSnapshotDateProperty(): ?string
    {
        $latestDate = ContractPriceSnapshot::max('snapshot_date');

        return $latestDate ? Carbon::parse($latestDate)->format('j.n.Y') : null;
    }

    /**
     * Lead chart payload: annual cost @ 5000 kWh for the four primary segments,
     * aggregated to the selected period. Coral on the first segment.
     *
     * @return array{x:array<int,int>,series:array<int,array{label:string,values:array<int,?float>}>,unit:string,decimals:int}
     */
    public function getLeadChartPayloadProperty(): array
    {
        return $this->buildAnnualCostChart($this->primarySegments, $this->consumption);
    }

    /**
     * Spot deep-dive: distribution of supplier margins (avg over time).
     *
     * @return array<string,mixed>
     */
    public function getSpotMarginChartPayloadProperty(): array
    {
        $series = $this->aggregatedSeries('spot', 'spot_margin', null);

        return [
            'unit' => 'cent',
            'decimals' => 2,
            'x' => $series['x'],
            'series' => [['label' => 'Marginaali ka.', 'values' => $series['avg']]],
        ];
    }

    /**
     * Spot deep-dive: total spot energy price (spot avg + supplier margin).
     *
     * @return array<string,mixed>
     */
    public function getSpotTotalChartPayloadProperty(): array
    {
        $series = $this->aggregatedSeries('spot', 'spot_total_energy_price', null);

        return [
            'unit' => 'cent',
            'decimals' => 2,
            'x' => $series['x'],
            'series' => [['label' => 'Kokonaishinta ka.', 'values' => $series['avg']]],
        ];
    }

    /**
     * One row per segment: latest energy price, Δ 30 d, Δ since data start, sparkline SVG path.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getSegmentRowsProperty(): array
    {
        $rows = [];

        foreach ($this->segments as $segmentKey => $segmentLabel) {
            $metric = $segmentKey === 'spot' ? 'spot_total_energy_price' : 'energy_price';
            $series = $this->aggregatedSeries($segmentKey, $metric, null);

            if ($series['x'] === []) {
                continue;
            }

            $values = $series['avg'];
            $current = $this->lastNonNull($values);
            $thirtyDaysAgo = $this->valueClosestToOffset($series, $current['index'] ?? null, -30);
            $first = $this->firstNonNull($values);

            $monthlyFee = $this->aggregatedSeries($segmentKey, 'monthly_fee', null);
            $monthlyFeeCurrent = $this->lastNonNull($monthlyFee['avg']);

            $contractCount = $this->latestContractCount($segmentKey);

            $rows[] = [
                'segment_key' => $segmentKey,
                'segment_label' => $segmentLabel,
                'is_lead' => $segmentKey === ($this->primarySegments[0] ?? null),
                'is_primary' => in_array($segmentKey, $this->primarySegments, true),
                'is_spot' => $segmentKey === 'spot',
                'unit' => 'c/kWh',
                'current_price' => $current['value'] ?? null,
                'first_price' => $first['value'] ?? null,
                'price_30d_ago' => $thirtyDaysAgo,
                'delta_30d_pct' => $this->percentDelta($current['value'] ?? null, $thirtyDaysAgo),
                'delta_since_start_pct' => $this->percentDelta($current['value'] ?? null, $first['value'] ?? null),
                'monthly_fee' => $monthlyFeeCurrent['value'] ?? null,
                'contract_count' => $contractCount,
                'sparkline_path' => $this->sparklinePath($values, 80, 24),
            ];
        }

        return $rows;
    }

    /**
     * Consumption section rows for the currently selected consumption level.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getConsumptionRowsProperty(): array
    {
        $rows = [];

        foreach ($this->segments as $segmentKey => $segmentLabel) {
            $latestRow = ContractPriceDailyStatistic::query()
                ->where('segment_key', $segmentKey)
                ->where('metric_key', 'annual_cost')
                ->where('consumption_kwh', $this->consumption)
                ->orderByDesc('stat_date')
                ->first();

            if (! $latestRow) {
                continue;
            }

            $rows[] = [
                'segment_key' => $segmentKey,
                'segment_label' => $segmentLabel,
                'is_lead' => $segmentKey === ($this->primarySegments[0] ?? null),
                'is_primary' => in_array($segmentKey, $this->primarySegments, true),
                'min' => $latestRow->min_value,
                'p20' => $latestRow->p20_value,
                'avg' => $latestRow->avg_value,
                'p80' => $latestRow->p80_value,
                'max' => $latestRow->max_value,
                'contract_count' => $latestRow->contract_count,
            ];
        }

        return $rows;
    }

    /**
     * Three editorial callouts for the primary segments. Pure data, the view formats them.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getCalloutsProperty(): array
    {
        $callouts = [];

        foreach (array_slice($this->primarySegments, 0, 3) as $segmentKey) {
            $metric = $segmentKey === 'spot' ? 'spot_total_energy_price' : 'energy_price';
            $series = $this->aggregatedSeries($segmentKey, $metric, null);
            $values = $series['avg'];

            if ($values === []) {
                continue;
            }

            $current = $this->lastNonNull($values);
            $first = $this->firstNonNull($values);

            $callouts[] = [
                'segment_key' => $segmentKey,
                'segment_label' => $this->segments[$segmentKey] ?? $segmentKey,
                'is_lead' => $segmentKey === ($this->primarySegments[0] ?? null),
                'current_price' => $current['value'] ?? null,
                'unit' => 'c/kWh',
                'delta_since_start_pct' => $this->percentDelta($current['value'] ?? null, $first['value'] ?? null),
                'delta_30d_pct' => $this->percentDelta(
                    $current['value'] ?? null,
                    $this->valueClosestToOffset($series, $current['index'] ?? null, -30),
                ),
            ];
        }

        return $callouts;
    }

    /**
     * Editorial caption shown below the lead chart. Two short sentences, generated from data.
     *
     * @return array<int,string>
     */
    public function getCaptionProperty(): array
    {
        $sentences = [];
        $callouts = $this->callouts;

        if ($callouts === []) {
            return [];
        }

        $lead = $callouts[0];
        if ($lead['delta_since_start_pct'] !== null) {
            $verb = $lead['delta_since_start_pct'] < 0 ? 'halventuneet' : 'kallistuneet';
            $abs = number_format(abs($lead['delta_since_start_pct']), 0, ',', ' ');
            $sentences[] = "{$lead['segment_label']}-sopimukset ovat {$verb} {$abs} % aineiston alusta.";
        }

        $contrast = collect(array_slice($callouts, 1))
            ->filter(fn ($c) => $c['delta_since_start_pct'] !== null)
            ->sortByDesc(fn ($c) => abs($c['delta_since_start_pct']))
            ->first();

        if ($contrast) {
            $delta = $contrast['delta_since_start_pct'];
            if (abs($delta) < 1.5) {
                $sentences[] = "{$contrast['segment_label']}-sopimukset ovat pysyneet käytännössä paikoillaan.";
            } else {
                $verb = $delta < 0 ? 'halventuneet' : 'kallistuneet';
                $abs = number_format(abs($delta), 0, ',', ' ');
                $sentences[] = "{$contrast['segment_label']}-sopimukset ovat samalla aikavälillä {$verb} {$abs} %.";
            }
        }

        return $sentences;
    }

    /** Pre-formatted citation strings the user can copy. */
    public function getCitationsProperty(): array
    {
        $today = Carbon::today();
        $dateFi = $today->format('j.n.Y');
        $dateIso = $today->toDateString();
        $title = 'Sähkön hintatilastot';
        $url = config('app.url') . '/sahkosopimus/tilastot';

        return [
            'plain' => "Lähde: Voltikka, {$title}, päivitetty {$dateFi}. {$url}",
            'markdown' => "Lähde: [Voltikka, {$title}]({$url}), päivitetty {$dateFi}.",
            'html' => '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Voltikka, ' . htmlspecialchars($title) . '</a>, päivitetty <time datetime="' . $dateIso . '">' . $dateFi . '</time>.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getJsonLdProperty(): array
    {
        $window = $this->dataWindow;
        $today = Carbon::today()->toDateString();
        $url = config('app.url') . '/sahkosopimus/tilastot';
        $csvUrl = config('app.url') . '/sahkosopimus/tilastot.csv';

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Dataset',
            'name' => 'Voltikka — Sähkön hintatilastot sopimustyypeittäin',
            'description' => 'Päivittäin kerättyihin sähkösopimuksiin perustuvat hintatilastot Suomessa. Energiahinnat, perusmaksut, pörssimarginaalit ja vuosikustannukset 2 000 / 5 000 / 18 000 kWh kulutuksella sopimustyypeittäin.',
            'url' => $url,
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
            'isAccessibleForFree' => true,
            'keywords' => ['sähkön hinta', 'sähkösopimus', 'tilastot', 'pörssisähkö', 'määräaikainen', 'Suomi'],
            'inLanguage' => 'fi',
            'creator' => [
                '@type' => 'Organization',
                'name' => 'Voltikka',
                'url' => config('app.url'),
            ],
            'temporalCoverage' => $window['from'] && $window['to']
                ? "{$window['from']}/{$window['to']}"
                : null,
            'dateModified' => $today,
            'distribution' => [
                [
                    '@type' => 'DataDownload',
                    'encodingFormat' => 'text/csv',
                    'contentUrl' => $csvUrl,
                    'name' => 'Sähkön hintatilastot (CSV)',
                ],
            ],
        ];
    }

    public function render()
    {
        $window = $this->dataWindow;
        $latestDate = $this->latestSnapshotDate;

        return view('livewire.contract-price-statistics', [
            'leadChartPayload' => $this->leadChartPayload,
            'spotMarginPayload' => $this->spotMarginChartPayload,
            'spotTotalPayload' => $this->spotTotalChartPayload,
            'segmentRows' => $this->segmentRows,
            'consumptionRows' => $this->consumptionRows,
            'callouts' => $this->callouts,
            'caption' => $this->caption,
            'citations' => $this->citations,
            'dataWindow' => $window,
            'latestSnapshotDate' => $latestDate,
            'latestSnapshotCount' => $this->latestSnapshotCount,
            'jsonLd' => $this->jsonLd,
        ])->layout('layouts.app', [
            'title' => 'Sähkön hintatilastot, mitä suomalaiset oikeasti maksavat | Voltikka',
            'metaDescription' => 'Sähkösopimusten todellinen hintakehitys Suomessa. Voltikan päivittäin kerätyt tilastot pörssi-, määräaikaisista, joustosähkö- ja toistaiseksi voimassa olevista sopimuksista, sis. ALV 25,5 %.',
            'canonical' => config('app.url') . '/sahkosopimus/tilastot',
        ]);
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<int,string> $segmentKeys
     * @return array{x:array<int,int>,series:array<int,array{label:string,values:array<int,?float>}>,unit:string,decimals:int}
     */
    private function buildAnnualCostChart(array $segmentKeys, int $consumption): array
    {
        $aggregated = [];
        foreach ($segmentKeys as $key) {
            $aggregated[$key] = $this->aggregatedSeries($key, 'annual_cost', $consumption);
        }

        $allTimestamps = collect($aggregated)
            ->flatMap(fn ($s) => $s['x'])
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($allTimestamps === []) {
            return ['x' => [], 'series' => [], 'unit' => 'eur', 'decimals' => 0];
        }

        $series = [];
        foreach ($segmentKeys as $key) {
            $byTs = array_combine($aggregated[$key]['x'], $aggregated[$key]['avg']);
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

    /**
     * Aggregates daily statistics for a (segment, metric, consumption) slice into period-keyed series.
     *
     * @return array{x:array<int,int>,avg:array<int,?float>}
     */
    private function aggregatedSeries(string $segmentKey, string $metricKey, ?int $consumption): array
    {
        $rows = $this->dailyStats
            ->where('segment_key', $segmentKey)
            ->where('metric_key', $metricKey)
            ->filter(fn ($row) => $row->consumption_kwh === $consumption);

        if ($rows->isEmpty()) {
            return ['x' => [], 'avg' => []];
        }

        $grouped = $rows->groupBy(fn ($row) => $this->periodStart($row->stat_date)->toDateString())
            ->sortKeys();

        $x = [];
        $avg = [];

        foreach ($grouped as $periodStart => $periodRows) {
            $vals = $periodRows->pluck('avg_value')->filter(fn ($v) => $v !== null);
            $x[] = Carbon::parse($periodStart)->getTimestamp();
            $avg[] = $vals->isEmpty() ? null : (float) $vals->avg();
        }

        return ['x' => $x, 'avg' => $avg];
    }

    private function periodStart(CarbonInterface|string $date): CarbonInterface
    {
        $date = $date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date);

        return match ($this->period) {
            'daily' => $date->startOfDay(),
            'monthly' => $date->startOfMonth(),
            default => $date->startOfWeek(),
        };
    }

    /**
     * @param array<int,?float> $values
     * @return array{value:?float,index:?int}
     */
    private function lastNonNull(array $values): array
    {
        for ($i = count($values) - 1; $i >= 0; $i--) {
            if ($values[$i] !== null) {
                return ['value' => (float) $values[$i], 'index' => $i];
            }
        }

        return ['value' => null, 'index' => null];
    }

    /**
     * @param array<int,?float> $values
     * @return array{value:?float,index:?int}
     */
    private function firstNonNull(array $values): array
    {
        foreach ($values as $i => $v) {
            if ($v !== null) {
                return ['value' => (float) $v, 'index' => $i];
            }
        }

        return ['value' => null, 'index' => null];
    }

    /**
     * Find the value closest to a given day-offset from a reference index.
     * Used for "30 days ago" deltas regardless of period aggregation.
     *
     * @param array{x:array<int,int>,avg:array<int,?float>} $series
     */
    private function valueClosestToOffset(array $series, ?int $referenceIndex, int $dayOffset): ?float
    {
        if ($referenceIndex === null || ! isset($series['x'][$referenceIndex])) {
            return null;
        }

        $targetTs = $series['x'][$referenceIndex] + $dayOffset * 86400;
        $bestIdx = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($series['x'] as $i => $ts) {
            if ($series['avg'][$i] === null) {
                continue;
            }

            $diff = abs($ts - $targetTs);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestIdx = $i;
            }
        }

        if ($bestIdx === null) {
            return null;
        }

        // Require the candidate to be within a reasonable window of the target.
        $windowSeconds = abs($dayOffset) * 86400 * 1.5 + 7 * 86400;
        if ($bestDiff > $windowSeconds) {
            return null;
        }

        return (float) $series['avg'][$bestIdx];
    }

    private function percentDelta(?float $current, ?float $reference): ?float
    {
        if ($current === null || $reference === null || $reference == 0.0) {
            return null;
        }

        return (($current - $reference) / $reference) * 100.0;
    }

    private function latestContractCount(string $segmentKey): ?int
    {
        $row = ContractPriceDailyStatistic::query()
            ->where('segment_key', $segmentKey)
            ->where('metric_key', 'energy_price')
            ->whereNull('consumption_kwh')
            ->orderByDesc('stat_date')
            ->first();

        return $row ? (int) $row->contract_count : null;
    }

    /**
     * Generate an inline-SVG path string ("M x,y L x,y …") for sparkline rendering.
     *
     * @param array<int,?float> $values
     */
    private function sparklinePath(array $values, int $width, int $height): ?string
    {
        $clean = array_values(array_filter($values, fn ($v) => $v !== null));
        if (count($clean) < 2) {
            return null;
        }

        $min = min($clean);
        $max = max($clean);
        $range = $max - $min ?: 1.0;
        $count = count($values);
        $stepX = $count > 1 ? $width / ($count - 1) : 0;
        $padY = 2;
        $usableH = $height - 2 * $padY;

        $parts = [];
        $started = false;
        foreach ($values as $i => $v) {
            if ($v === null) {
                $started = false;
                continue;
            }
            $x = round($i * $stepX, 2);
            $y = round($padY + ($usableH - (($v - $min) / $range) * $usableH), 2);
            $parts[] = ($started ? 'L' : 'M') . " {$x},{$y}";
            $started = true;
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
