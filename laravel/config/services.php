<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'entsoe' => [
        'api_key' => env('ENTSOE_API_KEY'),
        'base_url' => 'https://web-api.tp.entsoe.eu/api',
        'finland_eic' => '10YFI-1--------U',
    ],

    'spot_forecasts' => [
        'nordpool_predict_fi' => [
            'url' => env('NORDPOOL_PREDICT_FI_URL', 'https://raw.githubusercontent.com/vividfog/nordpool-predict-fi/main/deploy/prediction.json'),
            'source_url' => 'https://github.com/vividfog/nordpool-predict-fi',
            'region' => 'FI',
            'vat_rate' => env('NORDPOOL_PREDICT_FI_VAT_RATE', 0.255),
            'connect_timeout' => env('NORDPOOL_PREDICT_FI_CONNECT_TIMEOUT', 5),
            'timeout' => env('NORDPOOL_PREDICT_FI_TIMEOUT', 15),
            'retry_attempts' => env('NORDPOOL_PREDICT_FI_RETRY_ATTEMPTS', 3),
            'retry_delay_ms' => env('NORDPOOL_PREDICT_FI_RETRY_DELAY_MS', 1000),
        ],
    ],

    'digitransit' => [
        'api_key' => env('DIGITRANSIT_API_KEY'),
        'base_url' => 'https://api.digitransit.fi/geocoding/v1',
    ],

    'pvgis' => [
        'connect_timeout' => env('PVGIS_CONNECT_TIMEOUT', 3),
        'timeout' => env('PVGIS_TIMEOUT', 12),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => 'https://openrouter.ai/api/v1',
        'default_model' => env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-4'),
    ],

    'postfast' => [
        'api_key' => env('POSTFAST_API_KEY'),
        'spot_social_publishing_enabled' => env('SPOT_SOCIAL_PUBLISHING_ENABLED', false),
    ],

    'remotion' => [
        'path' => env('REMOTION_PATH', '/app/remotion'),
        'output_dir' => env('REMOTION_OUTPUT_DIR', '/app/storage/app/videos'),
    ],

];
