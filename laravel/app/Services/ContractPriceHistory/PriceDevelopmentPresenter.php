<?php

namespace App\Services\ContractPriceHistory;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractStatistics\ContractStatisticsSegmentClassifier;
use Carbon\Carbon;

/**
 * Builds the "Näin hinta on kehittynyt" module on the contract detail page:
 * one server-rendered SVG chart, a small seller-behaviour fact record, and the
 * copy that scopes both.
 *
 * Two variants, because the honest question differs by pricing model:
 *
 * - **contract** (fixed / market-reset / consumption-effect): the contract's own
 *   observed energy price as a stepped ink line, overlaid on the median of its
 *   statistics segment from `contract_price_daily_statistics`.
 * - **spot**: the realized monthly market average plus this contract's margin as
 *   the ink line, against the trailing-12-month average as the dashed reference.
 *   A spot contract's own price history is just its margin, which is nearly
 *   always flat and teaches nothing; volatility is the fact that matters.
 *
 * Design constraints carried from the approved mockups and enforced here:
 * ink line is slate-900, the reference is a dashed slate-500 line with a direct
 * label, coral is never a data series, every delta is c/kWh and never a
 * percentage, and a window too short to describe renders an honest message
 * instead of a flat line.
 */
class PriceDevelopmentPresenter
{
    public function __construct(
        private readonly PricingMode $pricingMode,
        private readonly ContractStatisticsSegmentClassifier $segmentClassifier,
    ) {}

    /** Below this many days of observations there is nothing honest to plot. */
    public const MIN_TRACKING_DAYS = 21;

    /** Below this many completed months the spot volatility chart is noise. */
    public const MIN_SPOT_MONTHS = 3;

    /** The one €/kk component type; every other type is an energy price. */
    private const FEE_TYPE = 'Monthly';

    /** Completed months plotted in the spot variant. */
    private const SPOT_MONTHS = 12;

    private const INK = '#0f172a';        // slate-900, the contract series

    private const REFERENCE = '#64748b';  // slate-500, the dashed reference

    private const VIEW_WIDTH = 680;

    private const VIEW_HEIGHT = 230;

    private const PLOT_LEFT = 52;

    // Leaves room inside the 680-wide viewBox for the direct end labels.
    private const PLOT_RIGHT = 556;

    private const PLOT_TOP = 30;

    private const PLOT_BOTTOM = 190;

    private const MONTH_SHORT = [
        1 => 'tammi', 2 => 'helmi', 3 => 'maalis', 4 => 'huhti', 5 => 'touko', 6 => 'kesä',
        7 => 'heinä', 8 => 'elo', 9 => 'syys', 10 => 'loka', 11 => 'marras', 12 => 'joulu',
    ];

    private const MONTH_NAME = [
        1 => 'tammikuu', 2 => 'helmikuu', 3 => 'maaliskuu', 4 => 'huhtikuu', 5 => 'toukokuu',
        6 => 'kesäkuu', 7 => 'heinäkuu', 8 => 'elokuu', 9 => 'syyskuu', 10 => 'lokakuu',
        11 => 'marraskuu', 12 => 'joulukuu',
    ];

    /**
     * @param  array<string, array<int, array{date: string, price: float|int}>>  $priceHistory
     *                                                                                          `ContractDetail::$priceHistory`, keyed by `price_component_type`.
     * @param  array<string, mixed>  $calculatedCost
     * @return array{
     *     variant: string,
     *     available: bool,
     *     subtitle: string,
     *     message: ?string,
     *     note: ?string,
     *     tracked_since: ?\Carbon\Carbon,
     *     chart: ?array<string, mixed>,
     *     facts: array<int, string>
     * }
     */
    public function present(ElectricityContract $contract, array $priceHistory, array $calculatedCost = []): array
    {
        $isSpot = $contract->pricing_model === 'Spot';
        $byDate = $this->componentsByDate($priceHistory);

        if (! $isSpot) {
            $byDate = $this->withoutCollidedZeroEnergyPrices($byDate);
        }

        $trackedSince = $byDate === [] ? null : Carbon::parse((string) array_key_first($byDate))->startOfDay();

        $energySeries = $this->changeSeries($byDate, fn (array $components) => $this->representativeEnergy($components, $isSpot));
        $feeSeries = $this->changeSeries($byDate, fn (array $components) => $components[self::FEE_TYPE] ?? null);

        $result = $isSpot
            ? $this->spotVariant($contract, $calculatedCost, $energySeries, $feeSeries, $trackedSince)
            : $this->contractVariant($contract, $energySeries, $feeSeries, $trackedSince);

        return $result + ['tracked_since' => $trackedSince];
    }

    // ------------------------------------------------------------------
    // Variant: the contract's own price against its segment median
    // ------------------------------------------------------------------

