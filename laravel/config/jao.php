<?php

/*
 * JAO Nordic flow-based publication tool (https://publicationtool.jao.eu/nordic), no key.
 * Collected for monthly market reviews: day-ahead maxBorderFlow on the Finnish borders.
 * The API rejects a request range over 2 days. History starts at 2024-10-29T23:00Z (the
 * first flow-based delivery day). MTU is 60 min until 2025-09-30 and 15 min after that; the
 * importer stores hourly means and minima. JAO terms restrict redistribution; see
 * app/Services/Jao/AGENTS.md before any figure is published.
 */
return [
    'base_url' => env('JAO_NORDIC_BASE_URL', 'https://publicationtool.jao.eu/nordic/api/data'),
    'connect_timeout' => (float) env('JAO_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('JAO_TIMEOUT', 30),
    'request_delay_seconds' => (float) env('JAO_REQUEST_DELAY_SECONDS', 2),
    'retry_sleep_ms' => 5000,
    'max_window_hours' => 48,

    // Daily window: delivery days from today - 2 to tomorrow (D+1 is published about 10-11 CET).
    'lookback_days' => 2,
    'lookahead_days' => 2,

    // Response field suffix => stored border code. From zone _ to zone, so SE1_FI is import into FI.
    'borders' => [
        'SE1_FI' => 'SE1_FI',
        'SE3_FI' => 'SE3_FI',
        'EE_FI' => 'EE_FI',
        'NO4_FI' => 'NO4_FI',
        'FI_SE1' => 'FI_SE1',
        'FI_SE3' => 'FI_SE3',
        'FI_EE' => 'FI_EE',
        'FI_NO4' => 'FI_NO4',
    ],
];
