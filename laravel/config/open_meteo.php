<?php

/*
 * Open-Meteo weather data (https://open-meteo.com), CC BY 4.0, for monthly market reviews.
 * Free API: non-commercial use only (user decision 2026-11-01, see
 * market-review/decisions.md). Limits: fewer than 10,000 calls per day, 5,000 per
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

    // Observed HOURLY weather for own spot price models (part "observed_hourly"): wind at the
    // neighbour wind-power points, from the same ERA5 reanalysis as the daily part. Hours are UTC
    // (timezone=GMT, timeformat=unixtime); --from/--to dates of this part are UTC days. Both
    // heights are stored: the FMI forecasts of these points are 10 m wind, the reference model's
    // history is 100 m. Hours without any value (ERA5 delay) are not stored.
    'observed_hourly_model' => 'era5',
    'observed_hourly_variables' => ['wind_speed_10m', 'wind_speed_100m'],

    // Hourly forecast vintages, stored append-only in hourly_forecast_runs (source open_meteo).
    // Not in the default run: each product has its own schedule (--only=<key>).
    'hourly_forecasts' => [
        // Global horizontal irradiance (GHI) forecast at the FMI radiation stations; it must
        // match the FMI GLOB_1MIN history, which is also horizontal. One model, so that one
        // vintage is one model run: ECMWF IFS HRES 9 km (15 days, runs every 6 hours, hourly to
        // +90 h, 3-hourly to +144 h, 6-hourly later; Open-Meteo interpolates to 1 h).
        // shortwave_radiation is the mean of the PRECEDING hour, so the value at API time T is
        // stored at the hour that starts at T - 1 h (time_shift_seconds).
        'solar_ghi' => [
            'url' => 'https://api.open-meteo.com/v1/forecast',
            'model' => 'ecmwf_ifs',
            // Same unofficial metadata pattern as the ensemble products; best effort.
            'meta_url' => 'https://api.open-meteo.com/data/ecmwf_ifs/static/meta.json',
            'forecast_days' => 10,
            'variables' => ['shortwave_radiation'],
            'points' => 'solar_points',
            'time_shift_seconds' => -3600,
        ],
        // Issue-day weather of the own S model (s_v1, tasks/own-forecast-model-data/
        // s-model-freeze.md): the ECMWF IFS HRES 00 UTC run of the Helsinki day, from the Single
        // Runs API, so one vintage is exactly that run. This is the source the model was trained on
        // (research/s_weather_single_runs.py: same model, variables, 9 days and points). User
        // decision 2026-11-06 (option A): all four variables come from here, an exception to
        // "Open-Meteo only where FMI has no data"; FMI's ECMWF copy is still collected for the
        // comparison. Values stay at the API time (no shift), as in the training data. A run that
        // is not ready yet answers HTTP 400 "not available"; the command then waits quietly.
        'ecmwf_00z' => [
            'url' => 'https://single-runs-api.open-meteo.com/v1/forecast',
            'model' => 'ecmwf_ifs',
            'run_hour_utc' => 0,
            'forecast_days' => 9,
            'variables' => ['temperature_2m', 'wind_speed_10m', 'wind_speed_100m', 'shortwave_radiation'],
            // The 29 FMI stations (point key = FMISID string) and the 9 neighbour wind points.
            'points' => ['fmi.weather_stations', 'open_meteo.neighbour_wind_points'],
        ],
    ],

    // Expected response units (wind is requested in m/s; the API default is km/h).
    'units' => [
        'wind_speed_10m' => 'm/s',
        'wind_speed_100m' => 'm/s',
        'shortwave_radiation' => 'W/m²',
        'temperature_2m' => '°C',
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

    /*
     * Neighbour wind-power points for own spot price models (part "observed_hourly"). The same
     * keys are used by the FMI forecast collector for the forecasts of these points. Source:
     * nordpool-predict-fi util/openmeteo_windpower.py (commit f600ab7), see
     * tasks/own-forecast-model-data/spec.md.
     */
    'neighbour_wind_points' => [
        'ee01_hiiumaa' => ['name' => 'Hiiumaa (EE)', 'latitude' => 58.8960, 'longitude' => 22.5605],
        'ee02_sopi_tootsi' => ['name' => 'Sopi-Tootsi (EE)', 'latitude' => 58.6347, 'longitude' => 25.1230],
        'dk01_vesterhav' => ['name' => 'Vesterhav (DK)', 'latitude' => 56.4260, 'longitude' => 8.1281],
        'dk02_anholt' => ['name' => 'Anholt (DK)', 'latitude' => 56.6013, 'longitude' => 11.1047],
        'de01_schleswig_holstein' => ['name' => 'Schleswig-Holstein (DE)', 'latitude' => 54.2194, 'longitude' => 9.6961],
        'de02_niedersachsen' => ['name' => 'Niedersachsen (DE)', 'latitude' => 52.6367, 'longitude' => 9.8451],
        'se01_markbygden' => ['name' => 'Markbygden (SE)', 'latitude' => 65.2536, 'longitude' => 21.6020],
        'se02_blakliden' => ['name' => 'Blakliden (SE)', 'latitude' => 64.5, 'longitude' => 17.0],
        'se03_hajsberget' => ['name' => 'Häjsberget (SE)', 'latitude' => 59.7852, 'longitude' => 13.0042],
    ],

    /*
     * Solar sites: the 7 FMI open-data radiation stations (point key = FMISID as a string), so
     * the Open-Meteo forecast and the FMI GLOB_1MIN history have the same points. Coordinates:
     * tasks/own-forecast-model-data/decisions.md (2026-11-01).
     */
    'solar_points' => [
        '100908' => ['name' => 'Parainen Utö', 'latitude' => 59.77909, 'longitude' => 21.37479],
        '100968' => ['name' => 'Helsinki-Vantaa airport', 'latitude' => 60.32937, 'longitude' => 24.97274],
        '101004' => ['name' => 'Helsinki Kumpula', 'latitude' => 60.20307, 'longitude' => 24.96131],
        '101104' => ['name' => 'Jokioinen Ilmala', 'latitude' => 60.81397, 'longitude' => 23.49825],
        '101756' => ['name' => 'Sotkamo Kuolaniemi', 'latitude' => 64.11197, 'longitude' => 28.33639],
        '101932' => ['name' => 'Sodankylä Tähtelä', 'latitude' => 67.36663, 'longitude' => 26.62901],
        '102035' => ['name' => 'Utsjoki Kevo', 'latitude' => 69.75637, 'longitude' => 27.00678],
    ],
];
