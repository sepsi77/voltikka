<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\SpotPriceAverage;
use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

        $spotAnnualCostSeries = $this->aggregatedSeries('spot', 'annual_cost', $this->consumption);
        $spotAnnualCostCurrent = $this->lastNonNull($spotAnnualCostSeries['median'])['value'] ?? null;

        foreach ($this->deepDiveSegments as $segmentKey) {
            $metric = $segmentKey === 'spot' ? 'spot_total_energy_price' : 'energy_price';
            $bands = $segmentKey === 'spot'
                ? $this->spotEnergyPriceAggregatedSeriesWithBands()
                : $this->aggregatedSeriesWithBands($segmentKey, $metric);
            $annualCostSeries = $this->aggregatedSeries($segmentKey, 'annual_cost', $this->consumption);
            $annualCostCurrent = $this->lastNonNull($annualCostSeries['median'])['value'] ?? null;

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
                    $segmentKey === 'spot' ? null : $annualCostCurrent,
                    $segmentKey === 'spot' ? null : $spotAnnualCostCurrent,
                ),
                'chart' => [
                    'unit' => 'cent',
                    'decimals' => 2,
                    'x' => $bands['x'],
                    'series' => [
                        ['label' => $this->segments[$segmentKey] ?? $segmentKey, 'values' => $bands['median']],
                    ],
                    'band' => [
                        'lower' => $bands['p20'],
                        'upper' => $bands['p80'],
                        'label' => $segmentKey === 'spot'
                            ? 'Tavanomainen päivähinnan vaihteluväli'
                            : 'Halvempi 20 % – kalliimpi 20 %',
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

            $displaySeries = $segmentKey === 'spot' ? $this->spotEnergyPriceAggregatedSeries() : $series;
            if ($displaySeries['x'] === []) {
                $displaySeries = $series;
            }

            $values = $displaySeries['median'];
            $current = $this->lastNonNull($values);
            $thirtyDaysAgo = $this->valueClosestToOffset(
                ['x' => $displaySeries['x'], 'values' => $values],
                $current['index'] ?? null,
                -30,
            );
            $first = $this->firstNonNull($values);

            $monthlyFee = $this->aggregatedSeries($segmentKey, 'monthly_fee', null);
            $monthlyFeeCurrent = $this->lastNonNull($monthlyFee['median']);

            $contractCount = $this->latestContractCount($segmentKey);
            if ($contractCount !== null && $contractCount < 10) {
                continue;
            }

            $spotEnergySummary = $segmentKey === 'spot'
                ? $this->spotEnergyPriceSummaryForLatestDate()
                : null;
            $displayCurrentPrice = $spotEnergySummary['avg'] ?? ($current['value'] ?? null);
            $displayThirtyDaysAgo = $spotEnergySummary['avg_30d_ago'] ?? $thirtyDaysAgo;
            $displayFirstPrice = $spotEnergySummary['avg_at_start'] ?? ($first['value'] ?? null);

            $rows[] = [
                'segment_key' => $segmentKey,
                'segment_label' => $segmentLabel,
                'is_lead' => $segmentKey === ($this->primarySegments[0] ?? null),
                'is_primary' => in_array($segmentKey, $this->primarySegments, true),
                'is_spot' => $segmentKey === 'spot',
                'unit' => 'c/kWh',
                'current_price' => $displayCurrentPrice,
                'first_price' => $displayFirstPrice,
                'price_30d_ago' => $displayThirtyDaysAgo,
                'delta_30d_pct' => $this->percentDelta($displayCurrentPrice, $displayThirtyDaysAgo),
                'delta_since_start_pct' => $this->percentDelta($displayCurrentPrice, $displayFirstPrice),
                'monthly_fee' => $monthlyFeeCurrent['value'] ?? null,
                'contract_count' => $contractCount,
                'spot_range_p20' => $spotEnergySummary['p20'] ?? null,
                'spot_range_p80' => $spotEnergySummary['p80'] ?? null,
                'spot_range_days' => $spotEnergySummary['days'] ?? null,
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

            if (! $latestRow || (int) $latestRow->contract_count < 10) {
                continue;
            }

            $annualCostSeries = $this->aggregatedSeries($segmentKey, 'annual_cost', $this->consumption);

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
                'sparkline_path' => $this->sparklinePath($annualCostSeries['median'], 80, 24),
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
        $chart = $this->leadChartPayload;

        if (empty($chart['series'])) {
            return [];
        }

        $lead = $chart['series'][0] ?? null;
        if ($lead) {
            $first = $this->firstNonNull($lead['values']);
            $current = $this->lastNonNull($lead['values']);
            $delta = $this->percentDelta($current['value'] ?? null, $first['value'] ?? null);

            if ($delta !== null) {
                $sentences[] = $this->annualCostCaptionSentence($lead['label'], $delta, false);
            }
        }

        $contrast = collect(array_slice($chart['series'], 1))
            ->map(function ($series) {
                $first = $this->firstNonNull($series['values']);
                $current = $this->lastNonNull($series['values']);

                return [
                    'label' => $series['label'],
                    'delta' => $this->percentDelta($current['value'] ?? null, $first['value'] ?? null),
                ];
            })
            ->filter(fn ($series) => $series['delta'] !== null)
            ->sortByDesc(fn ($series) => abs($series['delta']))
            ->first();

        if ($contrast) {
            $sentences[] = $this->annualCostCaptionSentence($contrast['label'], $contrast['delta'], true);
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
        return view('livewire.contract-price-statistics', $this->statisticsViewData())->layout('layouts.app', [
            'title' => 'Sähkön hintatilastot, mitä suomalaiset oikeasti maksavat | Voltikka',
            'metaDescription' => 'Sähkösopimusten hintatilastot Suomessa. Vertaa eri sopimustyyppien hintaeroja, vuosikustannuksia ja hintakehitystä.',
            'canonical' => config('app.url') . '/sahkosopimus/tilastot',
        ]);
    }

    /**
     * Cache the fully prepared view payload. The source tables are refreshed at
     * most daily, while the payload requires many repeated scans/groupings of
     * the same statistics collection during each render.
     *
     * @return array<string,mixed>
     */
    private function statisticsViewData(): array
    {
        return $this->warmPreparedViewDataCache();
    }

    /**
     * Build the prepared view-data cache for the component's current period and
     * consumption state. Used by queued cache warmers after source data updates.
     *
     * @return array<string,mixed>
     */
    public function warmPreparedViewDataCache(): array
    {
        return Cache::remember(
            $this->statisticsViewDataCacheKey(),
            Carbon::tomorrow(),
            fn () => $this->buildStatisticsViewData(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildStatisticsViewData(): array
    {
        return [
            'leadChartPayload' => $this->leadChartPayload,
            'spotMarginPayload' => $this->spotMarginChartPayload,
            'deepDivePayloads' => $this->deepDivePayloads,
            'segmentRows' => $this->segmentRows,
            'consumptionRows' => $this->consumptionRows,
            'callouts' => $this->callouts,
            'caption' => $this->caption,
            'citations' => $this->citations,
            'dataWindow' => $this->dataWindow,
            'latestSnapshotDate' => $this->latestSnapshotDate,
            'latestSnapshotCount' => $this->latestSnapshotCount,
            'jsonLd' => $this->jsonLd,
        ];
    }

    private function statisticsViewDataCacheKey(): string
    {
        return 'contract-price-statistics:view-data:v4:' . md5(json_encode([
            'period' => $this->period,
            'consumption' => $this->consumption,
            'version' => $this->statisticsDataVersion(),
        ]));
    }

    /**
     * Cheap cache-busting fingerprint. Avoid loading the full statistics table
     * just to decide whether today's prepared payload can be reused.
     */
    private function statisticsDataVersion(): array
    {
        $statistics = ContractPriceDailyStatistic::query()
            ->selectRaw('COUNT(*) as row_count, MAX(stat_date) as latest_date, MAX(updated_at) as latest_update')
            ->first();

        $snapshots = ContractPriceSnapshot::query()
            ->selectRaw('MAX(snapshot_date) as latest_date, MAX(updated_at) as latest_update')
            ->first();

        $spotAverages = SpotPriceAverage::query()
            ->forRegion('FI')
            ->ofType(SpotPriceAverage::PERIOD_DAILY)
            ->selectRaw('COUNT(*) as row_count, MAX(period_start) as latest_date, MAX(updated_at) as latest_update')
            ->first();

        $spotHours = SpotPriceHour::query()
            ->forRegion('FI')
            ->selectRaw('COUNT(*) as row_count, MAX(utc_datetime) as latest_date')
            ->first();

        return [
            'statistics_row_count' => (int) ($statistics?->row_count ?? 0),
            'statistics_latest_date' => $statistics?->latest_date,
            'statistics_latest_update' => $statistics?->latest_update,
            'snapshots_latest_date' => $snapshots?->latest_date,
            'snapshots_latest_update' => $snapshots?->latest_update,
            'spot_averages_row_count' => (int) ($spotAverages?->row_count ?? 0),
            'spot_averages_latest_date' => $spotAverages?->latest_date,
            'spot_averages_latest_update' => $spotAverages?->latest_update,
            'spot_hours_row_count' => (int) ($spotHours?->row_count ?? 0),
            'spot_hours_latest_date' => $spotHours?->latest_date,
        ];
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

    /**
     * Period-aggregated series for the spot energy-price cell/sparkline in the
     * contract-type table. This intentionally tracks the displayed c/kWh basis
     * (trailing-12-month daily spot average + typical margin), while the annual
     * cost sparkline lives in the annual-price table below.
     *
     * @return array{x:array<int,int>,median:array<int,?float>}
     */
    private function spotEnergyPriceAggregatedSeries(): array
    {
        $bands = $this->spotEnergyPriceAggregatedSeriesWithBands();

        return ['x' => $bands['x'], 'median' => $bands['median']];
    }

    /**
     * @return array{x:array<int,int>,median:array<int,?float>,p20:array<int,?float>,p80:array<int,?float>,min:array<int,?float>,max:array<int,?float>}
     */
    private function spotEnergyPriceAggregatedSeriesWithBands(): array
    {
        $rows = $this->dailyStats
            ->where('segment_key', 'spot')
            ->where('metric_key', 'spot_total_energy_price')
            ->where('consumption_kwh', null);

        if ($rows->isEmpty()) {
            return ['x' => [], 'median' => [], 'p20' => [], 'p80' => [], 'min' => [], 'max' => []];
        }

        $daily = $rows
            ->map(function ($row) {
                $summary = $this->spotEnergyPriceSummaryForDate(Carbon::parse($row->stat_date)->toDateString());

                return [
                    'date' => $row->stat_date,
                    'median' => $summary['avg'],
                    'p20' => $summary['p20'],
                    'p80' => $summary['p80'],
                ];
            })
            ->filter(fn ($row) => $row['median'] !== null);

        if ($daily->isEmpty()) {
            return ['x' => [], 'median' => [], 'p20' => [], 'p80' => [], 'min' => [], 'max' => []];
        }

        $grouped = $daily
            ->groupBy(fn ($row) => $this->periodStart($row['date'])->toDateString())
            ->sortKeys();

        $x = $median = $p20 = $p80 = $min = $max = [];
        foreach ($grouped as $periodStart => $periodRows) {
            $x[] = Carbon::parse($periodStart)->getTimestamp();
            $median[] = $this->averageOrNull($periodRows->pluck('median'));
            $p20[] = $this->averageOrNull($periodRows->pluck('p20'));
            $p80[] = $this->averageOrNull($periodRows->pluck('p80'));
            $min[] = $this->averageOrNull($periodRows->pluck('p20'));
            $max[] = $this->averageOrNull($periodRows->pluck('p80'));
        }

        return ['x' => $x, 'median' => $median, 'p20' => $p20, 'p80' => $p80, 'min' => $min, 'max' => $max];
    }

    /**
     * Spot's table c/kWh should represent a full seasonal cycle rather than a
     * single cheap/expensive day. Use daily spot averages for the latest trailing
     * 12 months and add the latest typical supplier margin.
     *
     * @return array{avg:?float,p20:?float,p80:?float,days:int,avg_30d_ago:?float,avg_at_start:?float}
     */
    private function spotEnergyPriceSummaryForLatestDate(): array
    {
        $spotRows = $this->dailyStats
            ->where('segment_key', 'spot')
            ->where('metric_key', 'spot_total_energy_price')
            ->where('consumption_kwh', null)
            ->sortBy('stat_date')
            ->values();

        if ($spotRows->isEmpty()) {
            return ['avg' => null, 'p20' => null, 'p80' => null, 'days' => 0, 'avg_30d_ago' => null, 'avg_at_start' => null];
        }

        $latestDate = Carbon::parse($spotRows->last()->stat_date)->toDateString();
        $firstDate = Carbon::parse($spotRows->first()->stat_date)->toDateString();
        $thirtyDaysAgo = Carbon::parse($latestDate)->subDays(30)->toDateString();
        $latest = $this->spotEnergyPriceSummaryForDate($latestDate);

        return [
            ...$latest,
            'avg_30d_ago' => $this->spotEnergyPriceSummaryForDate($thirtyDaysAgo)['avg'],
            'avg_at_start' => $this->spotEnergyPriceSummaryForDate($firstDate)['avg'],
        ];
    }

    /**
     * @return array{avg:?float,p20:?float,p80:?float,days:int}
     */
    private function spotEnergyPriceSummaryForDate(string $dateString): array
    {
        $endDate = Carbon::parse($dateString)->toDateString();
        if (array_key_exists($endDate, $this->spotEnergySummaryCache)) {
            return $this->spotEnergySummaryCache[$endDate];
        }

        $startDate = Carbon::parse($endDate)->subDays(364)->toDateString();
        $margin = $this->latestSpotMargin($endDate) ?? 0.0;

        $dailyMarketPrices = $this->dailySpotMarketPricesForWindow($startDate, $endDate);

        if ($dailyMarketPrices !== []) {
            $dailyTotals = array_map(fn (float $price) => $price + $margin, $dailyMarketPrices);

            return $this->spotEnergySummaryCache[$endDate] = [
                'avg' => array_sum($dailyTotals) / count($dailyTotals),
                'p20' => $this->percentileFromValues($dailyTotals, 20),
                'p80' => $this->percentileFromValues($dailyTotals, 80),
                'days' => count($dailyTotals),
            ];
        }

        // Test/development fallback: if stored spot averages are unavailable,
        // use the page's own daily spot-total medians over the same window.
        $dailyTotals = $this->dailyStats
            ->where('segment_key', 'spot')
            ->where('metric_key', 'spot_total_energy_price')
            ->where('consumption_kwh', null)
            ->filter(fn ($row) => Carbon::parse($row->stat_date)->betweenIncluded($startDate, $endDate))
            ->pluck('median_value')
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (float) $value)
            ->values()
            ->all();

        if ($dailyTotals === []) {
            return $this->spotEnergySummaryCache[$endDate] = ['avg' => null, 'p20' => null, 'p80' => null, 'days' => 0];
        }

        return $this->spotEnergySummaryCache[$endDate] = [
            'avg' => array_sum($dailyTotals) / count($dailyTotals),
            'p20' => $this->percentileFromValues($dailyTotals, 20),
            'p80' => $this->percentileFromValues($dailyTotals, 80),
            'days' => count($dailyTotals),
        ];
    }

    /** @var array<string, array{avg:?float,p20:?float,p80:?float,days:int}> */
    private array $spotEnergySummaryCache = [];

    /**
     * @return array<int, float>
     */
    private function dailySpotMarketPricesForWindow(string $startDate, string $endDate): array
    {
        $expectedDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $minimumCompleteDays = min(300, (int) floor($expectedDays * 0.8));

        $averagePrices = SpotPriceAverage::forRegion('FI')
            ->ofType(SpotPriceAverage::PERIOD_DAILY)
            ->whereBetween('period_start', [$startDate, $endDate])
            ->whereNotNull('avg_price_with_tax')
            ->orderBy('period_start')
            ->pluck('avg_price_with_tax')
            ->map(fn ($value) => (float) $value)
            ->values()
            ->all();

        if (count($averagePrices) >= $minimumCompleteDays) {
            return $averagePrices;
        }

        $hourlyDailyPrices = SpotPriceHour::forRegion('FI')
            ->whereBetween('utc_datetime', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()])
            ->selectRaw('DATE(utc_datetime) as price_date, AVG(price_without_tax * (1 + vat_rate)) as avg_price_with_tax')
            ->groupBy('price_date')
            ->orderBy('price_date')
            ->pluck('avg_price_with_tax')
            ->map(fn ($value) => (float) $value)
            ->values()
            ->all();

        if (count($hourlyDailyPrices) >= $minimumCompleteDays || count($hourlyDailyPrices) > count($averagePrices)) {
            return $hourlyDailyPrices;
        }

        return $averagePrices;
    }

    private function latestSpotMargin(string $latestDate): ?float
    {
        $row = $this->dailyStats
            ->where('segment_key', 'spot')
            ->where('metric_key', 'spot_margin')
            ->where('consumption_kwh', null)
            ->filter(fn ($stat) => Carbon::parse($stat->stat_date)->lte($latestDate))
            ->sortByDesc('stat_date')
            ->first();

        return $row?->median_value !== null ? (float) $row->median_value : null;
    }

    /**
     * @param array<int, float> $values
     */
    private function percentileFromValues(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);
        if ($n === 1) {
            return $values[0];
        }

        $index = ($percentile / 100) * ($n - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;

        return $values[$lower] * (1 - $weight) + $values[$upper] * $weight;
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
     * began. Other segments are compared against spot using annual cost at the
     * selected consumption level, not current c/kWh, so cheap/expensive spot
     * days do not distort contract-type comparisons.
     *
     * Returns null when there isn't enough data for a meaningful claim.
     *
     * @return array{headline:string,headline_label:string,tone:string,sentence_before:string,sentence_highlight:string,sentence_after:string,sentence_plain:string}|null
     */
    private function buildQuotableForSegment(string $segmentKey, ?float $current, ?float $first, ?float $annualCostCurrent, ?float $spotAnnualCostCurrent): ?array
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

        // Spot, or any segment when we lack a reliable annual-cost comparison:
        // frame as change since data start. Do not compare non-spot c/kWh
        // against current spot c/kWh because that overstates differences on
        // unusually cheap or expensive spot days.
        if ($segmentKey === 'spot' || $annualCostCurrent === null || $spotAnnualCostCurrent === null || $spotAnnualCostCurrent <= 0) {
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

        // Other segments: comparison against spot annual cost at the selected
        // consumption level. Spot annual cost uses trailing-365-day spot prices.
        $fmtEur = fn (float $v) => number_format($v, 0, ',', ' ');
        $consumption = number_format($this->consumption, 0, ',', ' ');
        $vsSpot = (($annualCostCurrent - $spotAnnualCostCurrent) / $spotAnnualCostCurrent) * 100.0;
        if (abs($vsSpot) < 1.5) {
            return [
                'headline' => "≈\u{00A0}pörssi",
                'headline_label' => "Vs. pörssisähkö, {$consumption} kWh/v",
                'tone' => 'neutral',
                'sentence_before' => "{$subject} ovat ",
                'sentence_highlight' => 'lähellä pörssisähkön tasoa',
                'sentence_after' => " {$consumption}\u{00A0}kWh vuosikulutuksella (" . $fmtEur($annualCostCurrent) . "\u{00A0}€/v vs.\u{00A0}" . $fmtEur($spotAnnualCostCurrent) . "\u{00A0}€/v).",
                'sentence_plain' => "{$subject} ovat lähellä pörssisähkön tasoa {$consumption} kWh vuosikulutuksella (" . $fmtEur($annualCostCurrent) . " €/v vs. " . $fmtEur($spotAnnualCostCurrent) . " €/v).",
            ];
        }
        $absPct = number_format(abs($vsSpot), 0, ',', ' ');
        $sign = $vsSpot < 0 ? "−" : "+";
        $direction = $vsSpot > 0 ? 'enemmän' : 'vähemmän';
        return [
            'headline' => "{$sign}{$absPct}\u{00A0}%",
            'headline_label' => "Vs. pörssisähkö, {$consumption} kWh/v",
            'tone' => $vsSpot > 0 ? 'up' : 'down',
            'sentence_before' => "{$subject} maksavat {$consumption}\u{00A0}kWh vuosikulutuksella ",
            'sentence_highlight' => "{$absPct}\u{00A0}% {$direction}",
            'sentence_after' => " kuin pörssisähkö (" . $fmtEur($annualCostCurrent) . "\u{00A0}€/v vs.\u{00A0}" . $fmtEur($spotAnnualCostCurrent) . "\u{00A0}€/v).",
            'sentence_plain' => "{$subject} maksavat {$consumption} kWh vuosikulutuksella {$absPct} % {$direction} kuin pörssisähkö (" . $fmtEur($annualCostCurrent) . " €/v vs. " . $fmtEur($spotAnnualCostCurrent) . " €/v).",
        ];
    }

    private function annualCostCaptionSentence(string $label, float $delta, bool $samePeriod): string
    {
        $prefix = $samePeriod ? "{$label}-sopimusten vuosikustannus on samalla aikavälillä" : "{$label}-sopimusten vuosikustannus on";

        if (abs($delta) < 1.5) {
            return "{$prefix} pysynyt käytännössä paikoillaan.";
        }

        $verb = $delta < 0 ? 'laskenut' : 'noussut';
        $abs = number_format(abs($delta), 0, ',', ' ');

        return $samePeriod
            ? "{$prefix} {$verb} {$abs} %."
            : "{$prefix} {$verb} {$abs} % aineiston alusta.";
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
