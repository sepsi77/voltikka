<?php

namespace App\Services\CanonicalPricing\MarketReset;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\SpotPriceAverage;
use App\Services\PriceForecasting\FixedTermHedgeCostService;
use App\Services\RetailPremium\VintageAwareReferencePriceService;
use Carbon\CarbonImmutable;

/**
 * Reads the FI EEX Base forward curve, the realized spot seasonal index, and the fixed-term
 * retail median for the market-reset estimator.
 *
 * Important properties, do not change casually:
 *
 * - **Vintage per lookup.** One ordered scalar query loads the available FI Base trade dates for
 *   this provider lifetime. Every lookup then resolves the latest date strictly before its own
 *   `$asOfDate` in memory. The caller decides the anchor: today for the forward months `F_m`, the
 *   current period's start for `F_reference`.
 * - **One curve query per vintage, not per month.** A listing rebuild costs 32 reset contracts x
 *   12 months of lookups; without the memoized curve that is hundreds of queries per page build.
 * - The reference-period lookup delegates to the retail-premium service's exact-vintage boundary,
 *   so the `quarter` / `quarter_month_average` candidate logic lives in exactly one place.
 * - The month -> quarter -> year ladder mirrors `FixedTermHedgeCostService`, which must not be
 *   refactored: it runs on the production 07:30 schedule and feeds immutable stored forecasts.
 */
class EexMarketReferenceCurveProvider implements MarketReferenceCurveProvider
{
    /** @var list<string>|null */
    private ?array $availableTradeDatesMemo = null;

    /** @var array<string, CarbonImmutable|null> */
    private array $tradeDateMemo = [];

    /** @var array<string, array<string, float>> */
    private array $curveMemo = [];

    /** @var array<string, array{kind: string, price_cents_per_kwh: float}|null> */
    private array $referenceMemo = [];

    /** @var array<int, float>|null|false */
    private array|null|false $seasonalIndexMemo = false;

    private float|null|false $fixedTermMedianMemo = false;

    public function __construct(
        private readonly FixedTermHedgeCostService $hedgeCostService,
        private readonly VintageAwareReferencePriceService $referencePriceService,
    ) {}

    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        $key = $asOfDate->toDateString();

        if (array_key_exists($key, $this->tradeDateMemo)) {
            return $this->tradeDateMemo[$key];
        }

        $availableTradeDates = $this->availableTradeDates();

        for ($index = count($availableTradeDates) - 1; $index >= 0; $index--) {
            if ($availableTradeDates[$index] < $key) {
                return $this->tradeDateMemo[$key] = CarbonImmutable::parse($availableTradeDates[$index])->startOfDay();
            }
        }

        return $this->tradeDateMemo[$key] = null;
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        $key = $asOfDate->toDateString().'|'.$anchorMonth->format('Y-m').'|'.implode(',', $kindPreference);

        if (array_key_exists($key, $this->referenceMemo)) {
            return $this->referenceMemo[$key];
        }

        $tradeDate = $this->tradeDate($asOfDate);

        if ($tradeDate === null) {
            return $this->referenceMemo[$key] = null;
        }

        $candidates = $this->referencePriceService->forResetPeriodAtTradeDate(
            $tradeDate,
            $anchorMonth,
            $kindPreference,
        );
        $resolved = null;

        foreach ($kindPreference as $kind) {
            $candidate = $candidates->get($kind);
            $price = $candidate['price_cents_per_kwh_including_vat'] ?? null;

            if (is_numeric($price)) {
                $resolved = [
                    'kind' => $kind,
                    'price_cents_per_kwh' => (float) $price,
                    'trade_date' => (string) ($candidate['trade_date'] ?? ''),
                ];
                break;
            }
        }

