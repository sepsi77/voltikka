<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Retail premium calibration report
    |--------------------------------------------------------------------------
    |
    | Settings for the read-only `retail-premiums:calibrate` report. The report
    | measures the pass-through coefficient (`beta`) of market-reset contracts
    | from the stored observation dataset and compares it with the single global
    | value the market-reset estimator uses
    | (`canonical_pricing.reset_forward_shift.beta`).
    |
    | Nothing here changes pricing behaviour. These values only decide when the
    | scheduled report escalates its log line to `warning`, which is the
    | self-surfacing signal that the configured global value needs review.
    |
    */
    'calibration' => [
        /*
         | Absolute difference between the measured per-company median `beta` and
         | the configured global `beta` that makes the report ask for a review.
         |
         | Most reset rows still carry `vat_basis = unknown`, so the measurement is
         | ambiguous by the 1.255 VAT factor. The report therefore escalates only
         | when NO VAT assumption reconciles the measurement with the configured
         | value; otherwise the 1.255 factor alone would trip the threshold on
         | every run.
         */
        'beta_review_threshold' => env('RETAIL_PREMIUM_CALIBRATION_BETA_THRESHOLD', 0.25),

        /*
         | Pass-through pairs one company needs before its own `beta` is treated as
         | measured rather than indicative. Three is the threshold recorded in
         | tasks/market-reset-annualised-pricing/decisions.md for considering a
         | per-company parameter.
         */
        'min_pairs_per_company' => env('RETAIL_PREMIUM_CALIBRATION_MIN_PAIRS', 3),
    ],
];
