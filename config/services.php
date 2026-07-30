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

    'facturapi' => [
        'user_key' => env('FACTURAPI_USER_KEY'),
    ],

    'facturama' => [
        'user'     => env('FACTURAMA_USER'),
        'password' => env('FACTURAMA_PASSWORD'),
        'sandbox'  => env('FACTURAMA_SANDBOX', true),
    ],

    'sw_sapiens' => [
        'user'     => env('SW_SAPIENS_USER'),
        'password' => env('SW_SAPIENS_PASSWORD'),
        'sandbox'  => env('SW_SAPIENS_SANDBOX', true),
        'token'    => env('SW_SAPIENS_TOKEN'),  // token estático opcional (Conectia sandbox infinito)
    ],

    'mercadolibre' => [
        'client_id' => env('MELI_CLIENT_ID'),
        'client_secret' => env('MELI_CLIENT_SECRET'),
        'site_id' => env('MELI_SITE_ID', 'MLM'),
        'callback_url' => env('MELI_CALLBACK_URL'),
        'usd_rate' => env('MELI_USD_RATE', 0.05),
    ],

    'whatsapp' => [
        'app_id' => env('WHATSAPP_APP_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
        'login_configuration_id' => env('WHATSAPP_LOGIN_CONFIGURATION_ID'),
        'embedded_signup_url' => env('WHATSAPP_EMBEDDED_SIGNUP_URL'),
        'redirect_uri' => env('WHATSAPP_REDIRECT_URI'),
        'baileys_url' => env('WHATSAPP_BAILEYS_URL', 'http://127.0.0.1:3025'),
        'baileys_token' => env('WHATSAPP_BAILEYS_TOKEN'),
    ],

    'stripe' => [
        'secret'          => env('STRIPE_SECRET_KEY'),
        'publishable'     => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'mediamtx' => [
        'hls_url'  => env('MEDIAMTX_HLS_URL', 'http://localhost:8888'),
        'rtsp_url' => env('MEDIAMTX_RTSP_URL', 'rtsp://localhost:8554'),
        'api_url'  => env('MEDIAMTX_API_URL', 'http://localhost:9997'),
    ],

];
