<?php

return [
    'fixed_term' => [
        'model_version' => env('PRICE_FORECASTING_MODEL_VERSION', 'fixed_term_futures_adjusted_v1'),
        'area' => env('PRICE_FORECASTING_FUTURES_AREA', 'FI'),
        'vat_multiplier' => (float) env('PRICE_FORECASTING_VAT_MULTIPLIER', 1.255),
        'direction_threshold_cents_per_kwh' => (float) env('PRICE_FORECASTING_DIRECTION_THRESHOLD', 0.15),
        'minimum_history_observations' => (int) env('PRICE_FORECASTING_MIN_HISTORY', 20),
        'default_horizon_days' => (int) env('PRICE_FORECASTING_DEFAULT_HORIZON_DAYS', 30),
        'durations_months' => [6, 12, 24],
        'target_quantiles' => ['median', 'p20', 'p80'],
    ],
];
