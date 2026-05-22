<?php

return [
    'endpoint' => env('EEX_FUTURES_ENDPOINT', 'https://api.eex-group.com/pub/market-data/chart/eod'),
    'price_ticker_endpoint' => env('EEX_FUTURES_PRICE_TICKER_ENDPOINT', 'https://api.eex-group.com/pub/market-data/price-ticker'),
    'referer' => env('EEX_FUTURES_REFERER', 'https://www.eex.com/'),
    'connect_timeout' => (float) env('EEX_FUTURES_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('EEX_FUTURES_TIMEOUT', 20),
    'retry_times' => (int) env('EEX_FUTURES_RETRY_TIMES', 3),
    'retry_sleep_ms' => (int) env('EEX_FUTURES_RETRY_SLEEP_MS', 1000),
    'request_delay_seconds' => (float) env('EEX_FUTURES_REQUEST_DELAY_SECONDS', 15),
    'request_delay_jitter_seconds' => (float) env('EEX_FUTURES_REQUEST_DELAY_JITTER_SECONDS', 5),
    'history_window_days' => (int) env('EEX_FUTURES_HISTORY_WINDOW_DAYS', 45),

    // Discovery scan ceilings. EOD fetching stops earlier when price-ticker returns empty data.
    'months_back' => (int) env('EEX_FUTURES_MONTHS_BACK', 1),
    'months_ahead' => (int) env('EEX_FUTURES_MONTHS_AHEAD', 24),
    'quarters_ahead' => (int) env('EEX_FUTURES_QUARTERS_AHEAD', 24),
    'years_ahead' => (int) env('EEX_FUTURES_YEARS_AHEAD', 12),

    /*
     * EEX currently publishes Nordic System Price and Nordic zonal month,
     * quarter, and year futures through the public chart endpoint. Baltic
     * bidding zones are intentionally not listed here because the current EEX
     * product-code file does not expose Baltic power future short codes for
     * this endpoint.
     */
    'instruments' => [
        ['market_region' => 'Nordics', 'area' => 'NP', 'area_name' => 'Nordic System Price', 'maturity_type' => 'month', 'short_code' => 'FBBM'],
        ['market_region' => 'Nordics', 'area' => 'NP', 'area_name' => 'Nordic System Price', 'maturity_type' => 'quarter', 'short_code' => 'FBBQ'],
        ['market_region' => 'Nordics', 'area' => 'NP', 'area_name' => 'Nordic System Price', 'maturity_type' => 'year', 'short_code' => 'FBBY'],

        ['market_region' => 'Nordics', 'area' => 'DK1', 'area_name' => 'Denmark DK1', 'maturity_type' => 'month', 'short_code' => '1DBM'],
        ['market_region' => 'Nordics', 'area' => 'DK1', 'area_name' => 'Denmark DK1', 'maturity_type' => 'quarter', 'short_code' => '1DBQ'],
        ['market_region' => 'Nordics', 'area' => 'DK1', 'area_name' => 'Denmark DK1', 'maturity_type' => 'year', 'short_code' => '1DBY'],

        ['market_region' => 'Nordics', 'area' => 'DK2', 'area_name' => 'Denmark DK2', 'maturity_type' => 'month', 'short_code' => '2DBM'],
        ['market_region' => 'Nordics', 'area' => 'DK2', 'area_name' => 'Denmark DK2', 'maturity_type' => 'quarter', 'short_code' => '2DBQ'],
        ['market_region' => 'Nordics', 'area' => 'DK2', 'area_name' => 'Denmark DK2', 'maturity_type' => 'year', 'short_code' => '2DBY'],

        ['market_region' => 'Nordics', 'area' => 'FI', 'area_name' => 'Finland', 'maturity_type' => 'month', 'short_code' => 'FNBM'],
        ['market_region' => 'Nordics', 'area' => 'FI', 'area_name' => 'Finland', 'maturity_type' => 'quarter', 'short_code' => 'FNBQ'],
        ['market_region' => 'Nordics', 'area' => 'FI', 'area_name' => 'Finland', 'maturity_type' => 'year', 'short_code' => 'FNBY'],

        ['market_region' => 'Nordics', 'area' => 'NO1', 'area_name' => 'Norway NO1', 'maturity_type' => 'month', 'short_code' => '1NBM'],
        ['market_region' => 'Nordics', 'area' => 'NO1', 'area_name' => 'Norway NO1', 'maturity_type' => 'quarter', 'short_code' => '1NBQ'],
        ['market_region' => 'Nordics', 'area' => 'NO1', 'area_name' => 'Norway NO1', 'maturity_type' => 'year', 'short_code' => '1NBY'],

        ['market_region' => 'Nordics', 'area' => 'NO2', 'area_name' => 'Norway NO2', 'maturity_type' => 'month', 'short_code' => '2NBM'],
        ['market_region' => 'Nordics', 'area' => 'NO2', 'area_name' => 'Norway NO2', 'maturity_type' => 'quarter', 'short_code' => '2NBQ'],
        ['market_region' => 'Nordics', 'area' => 'NO2', 'area_name' => 'Norway NO2', 'maturity_type' => 'year', 'short_code' => '2NBY'],

        ['market_region' => 'Nordics', 'area' => 'NO3', 'area_name' => 'Norway NO3', 'maturity_type' => 'month', 'short_code' => '3NBM'],
        ['market_region' => 'Nordics', 'area' => 'NO3', 'area_name' => 'Norway NO3', 'maturity_type' => 'quarter', 'short_code' => '3NBQ'],
        ['market_region' => 'Nordics', 'area' => 'NO3', 'area_name' => 'Norway NO3', 'maturity_type' => 'year', 'short_code' => '3NBY'],

        ['market_region' => 'Nordics', 'area' => 'NO4', 'area_name' => 'Norway NO4', 'maturity_type' => 'month', 'short_code' => '4NBM'],
        ['market_region' => 'Nordics', 'area' => 'NO4', 'area_name' => 'Norway NO4', 'maturity_type' => 'quarter', 'short_code' => '4NBQ'],
        ['market_region' => 'Nordics', 'area' => 'NO4', 'area_name' => 'Norway NO4', 'maturity_type' => 'year', 'short_code' => '4NBY'],

        ['market_region' => 'Nordics', 'area' => 'NO5', 'area_name' => 'Norway NO5', 'maturity_type' => 'month', 'short_code' => '5NBM'],
        ['market_region' => 'Nordics', 'area' => 'NO5', 'area_name' => 'Norway NO5', 'maturity_type' => 'quarter', 'short_code' => '5NBQ'],
        ['market_region' => 'Nordics', 'area' => 'NO5', 'area_name' => 'Norway NO5', 'maturity_type' => 'year', 'short_code' => '5NBY'],

        ['market_region' => 'Nordics', 'area' => 'SE1', 'area_name' => 'Sweden SE1', 'maturity_type' => 'month', 'short_code' => '1SBM'],
        ['market_region' => 'Nordics', 'area' => 'SE1', 'area_name' => 'Sweden SE1', 'maturity_type' => 'quarter', 'short_code' => '1SBQ'],
        ['market_region' => 'Nordics', 'area' => 'SE1', 'area_name' => 'Sweden SE1', 'maturity_type' => 'year', 'short_code' => '1SBY'],

        ['market_region' => 'Nordics', 'area' => 'SE2', 'area_name' => 'Sweden SE2', 'maturity_type' => 'month', 'short_code' => '2SBM'],
        ['market_region' => 'Nordics', 'area' => 'SE2', 'area_name' => 'Sweden SE2', 'maturity_type' => 'quarter', 'short_code' => '2SBQ'],
        ['market_region' => 'Nordics', 'area' => 'SE2', 'area_name' => 'Sweden SE2', 'maturity_type' => 'year', 'short_code' => '2SBY'],

        ['market_region' => 'Nordics', 'area' => 'SE3', 'area_name' => 'Sweden SE3', 'maturity_type' => 'month', 'short_code' => '3SBM'],
        ['market_region' => 'Nordics', 'area' => 'SE3', 'area_name' => 'Sweden SE3', 'maturity_type' => 'quarter', 'short_code' => '3SBQ'],
        ['market_region' => 'Nordics', 'area' => 'SE3', 'area_name' => 'Sweden SE3', 'maturity_type' => 'year', 'short_code' => '3SBY'],

        ['market_region' => 'Nordics', 'area' => 'SE4', 'area_name' => 'Sweden SE4', 'maturity_type' => 'month', 'short_code' => '4SBM'],
        ['market_region' => 'Nordics', 'area' => 'SE4', 'area_name' => 'Sweden SE4', 'maturity_type' => 'quarter', 'short_code' => '4SBQ'],
        ['market_region' => 'Nordics', 'area' => 'SE4', 'area_name' => 'Sweden SE4', 'maturity_type' => 'year', 'short_code' => '4SBY'],
    ],
];
