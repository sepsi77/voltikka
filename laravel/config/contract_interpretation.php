<?php

return [
    'enabled' => env('CONTRACT_INTERPRETATION_ENABLED', false),
    'provider' => 'openrouter',
    'model' => env('CONTRACT_INTERPRETATION_MODEL', 'openai/gpt-5.6-luna'),
    'schema_version' => 'schema-v3',
    'prompt_version' => 'prompt-v11',
    'validator_version' => 'validator-v8',
    'schema_path' => resource_path('contract-interpretation/schema-v3.json'),
    'prompt_path' => resource_path('contract-interpretation/system-prompt-v11.md'),
    'reasoning_effort' => env('CONTRACT_INTERPRETATION_REASONING_EFFORT', 'low'),
    'max_tokens' => (int) env('CONTRACT_INTERPRETATION_MAX_TOKENS', 6000),
    'connect_timeout' => (int) env('CONTRACT_INTERPRETATION_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('CONTRACT_INTERPRETATION_TIMEOUT', 120),
    'max_repair_attempts' => (int) env('CONTRACT_INTERPRETATION_MAX_REPAIR_ATTEMPTS', 2),
    'queue' => env('CONTRACT_INTERPRETATION_QUEUE', 'default'),
];