        return $this->referenceMemo[$key] = $resolved;
    }

    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array
    {
        $tradeDate = $this->tradeDate($asOfDate);

        if ($tradeDate === null) {
            return null;
        }

        $curve = $this->curve($tradeDate);
        $month = $deliveryMonth->startOfMonth();

        foreach (['month', 'quarter', 'year'] as $kind) {
            $key = $kind.'|'.$this->hedgeCostService->maturityForMonth($month, $kind);

            if (array_key_exists($key, $curve)) {
                return [
                    'kind' => $kind,
                    'price_cents_per_kwh' => $curve[$key] / 10.0 * $this->vatMultiplier(),
                ];
            }
        }

        return null;
    }

    public function spotSeasonalIndex(): ?array
    {
        if ($this->seasonalIndexMemo !== false) {
            return $this->seasonalIndexMemo;
        }

        $config = (array) config('canonical_pricing.reset_forward_shift.seasonal_index', []);
        $lookbackYears = (int) ($config['lookback_years'] ?? 4);
        $minYears = max(1, (int) ($config['min_years_per_month'] ?? 2));

        $latest = SpotPriceAverage::query()
            ->where('region', 'FI')
            ->where('period_type', SpotPriceAverage::PERIOD_MONTHLY)
            ->max('period_start');

        if ($latest === null) {
            return $this->seasonalIndexMemo = null;
        }

        $from = CarbonImmutable::parse($latest)->startOfMonth()->subYears($lookbackYears);

        $rows = SpotPriceAverage::query()
            ->where('region', 'FI')
            ->where('period_type', SpotPriceAverage::PERIOD_MONTHLY)
            ->where('period_start', '>=', $from->toDateString())
            ->orderBy('period_start')
            ->get(['period_start', 'avg_price_with_tax']);

        // Group by calendar year, then normalise each month by that year's own mean of the
        // months present. Normalising per year is what removes the price level and leaves the
        // seasonal shape; it also means an incomplete year still contributes usable ratios.
        $byYear = [];
        foreach ($rows as $row) {
            $price = (float) $row->avg_price_with_tax;
            if ($price <= 0) {
                continue;
            }
            $start = CarbonImmutable::parse($row->period_start);
            $byYear[(int) $start->year][(int) $start->month] = $price;
        }

        $ratios = [];
        foreach ($byYear as $months) {
            if (count($months) < 6) {
                continue; // too few months to define that year's level
            }
            $yearMean = array_sum($months) / count($months);
            if ($yearMean <= 0) {
                continue;
            }
            foreach ($months as $month => $price) {
                $ratios[$month][] = $price / $yearMean;
            }
        }

        $index = [];
        foreach ($ratios as $month => $values) {
            if (count($values) < $minYears) {
                continue;
            }
            $index[$month] = array_sum($values) / count($values);
        }

        if (count($index) < 12) {
            return $this->seasonalIndexMemo = null;
        }

        ksort($index);

        return $this->seasonalIndexMemo = $index;
    }

    /**
     * **Reported context only.** It must never gate an estimate: banding a reset's annual
     * equivalent to a multiple of this median would encode the prior that a market-reset product
     * has to be cheaper than a fixed deal. See `MarketResetPriceEstimator::isPlausible()`.
     */
    public function fixedTermMedianEnergyPrice(): ?float
    {
        if ($this->fixedTermMedianMemo !== false) {
            return $this->fixedTermMedianMemo;
        }

        $segment = (string) config('canonical_pricing.reset_forward_shift.context_fixed_term_segment_key', 'fixed_term_12');

        $row = ContractPriceDailyStatistic::query()
            ->where('segment_key', $segment)
            ->where('metric_key', 'energy_price')
            ->whereNull('consumption_kwh')
            ->orderByDesc('stat_date')
            ->first(['median_value', 'avg_value']);

        $value = $row?->median_value ?? $row?->avg_value;

        return $this->fixedTermMedianMemo = is_numeric($value) ? (float) $value : null;
    }

    /** @return list<string> */
    private function availableTradeDates(): array
    {
        return $this->availableTradeDatesMemo ??= ElectricityFuturesEodPrice::query()
            ->where('area', config('price_forecasting.fixed_term.area', 'FI'))
            ->where('product', 'Base')
            ->select('trade_date')
            ->distinct()
            ->orderBy('trade_date')
            ->pluck('trade_date')
            ->map(fn ($tradeDate) => CarbonImmutable::parse($tradeDate)->toDateString())
            ->all();
    }

    /**
     * @return array<string, float> `maturity_type|maturity` => settlement price (EUR/MWh)
     */
    private function curve(CarbonImmutable $tradeDate): array
    {
        $key = $tradeDate->toDateString();
        $nextDate = $tradeDate->addDay()->toDateString();

        return $this->curveMemo[$key] ??= ElectricityFuturesEodPrice::query()
            ->where('area', config('price_forecasting.fixed_term.area', 'FI'))
            ->where('product', 'Base')
            ->where('trade_date', '>=', $key)
            ->where('trade_date', '<', $nextDate)
            ->whereIn('maturity_type', ['month', 'quarter', 'year'])
            ->get(['maturity_type', 'maturity', 'settlement_price'])
            ->mapWithKeys(fn (ElectricityFuturesEodPrice $price) => [
                $price->maturity_type.'|'.$price->maturity => (float) $price->settlement_price,
            ])
            ->all();
    }

    private function vatMultiplier(): float
    {
        return (float) config('price_forecasting.fixed_term.vat_multiplier', 1.255);
    }
}
