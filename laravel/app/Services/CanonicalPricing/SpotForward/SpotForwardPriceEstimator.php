<?php

namespace App\Services\CanonicalPricing\SpotForward;

use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\SpotForward\DTO\SpotEstimate;
use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;
use Carbon\CarbonImmutable;

class SpotForwardPriceEstimator
{
    public function __construct(
        private readonly MarketReferenceCurveProvider $curve,
        private readonly ResetEstimatorSettings $settings = new ResetEstimatorSettings,
    ) {}

    public function estimate(CarbonImmutable $windowStart, SpotAssumptions $shape): SpotEstimate
    {
        $windowStart = $windowStart->setTimezone('Europe/Helsinki')->startOfDay();
        $shapeError = $this->shapeError($shape, $windowStart);

        if ($shapeError !== null) {
            return $this->fallback($shape, [$shapeError]);
        }

        $currentAsOf = $windowStart->startOfMonth();
        $currentTradeDate = $this->curve->tradeDate($currentAsOf);
        if ($currentTradeDate === null) {
            return $this->fallback($shape, ['missing_current_curve_vintage']);
        }
        if ($this->isStale($currentTradeDate, $currentAsOf)) {
            return $this->fallback($shape, ['stale_current_curve_vintage']);
        }

        $futureTradeDate = $this->curve->tradeDate($windowStart);
        if ($futureTradeDate === null) {
            return $this->fallback($shape, ['missing_future_curve_vintage']);
        }
        if ($this->isStale($futureTradeDate, $windowStart)) {
            return $this->fallback($shape, ['stale_future_curve_vintage']);
        }

        $dayOffset = (float) $shape->dayAvgWithTax - (float) $shape->overallAvgWithTax;
        $nightOffset = (float) $shape->nightAvgWithTax - (float) $shape->overallAvgWithTax;
        $windowEnd = $windowStart->addYear();
        $month = $windowStart->startOfMonth();
        $months = [];
        $flags = [];
        $weightedBase = 0.0;
        $weightedDay = 0.0;
        $weightedNight = 0.0;
        $totalDays = 0;

        while ($month->lessThan($windowEnd)) {
            $monthEnd = $month->addMonthNoOverflow();
            $sliceStart = $month->greaterThan($windowStart) ? $month : $windowStart;
            $sliceEnd = $monthEnd->lessThan($windowEnd) ? $monthEnd : $windowEnd;
            $days = $sliceStart->diffInDays($sliceEnd);
            $asOf = $month->equalTo($windowStart->startOfMonth()) ? $currentAsOf : $windowStart;
            $forward = $this->curve->forwardPriceForMonth($asOf, $month);

            if ($forward === null || ! isset($forward['kind'], $forward['price_cents_per_kwh'])
                || ! is_string($forward['kind']) || ! is_numeric($forward['price_cents_per_kwh'])
                || ! is_finite((float) $forward['price_cents_per_kwh'])) {
                return $this->fallback($shape, ['missing_forward_month_'.$month->format('Y_m')]);
            }

            $base = (float) $forward['price_cents_per_kwh'];
            $day = max(0.0, $base + $dayOffset);
            $night = max(0.0, $base + $nightOffset);
            $tradeDate = $month->equalTo($windowStart->startOfMonth()) ? $currentTradeDate : $futureTradeDate;
            $key = $month->format('Y-m');
            $months[$key] = [
                'base_price' => $base,
                'day_price' => $day,
                'night_price' => $night,
                'source_kind' => $forward['kind'],
                'trade_date' => $tradeDate->toDateString(),
            ];

            if ($forward['kind'] !== 'month') {
                $flags['forward_month_from_'.$forward['kind'].'_contract'] = true;
            }

            $weightedBase += $base * $days;
            $weightedDay += $day * $days;
            $weightedNight += $night * $days;
            $totalDays += $days;
            $month = $monthEnd;
        }

        if ($totalDays <= 0) {
            return $this->fallback($shape, ['empty_forward_window']);
        }

        return new SpotEstimate(
            basis: SpotEstimateBasis::ForwardCurve,
            shapeOverallCentsPerKwh: $shape->overallAvgWithTax,
            shapeDayCentsPerKwh: $shape->dayAvgWithTax,
            shapeNightCentsPerKwh: $shape->nightAvgWithTax,
            dayOffsetCentsPerKwh: $dayOffset,
            nightOffsetCentsPerKwh: $nightOffset,
            shapePeriodStart: $shape->periodStart?->toDateString(),
            shapePeriodEnd: $shape->periodEnd?->toDateString(),
            currentCurveTradeDate: $currentTradeDate->toDateString(),
            futureCurveTradeDate: $futureTradeDate->toDateString(),
            months: $months,
            annualEquivalentBaseCentsPerKwh: $weightedBase / $totalDays,
            annualEquivalentDayCentsPerKwh: $weightedDay / $totalDays,
            annualEquivalentNightCentsPerKwh: $weightedNight / $totalDays,
            confidence: 'higher',
            flags: array_keys($flags),
        );
    }

