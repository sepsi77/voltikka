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

    'digitransit' => [
        'api_key' => env('DIGITRANSIT_API_KEY'),
        'base_url' => 'https://api.digitransit.fi/geocoding/v1',
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
    ],

    'remotion' => [
        'path' => env('REMOTION_PATH', '/app/remotion'),
        'output_dir' => env('REMOTION_OUTPUT_DIR', '/app/storage/app/videos'),
    ],

];
