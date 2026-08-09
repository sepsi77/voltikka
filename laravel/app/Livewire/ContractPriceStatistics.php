<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\SpotPriceAverage;
use App\Models\SpotPriceHour;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractStatistics\AnnualSeriesCompatibility;
use App\Services\ContractStatistics\ContractStatisticsSegmentClassifier;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;

class ContractPriceStatistics extends Component
{
    /** The non-quarterly reset category restarts after quarterly contracts get their own segment. */
    private const MARKET_RESET_COMPARABLE_HISTORY_START = '2026-08-10';

    /** The broader reset-price category must earn a useful trend from its stable membership. */
    private const MARKET_RESET_MINIMUM_PUBLIC_OBSERVATIONS = 30;

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

    /** @see ContractStatisticsSegmentClassifier::SEGMENT_LABELS */
    public array $segments = ContractStatisticsSegmentClassifier::SEGMENT_LABELS;

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
        'quarterly',
        'market_reset',
    ];

    /**
     * Request/job-scoped cache for the daily statistic rows.
     *
     * The prepared payload builder scans this collection many times. In normal
     * Livewire requests computed properties are memoized, but queued cache
     * warmers instantiate the component directly; keeping an explicit cache
     * prevents repeated full-table hydration inside one warm job.
     *
     * @var Collection<int, ContractPriceDailyStatistic>|null
     */
    private ?Collection $dailyStatsCache = null;

    /** @var array<string, Collection<int, ContractPriceDailyStatistic>>|null */
    private ?array $dailyStatsIndexCache = null;

    /** @var array<string, array<string,mixed>> */
    private array $aggregatedSeriesCache = [];

    /** @var array<string, array{x:array<int,int>,median:array<int,?float>,p20:array<int,?float>,p80:array<int,?float>}> */
    private array $spotEnergyPriceAggregatedSeriesCache = [];

    /** @var array<string, float>|null */
    private ?array $dailySpotAveragePricesByDateCache = null;

    /** @var array<string, float>|null */
    private ?array $hourlySpotAveragePricesByDateCache = null;

    /** @var array{start:string,end:string}|null */
    private ?array $spotMarketPriceBoundsCache = null;

    private bool $latestSnapshotDateLoaded = false;

    private ?string $latestSnapshotDateCache = null;

    /**
     * SEO-optimized H3 headings for the deep-dive sections.
     *
     * Must contain "hintakehitys" and an inflection of "sähkösopimus" so the
     * page ranks for "<segment-type> sähkösopimusten hintakehitys" queries.
     * Kept separate from {@see $segments} because the short label is reused
     * in the contract-type/consumption tables and TOC chips.
     */
    public array $deepDiveHeadings = [
        'spot' => 'Pörssisähkösopimusten hintakehitys',
        'market_reset' => 'Jaksoittain vaihtuvien sähkösopimusten hintakehitys',
        'quarterly' => 'Kvartaalisähkösopimusten hintakehitys',
        'fixed_term_6' => '6 kk määräaikaisten sähkösopimusten hintakehitys',
        'fixed_term_12' => '12 kk määräaikaisten sähkösopimusten hintakehitys',
        'fixed_term_24' => '24 kk määräaikaisten sähkösopimusten hintakehitys',
        'hybrid' => 'Joustosähkösopimusten hintakehitys',
        'open_ended' => 'Toistaiseksi voimassa olevien sähkösopimusten hintakehitys',
    ];

    /** URL-friendly anchor slugs for the deep-dive sections. */
    public array $deepDiveAnchors = [
        'spot' => 'porssisahko',
        'market_reset' => 'paivittyva-hinta',
        'quarterly' => 'kvartaalisahko',
        'fixed_term_6' => 'maaraaikainen-6-kk',
        'fixed_term_12' => 'maaraaikainen-12-kk',
        'fixed_term_24' => 'maaraaikainen-24-kk',
        'hybrid' => 'joustosahko',
        'open_ended' => 'toistaiseksi-voimassa-oleva',
    ];

    /** Plain-Finnish 1–2 sentence intro per segment for the deep-dive blocks. */
    public array $deepDiveDescriptions = [
        'spot' => 'Pörssisopimuksissa energian hinta seuraa pörssin tuntihintaa, johon sopimustarjoaja lisää oman marginaalinsa. Hinta vaihtelee päivästä toiseen markkinatilanteen mukaan.',
        'market_reset' => 'Jaksoittain vaihtuvissa sopimuksissa energian hinta pysyy samana yhden jakson ajan. Myyjä vahvistaa uuden hinnan esimerkiksi kuukausittain tai kausittain, joten hinta ei seuraa pörssiä tunneittain.',
        'quarterly' => 'Kvartaalisähkösopimuksen energiahinta pysyy samana kolme kuukautta kerrallaan. Myyjä vahvistaa uuden hinnan seuraavalle vuosineljännekselle.',
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
        if ($this->dailyStatsCache !== null) {
            return $this->dailyStatsCache;
        }

        $rows = ContractPriceDailyStatistic::query()
            ->activeMetricMethods()
            ->select([
                'stat_date',
                'segment_key',
                'metric_key',
                'pricing_basis',
                'method_version',
                'calculation_basis',
                'estimate_basis',
                'compatibility_key',
                'basis_counts',
                'consumption_kwh',
                'p20_value',
                'avg_value',
                'median_value',
                'p80_value',
                'contract_count',
            ])
            ->orderBy('stat_date')
            ->get();

        $expectedBasis = app(PricingMode::class)->expectedContractPriceBasis()->value;
        $latestExpectedDate = $rows
            ->where('method_version', ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION)
            ->where('pricing_basis', $expectedBasis)
            ->max(fn (ContractPriceDailyStatistic $row) => $row->stat_date->toDateString());

        if ($latestExpectedDate === null) {
            return $this->dailyStatsCache = collect();
        }

        // Unit statistics own the public endpoint date and still require the
        // expected current pricing basis. Active annual rows can use a mixed
        // evidence basis on that same date. Older rows keep their dated basis,
        // but every row has already passed the metric-method isolation scope.
        return $this->dailyStatsCache = $rows
            ->filter(function (ContractPriceDailyStatistic $row) use ($expectedBasis, $latestExpectedDate): bool {
                $date = $row->stat_date->toDateString();

                if ($date < $latestExpectedDate) {
                    return true;
                }

                if ($date !== $latestExpectedDate) {
                    return false;
                }

                if ($row->metric_key === 'annual_cost') {
                    return in_array($row->pricing_basis, [$expectedBasis, 'mixed_evidence'], true);
                }

                return $row->pricing_basis === $expectedBasis;
            })
            ->values();
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

        $dates = [];
        foreach ($stats as $stat) {
            $date = $stat->stat_date->toDateString();
            $dates[$date] = true;
        }

        $from = array_key_first($dates);
        $to = array_key_last($dates);

        return [
            'from' => $from,
            'to' => $to,
            'days' => Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1,
            'dayCount' => count($dates),
        ];
    }

    public function getLatestSnapshotCountProperty(): int
    {
        $latestDate = $this->latestSnapshotDateRaw();

        return $latestDate
            ? ContractPriceSnapshot::whereDate('snapshot_date', $latestDate)->count()
            : 0;
    }

    public function getLatestSnapshotDateProperty(): ?string
    {
        $latestDate = $this->latestSnapshotDateRaw();

        return $latestDate ? Carbon::parse($latestDate)->format('j.n.Y') : null;
    }

    public function getLatestPricingBasisProperty(): string
    {
        return app(PricingMode::class)->expectedContractPriceBasis()->value;
    }

    /**
     * Lead chart payload: annual cost @ 5000 kWh for the four primary segments,
     * aggregated to the selected period. Coral on the first segment.
     *
     * @return array{x:array<int,int>,series:array<int,array{label:string,values:array<int,?float>}>,unit:string,decimals:int,showPoints:bool,showLatestPoint:bool}
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

            if (! $this->shouldPublishSegmentTrend(
                $segmentKey,
                $metric,
                null,
                allowDatedHistory: $segmentKey === 'quarterly',
            )) {
                continue;
            }

            $latestMetricRow = $this->latestStatisticRow($segmentKey, $metric, null);
            $latestObservationDate = $latestMetricRow?->stat_date->toDateString();
            $isCurrent = $latestObservationDate !== null && $latestObservationDate === $this->dataWindow['to'];
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
                'heading' => $this->deepDiveHeadings[$segmentKey] ?? ($this->segments[$segmentKey] ?? $segmentKey),
                'anchor' => $this->deepDiveAnchors[$segmentKey] ?? $segmentKey,
                'description' => $this->deepDiveDescriptions[$segmentKey] ?? '',
                'is_spot' => $segmentKey === 'spot',
                'current' => $current['value'] ?? null,
                'unit' => 'c/kWh',
                'delta_30d_pct' => $this->percentDelta($current['value'] ?? null, $thirtyDaysAgo),
                'delta_since_start_pct' => $this->percentDelta($current['value'] ?? null, $first['value'] ?? null),
                'contract_count' => $isCurrent ? $this->latestContractCount($segmentKey) : null,
                'latest_observation_date' => $latestObservationDate,
                'is_current' => $isCurrent,
                'pricing_basis' => $latestMetricRow?->pricing_basis,
                'has_data' => count($bands['x']) >= 2 && $current['value'] !== null,
                'quotable' => $isCurrent ? $this->buildQuotableForSegment(
                    $segmentKey,
                    $current['value'] ?? null,
                    $first['value'] ?? null,
                    $segmentKey === 'spot' ? null : $annualCostCurrent,
                    $segmentKey === 'spot' ? null : $spotAnnualCostCurrent,
                ) : null,
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

            if (! $this->shouldPublishSegmentTrend(
                $segmentKey,
                $metric,
                null,
                allowDatedHistory: $segmentKey === 'quarterly',
            )) {
                continue;
            }

            $latestMetricRow = $this->latestStatisticRow($segmentKey, $metric, null);
            $latestObservationDate = $latestMetricRow?->stat_date->toDateString();
            $isCurrent = $latestObservationDate !== null && $latestObservationDate === $this->dataWindow['to'];
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
                'latest_observation_date' => $latestObservationDate,
                'is_current' => $isCurrent,
                'spot_range_p20' => $spotEnergySummary['p20'] ?? null,
                'spot_range_p80' => $spotEnergySummary['p80'] ?? null,
                'spot_range_days' => $spotEnergySummary['days'] ?? null,
                'pricing_basis' => $latestMetricRow?->pricing_basis,
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
            if (! $this->shouldPublishSegmentTrend(
                $segmentKey,
                'annual_cost',
                $this->consumption,
                allowDatedHistory: $segmentKey === 'quarterly',
            )) {
                continue;
            }

            $latestRow = $this->latestStatisticRow($segmentKey, 'annual_cost', $this->consumption);

            if (! $latestRow || (int) $latestRow->contract_count < 10) {
                continue;
            }

            $annualCostSeries = $this->aggregatedSeries($segmentKey, 'annual_cost', $this->consumption);

            $rows[] = [
                'segment_key' => $segmentKey,
                'segment_label' => $segmentLabel,
                'is_lead' => $segmentKey === ($this->primarySegments[0] ?? null),
                'is_primary' => in_array($segmentKey, $this->primarySegments, true),
                'p20' => $latestRow->p20_value,
                'median' => $latestRow->median_value,
                'p80' => $latestRow->p80_value,
                'contract_count' => $latestRow->contract_count,
                'latest_observation_date' => $latestRow->stat_date->toDateString(),
                'is_current' => $latestRow->stat_date->toDateString() === $this->dataWindow['to'],
                'pricing_basis' => $latestRow->pricing_basis,
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
            $displaySeries = $segmentKey === 'spot' ? $this->spotEnergyPriceAggregatedSeries() : $series;
            if ($displaySeries['x'] === []) {
                $displaySeries = $series;
            }
            $values = $displaySeries['median'];

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
                        ['x' => $displaySeries['x'], 'values' => $values],
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
            $current = $this->lastNonNull($lead['values']);
            $first = $this->firstNonNullInCurrentAnnualRegime($lead, $current);
            $delta = $this->compatibleAnnualDelta($lead, $current, $first);

            if ($delta !== null) {
                $sentences[] = $this->annualCostCaptionSentence(
                    $lead['label'],
                    $delta,
                    ($first['index'] ?? null) === 0,
                );
            }
        }

        $contrast = collect(array_slice($chart['series'], 1))
            ->map(function ($series) {
                $current = $this->lastNonNull($series['values']);
                $first = $this->firstNonNullInCurrentAnnualRegime($series, $current);

                return [
                    'label' => $series['label'],
                    'delta' => $this->compatibleAnnualDelta($series, $current, $first),
                    'from_dataset_start' => ($first['index'] ?? null) === 0,
                ];
            })
            ->filter(fn ($series) => $series['delta'] !== null)
            ->sortByDesc(fn ($series) => abs($series['delta']))
            ->first();

        if ($contrast) {
            $sentences[] = $this->annualCostCaptionSentence(
                $contrast['label'],
                $contrast['delta'],
                $contrast['from_dataset_start'],
            );
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
        $url = config('app.url').'/sahkosopimus/tilastot';

        return [
            'plain' => "Lähde: Voltikka, {$title}, päivitetty {$dateFi}. {$url}",
            'markdown' => "Lähde: [Voltikka, {$title}]({$url}), päivitetty {$dateFi}.",
            'html' => '<a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Voltikka, '.htmlspecialchars($title).'</a>, päivitetty <time datetime="'.$dateIso.'">'.$dateFi.'</time>.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getJsonLdProperty(): array
    {
        $window = $this->dataWindow;
        $today = Carbon::today()->toDateString();
        $url = config('app.url').'/sahkosopimus/tilastot';
        $csvUrl = config('app.url').'/sahkosopimus/tilastot.csv';

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Dataset',
            'name' => 'Voltikka — Sähkön hintatilastot sopimustyypeittäin',
            'description' => 'Sähkösopimusten hintakehitys ja päivittäin kerätyt hintatilastot Suomessa. Energiahinnat, perusmaksut, pörssimarginaalit ja vuosikustannukset 2 000 / 5 000 / 18 000 kWh kulutuksella sopimustyypeittäin.',
            'url' => $url,
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
            'isAccessibleForFree' => true,
            'keywords' => ['sähkösopimusten hintakehitys', 'sähkön hintakehitys', 'sähkön hinta', 'sähkösopimus', 'tilastot', 'pörssisähkö', 'määräaikainen', 'Suomi'],
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
            'title' => 'Sähkösopimusten hintakehitys ja hintatilastot Suomessa | Voltikka',
            'metaDescription' => 'Sähkösopimusten hintakehitys ja hintatilastot Suomessa. Seuraa pörssi-, määräaikais- ja toistaiseksi voimassa olevien sopimusten hintojen kehitystä, hintaeroja ja vuosikustannuksia eri kulutustasoilla.',
            'canonical' => config('app.url').'/sahkosopimus/tilastot',
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
            'latestPricingBasis' => $this->latestPricingBasis,
            'activeAnnualMethod' => ContractPriceDailyStatistic::activeAnnualMethodVersion()->value,
            'jsonLd' => $this->jsonLd,
        ];
    }

    private function statisticsViewDataCacheKey(): string
    {
        return 'contract-price-statistics:view-data:v18:'.md5(json_encode([
            'period' => $this->period,
            'consumption' => $this->consumption,
            'pricing_basis' => app(PricingMode::class)->expectedContractPriceBasis()->value,
            'annual_method' => ContractPriceDailyStatistic::activeAnnualMethodVersion()->value,
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
            ->activeMetricMethods()
            ->selectRaw('COUNT(*) as row_count, MAX(stat_date) as latest_date, MAX(updated_at) as latest_update')
            ->first();

        $snapshots = ContractPriceSnapshot::query()
            ->where('pricing_basis', app(PricingMode::class)->expectedContractPriceBasis()->value)
            ->selectRaw('MAX(snapshot_date) as latest_date, MAX(updated_at) as latest_update')
            ->first();
        $this->latestSnapshotDateLoaded = true;
        $this->latestSnapshotDateCache = $snapshots?->latest_date;

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
            'active_annual_method' => ContractPriceDailyStatistic::activeAnnualMethodVersion()->value,
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
     * @param  array<int,string>  $segmentKeys
     * @return array{x:array<int,int>,series:array<int,array{label:string,values:array<int,?float>}>,unit:string,decimals:int,showPoints:bool,showLatestPoint:bool}
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
            return [
                'x' => [],
                'series' => [],
                'unit' => 'eur',
                'decimals' => 0,
                'showPoints' => $this->period !== 'daily',
                'showLatestPoint' => $this->period === 'daily',
            ];
        }

        $series = [];
        foreach ($segmentKeys as $key) {
            $byTs = array_combine($aggregated[$key]['x'], $aggregated[$key]['median']);
            $compatibilityByTs = array_combine($aggregated[$key]['x'], $aggregated[$key]['compatibility_keys']);
            $values = [];
            $compatibilityKeys = [];
            foreach ($allTimestamps as $ts) {
                $values[] = $byTs[$ts] ?? null;
                $compatibilityKeys[] = $compatibilityByTs[$ts] ?? null;
            }

            $series[] = [
                'label' => $this->segments[$key] ?? $key,
                'values' => $values,
                'compatibility_keys' => $compatibilityKeys,
            ];
        }

        return [
            'x' => $allTimestamps,
            'series' => $series,
            'unit' => 'eur',
            'decimals' => 0,
            'showPoints' => $this->period !== 'daily',
            'showLatestPoint' => $this->period === 'daily',
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
     * @return array{x:array<int,int>,median:array<int,?float>,p20:array<int,?float>,p80:array<int,?float>}
     */
    private function aggregatedSeriesWithBands(string $segmentKey, string $metricKey): array
    {
        $cacheKey = $this->period.'|'.$segmentKey.'|'.$metricKey;
        if (isset($this->aggregatedSeriesCache[$cacheKey])) {
            return $this->aggregatedSeriesCache[$cacheKey];
        }

        $rows = $this->dailyStatisticRows($segmentKey, $metricKey, null);

        if ($rows->isEmpty()) {
            return $this->aggregatedSeriesCache[$cacheKey] = ['x' => [], 'median' => [], 'p20' => [], 'p80' => []];
        }

        $grouped = $rows
            ->groupBy(fn ($row) => $this->periodStart($row->stat_date)->toDateString())
            ->sortKeys();

        $x = $median = $p20 = $p80 = [];
        $previousPeriodStart = null;

        foreach ($grouped as $periodStart => $periodRows) {
            $currentPeriodStart = Carbon::parse($periodStart);

            if ($previousPeriodStart !== null) {
                $nextExpectedPeriod = $this->nextPeriodStart($previousPeriodStart);

                if ($nextExpectedPeriod->lt($currentPeriodStart)) {
                    $x[] = $nextExpectedPeriod->getTimestamp();
                    $median[] = null;
                    $p20[] = null;
                    $p80[] = null;
                }
            }

            $x[] = $currentPeriodStart->getTimestamp();
            $median[] = $this->averageOrNull($periodRows->pluck('median_value'));
            $p20[] = $this->averageOrNull($periodRows->pluck('p20_value'));
            $p80[] = $this->averageOrNull($periodRows->pluck('p80_value'));
            $previousPeriodStart = $currentPeriodStart;
        }

        return $this->aggregatedSeriesCache[$cacheKey] = ['x' => $x, 'median' => $median, 'p20' => $p20, 'p80' => $p80];
    }

    private function nextPeriodStart(CarbonInterface $periodStart): CarbonInterface
    {
        return match ($this->period) {
            'monthly' => $periodStart->copy()->addMonthNoOverflow()->startOfMonth(),
            'weekly' => $periodStart->copy()->addWeek()->startOfWeek(),
            default => $periodStart->copy()->addDay()->startOfDay(),
        };
    }

    private function averageOrNull(Collection $values): ?float
    {
        $clean = $values->filter(fn ($v) => $v !== null);

        return $clean->isEmpty() ? null : (float) $clean->avg();
    }

    /**
     * Aggregates daily statistics for a (segment, metric, consumption) slice
     * into period-keyed series. The headline `median` series uses each day's
     * stored median (robust to outliers and bad-import single rows). Weekly /
     * monthly aggregation averages those daily medians so the trend stays
     * market-day weighted.
     *
     * @return array{x:array<int,int>,median:array<int,?float>,compatibility_keys:array<int,?string>}
     */
    private function aggregatedSeries(string $segmentKey, string $metricKey, ?int $consumption): array
    {
        $cacheKey = $this->period.'|'.$segmentKey.'|'.$metricKey.'|'.($consumption ?? 'null');
        if (isset($this->aggregatedSeriesCache[$cacheKey])) {
            return $this->aggregatedSeriesCache[$cacheKey];
        }

        $rows = $this->dailyStatisticRows($segmentKey, $metricKey, $consumption);

        if ($rows->isEmpty()) {
            return $this->aggregatedSeriesCache[$cacheKey] = ['x' => [], 'median' => [], 'compatibility_keys' => []];
        }

        $grouped = $rows->groupBy(fn ($row) => $this->periodStart($row->stat_date)->toDateString())
            ->sortKeys();

        $x = [];
        $median = [];
        $compatibilityKeys = [];
        $compatibility = $metricKey === 'annual_cost' ? new AnnualSeriesCompatibility : null;

        foreach ($grouped as $periodStart => $periodRows) {
            $periodRows = $periodRows->sortBy('stat_date')->values();
            $vals = $periodRows->pluck('median_value')->filter(fn ($v) => $v !== null);
            $periodCompatibility = $compatibility?->evaluatePeriod(
                $periodRows->map(fn (ContractPriceDailyStatistic $row): ?string => $this->annualDisplayCompatibilityKey($row))->all(),
            );

            $x[] = Carbon::parse($periodStart)->getTimestamp();
            $median[] = $vals->isEmpty() || ($periodCompatibility !== null && ! $periodCompatibility['comparable'])
                ? null
                : (float) $vals->avg();
            $compatibilityKeys[] = $periodCompatibility['compatibility_key'] ?? null;
        }

        if ($compatibility !== null && $this->period !== 'daily') {
            $latest = $rows
                ->whereNotNull('median_value')
                ->sortByDesc('stat_date')
                ->first();
            $latestTimestamp = $latest?->stat_date->getTimestamp();

            if ($latest !== null && $latestTimestamp !== $x[array_key_last($x)]) {
                $endpointCompatibility = $compatibility->evaluatePeriod([
                    $this->annualDisplayCompatibilityKey($latest),
                ]);
                $x[] = $latestTimestamp;
                $median[] = $endpointCompatibility['comparable'] ? (float) $latest->median_value : null;
                $compatibilityKeys[] = $endpointCompatibility['compatibility_key'];
            }
        }

        return $this->aggregatedSeriesCache[$cacheKey] = [
            'x' => $x,
            'median' => $median,
            'compatibility_keys' => $compatibilityKeys,
        ];
    }

    private function annualDisplayCompatibilityKey(ContractPriceDailyStatistic $row): ?string
    {
        return AnnualSeriesCompatibility::aggregateDisplayKey(
            $row->compatibility_key,
            $row->method_version,
            $row->basis_counts,
        );
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
     * @return array{x:array<int,int>,median:array<int,?float>,p20:array<int,?float>,p80:array<int,?float>}
     */
    private function spotEnergyPriceAggregatedSeriesWithBands(): array
    {
        if (isset($this->spotEnergyPriceAggregatedSeriesCache[$this->period])) {
            return $this->spotEnergyPriceAggregatedSeriesCache[$this->period];
        }

        $rows = $this->dailyStatisticRows('spot', 'spot_total_energy_price', null);

        if ($rows->isEmpty()) {
            return $this->spotEnergyPriceAggregatedSeriesCache[$this->period] = [
                'x' => [],
                'median' => [],
                'p20' => [],
                'p80' => [],
            ];
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
            return $this->spotEnergyPriceAggregatedSeriesCache[$this->period] = [
                'x' => [],
                'median' => [],
                'p20' => [],
                'p80' => [],
            ];
        }

        $grouped = $daily
            ->groupBy(fn ($row) => $this->periodStart($row['date'])->toDateString())
            ->sortKeys();

        $x = $median = $p20 = $p80 = [];
        foreach ($grouped as $periodStart => $periodRows) {
            $x[] = Carbon::parse($periodStart)->getTimestamp();
            $median[] = $this->averageOrNull($periodRows->pluck('median'));
            $p20[] = $this->averageOrNull($periodRows->pluck('p20'));
            $p80[] = $this->averageOrNull($periodRows->pluck('p80'));
        }

        return $this->spotEnergyPriceAggregatedSeriesCache[$this->period] = [
            'x' => $x,
            'median' => $median,
            'p20' => $p20,
            'p80' => $p80,
        ];
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
        $spotRows = $this->dailyStatisticRows('spot', 'spot_total_energy_price', null)
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
            return $this->spotEnergySummaryCache[$endDate] = [
                'avg' => array_sum($dailyMarketPrices) / count($dailyMarketPrices) + $margin,
                'p20' => $this->percentileFromValues($dailyMarketPrices, 20) + $margin,
                'p80' => $this->percentileFromValues($dailyMarketPrices, 80) + $margin,
                'days' => count($dailyMarketPrices),
            ];
        }

        // Test/development fallback: if stored spot averages are unavailable,
        // use the page's own daily spot-total medians over the same window.
        $dailyTotals = $this->dailyStatisticRows('spot', 'spot_total_energy_price', null)
            ->filter(fn ($row) => $row->stat_date->betweenIncluded($startDate, $endDate))
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

        $averagePrices = $this->spotMarketPricesForWindow($this->dailySpotAveragePricesByDate(), $startDate, $endDate);

        if (count($averagePrices) >= $minimumCompleteDays) {
            return $averagePrices;
        }

        $hourlyDailyPrices = $this->spotMarketPricesForWindow($this->hourlySpotAveragePricesByDate(), $startDate, $endDate);

        if (count($hourlyDailyPrices) >= $minimumCompleteDays || count($hourlyDailyPrices) > count($averagePrices)) {
            return $hourlyDailyPrices;
        }

        return $averagePrices;
    }

    private function latestSpotMargin(string $latestDate): ?float
    {
        $row = $this->dailyStatisticRows('spot', 'spot_margin', null)
            ->filter(fn ($stat) => $stat->stat_date->lte($latestDate))
            ->sortByDesc('stat_date')
            ->first();

        return $row?->median_value !== null ? (float) $row->median_value : null;
    }

    /**
     * @param  array<int, float>  $values
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
     * @param  array<int,?float>  $values
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
     * @param  array<int,?float>  $values
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
     * @param  array{x:array<int,int>,values:array<int,?float>}  $series
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
            'market_reset' => 'Jaksoittain vaihtuvan hinnan sopimukset',
            'fixed_term_6' => 'Lyhyet määräaikaiset (6 kk) sopimukset',
            'fixed_term_12' => 'Vuoden määräaikaiset sopimukset',
            'fixed_term_24' => 'Kahden vuoden määräaikaiset sopimukset',
            'hybrid' => 'Joustosähkösopimukset',
            'open_ended' => 'Toistaiseksi voimassa olevat sopimukset',
        ];
        $subject = $subjects[$segmentKey] ?? (($this->segments[$segmentKey] ?? $segmentKey).'-sopimukset');

        $fmtCents = fn (float $v) => number_format($v, 2, ',', ' ');

        // Spot, or any segment when we lack a reliable annual-cost comparison:
        // frame as change since data start. Do not compare non-spot c/kWh
        // against current spot c/kWh because that overstates differences on
        // unusually cheap or expensive spot days.
        if ($segmentKey === 'spot' || $annualCostCurrent === null || $spotAnnualCostCurrent === null || $spotAnnualCostCurrent <= 0) {
            if ($first === null) {
                return [
                    'headline' => $fmtCents($current)."\u{00A0}c/kWh",
                    'headline_label' => 'Energiahinta nyt',
                    'tone' => 'neutral',
                    'sentence_before' => "{$subject} on tällä hetkellä ",
                    'sentence_highlight' => $fmtCents($current)."\u{00A0}c/kWh",
                    'sentence_after' => '.',
                    'sentence_plain' => "{$subject} on tällä hetkellä ".$fmtCents($current).' c/kWh.',
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
                    'sentence_after' => ' aineiston alusta ('.$fmtCents($current)."\u{00A0}c/kWh).",
                    'sentence_plain' => "{$subject} on pysynyt käytännössä ennallaan aineiston alusta (".$fmtCents($current).' c/kWh).',
                ];
            }
            $absPct = number_format(abs($delta), 0, ',', ' ');
            $sign = $delta < 0 ? '−' : '+';
            $verb = $delta < 0 ? 'laskenut' : 'noussut';

            return [
                'headline' => "{$sign}{$absPct}\u{00A0}%",
                'headline_label' => 'Aineiston alusta',
                'tone' => $delta < 0 ? 'down' : 'up',
                'sentence_before' => "{$subject} on {$verb} ",
                'sentence_highlight' => "{$absPct}\u{00A0}%",
                'sentence_after' => ' aineiston alusta ('.$fmtCents($current)."\u{00A0}c/kWh).",
                'sentence_plain' => "{$subject} on {$verb} {$absPct} % aineiston alusta (".$fmtCents($current).' c/kWh).',
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
                'sentence_after' => " {$consumption}\u{00A0}kWh vuosikulutuksella (".$fmtEur($annualCostCurrent)."\u{00A0}€/v vs.\u{00A0}".$fmtEur($spotAnnualCostCurrent)."\u{00A0}€/v).",
                'sentence_plain' => "{$subject} ovat lähellä pörssisähkön tasoa {$consumption} kWh vuosikulutuksella (".$fmtEur($annualCostCurrent).' €/v vs. '.$fmtEur($spotAnnualCostCurrent).' €/v).',
            ];
        }
        $absPct = number_format(abs($vsSpot), 0, ',', ' ');
        $sign = $vsSpot < 0 ? '−' : '+';
        $direction = $vsSpot > 0 ? 'enemmän' : 'vähemmän';

        return [
            'headline' => "{$sign}{$absPct}\u{00A0}%",
            'headline_label' => "Vs. pörssisähkö, {$consumption} kWh/v",
            'tone' => $vsSpot > 0 ? 'up' : 'down',
            'sentence_before' => "{$subject} maksavat {$consumption}\u{00A0}kWh vuosikulutuksella ",
            'sentence_highlight' => "{$absPct}\u{00A0}% {$direction}",
            'sentence_after' => ' kuin pörssisähkö ('.$fmtEur($annualCostCurrent)."\u{00A0}€/v vs.\u{00A0}".$fmtEur($spotAnnualCostCurrent)."\u{00A0}€/v).",
            'sentence_plain' => "{$subject} maksavat {$consumption} kWh vuosikulutuksella {$absPct} % {$direction} kuin pörssisähkö (".$fmtEur($annualCostCurrent).' €/v vs. '.$fmtEur($spotAnnualCostCurrent).' €/v).',
        ];
    }

    /**
     * @param  array{values:array<int,?float>,compatibility_keys:array<int,?string>}  $series
     * @param  array{value:?float,index:?int}  $current
     * @param  array{value:?float,index:?int}  $reference
     */
    private function compatibleAnnualDelta(array $series, array $current, array $reference): ?float
    {
        if ($current['index'] === null || $reference['index'] === null) {
            return null;
        }

        $currentKey = $series['compatibility_keys'][$current['index']] ?? null;
        $referenceKey = $series['compatibility_keys'][$reference['index']] ?? null;
        if (! AnnualSeriesCompatibility::sameKey($currentKey, $referenceKey)) {
            return null;
        }

        return $this->percentDelta($current['value'], $reference['value']);
    }

    /**
     * @param  array{values:array<int,?float>,compatibility_keys:array<int,?string>}  $series
     * @param  array{value:?float,index:?int}  $current
     * @return array{value:?float,index:?int}
     */
    private function firstNonNullInCurrentAnnualRegime(array $series, array $current): array
    {
        if ($current['index'] === null) {
            return ['value' => null, 'index' => null];
        }

        $currentKey = $series['compatibility_keys'][$current['index']] ?? null;
        foreach ($series['values'] as $index => $value) {
            $key = $series['compatibility_keys'][$index] ?? null;
            if ($value !== null && AnnualSeriesCompatibility::sameKey($key, $currentKey)) {
                return ['value' => (float) $value, 'index' => $index];
            }
        }

        return ['value' => null, 'index' => null];
    }

    private function annualCostCaptionSentence(string $label, float $delta, bool $fromDatasetStart): string
    {
        $prefix = $fromDatasetStart
            ? "{$label}-sopimusten vuosikustannus on"
            : "{$label}-sopimusten vuosikustannus on nykyisen vertailukelpoisen jakson aikana";

        if (abs($delta) < 1.5) {
            return "{$prefix} pysynyt käytännössä paikoillaan.";
        }

        $verb = $delta < 0 ? 'laskenut' : 'noussut';
        $abs = number_format(abs($delta), 0, ',', ' ');

        return $fromDatasetStart
            ? "{$prefix} {$verb} {$abs} % aineiston alusta."
            : "{$prefix} {$verb} {$abs} %.";
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
        $row = $this->latestStatisticRow($segmentKey, 'annual_cost', 5000);

        return $row ? (int) $row->contract_count : null;
    }

    /**
     * Hide stale segments and keep the new broader reset-price category private
     * until it has enough daily observations to show a useful trend.
     */
    private function shouldPublishSegmentTrend(
        string $segmentKey,
        string $metricKey,
        ?int $consumption,
        bool $allowDatedHistory = false,
    ): bool {
        $rows = $this->dailyStatisticRows($segmentKey, $metricKey, $consumption)
            ->filter(fn (ContractPriceDailyStatistic $row): bool => $row->median_value !== null);

        if ($segmentKey === 'market_reset') {
            $rows = $rows->filter(
                fn (ContractPriceDailyStatistic $row): bool => $row->stat_date->toDateString() >= self::MARKET_RESET_COMPARABLE_HISTORY_START,
            );
        }

        if ($rows->isEmpty()) {
            return false;
        }

        $latestPublicDate = $this->dataWindow['to'];
        $hasCurrentPoint = $latestPublicDate !== null && $rows->contains(
            fn (ContractPriceDailyStatistic $row): bool => $row->stat_date->toDateString() === $latestPublicDate,
        );

        $observationCount = $rows
            ->map(fn (ContractPriceDailyStatistic $row): string => $row->stat_date->toDateString())
            ->unique()
            ->count();

        if (! $hasCurrentPoint) {
            return $allowDatedHistory && $observationCount >= self::MARKET_RESET_MINIMUM_PUBLIC_OBSERVATIONS;
        }

        return $segmentKey !== 'market_reset'
            || $observationCount >= self::MARKET_RESET_MINIMUM_PUBLIC_OBSERVATIONS;
    }

    private function latestStatisticRow(string $segmentKey, string $metricKey, ?int $consumption): ?ContractPriceDailyStatistic
    {
        return $this->dailyStatisticRows($segmentKey, $metricKey, $consumption)
            ->when(
                $metricKey !== 'annual_cost',
                fn (Collection $rows) => $rows->where(
                    'pricing_basis',
                    app(PricingMode::class)->expectedContractPriceBasis()->value,
                ),
            )
            ->sortByDesc('stat_date')
            ->first();
    }

    /**
     * @return Collection<int, ContractPriceDailyStatistic>
     */
    private function dailyStatisticRows(string $segmentKey, string $metricKey, ?int $consumption): Collection
    {
        if ($this->dailyStatsIndexCache === null) {
            $indexed = [];

            foreach ($this->dailyStats as $row) {
                $key = $this->dailyStatisticIndexKey(
                    $row->segment_key,
                    $row->metric_key,
                    $row->consumption_kwh,
                );
                $indexed[$key][] = $row;
            }

            $this->dailyStatsIndexCache = collect($indexed)
                ->map(fn (array $rows) => collect($rows))
                ->all();
        }

        return $this->dailyStatsIndexCache[$this->dailyStatisticIndexKey($segmentKey, $metricKey, $consumption)]
            ?? collect();
    }

    private function dailyStatisticIndexKey(string $segmentKey, string $metricKey, ?int $consumption): string
    {
        return $segmentKey.'|'.$metricKey.'|'.($consumption ?? 'null');
    }

    private function latestSnapshotDateRaw(): ?string
    {
        if (! $this->latestSnapshotDateLoaded) {
            $this->latestSnapshotDateCache = ContractPriceSnapshot::query()
                ->where('pricing_basis', app(PricingMode::class)->expectedContractPriceBasis()->value)
                ->max('snapshot_date');
            $this->latestSnapshotDateLoaded = true;
        }

        return $this->latestSnapshotDateCache;
    }

    /**
     * @return array<string, float>
     */
    private function dailySpotAveragePricesByDate(): array
    {
        if ($this->dailySpotAveragePricesByDateCache !== null) {
            return $this->dailySpotAveragePricesByDateCache;
        }

        $bounds = $this->spotMarketPriceBounds();

        return $this->dailySpotAveragePricesByDateCache = SpotPriceAverage::forRegion('FI')
            ->ofType(SpotPriceAverage::PERIOD_DAILY)
            ->whereBetween('period_start', [$bounds['start'], $bounds['end']])
            ->whereNotNull('avg_price_with_tax')
            ->orderBy('period_start')
            ->get(['period_start', 'avg_price_with_tax'])
            ->mapWithKeys(fn (SpotPriceAverage $average) => [
                Carbon::parse($average->period_start)->toDateString() => (float) $average->avg_price_with_tax,
            ])
            ->all();
    }

    /**
     * @return array<string, float>
     */
    private function hourlySpotAveragePricesByDate(): array
    {
        if ($this->hourlySpotAveragePricesByDateCache !== null) {
            return $this->hourlySpotAveragePricesByDateCache;
        }

        $bounds = $this->spotMarketPriceBounds();

        return $this->hourlySpotAveragePricesByDateCache = SpotPriceHour::forRegion('FI')
            ->whereBetween('utc_datetime', [Carbon::parse($bounds['start'])->startOfDay(), Carbon::parse($bounds['end'])->endOfDay()])
            ->selectRaw('DATE(utc_datetime) as price_date, AVG(price_without_tax * (1 + vat_rate)) as avg_price_with_tax')
            ->groupBy('price_date')
            ->orderBy('price_date')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->price_date => (float) $row->avg_price_with_tax])
            ->all();
    }

    /**
     * @return array{start:string,end:string}
     */
    private function spotMarketPriceBounds(): array
    {
        if ($this->spotMarketPriceBoundsCache !== null) {
            return $this->spotMarketPriceBoundsCache;
        }

        $spotRows = $this->dailyStatisticRows('spot', 'spot_total_energy_price', null);

        if ($spotRows->isEmpty()) {
            $today = Carbon::today();

            return $this->spotMarketPriceBoundsCache = [
                'start' => $today->copy()->subDays(364)->toDateString(),
                'end' => $today->toDateString(),
            ];
        }

        $dates = $spotRows->pluck('stat_date')->map(fn ($date) => Carbon::parse($date));
        $first = $dates->min();
        $last = $dates->max();

        return $this->spotMarketPriceBoundsCache = [
            'start' => $first->copy()->subDays(364)->toDateString(),
            'end' => $last->toDateString(),
        ];
    }

    /**
     * @param  array<string, float>  $pricesByDate
     * @return array<int, float>
     */
    private function spotMarketPricesForWindow(array $pricesByDate, string $startDate, string $endDate): array
    {
        $prices = [];

        foreach ($pricesByDate as $date => $price) {
            if ($date < $startDate) {
                continue;
            }

            if ($date > $endDate) {
                break;
            }

            $prices[] = $price;
        }

        return $prices;
    }

    /**
     * Generate an inline-SVG path string ("M x,y L x,y …") for sparkline rendering.
     *
     * @param  array<int,?float>  $values
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
            $parts[] = ($started ? 'L' : 'M')." {$x},{$y}";
            $started = true;
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