    /**
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $energySeries
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $feeSeries
     * @return array<string, mixed>
     */
    private function contractVariant(
        ElectricityContract $contract,
        array $energySeries,
        array $feeSeries,
        ?Carbon $trackedSince,
    ): array {
        $span = $this->spanDays($energySeries);
        $points = $this->plateauPoints($energySeries);

        if (count($points) < 2 || $span < self::MIN_TRACKING_DAYS) {
            return [
                'variant' => 'contract',
                'available' => false,
                'subtitle' => $this->trackedSinceSentence($trackedSince),
                'message' => $this->shortHistoryMessage($energySeries, $span),
                'note' => null,
                'chart' => null,
                'facts' => [],
            ];
        }

        $window = [$points[0]['date'], end($points)['date']];
        $expectedBasis = $this->pricingMode->expectedContractPriceBasis();
        $segmentKey = $this->segmentClassifier->classify($contract, $expectedBasis);
        $segmentLabel = mb_strtolower(ContractStatisticsSegmentClassifier::SEGMENT_LABELS[$segmentKey] ?? $segmentKey);

        $buckets = $this->buckets($window[0], $window[1]);
        $reference = $this->segmentMedianByBucket($segmentKey, $buckets);
        $hasReference = count(array_filter($reference, fn (?float $v) => $v !== null)) >= 2;

        $chart = $this->buildChart(
            steppedPoints: $points,
            referenceByBucket: $hasReference ? $reference : [],
            buckets: $buckets,
            seriesLabel: 'Tämän sopimuksen energianhinta',
            referenceLabel: 'Mediaani, '.$segmentLabel,
            referenceShortLabel: 'mediaani',
            tooltipSeriesLabel: 'Tämä sopimus',
            tooltipReferenceLabel: 'Vertailuryhmän mediaani',
            ariaLabel: 'Sopimuksen energianhinta senttiä kilowattitunnilta verrattuna vastaavien sopimusten mediaaniin.',
        );

        $note = $hasReference
            ? null
            : 'Vertailuryhmän mediaania ei ole vielä tallessa tältä ajanjaksolta, joten kuvaajassa on vain tämän sopimuksen oma hinta.';

        return [
            'variant' => 'contract',
            'available' => true,
            'subtitle' => 'Energianhinta c/kWh · '.$this->trackedSinceSentence($trackedSince),
            'message' => null,
            'note' => $note,
            'chart' => $chart,
            'facts' => $this->contractFacts($energySeries, $feeSeries),
        ];
    }

    // ------------------------------------------------------------------
    // Variant: spot volatility
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $calculatedCost
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $energySeries
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $feeSeries
     * @return array<string, mixed>
     */
    private function spotVariant(
        ElectricityContract $contract,
        array $calculatedCost,
        array $energySeries,
        array $feeSeries,
        ?Carbon $trackedSince,
    ): array {
        $months = $this->completedSpotMonths();

        if (count($months) < self::MIN_SPOT_MONTHS) {
            return [
                'variant' => 'spot',
                'available' => false,
                'subtitle' => $this->trackedSinceSentence($trackedSince),
                'message' => 'Pörssin kuukausikeskiarvoja on tallessa vasta '.count($months)
                    .' täydeltä kuukaudelta, joten hinnan vaihtelua ei vielä näytetä.',
                'note' => null,
                'chart' => null,
                'facts' => [],
            ];
        }

        // A null margin is left out of the numbers instead of guessed. On a
        // promo-then-spot contract the `General` component is a flat intro
        // price, not a margin, and adding it would invent a price.
        $margin = $this->marginCents($calculatedCost);

        $buckets = [];
        $values = [];
        foreach ($months as $month) {
            /** @var Carbon $start */
            $start = $month['start'];
            $buckets[] = [
                'start' => $start->copy(),
                'end' => $start->copy()->endOfMonth(),
                'label' => self::MONTH_SHORT[$start->month],
                'title' => ucfirst(self::MONTH_NAME[$start->month]).' '.$start->year,
            ];
            $values[] = $month['avg'] + ($margin ?? 0.0);
        }

        $reference = $this->trailingYearAverage();
        $reference = $reference !== null ? $reference + ($margin ?? 0.0) : $this->mean($values);

        $points = [];
        foreach ($buckets as $index => $bucket) {
            $points[] = ['date' => $bucket['start']->copy()->addDays(14), 'value' => $values[$index]];
        }

        $chart = $this->buildChart(
            steppedPoints: [],
            referenceByBucket: array_fill(0, count($buckets), $reference),
            buckets: $buckets,
            seriesLabel: $margin !== null ? 'Kuukauden keskihinta, marginaali mukana' : 'Pörssin kuukausikeskihinta',
            referenceLabel: '12 kk keskihinta',
            referenceShortLabel: '12 kk',
            tooltipSeriesLabel: $margin !== null ? 'Keskihinta + marginaali' : 'Pörssin keskihinta',
            tooltipReferenceLabel: '12 kk keskihinta',
            ariaLabel: 'Pörssisähkön toteutunut kuukausikeskihinta senttiä kilowattitunnilta verrattuna 12 kuukauden keskihintaan.',
            linePoints: $points,
            flatReference: true,
        );

        $note = $margin !== null
            ? 'Toteutunut pörssin kuukausikeskihinta, johon on lisätty tämän sopimuksen marginaali '
                .$this->cents($margin).' c/kWh. Kuvaaja kertoo hinnan vaihtelusta, ei tämän sopimuksen toteutuneesta laskutuksesta.'
            : 'Tämän sopimuksen marginaali ei ole yksiselitteinen, joten kuvaajassa on pelkkä pörssin toteutunut kuukausikeskihinta.';

        return [
            'variant' => 'spot',
            'available' => true,
            'subtitle' => 'Kuukauden keskihinta c/kWh · '.$this->trackedSinceSentence($trackedSince),
            'message' => null,
            'note' => $note,
            'chart' => $chart,
            'facts' => $this->spotFacts($buckets, $values, $energySeries, $feeSeries, $margin),
        ];
    }

