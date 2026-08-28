<?php

namespace App\Services\RetailPremium;

use App\Models\ElectricityFuturesEodPrice;
use App\Services\PriceForecasting\FixedTermHedgeCostService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class VintageAwareReferencePriceService
{
    public function __construct(private readonly FixedTermHedgeCostService $fixedTermHedgeCostService) {}

    /**
     * Get every available reference for the delivery period a market reset price applies to.
     *
     * A quarterly reset price is set before the period starts, so the quarter contract normally
     * still exists at that vintage and a plain lookup works. `quarter_month_average` is stored
     * beside it as a separate candidate: it is the day-weighted average of the three month
     * contracts of the same quarter, which is the only quarter-shaped reference left once the
     * quarter has entered delivery (a mid-period re-anchoring vintage).
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function forResetPeriod(CarbonInterface $asOfDate, CarbonInterface $periodAnchor): Collection
    {
        $tradeDate = $this->fixedTermHedgeCostService->latestTradeDateBefore($asOfDate);

        if ($tradeDate === null) {
            return collect();
        }

        return $this->forResetPeriodAtTradeDate($tradeDate, $periodAnchor);
    }

    /**
     * Get requested reset-period references at an already-resolved exact vintage.
     *
     * @param  list<string>  $kinds
     * @return Collection<string, array<string, mixed>>
     */
    public function forResetPeriodAtTradeDate(
        CarbonInterface $tradeDate,
        CarbonInterface $periodAnchor,
        array $kinds = ['month', 'quarter', 'year', 'quarter_month_average'],
    ): Collection {
        $references = $this->forDeliveryMonthAtTradeDate($tradeDate, $periodAnchor, $kinds);

        if (! in_array('quarter_month_average', $kinds, true)) {
            return $references;
        }

        $derivedQuarter = $this->quarterFromMonthFutures($tradeDate, $periodAnchor);

        if ($derivedQuarter !== null) {
            $references->put('quarter_month_average', $derivedQuarter);
        }

        return $references;
    }

    /**
     * Get each available month, quarter, and year reference for one delivery month.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function forDeliveryMonth(CarbonInterface $asOfDate, CarbonInterface $deliveryMonth): Collection
    {
        $tradeDate = $this->fixedTermHedgeCostService->latestTradeDateBefore($asOfDate);

        if ($tradeDate === null) {
            return collect();
        }

        return $this->forDeliveryMonthAtTradeDate($tradeDate, $deliveryMonth);
    }

    /**
     * Get requested delivery-month references at an already-resolved exact vintage.
     *
     * @param  list<string>  $kinds
     * @return Collection<string, array<string, mixed>>
     */
    public function forDeliveryMonthAtTradeDate(
        CarbonInterface $tradeDate,
        CarbonInterface $deliveryMonth,
        array $kinds = ['month', 'quarter', 'year'],
    ): Collection {
        $exactTradeDate = CarbonImmutable::instance($tradeDate)->startOfDay();
        $nextTradeDate = $exactTradeDate->addDay();
        $delivery = CarbonImmutable::instance($deliveryMonth)->startOfMonth();
        $maturities = collect(['month', 'quarter', 'year'])
            ->filter(fn (string $kind) => in_array($kind, $kinds, true))
            ->mapWithKeys(fn (string $kind) => [
                $kind => $this->fixedTermHedgeCostService->maturityForMonth($delivery, $kind),
            ]);

        if ($maturities->isEmpty()) {
            return collect();
        }

        $prices = ElectricityFuturesEodPrice::query()
            ->where('area', config('price_forecasting.fixed_term.area', 'FI'))
            ->where('product', 'Base')
            ->where('trade_date', '>=', $exactTradeDate->toDateString())
            ->where('trade_date', '<', $nextTradeDate->toDateString())
            ->where(function ($query) use ($maturities) {
                foreach ($maturities as $kind => $maturity) {
                    $query->orWhere(fn ($candidate) => $candidate
                        ->where('maturity_type', $kind)
                        ->where('maturity', $maturity));
                }
            })
            ->get()
            ->keyBy(fn (ElectricityFuturesEodPrice $price) => $price->maturity_type.'|'.$price->maturity);

        return $maturities->mapWithKeys(function (string $maturity, string $kind) use ($prices, $exactTradeDate, $delivery) {
            $price = $prices->get($kind.'|'.$maturity);

            if ($price === null) {
                return [];
            }

            $span = $this->deliverySpan($delivery, $kind);

            return [$kind => $this->referencePayload(
                $kind,
                $exactTradeDate,
                (float) $price->settlement_price,
                [
                    'maturity' => $maturity,
                    'delivery_start_month' => $span['start']->toDateString(),
                    'delivery_end_month' => $span['end']->toDateString(),
                    'vintage_inside_delivery_period' => $exactTradeDate->gte($span['start']),
                ],
            )];
        });
    }

    /**
     * Build the quarter containing one delivery month from that quarter's month contracts.
     *
     * @return array<string, mixed>|null
     */
    private function quarterFromMonthFutures(CarbonInterface $tradeDate, CarbonInterface $deliveryMonth): ?array
    {
        $exactTradeDate = CarbonImmutable::instance($tradeDate)->startOfDay();
        $span = $this->deliverySpan(CarbonImmutable::instance($deliveryMonth)->startOfMonth(), 'quarter');
        $months = collect([0, 1, 2])->map(fn (int $offset) => $span['start']->addMonths($offset));
        $prices = ElectricityFuturesEodPrice::query()
            ->where('area', config('price_forecasting.fixed_term.area', 'FI'))
            ->where('product', 'Base')
            ->where('maturity_type', 'month')
            ->where('trade_date', '>=', $exactTradeDate->toDateString())
            ->where('trade_date', '<', $exactTradeDate->addDay()->toDateString())
            ->whereIn('maturity', $months->map(fn (CarbonImmutable $month) => $month->format('Ym'))->all())
            ->get()
            ->keyBy('maturity');

        if ($prices->count() !== 3) {
            return null;
        }

        $weighted = 0.0;
        $weights = 0;

        foreach ($months as $month) {
            $weight = $month->daysInMonth;
            $weighted += (float) $prices[$month->format('Ym')]->settlement_price * $weight;
            $weights += $weight;
        }

        return $this->referencePayload(
            'quarter_month_average',
            $tradeDate,
            $weighted / $weights,
            [
                'derivation' => 'day_weighted_month_futures_average',
                'month_maturities' => $months->map(fn (CarbonImmutable $month) => $month->format('Ym'))->all(),
                'month_settlement_prices_eur_per_mwh' => $months
                    ->mapWithKeys(fn (CarbonImmutable $month) => [
                        $month->format('Ym') => (float) $prices[$month->format('Ym')]->settlement_price,
                    ])
                    ->all(),
                'delivery_start_month' => $span['start']->toDateString(),
                'delivery_end_month' => $span['end']->toDateString(),
                'vintage_inside_delivery_period' => CarbonImmutable::instance($tradeDate)->gte($span['start']),
            ],
        );
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function deliverySpan(CarbonImmutable $deliveryMonth, string $kind): array
    {
        return match ($kind) {
            'quarter', 'quarter_month_average' => [
                'start' => $deliveryMonth->month(((int) floor(($deliveryMonth->month - 1) / 3)) * 3 + 1),
                'end' => $deliveryMonth->month(((int) floor(($deliveryMonth->month - 1) / 3)) * 3 + 3),
            ],
            'year' => [
                'start' => $deliveryMonth->month(1),
                'end' => $deliveryMonth->month(12),
            ],
            default => ['start' => $deliveryMonth, 'end' => $deliveryMonth],
        };
    }

    /**
     * Get pure-tenor strip candidates and the existing mixed-fallback term strip.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function forDeliveryTerm(CarbonInterface $asOfDate, int $durationMonths): Collection
    {
        $asOf = CarbonImmutable::instance($asOfDate)->startOfDay();
        $tradeDate = $this->fixedTermHedgeCostService->latestTradeDateBefore($asOf);

        if ($tradeDate === null || $durationMonths < 1) {
            return collect();
        }

        $deliveryStart = $asOf->startOfMonth()->addMonth();
        $exactTradeDate = CarbonImmutable::instance($tradeDate)->startOfDay();
        $curve = ElectricityFuturesEodPrice::query()
            ->where('area', config('price_forecasting.fixed_term.area', 'FI'))
            ->where('product', 'Base')
            ->where('trade_date', '>=', $exactTradeDate->toDateString())
            ->where('trade_date', '<', $exactTradeDate->addDay()->toDateString())
            ->whereIn('maturity_type', ['month', 'quarter', 'year'])
            ->get()
            ->keyBy(fn (ElectricityFuturesEodPrice $price) => $price->maturity_type.'|'.$price->maturity);
        $weighted = ['month' => 0.0, 'quarter' => 0.0, 'year' => 0.0];
        $weights = ['month' => 0, 'quarter' => 0, 'year' => 0];
        $missing = ['month' => [], 'quarter' => [], 'year' => []];

        for ($offset = 0; $offset < $durationMonths; $offset++) {
            $month = $deliveryStart->addMonths($offset);
            $weight = $month->daysInMonth;

            foreach (array_keys($weighted) as $kind) {
                $maturity = $this->fixedTermHedgeCostService->maturityForMonth($month, $kind);
                $price = $curve->get($kind.'|'.$maturity);

                if ($price === null) {
                    $missing[$kind][] = $month->format('Y-m');

                    continue;
                }

                $weighted[$kind] += (float) $price->settlement_price * $weight;
                $weights[$kind] += $weight;
            }
        }

        $references = collect();

        foreach (array_keys($weighted) as $kind) {
            if ($missing[$kind] !== [] || $weights[$kind] === 0) {
                continue;
            }

            $references->put($kind, $this->referencePayload(
                $kind,
                $tradeDate,
                $weighted[$kind] / $weights[$kind],
                [
                    'delivery_start_month' => $deliveryStart->toDateString(),
                    'delivery_end_month' => $deliveryStart->addMonths($durationMonths - 1)->toDateString(),
                    'duration_months' => $durationMonths,
                    'coverage_quality' => 'all_'.$kind,
                ],
            ));
        }

        $termStrip = $this->fixedTermHedgeCostService->calculate($asOf, $durationMonths);

        if (is_array($termStrip) && is_numeric($termStrip['price_cents_per_kwh'] ?? null)) {
            $includedPrice = (float) $termStrip['price_cents_per_kwh'];
            $vatMultiplier = (float) config('price_forecasting.fixed_term.vat_multiplier', 1.255);
            $references->put('term_strip', [
                'reference_kind' => 'term_strip',
                'trade_date' => $termStrip['trade_date'],
                'price_cents_per_kwh_including_vat' => $includedPrice,
                'price_cents_per_kwh_excluding_vat' => $includedPrice / $vatMultiplier,
                'settlement_price_eur_per_mwh' => null,
                'metadata' => $termStrip,
            ]);
        }

        return $references;
    }

    public function priceForVatBasis(array $reference, string $vatBasis): ?float
    {
        $value = match ($vatBasis) {
            'included' => $reference['price_cents_per_kwh_including_vat'] ?? null,
            'excluded' => $reference['price_cents_per_kwh_excluding_vat'] ?? null,
            default => null,
        };

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function referencePayload(
        string $kind,
        CarbonInterface $tradeDate,
        float $settlementPriceEurPerMwh,
        array $metadata,
    ): array {
        $excludingVat = $settlementPriceEurPerMwh / 10.0;
        $vatMultiplier = (float) config('price_forecasting.fixed_term.vat_multiplier', 1.255);

        return [
            'reference_kind' => $kind,
            'trade_date' => CarbonImmutable::instance($tradeDate)->toDateString(),
            'price_cents_per_kwh_including_vat' => $excludingVat * $vatMultiplier,
            'price_cents_per_kwh_excluding_vat' => $excludingVat,
            'settlement_price_eur_per_mwh' => $settlementPriceEurPerMwh,
            'metadata' => $metadata,
        ];
    }
}
