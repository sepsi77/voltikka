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

        $delivery = CarbonImmutable::instance($deliveryMonth)->startOfMonth();
        $maturities = collect(['month', 'quarter', 'year'])
            ->mapWithKeys(fn (string $kind) => [
                $kind => $this->fixedTermHedgeCostService->maturityForMonth($delivery, $kind),
            ]);
        $prices = ElectricityFuturesEodPrice::query()
            ->where('area', config('price_forecasting.fixed_term.area', 'FI'))
            ->where('product', 'Base')
            ->whereDate('trade_date', $tradeDate->toDateString())
            ->where(function ($query) use ($maturities) {
                foreach ($maturities as $kind => $maturity) {
                    $query->orWhere(fn ($candidate) => $candidate
                        ->where('maturity_type', $kind)
                        ->where('maturity', $maturity));
                }
            })
            ->get()
            ->keyBy(fn (ElectricityFuturesEodPrice $price) => $price->maturity_type.'|'.$price->maturity);

        return $maturities->mapWithKeys(function (string $maturity, string $kind) use ($prices, $tradeDate, $delivery) {
            $price = $prices->get($kind.'|'.$maturity);

            if ($price === null) {
                return [];
            }

            return [$kind => $this->referencePayload(
                $kind,
                $tradeDate,
                (float) $price->settlement_price,
                [
                    'maturity' => $maturity,
                    'delivery_start_month' => $delivery->toDateString(),
                    'delivery_end_month' => $delivery->toDateString(),
                ],
            )];
        });
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
        $curve = ElectricityFuturesEodPrice::query()
            ->where('area', config('price_forecasting.fixed_term.area', 'FI'))
            ->where('product', 'Base')
            ->whereDate('trade_date', $tradeDate->toDateString())
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