    // ------------------------------------------------------------------
    // Seller behaviour record
    // ------------------------------------------------------------------

    /**
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $energySeries
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $feeSeries
     * @return array<int, string>
     */
    private function contractFacts(array $energySeries, array $feeSeries): array
    {
        return array_values(array_filter([
            $this->changeFact($energySeries, 'Energianhintaa', 'Energianhinta'),
            $this->latestChangeFact($energySeries),
            $this->monthlyFeeFact($feeSeries),
        ]));
    }

    /**
     * @param  array<int, array{start: \Carbon\Carbon, end: \Carbon\Carbon, label: string, title: string}>  $buckets
     * @param  array<int, float>  $values
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $energySeries
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $feeSeries
     * @return array<int, string>
     */
    private function spotFacts(array $buckets, array $values, array $energySeries, array $feeSeries, ?float $margin): array
    {
        $facts = [];

        $cheapest = array_keys($values, min($values), true)[0];
        $priciest = array_keys($values, max($values), true)[0];

        $facts[] = 'Halvin kuukausi: '.self::MONTH_NAME[$buckets[$cheapest]['start']->month]
            .' '.$this->cents($values[$cheapest]).' c/kWh';
        $facts[] = 'Kallein kuukausi: '.self::MONTH_NAME[$buckets[$priciest]['start']->month]
            .' '.$this->cents($values[$priciest]).' c/kWh';

        // Only claim something about "the margin" when the calculated payload
        // agrees that the tracked component really is the margin. On a
        // promo-then-spot contract the tracked `General` row is an intro price.
        $latest = $energySeries['points'] === [] ? null : end($energySeries['points'])['value'];
        $marginIsTracked = $margin !== null && $latest !== null && abs($latest - $margin) < 0.005;

        if ($marginIsTracked) {
            $facts[] = $this->changeFact($energySeries, 'Marginaalia', 'Marginaali');
            $facts[] = $this->latestChangeFact($energySeries);
        }

        $facts[] = $this->monthlyFeeFact($feeSeries);

        return array_values(array_filter($facts));
    }

    /**
     * How the seller has moved this price inside the observation window. Always
     * a count and a direction, never a percentage.
     *
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $series
     */
    private function changeFact(array $series, string $partitive, string $nominative): ?string
    {
        $points = $series['points'];
        $span = $this->spanDays($series);

        // One short window is one observation, not a track record.
        if ($points === [] || $span < self::MIN_TRACKING_DAYS) {
            return null;
        }

        $changes = count($points) - 1;

        if ($changes === 0) {
            return "{$nominative} ennallaan koko seurannan ajan";
        }

        $ups = 0;
        $downs = 0;
        for ($i = 1; $i < count($points); $i++) {
            $points[$i]['value'] > $points[$i - 1]['value'] ? $ups++ : $downs++;
        }

        $verb = match (true) {
            $downs === 0 => 'korotettu',
            $ups === 0 => 'laskettu',
            default => 'muutettu',
        };

        $months = max(1, (int) round($span / 30.4));

        if ($changes === 1) {
            return "{$partitive} {$verb} kerran {$months} kuukauden seurannan aikana";
        }

        return "{$partitive} {$verb} {$changes} kertaa {$months} kuukaudessa";
    }

