<?php

/*
 * ENTSO-E Transparency Platform datasets for monthly market reviews (entsoe:fetch).
 * The API key, base URL and connect timeout come from services.entsoe (ENTSOE_API_KEY). ENTSO-E allows
 * 400 requests per minute; the client still waits between requests.
 * See app/Services/EntsoeTransparency/AGENTS.md.
 */
return [
    'timeout' => (float) env('ENTSOE_TRANSPARENCY_TIMEOUT', 60),
    'request_delay_seconds' => (float) env('ENTSOE_TRANSPARENCY_REQUEST_DELAY_SECONDS', 0.5),
    'retry_sleep_ms' => 2000,

    // Unavailability (REMIT). The API returns at most 200 documents per request (offset paging)
    // and rejects a period or an update window longer than one year.
    'outages' => [
        // Daily run: documents created or updated in the last N days (periodStartUpdate).
        'update_lookback_days' => 3,
        // Daily run: outage period searched around today. With an update window the period may
        // span more than one year (checked 2026-11-01).
        'past_days' => 365,
        'future_days' => 730,
        // Backfill without --to ends this many days after today, so known future outages are included.
        'backfill_future_days' => 365,
        // Generation units (A80) and production units (A77) per bidding zone.
        'document_types' => ['A80', 'A77'],
        'zones' => [
            'FI' => '10YFI-1--------U',
            'SE1' => '10Y1001A1001A44P',
            'SE2' => '10Y1001A1001A45N',
            'SE3' => '10Y1001A1001A46L',
        ],
        // Transmission (A78), one query per direction. Key = FROM_TO (the JAO convention):
        // out_Domain is the exporting zone, in_Domain the importing zone.
        'borders' => [
            'SE1_FI' => ['out' => 'SE1', 'in' => 'FI'],
            'FI_SE1' => ['out' => 'FI', 'in' => 'SE1'],
            'SE3_FI' => ['out' => 'SE3', 'in' => 'FI'],
            'FI_SE3' => ['out' => 'FI', 'in' => 'SE3'],
            'EE_FI' => ['out' => 'EE', 'in' => 'FI'],
            'FI_EE' => ['out' => 'FI', 'in' => 'EE'],
        ],
        'border_zones' => [
            'FI' => '10YFI-1--------U',
            'SE1' => '10Y1001A1001A44P',
            'SE3' => '10Y1001A1001A46L',
            'EE' => '10Y1001A1001A39I',
        ],
    ],

    // Actual generation per production type (A75, A16), stored as hourly means of MW.
    'generation' => [
        'lookback_days' => 4,
        'backfill_chunk_days' => 7,
        'zones' => [
            'SE1' => '10Y1001A1001A44P',
            'SE2' => '10Y1001A1001A45N',
            'SE3' => '10Y1001A1001A46L',
            'SE4' => '10Y1001A1001A47J',
            'EE' => '10Y1001A1001A39I',
            'DE-LU' => '10Y1001A1001A82H',
        ],
    ],

    // Weekly reservoir filling (A72, A16), MWh stored energy per country.
    'reservoirs' => [
        'lookback_days' => 56,
        'backfill_chunk_days' => 364,
        'zones' => [
            'NO' => '10YNO-0--------C',
            'SE' => '10YSE-1--------K',
            'FI' => '10YFI-1--------U',
        ],
    ],
];
