<?php

/*
 * Readiness facts for Temporal workflows (tasks/temporal-orchestration/).
 *
 * `php artisan orchestration:readiness s-forecast --issue-date=D --view=morning|afternoon` checks
 * these inputs. The own S model (`s_v1`) may run only when every input of both views is ready
 * (user decision 2026-11-06). The input list follows the model freeze,
 * tasks/own-forecast-model-data/s-model-freeze.md section 2. Not model inputs, so never gates:
 * the third-party forecast (scoring only), Fingrid 245/246 and FMI forecasts.
 *
 * The ENTSO-E outage fetches before each cut are not database facts: the workflow runs them
 * itself (one before 11:30, one after 14:00 and before 14:30) and treats a failed fetch as a
 * missing input.
 */
return [
    // Phase 2 groups that run as Temporal Schedules, so routes/console.php skips them. Empty on
    // Railway (every group runs in the web scheduler); set on the VPS only (tasks/vps-migration/).
    'temporal_schedule_groups' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TEMPORAL_SCHEDULE_GROUPS', '')),
    ))),

    's_forecast' => [
        'timezone' => 'Europe/Helsinki',

        // Local times on the issue date D. A vintage is visible to a view only when it was
        // fetched at or before the view's cut.
        'cuts' => [
            'morning' => '11:30',
            'afternoon' => '14:30',
        ],

        'inputs' => [
            // Day-ahead prices for every Helsinki hour of the day (23/24/25 hours). The morning
            // view needs D, the afternoon view D+1. Not cut by fetch time: a late auction result
            // is still the D+1 price input.
            ['key' => 'day_ahead_d', 'view' => 'morning', 'type' => 'day_complete', 'day_offset' => 0,
                'table' => 'spot_prices_hour', 'key_column' => 'region',
                'keys' => ['FI', 'SE1', 'SE3', 'EE', 'DE-LU']],
            ['key' => 'day_ahead_d1', 'view' => 'afternoon', 'type' => 'day_complete', 'day_offset' => 1,
                'table' => 'spot_prices_hour', 'key_column' => 'region',
                'keys' => ['FI', 'SE1', 'SE3', 'EE', 'DE-LU']],

            // JAO maxBorderFlow, delivery through the end of D (morning) and D+1 (afternoon).
            ['key' => 'jao_capacity_d', 'view' => 'morning', 'type' => 'day_complete', 'day_offset' => 0,
                'table' => 'jao_max_border_flows', 'key_column' => 'border',
                'keys' => ['SE1_FI', 'SE3_FI', 'EE_FI', 'FI_SE1', 'FI_SE3', 'FI_EE']],
            ['key' => 'jao_capacity_d1', 'view' => 'afternoon', 'type' => 'day_complete', 'day_offset' => 1,
                'table' => 'jao_max_border_flows', 'key_column' => 'border',
                'keys' => ['SE1_FI', 'SE3_FI', 'EE_FI', 'FI_SE1', 'FI_SE3', 'FI_EE']],

            // Fingrid hourly values (upserted, re-read later): a row must exist for every dataset
            // at the last hour before the cut (before 10:00 and before 14:00). No fetched_at cut,
            // because a re-read rewrites it.
            ['key' => 'fingrid_morning', 'view' => 'morning', 'type' => 'fingrid_through',
                'datasets' => [75, 124, 188, 191, 194, 267, 268], 'through_local' => '09:00'],
            ['key' => 'fingrid_afternoon', 'view' => 'afternoon', 'type' => 'fingrid_through',
                'datasets' => [75, 124, 188, 191, 194, 267, 268], 'through_local' => '13:00'],

            // ECMWF IFS HRES 00 UTC run of D (Open-Meteo Single Runs, `weather:fetch --only=ecmwf_00z`):
            // a row with issued_at = D 00:00 UTC for every point that the collector fetches. Both
            // views use the same run; it is checked in the afternoon poll, without a fetch cut.
            ['key' => 'weather_ecmwf_00z', 'view' => 'afternoon', 'type' => 'forecast_run',
                'source' => 'open_meteo', 'product' => 'ecmwf_00z', 'run_hour_utc' => 0,
                'points_from' => ['fmi.weather_stations', 'open_meteo.neighbour_wind_points']],

            // temp_south_7 (freeze section 2): Previous Runs lead-1 temperature at the five southern
            // stations (`weather:fetch --only=ecmwf_prev_day1`, 09:40 and 13:40). Each point needs a
            // row fetched before the view's Fingrid cut (D 10:00 / D 14:00) that covers the 168
            // hours before that cut. issued_at is null (one series mixes runs), so the fetch time
            // is the cut.
            ['key' => 'weather_prev_day1_morning', 'view' => 'morning', 'type' => 'forecast_window',
                'source' => 'open_meteo', 'product' => 'ecmwf_prev_day1', 'cut_local' => '10:00', 'hours_before' => 168,
                'point_keys_from' => 'open_meteo.hourly_forecasts.ecmwf_prev_day1.point_keys'],
            ['key' => 'weather_prev_day1_afternoon', 'view' => 'afternoon', 'type' => 'forecast_window',
                'source' => 'open_meteo', 'product' => 'ecmwf_prev_day1', 'cut_local' => '14:00', 'hours_before' => 168,
                'point_keys_from' => 'open_meteo.hourly_forecasts.ecmwf_prev_day1.point_keys'],

            // FI futures (trade date <= D-1): the same-day eex_futures checkpoint is ready before
            // the morning cut.
            ['key' => 'eex_futures', 'view' => 'morning', 'type' => 'checkpoint_ready',
                'checkpoint' => 'eex_futures'],
        ],
    ],
];
