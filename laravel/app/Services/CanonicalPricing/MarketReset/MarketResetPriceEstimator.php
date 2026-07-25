<?php

namespace App\Services\CanonicalPricing\MarketReset;

use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimateRequest;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use Carbon\CarbonImmutable;

/**
 * Prices the uncovered/held-forward tail of a market-reset contract with a shape-only
 * forward-curve shift:
 *
 *     P_m = P_current_period + beta * (F_m - F_reference)
 *
 * Only *differences* on one curve vintage are used, so the seasonal shape is imported while
 * the price level stays anchored on the provider's own published price. A uniform curve error
 * cancels out, which is why the estimator is robust to a wrong or drifting curve level.
 *
 * Fallback ladder, recorded on the result so every surface can state the basis:
 *   1. forward-curve shift (higher confidence);
 *   2. multi-year spot seasonal index (lower confidence, `P_m = P_current * s_m / s_ref`);
 *   3. hold flat, i.e. the behaviour that existed before this estimator.
 *
 * See AGENTS.md in this directory for the measurements and the retracted alternatives.
 */
class MarketResetPriceEstimator
{
    public function __construct(
        private readonly MarketReferenceCurveProvider $curve,
        private readonly ResetEstimatorSettings $settings = new ResetEstimatorSettings(),
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->enabled;
    }

    public function estimate(ResetEstimateRequest $request): ResetEstimate
    {
        if (! $this->settings->enabled) {
            return ResetEstimate::holdFlat($request->cadence, $request->anchorEnergyPriceCentsPerKwh, ['disabled']);
        }

        if ($request->tailMonthKeys === []) {
            return ResetEstimate::holdFlat($request->cadence, $request->anchorEnergyPriceCentsPerKwh, ['no_uncovered_tail']);
        }

        $forward = $this->forwardShift($request);

        if ($forward !== null && $this->isPlausible($forward)) {
            return $forward;
        }

        $seasonal = $this->seasonalIndexShift(
            $request,
            $forward === null ? [] : ['forward_shift_outside_plausibility_band'],
        );

        if ($seasonal !== null && $this->isPlausible($seasonal)) {
            return $seasonal;
        }

        $flags = ['no_usable_market_shape'];
        if ($forward !== null) {
            $flags[] = 'forward_shift_outside_plausibility_band';
        }
        if ($seasonal !== null) {
            $flags[] = 'seasonal_index_outside_plausibility_band';
        }

        return ResetEstimate::holdFlat($request->cadence, $request->anchorEnergyPriceCentsPerKwh, $flags);
    }

    /**
     * Step 1: `P_m = P_current + beta * (F_m - F_reference)`, one curve vintage for both terms.
     */
    private function forwardShift(ResetEstimateRequest $request): ?ResetEstimate
    {
        $tradeDate = $this->curve->tradeDate($request->asOfDate);

        if ($tradeDate === null) {
            return null;
        }

        // A stale curve carries a stale *shape*, which is the one thing this estimator
        // consumes. Reject it rather than shift on it.
        if ($tradeDate->diffInDays($request->asOfDate) > $this->settings->maxCurveAgeDays) {
            return null;
        }

        $reference = $this->curve->referencePrice(
            $request->asOfDate,
            $request->anchorPeriodMonth,
            $request->referenceKindPreference(),
        );

        if ($reference === null) {
            return null;
        }

        $beta = $this->settings->beta;
        $offsets = [];
        $fallbackKinds = [];

        foreach ($request->tailMonthKeys as $monthKey) {
            $forward = $this->curve->forwardPriceForMonth($request->asOfDate, $this->monthFromKey($monthKey));

            if ($forward === null) {
                // A missing delivery month means the shape is incomplete for the window. Do not
                // silently hold that month flat inside an otherwise shifted estimate.
                return null;
            }

            if ($forward['kind'] !== 'month') {
                $fallbackKinds[$forward['kind']] = true;
            }

            $offsets[$monthKey] = $beta * ($forward['price_cents_per_kwh'] - $reference['price_cents_per_kwh']);
        }

        $flags = [];
        foreach (array_keys($fallbackKinds) as $kind) {
            $flags[] = 'forward_month_from_'.$kind.'_contract';
        }

        return new ResetEstimate(
            basis: ResetEstimateBasis::ForwardCurveShift,
            offsetsByMonthKey: $offsets,
            beta: $beta,
            cadence: $request->cadence,
            currentPeriodEnergyPriceCentsPerKwh: $request->anchorEnergyPriceCentsPerKwh,
            annualEquivalentEnergyPriceCentsPerKwh: $this->annualEquivalent($request, $offsets),
            referenceKind: $reference['kind'],
            referencePriceCentsPerKwh: $reference['price_cents_per_kwh'],
            curveTradeDate: $tradeDate->toDateString(),
            anchorPeriodLabel: $this->anchorPeriodLabel($request),
            tailStartsMonthKey: $request->tailMonthKeys[0],
            flags: $flags,
        );
    }

