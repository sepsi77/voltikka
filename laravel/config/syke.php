<?php

/*
 * Syke (Finnish Environment Institute) Hydrologiarajapinta OData API, no key.
 * Licence: Creative Commons Attribution 4.0 International. Credit: "Source: Finnish Environment
 * Institute (Syke), Hydrologiarajapinta" (see app/Services/Syke/AGENTS.md).
 *
 * Collected as slow input features for an own spot price model. Nothing public reads the rows.
 * The places are northern hydro catchments; each precipitation place has a snow place for the
 * same area (Suure 5 = areal precipitation PA, Suure 7 = areal snow water equivalent SA, both mm).
 */
return [
    // Version 1.2 (2026-05-11) is current; 1.1 is listed as a previous version and returns the
    // same rows for these series (checked 2026-11-01).
    'base_url' => env('SYKE_HYDROLOGY_BASE_URL', 'https://rajapinnat.ymparisto.fi/api/Hydrologiarajapinta/1.2/odata'),
    'connect_timeout' => (float) env('SYKE_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('SYKE_TIMEOUT', 30),
    // No published rate limit; keep a short pause between requests to be polite.
    'request_delay_seconds' => (float) env('SYKE_REQUEST_DELAY_SECONDS', 1.0),
    'retry_sleep_ms' => 2000,
    // Safety stop for nextLink paging (the server returns at most 500 rows per page).
    'max_pages' => 500,

    // Daily window: the 5-day values arrive about 11 days after the period start, and snow
    // values are dated the 1st and 16th of a month. 90 days also catches late corrections.
    'daily_lookback_days' => 90,
    // Backfill start when only --to is given. Earlier dates are allowed with --from.
    'backfill_start' => '2023-01-01',

    'series' => [
        // 5-day areal precipitation (Jakso 2). Aika = first day of the period: the value at D
        // covers D..D+4; the period dated the 26th covers the 26th to the month end.
        'precipitation_5d' => [
            'entity' => 'SadantaAlue',
            'jakso_id' => 2,
            'unit' => 'mm',
            'places' => [
                810 => 'Oulujoki, Jokivarsi (Oulujoki)',
                811 => 'Oulujärvi (Oulujoki)',
                834 => 'Jokijärvi, Niskakoski (Iijoki)',
                837 => 'Koitijärvi (Iijoki)',
                848 => 'Sinettäjärvi-Luusua (Kemijoki)',
                852 => 'Raudanjoki ym + suuosa (Kemijoki)',
                879 => 'Raanujärvi (Tornionjoki)',
                881 => 'Vietosen oma alue (Tornionjoki)',
                885 => 'Inarin lähialueet (Paatsjoki)',
                886 => 'Juutuanjoki, Saukkoniva (Paatsjoki)',
            ],
        ],
        // Areal snow water equivalent on date D (a state, not a period sum). Dated the 1st and
        // 16th; no values in summer. Arvo is a decimal string in the JSON.
        'snow_water_equivalent' => [
            'entity' => 'LumiAlue',
            'jakso_id' => null,
            'unit' => 'mm',
            'places' => [
                159 => 'Oulujoki, Jokivarsi (Oulujoki)',
                160 => 'Oulujärvi (Oulujoki)',
                183 => 'Jokijärvi, Niskakoski (Iijoki)',
                185 => 'Koitijärvi (Iijoki)',
                196 => 'Sinettäjärvi-Luusua (Kemijoki)',
                200 => 'Raudanjoki ym + suuosa (Kemijoki)',
                226 => 'Raanujärvi (Tornionjoki)',
                228 => 'Vietosen oma alue (Tornionjoki)',
                232 => 'Inarin lähialueet (Paatsjoki)',
                233 => 'Juutuanjoki, Saukkoniva (Paatsjoki)',
            ],
        ],
    ],
];
