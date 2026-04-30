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

    /** Segments shown as full deep-dive sections, in display order. */
    public array $deepDiveSegments = [
        'spot',
        'fixed_term_6',
        'fixed_term_12',
        'fixed_term_24',
        'hybrid',
        'open_ended',
    ];

    /** URL-friendly anchor slugs for the deep-dive sections. */
    public array $deepDiveAnchors = [
        'spot' => 'porssisahko',
        'fixed_term_6' => 'maaraaikainen-6-kk',
        'fixed_term_12' => 'maaraaikainen-12-kk',
        'fixed_term_24' => 'maaraaikainen-24-kk',
        'hybrid' => 'joustosahko',
        'open_ended' => 'toistaiseksi-voimassa-oleva',
    ];

    /** Plain-Finnish 1–2 sentence intro per segment for the deep-dive blocks. */
    public array $deepDiveDescriptions = [
        'spot' => 'Pörssisopimuksissa energian hinta seuraa pörssin tuntihintaa, johon sopimustarjoaja lisää oman marginaalinsa. Hinta vaihtelee päivästä toiseen markkinatilanteen mukaan.',
        'fixed_term_6' => 'Lyhyen määräaikaisen sopimuksen energiahinta lukitaan kuudeksi kuukaudeksi. Suojaa lyhyellä aikavälillä, mutta jää alttiiksi pörssin liikkeille uusittaessa.',
        'fixed_term_12' => 'Vuoden mittainen kiinteähintainen sopimus lukitsee energiahinnan koko sopimuskaudeksi. Hinnat heijastavat pitkän aikavälin näkymiä, eivät päivittäistä pörssiä.',
        'fixed_term_24' => 'Kahden vuoden määräaikaisessa sopimuksessa hinta lukitaan pidemmäksi aikaa. Tarjoukset päivittyvät hitaammin, ja markkinoilla on usein vuoden sopimuksia harvempi valikoima.',
        'hybrid' => 'Joustosähkö-sopimuksissa kuukausimaksu ja kiinteä energiahinta säilyvät koko sopimuskauden. Kulutuksen ajoittamisesta voi tulla noin ±0–3 c/kWh kulutusvaikutus, jota tämän tilaston aineisto ei kata.',
        'open_ended' => 'Toistaiseksi voimassa olevat sopimukset jatkuvat kunnes asiakas tai myyjä irtisanoo ne. Tarjoaja voi muuttaa hintaa ennakkoilmoituksella, joten energiahinta päivittyy hitaammin kuin pörssi mutta nopeammin kuin määräaikaiset.',
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
            'series' => [['label' => 'Marginaali, mediaani', 'values' => $series['median']]],
        ];
    }

    /**
     * Per-segment deep-dive payloads. Each entry contains a callout summary,
     * a description, and a range chart (avg line + p20–p80 band).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getDeepDivePayloadsProperty(): array
    {
        $payloads = [];

        $spotBands = $this->aggregatedSeriesWithBands('spot', 'spot_total_energy_price');
        $spotCurrent = $this->lastNonNull($spotBands['median'])['value'] ?? null;

        foreach ($this->deepDiveSegments as $segmentKey) {
            $metric = $segmentKey === 'spot' ? 'spot_total_energy_price' : 'energy_price';
            $bands = $this->aggregatedSeriesWithBands($segmentKey, $metric);

            $current = $this->lastNonNull($bands['median']);
            $first = $this->firstNonNull($bands['median']);
            $thirtyDaysAgo = $current['index'] !== null
                ? $this->valueClosestToOffset(
                    ['x' => $bands['x'], 'values' => $bands['median']],
                    $current['index'],
                    -30,
                )
                : null;

            $payloads[] = [
                'segment_key' => $segmentKey,
                'segment_label' => $this->segments[$segmentKey] ?? $segmentKey,
                'anchor' => $this->deepDiveAnchors[$segmentKey] ?? $segmentKey,
                'description' => $this->deepDiveDescriptions[$segmentKey] ?? '',
                'is_spot' => $segmentKey === 'spot',
                'current' => $current['value'] ?? null,
                'unit' => 'c/kWh',
                'delta_30d_pct' => $this->percentDelta($current['value'] ?? null, $thirtyDaysAgo),
                'delta_since_start_pct' => $this->percentDelta($current['value'] ?? null, $first['value'] ?? null),
                'contract_count' => $this->latestContractCount($segmentKey),
                'has_data' => count($bands['x']) >= 2 && $current['value'] !== null,
                'quotable' => $this->buildQuotableForSegment(
                    $segmentKey,
                    $current['value'] ?? null,
                    $first['value'] ?? null,
                    $segmentKey === 'spot' ? null : $spotCurrent,
                ),
                'chart' => [
                    'unit' => 'cent',
                    'decimals' => 2,
                    'x' => $bands['x'],
                    'series' => [
                        ['label' => $this->segments[$segmentKey] ?? $segmentKey, 'values' => $bands['median']],
                    ],
                    // Spot contracts all share the same daily spot price, so their
                    // p20–p80 spread (driven only by margin differences) is too thin
                    // to read on a chart whose y-axis is dominated by spot's huge
                    // temporal swings. Use min–max for spot to make the provider
                    // spread visible; keep p20–p80 for other segments where it is
                    // the more interesting "typical range" story.
                    'band' => $segmentKey === 'spot'
                        ? [
                            'lower' => $bands['min'],
                            'upper' => $bands['max'],
                            'label' => 'Halvin – kallein tarjous',
                        ]
                        : [
                            'lower' => $bands['p20'],
                            'upper' => $bands['p80'],
                            'label' => 'Halvempi 20 % – kalliimpi 20 %',
                        ],
                ],
            ];
        }

        return $payloads;
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

            $values = $series['median'];
            $current = $this->lastNonNull($values);
            $thirtyDaysAgo = $this->valueClosestToOffset(
                ['x' => $series['x'], 'values' => $values],
                $current['index'] ?? null,
                -30,
            );
            $first = $this->firstNonNull($values);

            $monthlyFee = $this->aggregatedSeries($segmentKey, 'monthly_fee', null);
            $monthlyFeeCurrent = $this->lastNonNull($monthlyFee['median']);

            $contractCount = $this->latestContractCount($segmentKey);

            // Sparkline must track the SAME metric as the lead chart (annual_cost
            // at the page consumption), so that spot's smoothed rolling-12mo trend
            // and the table's per-row trend show the same shape. Using daily
            // energy_price here would make spot's sparkline drop ~70 % while the
            // lead chart line stayed flat.
            $costSeries = $this->aggregatedSeries($segmentKey, 'annual_cost', $this->consumption);
            $sparklineValues = $costSeries['median'] !== [] ? $costSeries['median'] : $values;

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
                'sparkline_path' => $this->sparklinePath($sparklineValues, 80, 24),
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
                'median' => $latestRow->median_value,
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
            $values = $series['median'];

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
                    $this->valueClosestToOffset(
                        ['x' => $series['x'], 'values' => $values],
                        $current['index'] ?? null,
                        -30,
                    ),
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
            'deepDivePayloads' => $this->deepDivePayloads,
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
            'metaDescription' => 'Sähkösopimusten hintatilastot Suomessa. Vertaa eri sopimustyyppien hintaeroja, vuosikustannuksia ja hintakehitystä.',
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

    /**
     * Like {@see aggregatedSeries()} but also returns per-period p20/p80 bands
     * around the median. Used by the per-segment deep-dive charts.
     *
     * The `median` key is sourced from each day's stored median; period
     * aggregation averages those daily medians, so the trend is market-day
     * weighted rather than contract-row weighted.
     *
     * @return array{x:array<int,int>,median:array<int,?float>,p20:array<int,?float>,p80:array<int,?float>,min:array<int,?float>,max:array<int,?float>}
     */
    private function aggregatedSeriesWithBands(string $segmentKey, string $metricKey): array
    {
        $rows = $this->dailyStats
            ->where('segment_key', $segmentKey)
            ->where('metric_key', $metricKey)
            ->filter(fn ($row) => $row->consumption_kwh === null);

        if ($rows->isEmpty()) {
            return ['x' => [], 'median' => [], 'p20' => [], 'p80' => [], 'min' => [], 'max' => []];
        }

        $grouped = $rows
            ->groupBy(fn ($row) => $this->periodStart($row->stat_date)->toDateString())
            ->sortKeys();

        $x = $median = $p20 = $p80 = $min = $max = [];
        foreach ($grouped as $periodStart => $periodRows) {
            $x[] = Carbon::parse($periodStart)->getTimestamp();
            $median[] = $this->averageOrNull($periodRows->pluck('median_value'));
            $p20[] = $this->averageOrNull($periodRows->pluck('p20_value'));
            $p80[] = $this->averageOrNull($periodRows->pluck('p80_value'));
            $min[] = $this->minOrNull($periodRows->pluck('min_value'));
            $max[] = $this->maxOrNull($periodRows->pluck('max_value'));
        }

        return ['x' => $x, 'median' => $median, 'p20' => $p20, 'p80' => $p80, 'min' => $min, 'max' => $max];
    }

    private function averageOrNull(Collection $values): ?float
    {
        $clean = $values->filter(fn ($v) => $v !== null);
        return $clean->isEmpty() ? null : (float) $clean->avg();
    }

    private function minOrNull(Collection $values): ?float
    {
        $clean = $values->filter(fn ($v) => $v !== null);
        return $clean->isEmpty() ? null : (float) $clean->min();
    }

    private function maxOrNull(Collection $values): ?float
    {
        $clean = $values->filter(fn ($v) => $v !== null);
        return $clean->isEmpty() ? null : (float) $clean->max();
    }

    /**
     * Aggregates daily statistics for a (segment, metric, consumption) slice
     * into period-keyed series. The headline `median` series uses each day's
     * stored median (robust to outliers and bad-import single rows). Weekly /
     * monthly aggregation averages those daily medians so the trend stays
     * market-day weighted.
     *
     * @return array{x:array<int,int>,median:array<int,?float>}
     */
    private function aggregatedSeries(string $segmentKey, string $metricKey, ?int $consumption): array
    {
        $rows = $this->dailyStats
            ->where('segment_key', $segmentKey)
            ->where('metric_key', $metricKey)
            ->filter(fn ($row) => $row->consumption_kwh === $consumption);

        if ($rows->isEmpty()) {
            return ['x' => [], 'median' => []];
        }

        $grouped = $rows->groupBy(fn ($row) => $this->periodStart($row->stat_date)->toDateString())
            ->sortKeys();

        $x = [];
        $median = [];

        foreach ($grouped as $periodStart => $periodRows) {
            $vals = $periodRows->pluck('median_value')->filter(fn ($v) => $v !== null);
            $x[] = Carbon::parse($periodStart)->getTimestamp();
            $median[] = $vals->isEmpty() ? null : (float) $vals->avg();
        }

        return ['x' => $x, 'median' => $median];
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
     * @param array{x:array<int,int>,values:array<int,?float>} $series
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
            if ($series['values'][$i] === null) {
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

        return (float) $series['values'][$bestIdx];
    }

    /**
     * Build the AI-citable headline + sentence shown above each deep-dive chart.
     *
     * Spot is the reference, so its quotable describes change since the dataset
     * began. Other segments are framed against the current spot price ("X % more
     * than pörssisähkö"), which is what readers and citing journalists actually
     * want.
     *
     * Returns null when there isn't enough data for a meaningful claim.
     *
     * @return array{headline:string,headline_label:string,tone:string,sentence_before:string,sentence_highlight:string,sentence_after:string,sentence_plain:string}|null
     */
    private function buildQuotableForSegment(string $segmentKey, ?float $current, ?float $first, ?float $spotCurrent): ?array
    {
        if ($current === null) {
            return null;
        }

        $subjects = [
            'spot' => 'Pörssisähkösopimusten energiahinta',
            'fixed_term_6' => 'Lyhyet määräaikaiset (6 kk) sopimukset',
            'fixed_term_12' => 'Vuoden määräaikaiset sopimukset',
            'fixed_term_24' => 'Kahden vuoden määräaikaiset sopimukset',
            'hybrid' => 'Joustosähkösopimukset',
            'open_ended' => 'Toistaiseksi voimassa olevat sopimukset',
        ];
        $subject = $subjects[$segmentKey] ?? (($this->segments[$segmentKey] ?? $segmentKey) . '-sopimukset');

        $fmtCents = fn (float $v) => number_format($v, 2, ',', ' ');

        // Spot, or any segment when we lack a spot reference: frame as change since data start.
        if ($segmentKey === 'spot' || $spotCurrent === null || $spotCurrent <= 0) {
            if ($first === null) {
                return [
                    'headline' => $fmtCents($current) . "\u{00A0}c/kWh",
                    'headline_label' => 'Energiahinta nyt',
                    'tone' => 'neutral',
                    'sentence_before' => "{$subject} on tällä hetkellä ",
                    'sentence_highlight' => $fmtCents($current) . "\u{00A0}c/kWh",
                    'sentence_after' => '.',
                    'sentence_plain' => "{$subject} on tällä hetkellä " . $fmtCents($current) . " c/kWh.",
                ];
            }
            $delta = $this->percentDelta($current, $first);
            if ($delta === null) {
                return null;
            }
            if (abs($delta) < 1.5) {
                return [
                    'headline' => "≈\u{00A0}ennallaan",
                    'headline_label' => 'Aineiston alusta',
                    'tone' => 'neutral',
                    'sentence_before' => "{$subject} on pysynyt ",
                    'sentence_highlight' => 'käytännössä ennallaan',
                    'sentence_after' => " aineiston alusta (" . $fmtCents($current) . "\u{00A0}c/kWh).",
                    'sentence_plain' => "{$subject} on pysynyt käytännössä ennallaan aineiston alusta (" . $fmtCents($current) . " c/kWh).",
                ];
            }
            $absPct = number_format(abs($delta), 0, ',', ' ');
            $sign = $delta < 0 ? "−" : "+";
            $verb = $delta < 0 ? 'laskenut' : 'noussut';
            return [
                'headline' => "{$sign}{$absPct}\u{00A0}%",
                'headline_label' => 'Aineiston alusta',
                'tone' => $delta < 0 ? 'down' : 'up',
                'sentence_before' => "{$subject} on {$verb} ",
                'sentence_highlight' => "{$absPct}\u{00A0}%",
                'sentence_after' => " aineiston alusta (" . $fmtCents($current) . "\u{00A0}c/kWh).",
                'sentence_plain' => "{$subject} on {$verb} {$absPct} % aineiston alusta (" . $fmtCents($current) . " c/kWh).",
            ];
        }

        // Other segments: comparison against the current spot price.
        $vsSpot = (($current - $spotCurrent) / $spotCurrent) * 100.0;
        if (abs($vsSpot) < 1.5) {
            return [
                'headline' => "≈\u{00A0}pörssi",
                'headline_label' => 'Vs. pörssisähkö',
                'tone' => 'neutral',
                'sentence_before' => "{$subject} on hinnoiteltu ",
                'sentence_highlight' => 'lähelle pörssisähkön tasoa',
                'sentence_after' => ' (' . $fmtCents($current) . "\u{00A0}c/kWh vs.\u{00A0}" . $fmtCents($spotCurrent) . "\u{00A0}c/kWh).",
                'sentence_plain' => "{$subject} on hinnoiteltu lähelle pörssisähkön tasoa (" . $fmtCents($current) . " c/kWh vs. " . $fmtCents($spotCurrent) . " c/kWh).",
            ];
        }
        $absPct = number_format(abs($vsSpot), 0, ',', ' ');
        $sign = $vsSpot < 0 ? "−" : "+";
        $direction = $vsSpot > 0 ? 'enemmän' : 'vähemmän';
        return [
            'headline' => "{$sign}{$absPct}\u{00A0}%",
            'headline_label' => 'Vs. pörssisähkö',
            'tone' => $vsSpot > 0 ? 'up' : 'down',
            'sentence_before' => "{$subject} maksavat keskimäärin ",
            'sentence_highlight' => "{$absPct}\u{00A0}% {$direction}",
            'sentence_after' => " kuin pörssisähkö (" . $fmtCents($current) . "\u{00A0}c/kWh vs.\u{00A0}" . $fmtCents($spotCurrent) . "\u{00A0}c/kWh).",
            'sentence_plain' => "{$subject} maksavat keskimäärin {$absPct} % {$direction} kuin pörssisähkö (" . $fmtCents($current) . " c/kWh vs. " . $fmtCents($spotCurrent) . " c/kWh).",
        ];
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
