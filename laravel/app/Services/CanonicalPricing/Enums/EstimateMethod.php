<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * How the calculator filled window segments that the disclosed phases did not
 * cover exactly. Recorded on the outcome so every UI surface can state the basis.
 */
enum EstimateMethod: string
{
    /** Every window segment came from a disclosed, dated phase. */
    case None = 'none';

    /** Recurring reset: the current period's rates were held forward. */
    case HoldCurrentRecurringPrice = 'hold_current_recurring_price';

    /**
     * Recurring reset: the current period stays exact and the tail is repriced with a
     * shape-only shift of the FI forward curve (`P_m = P_current + beta * (F_m - F_ref)`).
     */
    case RecurringForwardCurveShift = 'recurring_forward_curve_shift';

    /**
     * Recurring reset, lower-confidence fallback: no usable forward curve, so the tail is
     * shaped by a multi-year realized-spot seasonal index instead.
     */
    case RecurringSpotSeasonalIndex = 'recurring_spot_seasonal_index';

    /** Adjustable open-ended supplier price shifted on the FI forward curve. */
    case SupplierAdjustedForwardCurveShift = 'supplier_adjusted_forward_curve_shift';

    /** Adjustable supplier-price fallback shaped by the realized Spot seasonal index. */
    case SupplierAdjustedSpotSeasonalIndex = 'supplier_adjusted_spot_seasonal_index';

    /** Adjustable supplier price held current because no usable market shape exists. */
    case HoldCurrentSupplierPrice = 'hold_current_supplier_price';

    /** Spot: rolling-365-day day/night averages plus the phase spot margin. */
    case Rolling365Spot = 'rolling_365_spot';

    /** Fixed-term: only the term price is known; it is annualized for ranking. */
    case TermPriceAnnualized = 'term_price_annualized';

    /** Hybrid: base components only; the consumption effect is disclosed, not costed. */
    case HybridBaseOnly = 'hybrid_base_only';
}
