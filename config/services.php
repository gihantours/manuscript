<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'similarity' => [
        'base_url' => env('SIMILARITY_SERVICE_URL', 'http://127.0.0.1:9000'),
        'timeout' => (int) env('SIMILARITY_TIMEOUT', 10),
        'connect_timeout' => (int) env('SIMILARITY_CONNECT_TIMEOUT', 3),
        'concurrency' => (int) env('SIMILARITY_CONCURRENCY', 4),
    ],

    'notification' => [
        'base_url' => env('NOTIFICATION_SERVICE_URL', 'http://127.0.0.1:9000'),
        'timeout' => (int) env('NOTIFICATION_TIMEOUT', 10),
        'connect_timeout' => (int) env('NOTIFICATION_CONNECT_TIMEOUT', 3),
    ],

];
