<?php

namespace App\Services\CanonicalPricing\MarketReset\Enums;

/**
 * Which rung of the fallback ladder produced a market-reset tail estimate.
 *
 * Recorded on the outcome so every UI surface can state the basis, and so the
 * comparison command can report how many lineages fell back and why.
 */
enum ResetEstimateBasis: string
{
    /**
     * Shape-only shift of the FI forward curve. `P_m = P_current + beta * (F_m - F_reference)`,
     * with `F_m` and `F_reference` read from ONE curve vintage.
     */
    case ForwardCurveShift = 'forward_curve_shift';

    /**
     * Lower-confidence fallback: multiplicative seasonal index from multi-year realized
     * spot. `P_m = P_current * s_m / s_reference`. Used when no usable curve exists.
     */
    case SpotSeasonalIndex = 'spot_seasonal_index';

    /**
     * No market data at all: the current period price is held flat, which is the
     * behaviour that existed before the forward shift was added.
     */
    case HoldFlat = 'hold_flat';

    public function isHigherConfidence(): bool
    {
        return $this === self::ForwardCurveShift;
    }

    public function shiftsPrices(): bool
    {
        return $this !== self::HoldFlat;
    }
}
