<?php

/*
 * GIE AGSI+ gas storage (https://agsi.gie.eu) for monthly market reviews. Free API key from
 * https://agsi.gie.eu/account, sent in the `x-key` header. Data use is free, but every
 * published figure must name "GIE AGSI" as its source. Limit: 60 calls per minute per IP.
 * See app/Services/GasStorage/AGENTS.md.
 */
return [
    'api_key' => env('GIE_API_KEY'),
    'base_url' => env('GIE_AGSI_BASE_URL', 'https://agsi.gie.eu/api'),
    'connect_timeout' => (float) env('GIE_AGSI_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('GIE_AGSI_TIMEOUT', 30),
    'request_delay_seconds' => 1.5,
    'retry_sleep_ms' => 5000,
    'page_size' => 300, // API maximum

    // GIE publishes the previous gas day at 19:30 and 23:00 CET and later corrects estimated
    // (E) days to confirmed (C), so the daily run refetches this many days.
    'lookback_days' => 14,

    // Area key => AGSI query parameters.
    'areas' => [
        'eu' => ['name' => 'EU', 'params' => ['type' => 'eu']],
        'de' => ['name' => 'Germany', 'params' => ['country' => 'de']],
    ],
];
