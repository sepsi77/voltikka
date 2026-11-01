<?php

/*
 * Open-Meteo weather data (https://open-meteo.com), CC BY 4.0, for monthly market reviews.
 * Free API: non-commercial use only (user decision 2026-11-01, see
 * tasks/monthly-market-review/decisions.md). Limits: fewer than 10,000 calls per day, 5,000 per
 * hour and 600 per minute. A request with more than 10 variables or more than 2 weeks of data
 * counts as several calls, so the backfill pauses longer. See app/Services/OpenMeteo/AGENTS.md.
 *
 * Never change the coordinates of an existing point key: stored rows would mix two places.
 * Add a new key instead.
 */
return [
    'archive_url' => env('OPEN_METEO_ARCHIVE_URL', 'https://archive-api.open-meteo.com/v1/archive'),
    'connect_timeout' => (float) env('OPEN_METEO_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('OPEN_METEO_TIMEOUT', 60),
    'request_delay_seconds' => (float) env('OPEN_METEO_REQUEST_DELAY_SECONDS', 1),
    // A one-year archive request with 10 variables counts as about 26-40 calls.
    'backfill_request_delay_seconds' => (float) env('OPEN_METEO_BACKFILL_REQUEST_DELAY_SECONDS', 30),
    'retry_sleep_ms' => 5000,

    // All daily values use Europe/Helsinki local days, also for the Nordic points, so that every
    // point has the same day and month boundaries as the review.
    'timezone' => 'Europe/Helsinki',

    // Observed daily weather. ERA5 only: one consistent reanalysis from 1940, so the
    // 1991-2020 normal and the observed month come from the same model. ERA5 is about 5-6 days
    // late, so the daily window is 21 days and days without any value are not stored.
    'observed_model' => 'era5',
    'observed_lookback_days' => 21,
    'observed_variables' => [
        'temperature_2m_mean', 'temperature_2m_min', 'temperature_2m_max',
        'wind_speed_10m_mean', 'wind_speed_10m_max', 'wind_speed_100m_mean', 'wind_speed_100m_max',
        'precipitation_sum', 'snowfall_sum', 'snowfall_water_equivalent_sum',
    ],

    // Expected response units (wind is requested in m/s; the API default is km/h).
    'units' => [
        'temperature_2m_mean' => '°C',
        'temperature_2m_min' => '°C',
        'temperature_2m_max' => '°C',
        'wind_speed_10m_mean' => 'm/s',
        'wind_speed_10m_max' => 'm/s',
        'wind_speed_100m_mean' => 'm/s',
        'wind_speed_100m_max' => 'm/s',
        'precipitation_sum' => 'mm',
        'snowfall_sum' => 'cm',
        'snowfall_water_equivalent_sum' => 'mm',
    ],

    // Outlook products. Each fetch is one append-only row per product and point in
    // weather_outlook_runs. 'default' products run without --only.
    'outlooks' => [
        // ECMWF IFS 0.25° ensemble, 15 days, control + 50 members, two runs per day.
        'ecmwf_ens' => [
            'url' => 'https://ensemble-api.open-meteo.com/v1/ensemble',
            'model' => 'ecmwf_ifs025',
            'meta_url' => 'https://ensemble-api.open-meteo.com/data/ecmwf_ifs025_ensemble/static/meta.json',
            'forecast_days' => 15,
            'variables' => ['temperature_2m_mean', 'wind_speed_10m_mean', 'wind_speed_100m_mean', 'precipitation_sum', 'snowfall_water_equivalent_sum'],
            'default' => true,
        ],
        // ECMWF extended range (sub-seasonal), 46 days, control + 50 members, daily runs.
        'ecmwf_ec46' => [
            'url' => 'https://seasonal-api.open-meteo.com/v1/seasonal',
            'model' => 'ecmwf_ec46',
            'meta_url' => 'https://seasonal-api.open-meteo.com/data/ecmwf_ec46/static/meta.json',
            'forecast_days' => 46,
            'variables' => ['temperature_2m_mean', 'wind_speed_10m_mean', 'wind_speed_100m_mean', 'precipitation_sum', 'snowfall_water_equivalent_sum'],
            'default' => false,
        ],
        // ECMWF SEAS5 seasonal, 183 days, control + 50 members, one run per month (published
        // about the 5th). SEAS5 has no 100 m wind.
        'ecmwf_seas5' => [
            'url' => 'https://seasonal-api.open-meteo.com/v1/seasonal',
            'model' => 'ecmwf_seas5',
            'meta_url' => 'https://seasonal-api.open-meteo.com/data/ecmwf_seas5/static/meta.json',
            'forecast_days' => 183,
            'variables' => ['temperature_2m_mean', 'wind_speed_10m_mean', 'precipitation_sum', 'snowfall_water_equivalent_sum'],
            'default' => false,
        ],
    ],

    /*
     * Points. Finnish weights are population shares: each region (maakunta) is assigned to the
     * nearest point and its approximate population (Statistics Finland, end of 2023, thousands)
     * is added. Total about 5,570k:
     *   helsinki  Uusimaa 1733 + Päijät-Häme 205 + Kymenlaakso 160            = 2098 -> 0.377
     *   turku     Varsinais-Suomi 485 + Satakunta 213 + Ahvenanmaa 30         =  728 -> 0.131
     *   tampere   Pirkanmaa 534 + Kanta-Häme 170                              =  704 -> 0.126
     *   jyvaskyla Keski-Suomi 275 + Etelä-Pohjanmaa 190 + Pohjanmaa 177       =  642 -> 0.115
     *   kuopio    Pohjois-Savo 248 + Pohjois-Karjala 163 + Etelä-Savo 129
     *             + Etelä-Karjala 126                                         =  666 -> 0.120
     *   oulu      Pohjois-Pohjanmaa 418 + Lappi 176 + Kainuu 70
     *             + Keski-Pohjanmaa 68                                        =  732 -> 0.131
     * This is an approximation of heating demand: Lapland is colder than Oulu.
     *
     * Hydro points have no weight. They are large reservoir areas: NO2 (Blåsjø, Norway's
     * largest reservoir), NO5 (Aurland/Sogn), SE1 (Akkajaure/Suorva, Lule river) and
     * SE2 (Storuman, Ume river).
     */
    'points' => [
        'helsinki' => ['name' => 'Helsinki', 'latitude' => 60.17, 'longitude' => 24.94, 'group' => 'fi', 'weight' => 0.377],
        'turku' => ['name' => 'Turku', 'latitude' => 60.45, 'longitude' => 22.27, 'group' => 'fi', 'weight' => 0.131],
        'tampere' => ['name' => 'Tampere', 'latitude' => 61.50, 'longitude' => 23.76, 'group' => 'fi', 'weight' => 0.126],
        'jyvaskyla' => ['name' => 'Jyväskylä', 'latitude' => 62.24, 'longitude' => 25.75, 'group' => 'fi', 'weight' => 0.115],
        'kuopio' => ['name' => 'Kuopio', 'latitude' => 62.89, 'longitude' => 27.68, 'group' => 'fi', 'weight' => 0.120],
        'oulu' => ['name' => 'Oulu', 'latitude' => 65.01, 'longitude' => 25.47, 'group' => 'fi', 'weight' => 0.131],
        'no2_blasjo' => ['name' => 'Blåsjø (NO2)', 'latitude' => 59.33, 'longitude' => 6.85, 'group' => 'hydro', 'weight' => null],
        'no5_aurland' => ['name' => 'Aurland (NO5)', 'latitude' => 60.80, 'longitude' => 7.20, 'group' => 'hydro', 'weight' => null],
        'se1_akkajaure' => ['name' => 'Akkajaure (SE1)', 'latitude' => 67.65, 'longitude' => 17.60, 'group' => 'hydro', 'weight' => null],
        'se2_storuman' => ['name' => 'Storuman (SE2)', 'latitude' => 65.10, 'longitude' => 17.10, 'group' => 'hydro', 'weight' => null],
    ],
];