    /**
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $series
     */
    private function latestChangeFact(array $series): ?string
    {
        $points = $series['points'];

        if (count($points) < 2) {
            return null;
        }

        $last = end($points);
        $previous = $points[count($points) - 2];
        $delta = $last['value'] - $previous['value'];

        return 'Viimeisin muutos '.$this->signedCents($delta).' c/kWh ('.$last['date']->format('j.n.Y').')';
    }

    /**
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $series
     */
    private function monthlyFeeFact(array $series): ?string
    {
        $points = $series['points'];

        if ($points === [] || $this->spanDays($series) < self::MIN_TRACKING_DAYS) {
            return null;
        }

        if (count($points) === 1) {
            return 'Perusmaksu ennallaan koko seurannan ajan';
        }

        $changes = count($points) - 1;

        return 'Perusmaksua muutettu '.($changes === 1 ? 'kerran' : $changes.' kertaa').' (nyt '
            .number_format(end($points)['value'], 2, ',', '').' €/kk)';
    }

    // ------------------------------------------------------------------
    // Chart geometry (server-rendered SVG)
    // ------------------------------------------------------------------

    /**
     * @param  array<int, array{date: \Carbon\Carbon, value: float}>  $steppedPoints
     * @param  array<int, ?float>  $referenceByBucket
     * @param  array<int, array{start: \Carbon\Carbon, end: \Carbon\Carbon, label: string, title: string}>  $buckets
     * @param  array<int, array{date: \Carbon\Carbon, value: float}>  $linePoints
     * @return array<string, mixed>
     */
    private function buildChart(
        array $steppedPoints,
        array $referenceByBucket,
        array $buckets,
        string $seriesLabel,
        string $referenceLabel,
        string $referenceShortLabel,
        string $tooltipSeriesLabel,
        string $tooltipReferenceLabel,
        string $ariaLabel,
        array $linePoints = [],
        bool $flatReference = false,
    ): array {
        $stepped = $steppedPoints !== [];
        $points = $stepped ? $steppedPoints : $linePoints;

        $tsMin = $buckets[0]['start']->getTimestamp();
        $tsMax = end($buckets)['end']->getTimestamp();
        foreach ($points as $point) {
            $tsMin = min($tsMin, $point['date']->getTimestamp());
            $tsMax = max($tsMax, $point['date']->getTimestamp());
        }
        $tsSpan = max(1, $tsMax - $tsMin);

        $allValues = array_column($points, 'value');
        foreach ($referenceByBucket as $value) {
            if ($value !== null) {
                $allValues[] = $value;
            }
        }

        [$domainMin, $domainMax, $ticks] = $this->domain($allValues);
        $domainSpan = max(0.0001, $domainMax - $domainMin);

        $x = fn (int $ts): float => round(
            self::PLOT_LEFT + (($ts - $tsMin) / $tsSpan) * (self::PLOT_RIGHT - self::PLOT_LEFT),
            2,
        );
        $y = fn (float $value): float => round(
            self::PLOT_BOTTOM - (($value - $domainMin) / $domainSpan) * (self::PLOT_BOTTOM - self::PLOT_TOP),
            2,
        );

        // Series path.
        $seriesPoints = [];
        foreach ($points as $point) {
            $seriesPoints[] = [
                'x' => $x($point['date']->getTimestamp()),
                'y' => $y($point['value']),
                'value' => $point['value'],
                'date' => $point['date']->copy(),
            ];
        }

        $seriesPath = '';
        foreach ($seriesPoints as $index => $point) {
            if ($index === 0) {
                $seriesPath = "M{$point['x']},{$point['y']}";

                continue;
            }

            if ($stepped) {
                // A published price holds until the next observation, so the
                // line steps rather than sloping between two known prices.
                $seriesPath .= " L{$point['x']},{$seriesPoints[$index - 1]['y']}";

                // A terminal plateau point repeats the price it extends, so the
                // vertical leg would be zero length.
                if ($point['y'] === $seriesPoints[$index - 1]['y']) {
                    continue;
                }
            }

            $seriesPath .= " L{$point['x']},{$point['y']}";
        }

        // Reference path. A flat reference (one trailing-year figure) is drawn
        // straight across; a moving reference follows the bucket means.
        $referencePath = '';
        $referenceValue = null;

        if ($flatReference) {
            $referenceValue = $referenceByBucket[0] ?? null;
            if ($referenceValue !== null) {
                $referencePath = 'M'.self::PLOT_LEFT.','.$y($referenceValue)
                    .' L'.self::PLOT_RIGHT.','.$y($referenceValue);
            }
        } else {
            $started = false;
            foreach ($buckets as $index => $bucket) {
                $value = $referenceByBucket[$index] ?? null;
                if ($value === null) {
                    $started = false;

                    continue;
                }
                $referenceValue = $value;
                $referencePath .= ($started ? ' L' : 'M').$x($this->bucketCentre($bucket)).','.$y($value);
                $started = true;
            }
        }

        // Hover bands and the accessible table mirror share one row set.
        $rows = [];
        $bandCount = max(1, count($buckets));
        $bandWidth = round((self::PLOT_RIGHT - self::PLOT_LEFT) / $bandCount, 2);
        foreach ($buckets as $index => $bucket) {
            $seriesValue = $stepped
                ? $this->valueAt($points, $bucket['end'])
                : ($points[$index]['value'] ?? null);

            $rows[] = [
                'x' => round(self::PLOT_LEFT + $index * $bandWidth, 2),
                'width' => $bandWidth,
                'label' => $bucket['label'],
                'label_x' => round(self::PLOT_LEFT + ($index + 0.5) * $bandWidth, 2),
                'title' => $bucket['title'],
                'series' => $seriesValue !== null ? $this->cents($seriesValue) : null,
                'reference' => ($referenceByBucket[$index] ?? null) !== null
                    ? $this->cents($referenceByBucket[$index])
                    : null,
            ];
        }

        $lastSeries = $seriesPoints === [] ? null : end($seriesPoints);

        $seriesLabelY = $lastSeries === null ? null : $lastSeries['y'] + 4;
        $referenceLabelY = $referenceValue === null ? null : $y($referenceValue) - 6;

        // Both direct labels sit at the right edge. When the two series end
        // close together the labels overlap into unreadable soup, so push the
        // reference label clear of the contract's own.
        if ($seriesLabelY !== null && $referenceLabelY !== null && abs($seriesLabelY - $referenceLabelY) < 14) {
            $referenceLabelY = $referenceLabelY <= $seriesLabelY
                ? $seriesLabelY - 14
                : $seriesLabelY + 14;
        }

        return [
            'view_box' => '0 0 '.self::VIEW_WIDTH.' '.self::VIEW_HEIGHT,
            'plot' => [
                'left' => self::PLOT_LEFT,
                'right' => self::PLOT_RIGHT,
                'top' => self::PLOT_TOP,
                'bottom' => self::PLOT_BOTTOM,
                'width' => self::PLOT_RIGHT - self::PLOT_LEFT,
                'height' => self::PLOT_BOTTOM - self::PLOT_TOP,
            ],
            'unit' => 'c/kWh',
            'ink' => self::INK,
            'reference_colour' => self::REFERENCE,
            'series_label' => $seriesLabel,
            'reference_label' => $referenceLabel,
            'tooltip_series_label' => $tooltipSeriesLabel,
            'tooltip_reference_label' => $tooltipReferenceLabel,
            'series_path' => $seriesPath,
            'series_points' => $seriesPoints,
            // A near-daily repricer produces dozens of change points; drawing a
            // dot on each one turns the line into a smear.
            'show_points' => count($seriesPoints) <= 12,
            'series_end_label' => $lastSeries === null ? null : [
                'x' => self::PLOT_RIGHT + 8,
                'y' => $seriesLabelY,
                'text' => $this->cents($lastSeries['value']),
            ],
            'reference_path' => $referencePath,
            'reference_end_label' => $referenceValue === null ? null : [
                'x' => self::PLOT_RIGHT + 8,
                'y' => $referenceLabelY,
                'text' => $referenceShortLabel.' '.$this->cents($referenceValue),
            ],
            'y_ticks' => array_map(fn (float $tick) => [
                'y' => $y($tick),
                'label' => $this->tick($tick),
            ], $ticks),
            // A signed zero baseline only exists when the data crosses zero;
            // spot months can print negative, contract prices cannot.
            'zero_y' => $domainMin < 0 ? $y(0.0) : null,
            'rows' => $rows,
            'aria_label' => $ariaLabel,
        ];
    }

