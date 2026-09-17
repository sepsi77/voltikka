<?php

namespace App\Services\CanonicalPricing\MarketReset;

use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\ForwardPremium\PremiumFamily;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimateRequest;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;
use Carbon\CarbonImmutable;

/**
 * Prices the uncovered/held-forward tail of a market-reset contract with a shape-only
 * forward-curve shift:
 *
 *     P_m = P_current_period + beta * (F_m - F_reference)
 *
 * Two vintages are intentional: forward months use the target-date curve, while the reference
 * uses the pricing-date curve, bounded by the target date. This preserves market-level changes
 * since the seller set its published price, without importing front-month convergence.
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
        private readonly ResetEstimatorSettings $settings = new ResetEstimatorSettings,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->enabled;
    }

    public function estimate(ResetEstimateRequest $request): ResetEstimate
    {
        if (! $this->settings->enabled) {
            return ResetEstimate::holdFlat($request->cadence, $request->anchorEnergyPriceCentsPerKwh, ['disabled'], $request->policy === ComparisonPolicy::Current ? 0.0 : 1.0);
        }

        if ($request->tailMonthKeys === []) {
            return ResetEstimate::holdFlat($request->cadence, $request->anchorEnergyPriceCentsPerKwh, ['no_uncovered_tail'], $request->policy === ComparisonPolicy::Current ? 0.0 : 1.0);
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

        return ResetEstimate::holdFlat($request->cadence, $request->anchorEnergyPriceCentsPerKwh, $flags, $request->policy === ComparisonPolicy::Current ? 0.0 : 1.0);
    }

    /**
     * Step 1: `P_m = P_current + beta * (F_m - F_reference)`.
     *
     * Two vintages, deliberately:
     *
     * - `F_m` from today's curve, because the coming year's shape *and level* is what the
     *   customer will actually pay. A curve that rose since the price was set means the next
     *   resets really will be higher; that is information, not noise.
     * - `F_reference` from the **pricing vintage** — the latest trade date before the period
     *   started. The seller set the period price from the forward for that period as it stood
     *   then. Reading it against today's front contract, which has converged toward realized
     *   spot, inflates the implied spread by a pure artifact (measured at 1.58 c/kWh, about
     *   79 EUR/yr at 5000 kWh, for a monthly cadence in July 2026).
     */
    private function forwardShift(ResetEstimateRequest $request): ?ResetEstimate
    {
        $tradeDate = $this->curve->tradeDate($request->asOfDate);

        if ($tradeDate === null || ($request->policy === ComparisonPolicy::Current && ! $tradeDate->lt($request->asOfDate))) {
            return null;
        }

        // A stale curve carries a stale *shape*, which is the one thing this estimator
        // consumes. Reject it rather than shift on it. This applies to the forward vintage only:
        // the reference vintage is expected to be old (up to a quarter for a quarterly cadence).
        if ($tradeDate->diffInDays($request->asOfDate) > $this->settings->maxCurveAgeDays) {
            return null;
        }

        $flags = [];
        $referenceAsOf = $request->currentPeriodStart->min($request->asOfDate);
        if ($request->currentPeriodStart->greaterThan($request->asOfDate)) {
            $flags[] = 'reference_vintage_bounded_by_as_of';
        }

        // Retained Historical replay keeps its former fallback. Current must not replace an
        // unavailable original vintage with today's old-period reference before trying peers.
        if ($request->policy === ComparisonPolicy::Historical && $this->curve->tradeDate($referenceAsOf) === null) {
            $referenceAsOf = $request->asOfDate;
            $flags[] = 'reference_vintage_fallback_today';
        }

        $reference = $this->curve->referencePrice(
            $referenceAsOf,
            $request->anchorPeriodMonth,
            $request->referenceKindPreference(),
        );

        if ($request->policy === ComparisonPolicy::Current && $reference !== null
            && (! is_finite((float) $reference['price_cents_per_kwh'])
                || ! $this->validReferenceDate($reference['trade_date'] ?? null, $referenceAsOf))) {
            $reference = null;
        }
        if ($reference === null) {
            return $this->forwardPremium($request, $tradeDate, $flags);
        }

        $beta = $this->settings->beta;
        $offsets = [];
        $fallbackKinds = [];

        foreach ($request->tailMonthKeys as $monthKey) {
            $forward = $this->curve->forwardPriceForMonth($request->asOfDate, $this->monthFromKey($monthKey));

            if ($forward === null || ($request->policy === ComparisonPolicy::Current && ! is_finite((float) $forward['price_cents_per_kwh']))) {
                // A missing delivery month means the shape is incomplete for the window. Do not
                // silently hold that month flat inside an otherwise shifted estimate.
                return null;
            }

            if ($forward['kind'] !== 'month') {
                $fallbackKinds[$forward['kind']] = true;
            }

            $offsets[$monthKey] = $beta * ($forward['price_cents_per_kwh'] * $request->marketPriceMultiplier
                - $reference['price_cents_per_kwh'] * $request->marketPriceMultiplier);
            if ($request->policy === ComparisonPolicy::Current && ! is_finite($offsets[$monthKey])) {
                return null;
            }
        }

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
            referencePriceCentsPerKwh: $reference['price_cents_per_kwh'] * $request->marketPriceMultiplier,
            curveTradeDate: $tradeDate->toDateString(),
            referenceTradeDate: ($reference['trade_date'] ?? '') !== '' ? $reference['trade_date'] : null,
            anchorPeriodLabel: $this->anchorPeriodLabel($request),
            tailStartsMonthKey: $request->tailMonthKeys[0],
            flags: $flags,
        );
    }

    private function forwardPremium(ResetEstimateRequest $request, CarbonImmutable $tradeDate, array $flags): ?ResetEstimate
    {
        if ($request->policy !== ComparisonPolicy::Current || $request->premium === null
            || $request->energyRates === []
            || array_keys($request->energyRates) !== array_keys($request->premium->premiumsByBucket)) {
            return null;
        }
        foreach ($request->premium->observations as $observation) {
            if ($observation->compatibility->family !== ($request->pricingMechanism === 'Hybrid' ? PremiumFamily::MarketResetHybridBase : PremiumFamily::MarketReset)
                || $observation->compatibility->resetCadence !== $request->cadence
                || $observation->observedAt->gt($request->asOfDate)
                || $observation->pricingDate?->gt($request->asOfDate)
                || ! $observation->referenceTradeDate->lt($request->asOfDate)) {
                return null;
            }
        }
        $offsets = [];
        $flags[] = 'missing_own_reference_using_comparable_premium';
        foreach ($request->tailMonthKeys as $key) {
            $point = $this->curve->forwardPriceForMonth($request->asOfDate, $this->monthFromKey($key));
            if ($point === null || ! is_finite((float) $point['price_cents_per_kwh'])) {
                return null;
            }
            if ($point['kind'] !== 'month') {
                $flags[] = 'forward_month_from_'.$point['kind'].'_contract';
            }
            foreach ($request->energyRates as $bucket => $rate) {
                $offsets[$key][$bucket] = $this->settings->beta * ($point['price_cents_per_kwh'] * $request->marketPriceMultiplier
                    + $request->premium->premiumsByBucket[$bucket] - $rate);
            }
        }
        $weighted = $weights = 0.0;
        foreach ($request->bucketMonthWeights as $key => $buckets) {
            foreach ($buckets as $bucket => $weight) {
                $energyBucket = SupplierAdjustedEstimate::energyBucket($bucket);
                $rate = $request->energyRates[$energyBucket] ?? null;
                if ($rate === null || $weight <= 0) {
                    continue;
                }
                $tailWeight = $request->tailBucketMonthWeights[$key][$bucket] ?? 0.0;
                $weighted += max(0.0, $rate) * ($weight - $tailWeight)
                    + max(0.0, $rate + ($offsets[$key][$energyBucket] ?? 0.0)) * $tailWeight;
                $weights += $weight;
            }
        }

        return new ResetEstimate(
            basis: ResetEstimateBasis::ForwardPremium,
            offsetsByMonthKey: [],
            beta: $this->settings->beta,
            cadence: $request->cadence,
            currentPeriodEnergyPriceCentsPerKwh: $request->anchorEnergyPriceCentsPerKwh,
            annualEquivalentEnergyPriceCentsPerKwh: $weights > 0 ? $weighted / $weights : null,
            curveTradeDate: $tradeDate->toDateString(),
            anchorPeriodLabel: $this->anchorPeriodLabel($request),
            tailStartsMonthKey: $request->tailMonthKeys[0],
            flags: array_values(array_unique($flags)),
            bucketOffsetsByMonthKey: $offsets,
            premium: $request->premium,
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

        $index = $this->curve->spotSeasonalIndex($request->asOfDate);

        if ($index === null) {
            return null;
        }

        $referenceIndex = $this->seasonalReferenceIndex($request, $index);

        if ($referenceIndex === null || ! is_finite($referenceIndex) || $referenceIndex <= 0) {
            return null;
        }

        $anchor = $request->anchorEnergyPriceCentsPerKwh;
        $beta = $this->settings->beta;
        $offsets = [];

        foreach ($request->tailMonthKeys as $monthKey) {
            $month = (int) $this->monthFromKey($monthKey)->month;
            $monthIndex = $index[$month] ?? null;

            if ($monthIndex === null || ! is_finite($monthIndex) || $monthIndex <= 0) {
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

    /** @param array<int, float> $index */
    private function seasonalReferenceIndex(ResetEstimateRequest $request, array $index): ?float
    {
        if ($request->cadence === 'monthly') {
            return $index[(int) $request->anchorPeriodMonth->month] ?? null;
        }

        // The anchor month is inside the known period, not a monthly price for that month.
        $quarterStart = $request->anchorPeriodMonth->startOfQuarter();
        $weighted = 0.0;
        $days = 0;

        for ($offset = 0; $offset < 3; $offset++) {
            $month = $quarterStart->addMonths($offset);
            $monthIndex = $index[(int) $month->month] ?? null;

            if ($monthIndex === null || ! is_finite($monthIndex) || $monthIndex <= 0) {
                return null;
            }

            $weighted += $monthIndex * $month->daysInMonth;
            $days += $month->daysInMonth;
        }

        return $weighted / $days;
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
     * ABSURDITY guard only: an annual-equivalent energy price outside a very wide absolute band is
     * a broken reference or a bad curve print, not a real price, so the estimate drops one rung and
     * the reason is flagged.
     *
     * This deliberately does **not** test the result against the fully-fixed retail market. An
     * earlier version banded it to a multiple of the fully-fixed 12-month median, which quietly
     * encoded the prior "a market-reset product must be cheaper than a fixed deal". That prior is
     * weak: an incumbent with inert customers on a near-default product can genuinely carry a
     * ~3.6 c/kWh spread (Helen at 7.59 c/kWh against a 4.03 c/kWh forward for the same month), and a
     * reset that honestly annualises above a fixed deal is a **true and useful finding**. Suppressing
     * it would be the same error as tuning the anchor until the output looked reasonable. Do not
     * re-introduce a market-relative band here.
     */
    private function isPlausible(ResetEstimate $estimate): bool
    {
        $annual = $estimate->annualEquivalentEnergyPriceCentsPerKwh;

        if ($annual === null) {
            return false;
        }

        return $annual >= $this->settings->absurdityFloorCentsPerKwh
            && $annual <= $this->settings->absurdityCeilingCentsPerKwh;
    }

    private function anchorPeriodLabel(ResetEstimateRequest $request): string
    {
        $month = $request->anchorPeriodMonth;

        if ($request->cadence === 'monthly') {
            return $month->format('Y-m');
        }

        return $month->format('Y').'-Q'.((int) ceil($month->month / 3));
    }

    private function validReferenceDate(mixed $date, CarbonImmutable $bound): bool
    {
        return is_string($date)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            && $date < $bound->toDateString();
    }

    private function monthFromKey(string $monthKey): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $monthKey.'-01', 'Europe/Helsinki')->startOfMonth();
    }
}
