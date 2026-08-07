<?php

return [
    'enabled' => env('CONTRACT_INTERPRETATION_ENABLED', false),
    'provider' => 'openrouter',
    'model' => env('CONTRACT_INTERPRETATION_MODEL', 'openai/gpt-5.6-luna'),
    'schema_version' => 'schema-v4',
    'prompt_version' => 'prompt-v19',
    'validator_version' => 'validator-v17',
    'schema_path' => resource_path('contract-interpretation/schema-v4.json'),
    'prompt_path' => resource_path('contract-interpretation/system-prompt-v19.md'),
    'reasoning_effort' => env('CONTRACT_INTERPRETATION_REASONING_EFFORT', 'medium'),
    'max_tokens' => (int) env('CONTRACT_INTERPRETATION_MAX_TOKENS', 6000),
    'connect_timeout' => (int) env('CONTRACT_INTERPRETATION_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('CONTRACT_INTERPRETATION_TIMEOUT', 120),
    'max_repair_attempts' => (int) env('CONTRACT_INTERPRETATION_MAX_REPAIR_ATTEMPTS', 2),
    'queue' => env('CONTRACT_INTERPRETATION_QUEUE', 'default'),
    'historical' => [
        'cutoff' => '2026-07-22',
        'addendum_version' => 'historical-addendum-v3',
        'addendum_path' => resource_path('contract-interpretation/historical-system-prompt-addendum-v3.md'),
        'connect_timeout' => 10,
        'timeout' => 100,
        'http_attempts' => 1,
        'queue' => 'historical-interpretation',
        'stale_processing_min_minutes' => 30,
    ],
];