    /**
     * @param  array<int, float>  $values
     * @return array{0: float, 1: float, 2: array<int, float>}
     */
    private function domain(array $values): array
    {
        $rawMin = min($values);
        $rawMax = max($values);

        $pad = max(0.1, ($rawMax - $rawMin) * 0.15);
        $min = $rawMin - $pad;
        $max = $rawMax + $pad;

        // Zero is a real boundary for a price: never pad below it unless the
        // data itself goes negative, and always include it when it does.
        if ($rawMin >= 0 && $min < 0) {
            $min = 0.0;
        }
        if ($rawMin < 0) {
            $max = max($max, 0.0);
        }

        $step = $this->niceStep(($max - $min) / 4);
        $ticks = [];
        for ($tick = ceil($min / $step) * $step; $tick <= $max + 0.0001; $tick += $step) {
            $ticks[] = round($tick, 4);
        }

        return [$min, $max, $ticks];
    }

    private function niceStep(float $raw): float
    {
        foreach ([0.05, 0.1, 0.2, 0.25, 0.5, 1.0, 1.5, 2.0, 2.5, 5.0, 10.0, 20.0, 25.0, 50.0] as $candidate) {
            if ($raw <= $candidate) {
                return $candidate;
            }
        }

        return 100.0;
    }

    /**
     * @param  array<int, array{date: \Carbon\Carbon, value: float}>  $points
     */
    private function valueAt(array $points, Carbon $date): ?float
    {
        $value = null;
        foreach ($points as $point) {
            if ($point['date']->lte($date)) {
                $value = $point['value'];
            }
        }

        return $value ?? ($points[0]['value'] ?? null);
    }

