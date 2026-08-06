<?php

return [
    'annual_cost' => [
        'active_method_version' => env(
            'CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION',
            'annual_cost_legacy_v1',
        ),
    ],
];
