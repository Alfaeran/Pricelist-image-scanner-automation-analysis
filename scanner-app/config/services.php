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

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Integration
    |--------------------------------------------------------------------------
    |
    | Configuration for WhatsApp chatbot integration.
    | Set WHATSAPP_DRIVER to 'meta_cloud' for official API or 'evolution'
    | for self-hosted Evolution API.
    |
    */

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'evolution'),
        'enabled' => env('WHATSAPP_ENABLED', false),

        // Meta WhatsApp Cloud API (Official)
        'meta' => [
            'token' => env('WHATSAPP_META_TOKEN', ''),
            'phone_number_id' => env('WHATSAPP_META_PHONE_ID', ''),
            'verify_token' => env('WHATSAPP_META_VERIFY_TOKEN', ''),
            'app_secret' => env('WHATSAPP_META_APP_SECRET', ''),
        ],

        // Evolution API (Self-hosted, Unofficial)
        'evolution' => [
            'url' => env('WHATSAPP_EVOLUTION_URL', 'http://localhost:8080'),
            'api_key' => env('WHATSAPP_EVOLUTION_API_KEY', ''),
            'instance' => env('WHATSAPP_EVOLUTION_INSTANCE', 'scanner-bot'),
        ],
    ],

];