    /**
     * @param  array{start: \Carbon\Carbon, end: \Carbon\Carbon}  $bucket
     */
    private function bucketCentre(array $bucket): int
    {
        return (int) round(($bucket['start']->getTimestamp() + $bucket['end']->getTimestamp()) / 2);
    }

    // ------------------------------------------------------------------
    // Source data
    // ------------------------------------------------------------------

    /**
     * Weekly buckets for a short window, monthly for a long one. Both series
     * and the tooltip read the same buckets, so the chart and its accessible
     * table mirror can never describe different periods.
     *
     * @return array<int, array{start: \Carbon\Carbon, end: \Carbon\Carbon, label: string, title: string}>
     */
    private function buckets(Carbon $from, Carbon $to): array
    {
        $weekly = $from->diffInDays($to) <= 84;
        $buckets = [];

        $cursor = $weekly ? $from->copy()->startOfWeek() : $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $end = $weekly ? $cursor->copy()->endOfWeek() : $cursor->copy()->endOfMonth();
            $buckets[] = [
                'start' => $cursor->copy(),
                'end' => $end,
                'label' => $weekly ? $cursor->format('j.n.') : self::MONTH_SHORT[$cursor->month],
                'title' => $weekly
                    ? 'Viikko '.$cursor->format('j.n.').'–'.$end->format('j.n.Y')
                    : ucfirst(self::MONTH_NAME[$cursor->month]).' '.$cursor->year,
            ];
            $cursor = $weekly ? $cursor->copy()->addWeek() : $cursor->copy()->addMonth();
        }

