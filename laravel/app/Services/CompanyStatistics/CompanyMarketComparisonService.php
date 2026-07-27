<?php

namespace App\Services\CompanyStatistics;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Compares one seller's own contract prices against the whole market, per
 * contract-type segment, from the statistics tables that already exist.
 *
 * Read `AGENTS.md` beside this file before changing the metric or the segment
 * floor. Both are load bearing.
 */
class CompanyMarketComparisonService
{
    /**
     * Reference consumptions that `contract_price_daily_statistics` actually
     * holds an `annual_cost` row for. The company page also offers 10 000 kWh,
     * which is why a selected consumption is snapped rather than trusted.
     */
    public const REFERENCE_CONSUMPTIONS = [2000, 5000, 18000];

    /**
     * A market band under this many contracts is noise, not a reference.
     * `/sahkosopimus/tilastot` hides such rows for the same reason.
     */
    public const MIN_MARKET_CONTRACTS = 10;

    /**
     * Sanity ceiling for a seller snapshot's energy price, in c/kWh.
     *
     * Mirrors the `energy_price` bound in
     * `ContractPriceStatisticsService::cleanValues()`; real Finnish retail
     * energy never exceeds 50 c/kWh, so a higher value is a broken import.
     *
     * The seller's own figure needs its own bound because that method applies
     * the 50 c/kWh ceiling to the **energy_price** metric only. The metric this
     * service reads is `annual_cost`, whose ceiling is 50 000 EUR, so a broken
     * row passes straight through it. Vaasan Sähkö's "Kiinteä 12 kk (yösähkö)"
     * was ingested at 585,46 c/kWh on 13 days in February 2026 and produced a
     * 39 724 EUR/year snapshot; with only a few contracts in that segment it
     * dragged the seller's median into a spike the market median never had.
     */
    private const MAX_PLAUSIBLE_ENERGY_PRICE_CENTS = 50.0;

    /** Trailing window for the trend chart. */
    private const CHART_DAYS = 365;

    /** A chart needs at least this many aggregated points to draw a trend. */
    private const MIN_CHART_POINTS = 3;

    /**
     * Preferred segments for the trend chart, best first.
     *
     * Määräaikainen 12 kk leads because it is the type a visitor comparing
     * sellers actually shops for: a known price for a known term. The other two
     * are the remaining fixed terms with a market wide enough to reference
     * (24 kk has 49 contracts, 6 kk has 20; 13-23 kk and yli 24 kk have 2 and 1
     * and never clear `MIN_MARKET_CONTRACTS`).
     *
     * A seller with no fixed-term product at all falls through to the largest
     * market segment it does sell. On 2026-07-24 that was 14 of 35 sellers,
     * most of them spot-only.
     */
    private const CHART_SEGMENT_PREFERENCE = [
        'fixed_term_12',
        'fixed_term_24',
        'fixed_term_6',
    ];

    private const CACHE_TTL_HOURS = 6;

