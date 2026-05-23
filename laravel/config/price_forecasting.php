<?php

return [
    'fixed_term' => [
        'model_version' => env('PRICE_FORECASTING_MODEL_VERSION', 'fixed_term_ewma_gap_v1'),
        'area' => env('PRICE_FORECASTING_FUTURES_AREA', 'FI'),
        'vat_multiplier' => (float) env('PRICE_FORECASTING_VAT_MULTIPLIER', 1.255),
        'ewma_alpha' => (float) env('PRICE_FORECASTING_EWMA_ALPHA', 0.25),
        'gap_closure_lambda' => (float) env('PRICE_FORECASTING_GAP_CLOSURE_LAMBDA', 0.30),
        'direction_threshold_cents_per_kwh' => (float) env('PRICE_FORECASTING_DIRECTION_THRESHOLD', 0.15),
        'minimum_history_observations' => (int) env('PRICE_FORECASTING_MIN_HISTORY', 10),
        'default_horizon_days' => (int) env('PRICE_FORECASTING_DEFAULT_HORIZON_DAYS', 30),
        'durations_months' => [6, 12, 24],
        'target_quantiles' => ['median', 'p20', 'p80'],
    ],
];
