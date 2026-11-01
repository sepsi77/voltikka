<?php

/*
 * NVE Magasinstatistikk (https://biapi.nve.no/magasinstatistikk), no key. Weekly reservoir
 * filling for Norway, elspot zones NO1-NO5 (omrType EL) and, if configured, the three
 * watercourse areas (VASS). Published every Wednesday at 13:00 Norwegian time for the ISO week
 * that ended on the previous Sunday. History from 1995. See app/Services/Nve/AGENTS.md.
 */
return [
    'base_url' => env('NVE_MAGASIN_BASE_URL', 'https://biapi.nve.no/magasinstatistikk/api/Magasinstatistikk'),
    'connect_timeout' => (float) env('NVE_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('NVE_TIMEOUT', 60),
    'request_delay_seconds' => (float) env('NVE_REQUEST_DELAY_SECONDS', 2),
    'retry_sleep_ms' => 5000,

    // NO = Norway total (omrnr 0), EL = elspot zones NO1-NO5 (omrnr 1-5).
    'area_types' => ['NO', 'EL'],

    // Default run upserts weekly rows from this many ISO years back (NVE revises rarely).
    'default_years_back' => 1,

    // NVE gives min/median/max "for the last twenty years" without the years. The importer
    // proves the period from the weekly history; this is the number of years.
    'reference_years' => 20,
    'reference_tolerance' => 0.00001,
];