    /**
     * @return array<string,mixed>|null Null when the market has no usable reference for this seller.
     */
    public function forCompany(string $companyName, int $selectedConsumption): ?array
    {
        $referenceConsumption = $this->snapConsumption($selectedConsumption);

        $payload = $this->cachedForReference($companyName, $referenceConsumption);

        if ($payload === null) {
            return null;
        }

        // Selection-dependent fields are added after the cache read. The cache
        // key carries only the snapped reference, so storing them would let a
        // 10 000 kWh visitor be served a payload that claims 5 000 kWh was the
        // selection and that nothing was snapped.
        return [
            ...$payload,
            'selected_consumption' => $selectedConsumption,
            'is_snapped' => $referenceConsumption !== $selectedConsumption,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function cachedForReference(string $companyName, int $referenceConsumption): ?array
    {
        if (app()->runningUnitTests()) {
            return $this->build($companyName, $referenceConsumption);
        }

        $pricingBasis = ContractPriceBasis::expectedCurrent()->value;
        $canonicalEnabled = (bool) config('canonical_pricing.enabled', false);
        $fingerprint = $this->fingerprint();

        if ($fingerprint === null) {
            return null;
        }

        return Cache::remember(
            'company-market-comparison:v5:'.($canonicalEnabled ? 'c1' : 'c0').':'.$pricingBasis.':'.md5($companyName).':'.$referenceConsumption.':'.$fingerprint,
            now()->addHours(self::CACHE_TTL_HOURS),
            fn () => $this->build($companyName, $referenceConsumption),
        );
    }

    /**
     * The statistics page prices only three consumptions, so an arbitrary
     * selection is snapped to the nearest one and the page says which. Same
     * reasoning as `ContractDetail::rankConsumption()`: building a market-wide
     * reference for a free value would mean recomputing the whole market.
     */
    public function snapConsumption(int $consumption): int
    {
        $best = self::REFERENCE_CONSUMPTIONS[0];

        foreach (self::REFERENCE_CONSUMPTIONS as $candidate) {
            if (abs($candidate - $consumption) < abs($best - $consumption)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function build(string $companyName, int $referenceConsumption): ?array
    {
        $expectedBasis = ContractPriceBasis::expectedCurrent()->value;
        $current = $this->buildForBasis($companyName, $referenceConsumption, $expectedBasis);

        if ($current !== null) {
            return $current;
        }

        // Canonical current values never fall back contract by contract. If no
        // internally consistent canonical market + company date exists, the
        // page can still show one explicitly dated historical observation.
        if ($expectedBasis === ContractPriceBasis::CanonicalCalculation->value) {
            return $this->buildForBasis(
                $companyName,
                $referenceConsumption,
                ContractPriceBasis::ObservedSellerData->value,
                historicalFallback: true,
            );
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function buildForBasis(
        string $companyName,
        int $referenceConsumption,
        string $pricingBasis,
        bool $historicalFallback = false,
    ): ?array {
        $statDate = $this->latestUsableDate($companyName, $referenceConsumption, $pricingBasis);
        if ($statDate === null) {
            return null;
        }

        $marketRows = ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', $statDate)
            ->where('metric_key', 'annual_cost')
            ->where('pricing_basis', $pricingBasis)
            ->where('consumption_kwh', $referenceConsumption)
            ->get()
            ->keyBy('segment_key');

        // The energy-rate guard repairs a known defect only in old relational
        // observations: those rows could contain a standing-charge-only annual
        // total or a unit-import error. A canonical annual result is complete on
        // its own and can validly have no public unit rate (canonical-only and
        // package contracts), so a relational guard must not remove it.
        $companySnapshots = ContractPriceSnapshot::query()
            ->where('company_name', $companyName)
            ->whereDate('snapshot_date', $statDate)
            ->where('pricing_basis', $pricingBasis)
            ->when($pricingBasis !== ContractPriceBasis::CanonicalCalculation->value, fn ($query) => $query
                ->where('energy_price_cents_per_kwh', '>', 0)
                ->where('energy_price_cents_per_kwh', '<=', self::MAX_PLAUSIBLE_ENERGY_PRICE_CENTS))
            ->get(['segment_key', 'annual_cost_'.$referenceConsumption.'_kwh as annual_cost']);

        $rows = $this->buildRows($companySnapshots, $marketRows);
        if ($rows === []) {
            return null;
        }

        $primary = $this->chartSegment($rows);

        return [
            'stat_date' => $statDate,
            'reference_consumption' => $referenceConsumption,
            'rows' => $rows,
            'pricing_basis' => $pricingBasis,
            'comparison_state' => $historicalFallback
                ? 'historical_observed_fallback'
                : ($pricingBasis === ContractPriceBasis::CanonicalCalculation->value ? 'current_canonical' : 'current_observed'),
            'is_historical_fallback' => $historicalFallback,
            'spot_benchmarks' => $historicalFallback ? null : $this->spotBenchmarks($statDate, $pricingBasis),
            'chart' => $this->buildChart($companyName, $primary['segment_key'], $referenceConsumption, $statDate, $pricingBasis),
            'chart_segment_key' => $primary['segment_key'],
            'chart_segment_label' => $primary['label'],
        ];
    }

    /**
     * Current Spot supplier-charge medians from the exact date and pricing
     * basis selected for the company comparison. Historical fallback payloads
     * do not call this method because current contract facts must not be
     * compared with dated observed rows.
     *
     * @return array{stat_date:string,pricing_basis:string,spot_margin?:array{median:float,contract_count:int},monthly_fee?:array{median:float,contract_count:int}}|null
     */
    private function spotBenchmarks(string $statDate, string $pricingBasis): ?array
    {
        $rows = ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', $statDate)
            ->where('segment_key', 'spot')
            ->where('pricing_basis', $pricingBasis)
            ->whereNull('consumption_kwh')
            ->whereIn('metric_key', ['spot_margin', 'monthly_fee'])
            ->get()
            ->keyBy('metric_key');

        $benchmarks = [
            'stat_date' => $statDate,
            'pricing_basis' => $pricingBasis,
        ];

        foreach (['spot_margin', 'monthly_fee'] as $metric) {
            $row = $rows->get($metric);

            if ($row === null
                || $row->contract_count < self::MIN_MARKET_CONTRACTS
                || ! is_numeric($row->median_value)) {
                continue;
            }

            $benchmarks[$metric] = [
                'median' => (float) $row->median_value,
                'contract_count' => (int) $row->contract_count,
            ];
        }

        return count($benchmarks) > 2 ? $benchmarks : null;
    }

    private function latestUsableDate(string $companyName, int $referenceConsumption, string $pricingBasis): ?string
    {
        $annualCostColumn = 'snapshots.annual_cost_'.$referenceConsumption.'_kwh';

        $date = ContractPriceSnapshot::query()
            ->from('contract_price_snapshots as snapshots')
            ->join('contract_price_daily_statistics as statistics', function ($join) {
                $join->on('statistics.stat_date', '=', 'snapshots.snapshot_date')
                    ->on('statistics.segment_key', '=', 'snapshots.segment_key')
                    ->on('statistics.pricing_basis', '=', 'snapshots.pricing_basis');
            })
            ->where('snapshots.company_name', $companyName)
            ->where('snapshots.pricing_basis', $pricingBasis)
            ->where('statistics.metric_key', 'annual_cost')
            ->where('statistics.consumption_kwh', $referenceConsumption)
            ->where('statistics.contract_count', '>=', self::MIN_MARKET_CONTRACTS)
            ->whereNotNull('statistics.p20_value')
            ->whereNotNull('statistics.median_value')
            ->whereNotNull('statistics.p80_value')
            ->whereNotNull($annualCostColumn)
            ->where($annualCostColumn, '>', 0)
            ->when($pricingBasis !== ContractPriceBasis::CanonicalCalculation->value, fn ($query) => $query
                ->where('snapshots.energy_price_cents_per_kwh', '>', 0)
                ->where('snapshots.energy_price_cents_per_kwh', '<=', self::MAX_PLAUSIBLE_ENERGY_PRICE_CENTS))
            ->max('snapshots.snapshot_date');

        return $date === null
            ? null
            : Carbon::parse($date)->setTimezone((string) config('app.timezone'))->toDateString();
    }

    /**
     * Pick the segment the trend chart draws.
     *
     * `CHART_SEGMENT_PREFERENCE` wins first, so a seller that sells a 12-month
     * fixed contract is always charted on that type. Only a seller with no
     * fixed-term product falls through to the largest market segment.
     *
     * Two earlier rules were wrong and should not come back:
     *
     * - **Largest company contract count.** The seller's own contracts are
     *   inside the market statistics, so a segment where it holds a large share
     *   makes the market median partly its own line. Vaasan Sähkö holds 5 of 13
     *   Kvartaalisähkö contracts and that chart drew the two series on top of
     *   each other.
     * - **Largest market count alone.** `open_ended` (62) and `spot` (59) are
     *   the biggest segments, so every one of the 35 sellers got one of those
     *   two and no seller ever got a fixed-term chart. `open_ended` is also the
     *   most dispersed segment (p20 448 EUR, p80 754 EUR at 5 000 kWh), so it
     *   is the type whose median says least.
     *
     * The overlap risk the first rule created is small here: the preferred
     * segments hold 49, 49 and 20 market contracts, and no seller holds more
     * than a few of them.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function chartSegment(array $rows): array
    {
        foreach (self::CHART_SEGMENT_PREFERENCE as $preferred) {
            foreach ($rows as $row) {
                if ($row['segment_key'] === $preferred) {
                    return $row;
                }
            }
        }

        $primary = $rows[0];

        foreach ($rows as $row) {
            if ($row['market_contract_count'] > $primary['market_contract_count']) {
                $primary = $row;
            }
        }

        return $primary;
    }

    /**
     * @param  Collection<int,ContractPriceSnapshot>  $companySnapshots
     * @param  Collection<string,ContractPriceDailyStatistic>  $marketRows
     * @return list<array<string,mixed>>
     */
    private function buildRows(Collection $companySnapshots, Collection $marketRows): array
    {
        $rows = [];

        foreach (ContractPriceStatisticsService::SEGMENT_LABELS as $segmentKey => $label) {
            $market = $marketRows->get($segmentKey);

            if ($market === null || $market->contract_count < self::MIN_MARKET_CONTRACTS) {
                continue;
            }

            $p20 = $market->p20_value === null ? null : (float) $market->p20_value;
            $median = $market->median_value === null ? null : (float) $market->median_value;
            $p80 = $market->p80_value === null ? null : (float) $market->p80_value;

            if ($p20 === null || $median === null || $p80 === null) {
                continue;
            }

            $companyValues = $companySnapshots
                ->where('segment_key', $segmentKey)
                ->pluck('annual_cost')
                ->filter(fn ($value) => $value !== null && (float) $value > 0)
                ->map(fn ($value) => (float) $value)
                ->values();

            if ($companyValues->isEmpty()) {
                continue;
            }

            $companyValue = $this->median($companyValues->all());

            $rows[] = [
                'segment_key' => $segmentKey,
                'label' => $label,
                'company_value' => $companyValue,
                'company_contract_count' => $companyValues->count(),
                'market_p20' => $p20,
                'market_median' => $median,
                'market_p80' => $p80,
                'market_contract_count' => (int) $market->contract_count,
                'delta_vs_median' => $companyValue - $median,
                'position' => match (true) {
                    $companyValue < $p20 => 'below_p20',
                    $companyValue > $p80 => 'above_p80',
                    default => 'in_band',
                },
                ...$this->trackGeometry($companyValue, $p20, $median, $p80),
            ];
        }

        return $rows;
    }

    /**
     * Precompute the range-row geometry on the server, so the Blade stays
     * presentation-only. Same rule as the signed spot bars on `/spot-price`.
     *
     * The track domain always contains the seller's own value, so a contract
     * cheaper than p20 or dearer than p80 still has a visible marker.
     *
     * The domain is derived from the p20-p80 spread, not from the market min
     * and max. Those two columns carry the broken rows this file already
     * guards against on the seller side (hybrid min 49 EUR, open-ended max
     * 1 340 EUR on 2026-07-24), so a min-max track would draw a dishonest
     * scale. Padding by 0,6 of the spread leaves the band about 45 % of the
     * track, which is what makes it read as a range rather than as a line.
     *
     * @return array{band_left_percent:float,band_width_percent:float,median_percent:float,marker_percent:float,track_low:float,track_high:float}
     */
    private function trackGeometry(float $companyValue, float $p20, float $median, float $p80): array
    {
        $spread = max($p80 - $p20, abs($median) * 0.02, 1.0);
        $pad = $spread * 0.6;

        $low = $p20 - $pad;
        $high = $p80 + $pad;

        // Keep an out-of-band marker off the very edge of the track.
        $low = min($low, $companyValue - $spread * 0.15);
        $high = max($high, $companyValue + $spread * 0.15);

        $span = $high - $low;

        $percent = fn (float $value) => $span > 0 ? round((($value - $low) / $span) * 100, 2) : 50.0;

        return [
            'band_left_percent' => $percent($p20),
            'band_width_percent' => round($percent($p80) - $percent($p20), 2),
            'median_percent' => $percent($median),
            'marker_percent' => $percent($companyValue),
            'track_low' => $low,
            'track_high' => $high,
        ];
    }

    /**
     * Trailing-12-month weekly trend for the seller's largest segment: the
     * market p20-p80 band, the market median, and the seller's own median.
     *
     * The payload shape is the one `resources/js/contract-price-statistics.js`
     * already renders, band included, so this adds no chart code.
     *
     * @return array<string,mixed>|null
     */
    private function buildChart(
        string $companyName,
        string $segmentKey,
        int $referenceConsumption,
        string $statDate,
        string $pricingBasis,
    ): ?array {
        $from = Carbon::parse($statDate)->subDays(self::CHART_DAYS - 1)->toDateString();
        $canonicalStart = $pricingBasis === ContractPriceBasis::CanonicalCalculation->value
            ? ContractPriceDailyStatistic::query()
                ->where('segment_key', $segmentKey)
                ->where('metric_key', 'annual_cost')
                ->where('consumption_kwh', $referenceConsumption)
                ->where('pricing_basis', $pricingBasis)
                ->whereDate('stat_date', '>=', $from)
                ->whereDate('stat_date', '<=', $statDate)
                ->min('stat_date')
            : null;
        $canonicalStart = $canonicalStart
            ? Carbon::parse($canonicalStart)->setTimezone((string) config('app.timezone'))->toDateString()
            : null;

        $marketDaily = ContractPriceDailyStatistic::query()
            ->where('segment_key', $segmentKey)
            ->where('metric_key', 'annual_cost')
            ->where('consumption_kwh', $referenceConsumption)
            ->where(function ($query) use ($pricingBasis, $canonicalStart) {
                $query->where('pricing_basis', $pricingBasis);

                if ($canonicalStart !== null) {
                    $query->orWhere(function ($observed) use ($canonicalStart) {
                        $observed->where('pricing_basis', ContractPriceBasis::ObservedSellerData->value)
                            ->whereDate('stat_date', '<', $canonicalStart);
                    });
                }
            })
            ->whereDate('stat_date', '>=', $from)
            ->whereDate('stat_date', '<=', $statDate)
            ->orderBy('stat_date')
            ->get(['stat_date', 'p20_value', 'median_value', 'p80_value']);

        if ($marketDaily->isEmpty()) {
            return null;
        }

        // Keep dated observed seller evidence before canonical forward collection
        // starts. On and after that boundary, only canonical rows can own a point.
        $companyDaily = ContractPriceSnapshot::query()
            ->where('company_name', $companyName)
            ->where('segment_key', $segmentKey)
            ->where(function ($query) use ($pricingBasis, $canonicalStart) {
                $query->where(function ($current) use ($pricingBasis) {
                    $current->where('pricing_basis', $pricingBasis)
                        ->when($pricingBasis === ContractPriceBasis::ObservedSellerData->value, fn ($observed) => $observed
                            ->where('energy_price_cents_per_kwh', '>', 0)
                            ->where('energy_price_cents_per_kwh', '<=', self::MAX_PLAUSIBLE_ENERGY_PRICE_CENTS));
                });

                if ($canonicalStart !== null) {
                    $query->orWhere(function ($observed) use ($canonicalStart) {
                        $observed->where('pricing_basis', ContractPriceBasis::ObservedSellerData->value)
                            ->whereDate('snapshot_date', '<', $canonicalStart)
                            ->where('energy_price_cents_per_kwh', '>', 0)
                            ->where('energy_price_cents_per_kwh', '<=', self::MAX_PLAUSIBLE_ENERGY_PRICE_CENTS);
                    });
                }
            })
            ->whereDate('snapshot_date', '>=', $from)
            ->whereDate('snapshot_date', '<=', $statDate)
            ->get(['snapshot_date', 'annual_cost_'.$referenceConsumption.'_kwh as annual_cost'])
            ->groupBy(fn ($row) => Carbon::parse($row->snapshot_date)->toDateString())
            ->map(function (Collection $rows) {
                $values = $rows
                    ->pluck('annual_cost')
                    ->filter(fn ($value) => $value !== null && (float) $value > 0)
                    ->map(fn ($value) => (float) $value)
                    ->values()
                    ->all();

                return $values === [] ? null : $this->median($values);
            });

        $weeks = [];

        foreach ($marketDaily as $row) {
            $week = $this->weekStart($row->stat_date);
            $weeks[$week]['p20'][] = $row->p20_value === null ? null : (float) $row->p20_value;
            $weeks[$week]['median'][] = $row->median_value === null ? null : (float) $row->median_value;
            $weeks[$week]['p80'][] = $row->p80_value === null ? null : (float) $row->p80_value;
        }

        foreach ($companyDaily as $date => $value) {
            $week = $this->weekStart($date);

            // Only weeks the market itself covers; a seller-only week would
            // draw its line over an empty band.
            if (! isset($weeks[$week])) {
                continue;
            }

            $weeks[$week]['company'][] = $value;
        }

        ksort($weeks);

        $x = $companySeries = $marketSeries = $lower = $upper = [];

        foreach ($weeks as $week => $values) {
            $x[] = Carbon::parse($week)->getTimestamp();
            $companySeries[] = $this->averageOrNull($values['company'] ?? []);
            $marketSeries[] = $this->averageOrNull($values['median'] ?? []);
            $lower[] = $this->averageOrNull($values['p20'] ?? []);
            $upper[] = $this->averageOrNull($values['p80'] ?? []);
        }

        $companyPoints = count(array_filter($companySeries, fn ($value) => $value !== null));

        if (count($x) < self::MIN_CHART_POINTS || $companyPoints < self::MIN_CHART_POINTS) {
            return null;
        }

        return [
            'unit' => 'eur',
            'decimals' => 0,
            // Without this the shared renderer draws the lead series in
            // slate-800 and the first non-lead series in slate-800 too, so
            // "this seller" and "market median" become one navy line.
            'leadStroke' => '#f97316',
            'x' => $x,
            'series' => [
                // Labels reach the chart tooltip, so they must match the
                // legend the Blade draws beside it.
                ['label' => $companyName, 'values' => $companySeries],
                ['label' => 'Markkinan mediaani', 'values' => $marketSeries],
            ],
            'band' => [
                'lower' => $lower,
                'upper' => $upper,
                'label' => 'Halvempi 20 % – kalliimpi 20 %',
            ],
            'current_pricing_basis' => $pricingBasis,
            'canonical_from' => $canonicalStart,
        ];
    }

    private function weekStart(CarbonInterface|string $date): string
    {
        $date = $date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date);

        return $date->startOfWeek()->toDateString();
    }

    /**
     * @param  array<int,?float>  $values
     */
    private function averageOrNull(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($value) => $value !== null));

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * @param  array<int,float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function fingerprint(): ?string
    {
        $pricingBasis = ContractPriceBasis::expectedCurrent()->value;
        $canonicalEnabled = (bool) config('canonical_pricing.enabled', false);
        $bases = $canonicalEnabled
            ? [ContractPriceBasis::CanonicalCalculation->value, ContractPriceBasis::ObservedSellerData->value]
            : [$pricingBasis];
        $sources = [];
        $hasData = false;

        foreach ($bases as $basis) {
            $statistics = ContractPriceDailyStatistic::query()->where('pricing_basis', $basis);
            $snapshots = ContractPriceSnapshot::query()->where('pricing_basis', $basis);
            $statDate = $statistics->max('stat_date');
            $snapshotDate = $snapshots->max('snapshot_date');
            $hasData = $hasData || $statDate !== null || $snapshotDate !== null;
            $sources[$basis] = [
                'statistics_latest_date' => $statDate,
                'statistics_latest_updated' => $statistics->max('updated_at'),
                'snapshots_latest_date' => $snapshotDate,
                'snapshots_latest_updated' => $snapshots->max('updated_at'),
            ];
        }

        if (! $hasData) {
            return null;
        }

        return md5(json_encode([
            'canonical_enabled' => $canonicalEnabled,
            'expected_pricing_basis' => $pricingBasis,
            'sources' => $sources,
        ]));
    }
}
