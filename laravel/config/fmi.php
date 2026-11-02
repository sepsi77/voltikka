<?php

/*
 * FMI open data WFS (https://opendata.fmi.fi/wfs), no key, CC BY 4.0. Collected for own spot
 * price models (tasks/own-forecast-model-data/). Limits: 20,000 download requests per day and
 * 600 requests per 5 minutes. See app/Services/Fmi/AGENTS.md for the time-stamp conventions.
 */
return [
    'base_url' => env('FMI_BASE_URL', 'https://opendata.fmi.fi/wfs'),
    'connect_timeout' => (float) env('FMI_CONNECT_TIMEOUT', 10),
    'timeout' => (float) env('FMI_TIMEOUT', 120),
    'request_delay_seconds' => (float) env('FMI_REQUEST_DELAY_SECONDS', 1),
    'retry_times' => 3,
    'retry_sleep_ms' => 5000,

    // Daily window of the observation parts: the last 72 hours up to the current UTC hour.
    'observation_lookback_hours' => 72,

    // Hourly weather observations (TA = air temperature °C, WS = wind speed m/s at 10 m).
    // FMI allows at most 744 hours per request; all stations go in one request.
    'hourly' => [
        'stored_query' => 'fmi::observations::weather::hourly::multipointcoverage',
        'parameters' => ['TA_PT1H_AVG', 'WS_PT1H_AVG'],
        'chunk_hours' => 744,
    ],

    // 1-minute global horizontal irradiance (W/m²), stored as the hourly mean GLOB_PT1H_AVG.
    // FMI allows at most 168 hours per request; all stations go in one request (about 5 MB).
    'radiation' => [
        'stored_query' => 'fmi::observations::radiation::multipointcoverage',
        'parameter' => 'GLOB_1MIN',
        'stored_parameter' => 'GLOB_PT1H_AVG',
        'chunk_hours' => 168,
    ],

    // Forecast vintages, append-only in hourly_forecast_runs (source fmi). Value keys are shared
    // with Open-Meteo where the quantity is the same. 'speed_from' computes wind speed from U/V.
    'forecast_hours' => 240,
    'forecasts' => [
        // FMI edited forecast (forecaster-edited blend), updated about every hour, to about D+9.5.
        'edited' => [
            'stored_query' => 'fmi::forecast::edited::weather::scandinavia::point::multipointcoverage',
            'parameters' => [
                'temperature' => 'temperature_2m',      // °C
                'windspeedms' => 'wind_speed_10m',      // m/s
                'totalcloudcover' => 'total_cloud_cover', // %
            ],
            'stations' => 'weather_stations',
            'latlon_points' => 'neighbour_points',
        ],
        // FMI's copy of the ECMWF surface forecast at every point: the 29 stations, the neighbour
        // points and DE02 (the edited forecast has no data at DE02). The S model is trained on
        // historical ECMWF IFS forecasts (Open-Meteo Previous Runs), so this product gives the same
        // model family live, free (user decision 2026-11-02: test FMI against Open-Meteo). One
        // request for all 38 points: about 0.7 MB, 3 s (tested 2026-11-02). WindSpeedMS is NaN in
        // this product, so the speed is computed from WindUMS/WindVMS.
        'ecmwf' => [
            'stored_query' => 'ecmwf::forecast::surface::point::multipointcoverage',
            'parameters' => [
                'Temperature' => 'temperature_2m', // °C
                'WindUMS' => 'wind_u_10m',         // m/s
                'WindVMS' => 'wind_v_10m',         // m/s
            ],
            'speed_from' => ['wind_u_10m', 'wind_v_10m', 'wind_speed_10m'],
            'stations' => 'weather_stations',
            'latlon_points' => ['neighbour_points', 'ecmwf_points'],
        ],
    ],

    // The 29 stations of the reference model (nordpool-predict-fi). Coordinates are FMI's own
    // station coordinates (2026-11-01); the forecast response is matched to a point by them.
    // 101268 has no observations after mid-2025 (checked 2026-11-01) but still has a forecast.
    'weather_stations' => [
        100908 => ['name' => 'Parainen Utö', 'latitude' => 59.77909, 'longitude' => 21.37479],
        100932 => ['name' => 'Hanko Russarö', 'latitude' => 59.77363, 'longitude' => 22.94868],
        101004 => ['name' => 'Helsinki Kumpula', 'latitude' => 60.20307, 'longitude' => 24.96131],
        101061 => ['name' => 'Rauma Kylmäpihlaja', 'latitude' => 61.14475, 'longitude' => 21.30273],
        101065 => ['name' => 'Turku lentoasema', 'latitude' => 60.51565, 'longitude' => 22.27916],
        101118 => ['name' => 'Tampere-Pirkkala lentoasema', 'latitude' => 61.41940, 'longitude' => 23.62256],
        101191 => ['name' => 'Utti lentoasema', 'latitude' => 60.89839, 'longitude' => 26.94882],
        101237 => ['name' => 'Lappeenranta lentoasema', 'latitude' => 61.04030, 'longitude' => 28.12916],
        101256 => ['name' => 'Kaskinen Sälgrund', 'latitude' => 62.33382, 'longitude' => 21.19081],
        101267 => ['name' => 'Pori Tahkoluoto satama', 'latitude' => 61.63042, 'longitude' => 21.37620],
        101268 => ['name' => 'Kristiinankaupunki majakka', 'latitude' => 62.20324, 'longitude' => 21.16983],
        101462 => ['name' => 'Vaasa lentoasema', 'latitude' => 63.05858, 'longitude' => 21.75460],
        101464 => ['name' => 'Mustasaari Valassaaret', 'latitude' => 63.43508, 'longitude' => 21.06856],
        101481 => ['name' => 'Maalahti Strömmingsbådan', 'latitude' => 62.97839, 'longitude' => 20.74008],
        101485 => ['name' => 'Vaasa Klemettilä', 'latitude' => 63.09871, 'longitude' => 21.63938],
        101537 => ['name' => 'Viitasaari Haapaniemi', 'latitude' => 63.08225, 'longitude' => 25.85862],
        101570 => ['name' => 'Kuopio lentoasema', 'latitude' => 62.99842, 'longitude' => 27.80817],
        101608 => ['name' => 'Joensuu lentoasema', 'latitude' => 62.65695, 'longitude' => 29.63608],
        101660 => ['name' => 'Pietarsaari Kallan', 'latitude' => 63.75144, 'longitude' => 22.52282],
        101661 => ['name' => 'Kokkola Tankar', 'latitude' => 63.95114, 'longitude' => 22.84537],
        101673 => ['name' => 'Kalajoki Ulkokalla', 'latitude' => 64.33073, 'longitude' => 23.44627],
        101783 => ['name' => 'Kemi I majakka', 'latitude' => 65.38508, 'longitude' => 24.09568],
        101784 => ['name' => 'Hailuoto Marjaniemi', 'latitude' => 65.03975, 'longitude' => 24.56118],
        101785 => ['name' => 'Raahe Lapaluoto satama', 'latitude' => 64.66589, 'longitude' => 24.40695],
        101786 => ['name' => 'Oulu lentoasema', 'latitude' => 64.93503, 'longitude' => 25.33920],
        101794 => ['name' => 'Oulu Vihreäsaari satama', 'latitude' => 65.00637, 'longitude' => 25.39325],
        101840 => ['name' => 'Kemi-Tornio lentoasema', 'latitude' => 65.78944, 'longitude' => 24.58278],
        101846 => ['name' => 'Kemi Ajos', 'latitude' => 65.67318, 'longitude' => 24.51519],
        101986 => ['name' => 'Kittilä lentoasema', 'latitude' => 67.69168, 'longitude' => 24.85538],
    ],

    // All 7 FMI open-data radiation stations (the same set in 2023-2026).
    'radiation_stations' => [
        100908 => ['name' => 'Parainen Utö', 'latitude' => 59.77909, 'longitude' => 21.37479],
        100968 => ['name' => 'Vantaa Helsinki-Vantaan lentoasema', 'latitude' => 60.32937, 'longitude' => 24.97274],
        101004 => ['name' => 'Helsinki Kumpula', 'latitude' => 60.20307, 'longitude' => 24.96131],
        101104 => ['name' => 'Jokioinen Ilmala', 'latitude' => 60.81397, 'longitude' => 23.49825],
        101756 => ['name' => 'Sotkamo Kuolaniemi', 'latitude' => 64.11197, 'longitude' => 28.33639],
        101932 => ['name' => 'Sodankylä Tähtelä', 'latitude' => 67.36663, 'longitude' => 26.62901],
        102035 => ['name' => 'Utsjoki Kevo', 'latitude' => 69.75637, 'longitude' => 27.00678],
    ],

    // Neighbour wind points of the reference model (edited forecast by latlon).
    'neighbour_points' => [
        'ee01_hiiumaa' => ['latitude' => 58.8960, 'longitude' => 22.5605],
        'ee02_sopi_tootsi' => ['latitude' => 58.6347, 'longitude' => 25.1230],
        'dk01_vesterhav' => ['latitude' => 56.4260, 'longitude' => 8.1281],
        'dk02_anholt' => ['latitude' => 56.6013, 'longitude' => 11.1047],
        'de01_schleswig_holstein' => ['latitude' => 54.2194, 'longitude' => 9.6961],
        'se01_markbygden' => ['latitude' => 65.2536, 'longitude' => 21.6020],
        'se02_blakliden' => ['latitude' => 64.5, 'longitude' => 17.0],
        'se03_hajsberget' => ['latitude' => 59.7852, 'longitude' => 13.0042],
    ],

    'ecmwf_points' => [
        'de02_niedersachsen' => ['latitude' => 52.6367, 'longitude' => 9.8451],
    ],
];