    /**
     * Step 2: multiplicative seasonal index from multi-year realized spot.
     *
     * Marked lower confidence on purpose. The realized monthly index has a year-to-year sd of
     * about 0.42 across 2022-2025 and 0.77-0.80 in the winter months that drive the
     * correction, so this is better than flat but must never outrank an available curve.
     *
     * @param  list<string>  $carriedFlags
     */
    private function seasonalIndexShift(ResetEstimateRequest $request, array $carriedFlags): ?ResetEstimate
    {
        if (! $this->settings->seasonalIndexEnabled) {
            return null;
        }

        $index = $this->curve->spotSeasonalIndex();

        if ($index === null) {
            return null;
        }

        $referenceMonth = (int) $request->anchorPeriodMonth->month;
        $referenceIndex = $index[$referenceMonth] ?? null;

        if ($referenceIndex === null || $referenceIndex <= 0) {
            return null;
        }

        $anchor = $request->anchorEnergyPriceCentsPerKwh;
        $beta = $this->settings->beta;
        $offsets = [];

        foreach ($request->tailMonthKeys as $monthKey) {
            $month = (int) $this->monthFromKey($monthKey)->month;
            $monthIndex = $index[$month] ?? null;

            if ($monthIndex === null) {
                return null;
            }

            $offsets[$monthKey] = $beta * ($anchor * ($monthIndex / $referenceIndex) - $anchor);
        }

        return new ResetEstimate(
            basis: ResetEstimateBasis::SpotSeasonalIndex,
            offsetsByMonthKey: $offsets,
            beta: $beta,
            cadence: $request->cadence,
            currentPeriodEnergyPriceCentsPerKwh: $anchor,
            annualEquivalentEnergyPriceCentsPerKwh: $this->annualEquivalent($request, $offsets),
            referenceKind: 'spot_seasonal_index',
            referencePriceCentsPerKwh: null,
            curveTradeDate: null,
            anchorPeriodLabel: $this->anchorPeriodLabel($request),
            tailStartsMonthKey: $request->tailMonthKeys[0],
            flags: array_values(array_unique(array_merge($carriedFlags, ['lower_confidence_seasonal_index']))),
        );
    }

    /**
     * Consumption-weighted energy price over the whole window, with the negative floor
     * applied — the figure shown as the estimated 12-month equivalent.
     *
     * @param  array<string, float>  $offsets
     */
    private function annualEquivalent(ResetEstimateRequest $request, array $offsets): ?float
    {
        $anchor = $request->anchorEnergyPriceCentsPerKwh;
        $weighted = 0.0;
        $weights = 0.0;

        foreach ($request->monthWeights as $monthKey => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $weighted += max(0.0, $anchor + ($offsets[$monthKey] ?? 0.0)) * $weight;
            $weights += $weight;
        }

        return $weights > 0 ? $weighted / $weights : null;
    }

    /**
     * Plausibility band against the fully-fixed retail market. A market-tracking product that
     * annualises far outside it is more likely a bad reference or a bad curve than a real
     * price, so the estimate drops one rung down the ladder and the reason is flagged.
     */
    private function isPlausible(ResetEstimate $estimate): bool
    {
        $annual = $estimate->annualEquivalentEnergyPriceCentsPerKwh;

        if ($annual === null) {
            return false;
        }

        if ($annual < $this->settings->plausibilityAbsoluteMinCentsPerKwh
            || $annual > $this->settings->plausibilityAbsoluteMaxCentsPerKwh) {
            return false;
        }

        $median = $this->curve->fixedTermMedianEnergyPrice();

        if ($median === null || $median <= 0) {
            return true; // no market centre to test against; the absolute band already applied
        }

        return $annual >= $median * $this->settings->plausibilityMinMultiple
            && $annual <= $median * $this->settings->plausibilityMaxMultiple;
    }

    private function anchorPeriodLabel(ResetEstimateRequest $request): string
    {
        $month = $request->anchorPeriodMonth;

        if ($request->cadence === 'monthly') {
            return $month->format('Y-m');
        }

        return $month->format('Y').'-Q'.((int) ceil($month->month / 3));
    }

    private function monthFromKey(string $monthKey): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $monthKey.'-01', 'Europe/Helsinki')->startOfMonth();
    }
}