        return $buckets;
    }

    /**
     * Segment median energy price per bucket, averaged from the stored daily
     * medians the same way `/sahkosopimus/tilastot` aggregates them, so the two
     * pages cannot disagree about the same market.
     *
     * @param  array<int, array{start: \Carbon\Carbon, end: \Carbon\Carbon}>  $buckets
     * @return array<int, ?float>
     */
    private function segmentMedianByBucket(string $segmentKey, array $buckets): array
    {
        $expectedBasis = $this->pricingMode->expectedContractPriceBasis()->value;
        $latestExpectedDate = ContractPriceDailyStatistic::query()
            ->where('pricing_basis', $expectedBasis)
            ->max('stat_date');

        if ($latestExpectedDate === null) {
            return array_fill(0, count($buckets), null);
        }

        $latestExpectedDate = Carbon::parse($latestExpectedDate)->toDateString();
        $queryEndDate = min($latestExpectedDate, end($buckets)['end']->toDateString());
        $rows = ContractPriceDailyStatistic::query()
            ->where('segment_key', $segmentKey)
            ->where('metric_key', 'energy_price')
            ->whereNull('consumption_kwh')
            ->whereBetween('stat_date', [
                $buckets[0]['start']->toDateString(),
                $queryEndDate,
            ])
            ->where(function ($query) use ($expectedBasis, $latestExpectedDate): void {
                $query->whereDate('stat_date', '<', $latestExpectedDate)
                    ->orWhere(function ($current) use ($expectedBasis, $latestExpectedDate): void {
                        $current->whereDate('stat_date', $latestExpectedDate)
                            ->where('pricing_basis', $expectedBasis);
                    });
            })
            ->orderBy('stat_date')
            ->get(['stat_date', 'median_value', 'pricing_basis']);

        $byDate = [];
        foreach ($rows as $row) {
            if ($row->median_value !== null) {
                $byDate[Carbon::parse($row->stat_date)->toDateString()] = (float) $row->median_value;
            }
        }

        $out = [];
        foreach ($buckets as $bucket) {
            $values = [];
            foreach ($byDate as $date => $value) {
                if ($date >= $bucket['start']->toDateString() && $date <= $bucket['end']->toDateString()) {
                    $values[] = $value;
                }
            }
            $out[] = $values === [] ? null : $this->mean($values);
        }

        return $out;
    }

    /**
     * Completed monthly spot averages, newest last. The running month is left
     * out because its average is a partial month.
     *
     * @return array<int, array{start: \Carbon\Carbon, avg: float}>
     */
    private function completedSpotMonths(): array
    {
        $rows = SpotPriceAverage::forRegion('FI')
            ->ofType(SpotPriceAverage::PERIOD_MONTHLY)
            ->where('period_start', '<', Carbon::today()->startOfMonth()->toDateString())
            ->whereNotNull('avg_price_with_tax')
            ->orderByDesc('period_start')
            ->limit(self::SPOT_MONTHS)
            ->get(['period_start', 'avg_price_with_tax']);

        return $rows
            ->sortBy('period_start')
            ->map(fn ($row) => [
                'start' => Carbon::parse($row->period_start)->startOfMonth(),
                'avg' => (float) $row->avg_price_with_tax,
            ])
            ->values()
            ->all();
    }

    private function trailingYearAverage(): ?float
    {
        $stored = SpotPriceAverage::latestRolling365Days('FI');

        return $stored?->avg_price_with_tax !== null ? (float) $stored->avg_price_with_tax : null;
    }

    /**
     * @param  array<string, mixed>  $calculatedCost
     */
    private function marginCents(array $calculatedCost): ?float
    {
        $margin = $calculatedCost['spot_price_margin'] ?? null;

        return is_numeric($margin) ? (float) $margin : null;
    }

    /**
     * @param  array<string, array<int, array{date: string, price: float|int}>>  $priceHistory
     * @return array<string, array<string, float>> date => type => price, oldest first
     */
    private function componentsByDate(array $priceHistory): array
    {
        $byDate = [];

        foreach ($priceHistory as $type => $rows) {
            foreach ($rows as $row) {
                $date = (string) $row['date'];
                $price = (float) $row['price'];

                // The merged replacement chain can hold two rows for one
                // date/type; prefer a real price over a zero placeholder.
                if (! isset($byDate[$date][$type]) || ($byDate[$date][$type] <= 0 && $price > 0)) {
                    $byDate[$date][(string) $type] = $price;
                }
            }
        }

        ksort($byDate);

        return $byDate;
    }

    /**
     * Drop a 0,00 c/kWh energy observation from a contract that is priced above
     * zero somewhere else in the same window.
     *
     * The upstream payload can carry two `General` components for one contract,
     * both with the null UUID and the same fuse size, one holding the real
     * energy price and one holding a zero placeholder. They resolve to a single
     * relational key, and before `CanonicalPriceComponentWriter` learned to
     * collapse them deterministically the zero could win the day's upsert. The
     * surviving rows are real database rows, so nothing downstream can tell
     * them apart from a price — and every other surface on the detail page
     * already prefers a positive row, so the chart was the only place that drew
     * the artifact. It drew it as a vertical drop to zero on the last observed
     * day, and the behaviour tags then reported that drop as a price change.
     *
     * A zero is only dropped when the same component type is positive on
     * another observed date, so a genuinely zero-priced package component is
     * kept: 7 active contracts (Helen Helpposähkö, Väre Kuukausisähkö,
     * Vattenfall Ilmasto Vakio) charge nothing per kWh and price at zero on
     * every single observed date, which this rule never touches. What it
     * excludes is the mixed shape — positive for months, then exactly zero —
     * and no seller has ever published that; it is the collision artifact.
     *
     * Two deliberate exclusions:
     *
     * - **Spot contracts are skipped entirely** (the caller decides). Their
     *   tracked component is the margin, and a 0 c/kWh margin is a real
     *   commercial position a seller can move to and back.
     * - **`Monthly` is skipped.** Dropping a base fee to 0 €/kk is an ordinary
     *   seller move, and the fee series has to be able to show it.
     *
     * This is a display guard, not a repair. The stored rows stay wrong until
     * they are rebuilt from the immutable source snapshots.
     *
     * @param  array<string, array<string, float>>  $byDate
     * @return array<string, array<string, float>>
     */
    private function withoutCollidedZeroEnergyPrices(array $byDate): array
    {
        $pricedTypes = [];

        foreach ($byDate as $components) {
            foreach ($components as $type => $price) {
                if ($type !== self::FEE_TYPE && $price > 0) {
                    $pricedTypes[$type] = true;
                }
            }
        }

        if ($pricedTypes === []) {
            return $byDate;
        }

        foreach ($byDate as $date => $components) {
            foreach ($components as $type => $price) {
                if ($price <= 0 && isset($pricedTypes[$type])) {
                    unset($byDate[$date][$type]);
                }
            }

            if ($byDate[$date] === []) {
                unset($byDate[$date]);
            }
        }

        return $byDate;
    }

    /**
     * Collapse a daily observation series into its actual changes. Voltikka
     * imports every contract every day, so the raw rows are mostly repeats.
     *
     * The last observation date is returned beside the change points instead of
     * being appended as a point. A price that has held for months is one
     * observation window, not a second change, and counting it as one made the
     * behaviour record claim a change that never happened.
     *
     * @param  array<string, array<string, float>>  $byDate
     * @param  callable(array<string, float>): ?float  $resolve
     * @return array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}
     */
    private function changeSeries(array $byDate, callable $resolve): array
    {
        $points = [];
        $previous = null;
        $lastDate = null;

        foreach ($byDate as $date => $components) {
            $value = $resolve($components);

            if ($value === null) {
                continue;
            }

            if ($previous === null || abs($value - $previous) > 0.0001) {
                $points[] = ['date' => Carbon::parse($date)->startOfDay(), 'value' => $value];
                $previous = $value;
            }

            $lastDate = Carbon::parse($date)->startOfDay();
        }

        return ['points' => $points, 'last_date' => $lastDate];
    }

    /**
     * Change points plus a terminal point at the last observation, so a price
     * that has held for months is drawn as the plateau it is.
     *
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $series
     * @return array<int, array{date: \Carbon\Carbon, value: float}>
     */
    private function plateauPoints(array $series): array
    {
        $points = $series['points'];

        if ($points === [] || $series['last_date'] === null) {
            return $points;
        }

        $last = end($points);

        if ($last['date']->toDateString() !== $series['last_date']->toDateString()) {
            $points[] = ['date' => $series['last_date']->copy(), 'value' => $last['value']];
        }

        return $points;
    }

    /**
     * The same weighting `ContractPriceStatisticsService::representativeEnergyPrice()`
     * uses, so the ink line and the dashed segment median are one basis. A
     * time-metered contract charted on DayTime alone would sit above a median
     * that blends day and night.
     *
     * @param  array<string, float>  $components
     */
    private function representativeEnergy(array $components, bool $isSpot): ?float
    {
        if ($isSpot) {
            foreach ($components as $type => $price) {
                if ($type !== self::FEE_TYPE) {
                    return $price;
                }
            }

            return null;
        }

        if (isset($components['General'])) {
            return $components['General'];
        }

        if (isset($components['DayTime']) || isset($components['NightTime'])) {
            $day = $components['DayTime'] ?? ($components['NightTime'] ?? 0.0);
            $night = $components['NightTime'] ?? $day;

            return ($day * 15 + $night * 9) / 24;
        }

        if (isset($components['SeasonalWinterDay']) || isset($components['SeasonalWinter']) || isset($components['SeasonalOther'])) {
            $winter = $components['SeasonalWinterDay'] ?? ($components['SeasonalWinter'] ?? ($components['SeasonalOther'] ?? 0.0));
            $other = $components['SeasonalOther'] ?? $winter;

            return ($winter * 5 + $other * 7) / 12;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /**
     * Length of the observation window, first observation to last, regardless
     * of how many changes happened inside it.
     *
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $series
     */
    private function spanDays(array $series): int
    {
        if ($series['points'] === [] || $series['last_date'] === null) {
            return 0;
        }

        return (int) $series['points'][0]['date']->diffInDays($series['last_date']);
    }

    /**
     * @param  array{points: array<int, array{date: \Carbon\Carbon, value: float}>, last_date: ?\Carbon\Carbon}  $series
     */
    private function shortHistoryMessage(array $series, int $span): string
    {
        $points = $series['points'];

        if ($points === []) {
            return 'Tälle sopimukselle ei ole vielä tallennettuja hintahavaintoja, joten hintakehitystä ei voi näyttää.';
        }

        if ($span === 0) {
            return 'Voltikka on havainnut tämän sopimuksen hinnan vain kerran ('
                .$points[0]['date']->format('j.n.Y').'), joten hintakehitystä ei vielä näytetä.';
        }

        return 'Hintaa on seurattu vasta '.$span.' päivän ajan ('.$points[0]['date']->format('j.n.Y')
            .' alkaen), joten hintakehitystä ei vielä näytetä.';
    }

    private function trackedSinceSentence(?Carbon $trackedSince): string
    {
        return $trackedSince === null
            ? 'Hintahistoriaa ei ole vielä kertynyt'
            : 'seurattu '.$trackedSince->format('j.n.Y').' alkaen';
    }

    /**
     * @param  array<int, float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    private function cents(float $value): string
    {
        return number_format($value, 2, ',', '');
    }

    private function signedCents(float $value): string
    {
        return ($value > 0 ? '+' : '').number_format($value, 2, ',', '');
    }

    private function tick(float $value): string
    {
        return number_format($value, abs($value - round($value)) < 0.001 ? 0 : 1, ',', '');
    }
}
