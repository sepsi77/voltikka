<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Canonical phase-aware pricing
    |--------------------------------------------------------------------------
    |
    | When enabled, public price calculations, rankings, and the deceptive-pricing
    | label are driven by validated `electricity_contracts.canonical_*` interpretation
    | data instead of the raw relational price components. Keep this off until the
    | staged comparison (contracts:compare-canonical-pricing) has been reviewed.
    |
    */
    'enabled' => env('CANONICAL_PRICING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Market-reset annualised price (forward-curve shift)
    |--------------------------------------------------------------------------
    |
    | A monthly/quarterly/seasonal reset product publishes one seasonal price per
    | period. Holding it flat for twelve months is badly wrong (too low in summer,
    | too high in winter). When this is enabled the uncovered/held-forward tail is
    | repriced with a shift of the FI forward curve:
    |
    |     P_m = P_current_period + beta * (F_m - F_reference)
    |
    | TWO vintages, deliberately. `F_m` reads today's curve, because the coming
    | year's level is what the customer will actually pay. `F_reference` reads the
    | PRICING vintage — the latest trade date before the current period started —
    | because that is the forward the seller priced the period from. Reading the
    | reference at today's vintage instead would inflate the implied spread by pure
    | front-month convergence.
    |
    | This is a SEPARATE flag from `enabled` above, because canonical pricing is
    | already live in production and cannot stage this change. With it off the
    | behaviour is byte-identical to holding the current period price flat.
    |
    | See app/Services/CanonicalPricing/MarketReset/AGENTS.md.
    |
    */
    'reset_forward_shift' => [
        'enabled' => env('RESET_FORWARD_SHIFT_ENABLED', false),

        /*
         | Pass-through coefficient. ONE global value on purpose: per-company
         | calibration is documented future work and the observed-reset sample
         | cannot support it yet. Measured support for 1.0 (month reference):
         | Pohjois-Karjalan Sahko 0.90 (R2 0.99) and Kokkolan Energia 1.01 (R2 0.66).
         */
        'beta' => env('RESET_FORWARD_SHIFT_BETA', 1.0),

        /*
         | Reject a curve vintage older than this many days and drop to the
         | lower-confidence spot seasonal index instead of shifting on stale shape.
         */
        'max_curve_age_days' => env('RESET_FORWARD_SHIFT_MAX_CURVE_AGE_DAYS', 14),

        /*
         | Last-resort multiplicative seasonal index from multi-year realized spot.
         | Deliberately marked lower confidence: the realized monthly index has a
         | year-to-year sd of about 0.42 across 2022-2025, and 0.77-0.80 in the
         | winter months that drive the correction.
         */
        'seasonal_index' => [
            'enabled' => env('RESET_FORWARD_SHIFT_SEASONAL_INDEX_ENABLED', true),
            'lookback_years' => 4,
            'min_years_per_month' => 2,
        ],

        /*
         | ABSURDITY band only, on the resulting annual-equivalent energy price. It
         | catches a broken reference or a bad curve print, nothing else.
         |
         | This is deliberately an ABSOLUTE band and NOT a multiple of the fully-fixed
         | retail median. A market-relative band would encode the prior "a market-reset
         | product must be cheaper than a fixed deal", which is weak: an incumbent with
         | inert customers on a near-default product can genuinely carry a ~3.6 c/kWh
         | spread. A reset that honestly annualises above a fixed deal is a true and
         | useful finding, and suppressing it would be fitting the output to a prior.
         | Do not re-introduce a market-relative band here.
         */
        'absurdity_band' => [
            'floor_cents_per_kwh' => 0.0,
            'ceiling_cents_per_kwh' => 60.0,
        ],

        /*
         | Reported for context only by `contracts:compare-canonical-pricing --resets`.
         | It must never gate the estimate; see `absurdity_band` above.
         */
        'context_fixed_term_segment_key' => 'fixed_term_12',
    ],
];