    private function shapeError(SpotAssumptions $shape, CarbonImmutable $windowStart): ?string
    {
        foreach ([$shape->overallAvgWithTax, $shape->dayAvgWithTax, $shape->nightAvgWithTax] as $value) {
            if ($value === null || ! is_finite($value)) {
                return 'invalid_shape_inputs';
            }
        }

        if ($shape->periodStart === null || $shape->periodEnd === null) {
            return 'invalid_shape_period';
        }

        // These fields are evidence dates, not instants. Normalize them to the application
        // timezone so a process-level default timezone cannot turn the same date into a future
        // timestamp and force a test-order-dependent fallback.
        $periodStart = CarbonImmutable::parse($shape->periodStart->toDateString(), 'Europe/Helsinki')->startOfDay();
        $periodEnd = CarbonImmutable::parse($shape->periodEnd->toDateString(), 'Europe/Helsinki')->startOfDay();
        if ($periodEnd->lessThan($periodStart)) {
            return 'invalid_shape_period';
        }
        if (abs($periodStart->diffInDays($periodEnd) - 364.0) > 0.001) {
            return 'incomplete_shape_period';
        }
        if ($periodEnd->greaterThan($windowStart)) {
            return 'future_shape_period';
        }
        if ($periodEnd->diffInDays($windowStart) > $this->settings->maxCurveAgeDays) {
            return 'stale_shape_period';
        }

        return null;
    }

    private function isStale(CarbonImmutable $tradeDate, CarbonImmutable $asOf): bool
    {
        return $tradeDate->diffInDays($asOf) > $this->settings->maxCurveAgeDays;
    }

    /** @param list<string> $flags */
    private function fallback(SpotAssumptions $shape, array $flags): SpotEstimate
    {
        return new SpotEstimate(
            basis: SpotEstimateBasis::Rolling365Fallback,
            shapeOverallCentsPerKwh: $shape->overallAvgWithTax,
            shapeDayCentsPerKwh: $shape->dayAvgWithTax,
            shapeNightCentsPerKwh: $shape->nightAvgWithTax,
            dayOffsetCentsPerKwh: $shape->dayAvgWithTax !== null && $shape->overallAvgWithTax !== null
                ? $shape->dayAvgWithTax - $shape->overallAvgWithTax
                : null,
            nightOffsetCentsPerKwh: $shape->nightAvgWithTax !== null && $shape->overallAvgWithTax !== null
                ? $shape->nightAvgWithTax - $shape->overallAvgWithTax
                : null,
            shapePeriodStart: $shape->periodStart?->toDateString(),
            shapePeriodEnd: $shape->periodEnd?->toDateString(),
            currentCurveTradeDate: null,
            futureCurveTradeDate: null,
            months: [],
            annualEquivalentBaseCentsPerKwh: $shape->overallAvgWithTax,
            annualEquivalentDayCentsPerKwh: $shape->dayAvgWithTax,
            annualEquivalentNightCentsPerKwh: $shape->nightAvgWithTax,
            confidence: 'fallback',
            flags: array_values(array_unique(['rolling_365_fallback', ...$flags])),
        );
    }
}
