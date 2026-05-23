<?php

namespace App\Services\PriceForecasting;

use App\Models\ElectricityFuturesEodPrice;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class FixedTermHedgeCostService
{
    public function calculate(CarbonInterface $asOfDate, int $durationMonths): ?array
    {
        $asOf = CarbonImmutable::instance($asOfDate)->startOfDay();
        $tradeDate = $this->latestTradeDateBefore($asOf);

        if ($tradeDate === null) {
            return null;
        }

        $curve = $this->loadCurve($tradeDate);
        $deliveryStart = $asOf->startOfMonth()->addMonth();
        $weightedSettlementPrice = 0.0;
        $totalWeight = 0;
        $coverageCounts = [
            'month' => 0,
            'quarter' => 0,
            'year' => 0,
        ];
        $missingMonths = [];

        for ($offset = 0; $offset < $durationMonths; $offset++) {
            $deliveryMonth = $deliveryStart->addMonths($offset);
            $weight = $deliveryMonth->daysInMonth;
            $totalWeight += $weight;

            $selectedType = null;
            $selectedPrice = null;

            foreach (['month', 'quarter', 'year'] as $maturityType) {
                $key = $maturityType.'|'.$this->maturityForMonth($deliveryMonth, $maturityType);

                if (array_key_exists($key, $curve)) {
                    $selectedType = $maturityType;
                    $selectedPrice = $curve[$key];
                    break;
                }
            }

            if ($selectedPrice === null) {
                $missingMonths[] = $deliveryMonth->format('Y-m');

                continue;
            }

            $coverageCounts[$selectedType]++;
            $weightedSettlementPrice += $selectedPrice * $weight;
        }

        if ($totalWeight === 0 || $missingMonths !== []) {
            return [
                'price_cents_per_kwh' => null,
                'trade_date' => $tradeDate->toDateString(),
                'coverage_quality' => 'partial_missing',
                'monthly_futures_months' => $coverageCounts['month'],
                'quarter_futures_months' => $coverageCounts['quarter'],
                'year_futures_months' => $coverageCounts['year'],
                'missing_delivery_months' => $missingMonths,
                'delivery_start_month' => $deliveryStart->toDateString(),
                'delivery_end_month' => $deliveryStart->addMonths($durationMonths - 1)->toDateString(),
            ];
        }

        $averageEurPerMwh = $weightedSettlementPrice / $totalWeight;
        $vatMultiplier = (float) config('price_forecasting.fixed_term.vat_multiplier', 1.255);

        return [
            'price_cents_per_kwh' => $averageEurPerMwh / 10.0 * $vatMultiplier,
            'trade_date' => $tradeDate->toDateString(),
            'coverage_quality' => $this->coverageQuality($coverageCounts),
            'monthly_futures_months' => $coverageCounts['month'],
            'quarter_futures_months' => $coverageCounts['quarter'],
            'year_futures_months' => $coverageCounts['year'],
            'missing_delivery_months' => $missingMonths,
            'delivery_start_month' => $deliveryStart->toDateString(),
            'delivery_end_month' => $deliveryStart->addMonths($durationMonths - 1)->toDateString(),
        ];
    }

    public function latestTradeDateBefore(CarbonInterface $asOfDate): ?CarbonImmutable
    {
        $tradeDate = ElectricityFuturesEodPrice::query()
            ->where('area', config('price_forecasting.fixed_term.area', 'FI'))
            ->where('product', 'Base')
            ->whereDate('trade_date', '<', CarbonImmutable::instance($asOfDate)->toDateString())
            ->max('trade_date');

        return $tradeDate ? CarbonImmutable::parse($tradeDate)->startOfDay() : null;
    }

    public function maturityForMonth(CarbonInterface $deliveryMonth, string $maturityType): string
    {
        $month = CarbonImmutable::instance($deliveryMonth)->startOfMonth();

        return match ($maturityType) {
            'month' => $month->format('Ym'),
            'quarter' => $month->month(((int) floor(($month->month - 1) / 3)) * 3 + 1)->format('Ym'),
            'year' => $month->month(1)->format('Ym'),
            default => throw new \InvalidArgumentException("Unsupported maturity type [{$maturityType}]."),
        };
    }

    private function loadCurve(CarbonInterface $tradeDate): array
    {
        return ElectricityFuturesEodPrice::query()
            ->where('area', config('price_forecasting.fixed_term.area', 'FI'))
            ->where('product', 'Base')
            ->whereDate('trade_date', CarbonImmutable::instance($tradeDate)->toDateString())
            ->whereIn('maturity_type', ['month', 'quarter', 'year'])
            ->get(['maturity_type', 'maturity', 'settlement_price'])
            ->mapWithKeys(fn (ElectricityFuturesEodPrice $price) => [
                $price->maturity_type.'|'.$price->maturity => (float) $price->settlement_price,
            ])
            ->all();
    }

    private function coverageQuality(array $coverageCounts): string
    {
        if ($coverageCounts['year'] > 0) {
            return 'mixed_with_year_fallback';
        }

        if ($coverageCounts['quarter'] > 0) {
            return 'mixed_with_quarter_fallback';
        }

        return 'all_monthly';
    }
}
