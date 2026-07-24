<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Canonical phase-aware pricing
    |--------------------------------------------------------------------------
    |
    | When enabled, public price calculations, rankings, and the deceptive-pricing
    | label are driven by validated `electricity_contracts.canonical_*` interpretation
    | data instead of the raw relational price components. Keep this off until the
    | staged comparison (contracts:compare-canonical-pricing) has been reviewed.
    |
    */
    'enabled' => env('CANONICAL_PRICING_ENABLED', false),
];
