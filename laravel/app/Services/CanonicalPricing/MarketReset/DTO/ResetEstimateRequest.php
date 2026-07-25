<?php

namespace App\Services\CanonicalPricing\MarketReset\DTO;

use Carbon\CarbonImmutable;

/**
 * Everything the estimator needs to reprice a market-reset tail.
 *
 * The caller (CanonicalContractPriceCalculator) owns the phase timeline, so it resolves
 * which months are contractually known and what the held-forward price is; the estimator
 * only supplies the per-month shape.
 */
readonly class ResetEstimateRequest
{
    /**
     * @param  string  $cadence  `monthly`, `quarterly`, or `seasonal`.
     * @param  CarbonImmutable  $asOfDate  Window start. The vintage anchor for the forward months
     *                                     `F_m`: the latest `trade_date < asOfDate`.
     * @param  CarbonImmutable  $anchorPeriodMonth  A month inside the last contractually known
     *                                              reset period. The reference delivery period is
     *                                              the cadence period containing it.
     * @param  CarbonImmutable  $currentPeriodStart  Start date of that same period. The vintage
     *                                               anchor for `F_reference`: the seller set the
     *                                               period price before the period began, so the
     *                                               spread must be read against the curve as it
     *                                               stood then, not against a front contract that
     *                                               has since converged to realized spot.
     * @param  list<string>  $tailMonthKeys  `Y-m` keys of the window months that are repriced.
     * @param  float  $anchorEnergyPriceCentsPerKwh  Consumption-weighted energy price of the
     *                                               held-forward rates, used for the negative
     *                                               floor and the absurdity guard.
     * @param  array<string, float>  $monthWeights  `Y-m` => kWh for every month of the window,
     *                                              including the contractually known ones.
     */
    public function __construct(
        public string $cadence,
        public CarbonImmutable $asOfDate,
        public CarbonImmutable $anchorPeriodMonth,
        public CarbonImmutable $currentPeriodStart,
        public array $tailMonthKeys,
        public float $anchorEnergyPriceCentsPerKwh,
        public array $monthWeights,
    ) {
    }

    /**
     * Reference delivery-period kinds to try, in order, for this cadence.
     *
     * Monthly resets are priced from the front month (measured pass-through 0.90 / 1.01).
     * Quarterly and seasonal resets are priced from the quarter; once the quarter has
     * entered delivery EEX stops publishing it, so the day-weighted average of its three
     * month contracts is the only quarter-shaped reference left.
     *
     * @return list<string>
     */
    public function referenceKindPreference(): array
    {
        return match ($this->cadence) {
            'quarterly', 'seasonal' => ['quarter', 'quarter_month_average'],
            default => ['month'],
        };
    }
}
