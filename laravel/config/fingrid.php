<?php

/*
 * Fingrid open data (https://data.fingrid.fi), CC BY 4.0. Collected for monthly market reviews.
 * Limits: one request every 2 seconds and 10,000 requests per 24 h per key.
 *
 * Only power datasets (MW, MWh/h) and hourly datasets belong here: the importer stores the
 * hourly MEAN of the samples. A 15-minute energy dataset (MWh per 15 min) would need a sum.
 * See app/Services/Fingrid/AGENTS.md.
 */
return [
    'api_key' => env('FINGRID_API_KEY'),
    'base_url' => env('FINGRID_BASE_URL', 'https://data.fingrid.fi/api'),
    'connect_timeout' => (float) env('FINGRID_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('FINGRID_TIMEOUT', 30),
    'request_delay_seconds' => (float) env('FINGRID_REQUEST_DELAY_SECONDS', 2.5),
    'retry_sleep_ms' => 3000,
    'page_size' => 20000,

    // Daily fetch window. Datahub data arrives 4 days late and is corrected for 11 days.
    'realtime_lookback_days' => 3,
    'datahub_lookback_days' => 16,

    'datasets' => [
        // System balance
        124 => ['name' => 'consumption', 'unit' => 'MW', 'group' => 'system'],
        74 => ['name' => 'production', 'unit' => 'MW', 'group' => 'system'],
        194 => ['name' => 'net_import', 'unit' => 'MW', 'group' => 'system'],
        // Production by type
        188 => ['name' => 'nuclear', 'unit' => 'MW', 'group' => 'production'],
        75 => ['name' => 'wind', 'unit' => 'MW', 'group' => 'production'],
        191 => ['name' => 'hydro', 'unit' => 'MW', 'group' => 'production'],
        201 => ['name' => 'chp_district_heating', 'unit' => 'MW', 'group' => 'production'],
        202 => ['name' => 'chp_industry', 'unit' => 'MW', 'group' => 'production'],
        205 => ['name' => 'reserve_and_small_scale', 'unit' => 'MW', 'group' => 'production'],
        // Forecasts and capacities
        246 => ['name' => 'wind_forecast_daily', 'unit' => 'MW', 'group' => 'forecast'],
        268 => ['name' => 'wind_capacity', 'unit' => 'MW', 'group' => 'capacity'],
        247 => ['name' => 'solar_forecast_daily', 'unit' => 'MW', 'group' => 'forecast'],
        267 => ['name' => 'solar_capacity', 'unit' => 'MW', 'group' => 'capacity'],
        // Flexibility
        371 => ['name' => 'electric_boilers', 'unit' => 'MW', 'group' => 'flexibility'],
        424 => ['name' => 'battery_capacity', 'unit' => 'MW', 'group' => 'capacity'],
        398 => ['name' => 'battery_discharging', 'unit' => 'MW', 'group' => 'flexibility'],
        399 => ['name' => 'battery_charging', 'unit' => 'MW', 'group' => 'flexibility'],
        // Measured flows. Positive = transmission FROM Finland (export), negative = import.
        // The official day-ahead capacity datasets (24-27, 112, 115) are not listed: since the
        // flow-based market coupling they hold only zeros or nothing (checked 2026-11-01).
        // The real day-ahead limit is JAO maxBorderFlow (not collected yet).
        60 => ['name' => 'flow_fi_se1', 'unit' => 'MW', 'group' => 'transmission'],
        61 => ['name' => 'flow_fi_se3', 'unit' => 'MW', 'group' => 'transmission'],
        55 => ['name' => 'flow_fi_ee', 'unit' => 'MW', 'group' => 'transmission'],
        // Datahub metering (hourly, from 2023-08-01). Series key = user group / band / type.
        364 => ['name' => 'datahub_avg_consumption_by_user_group', 'unit' => 'kWh', 'group' => 'datahub'],
        361 => ['name' => 'datahub_consumption_by_annual_band', 'unit' => 'kWh', 'group' => 'datahub'],
        358 => ['name' => 'datahub_consumption_by_customer_type', 'unit' => 'kWh', 'group' => 'datahub'],
    ],
];
