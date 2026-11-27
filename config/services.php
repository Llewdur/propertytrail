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

    'accounting' => [
        'url' => env('ACCOUNTING_SERVICE_URL', 'http://127.0.0.1:8093'),
        'token' => env('ACCOUNTING_SERVICE_TOKEN'),
        'client_id' => env('ACCOUNTING_SERVICE_CLIENT_ID'),
        'client_secret' => env('ACCOUNTING_SERVICE_CLIENT_SECRET'),
        'webhook_secret' => env('ACCOUNTING_WEBHOOK_SECRET'),
        'customer_email' => env('ACCOUNTING_CUSTOMER_EMAIL', env('MAIL_FROM_ADDRESS', 'no-reply@propertytrail.co.za')),
    ],

    'whatsapp' => [
        'url' => env('WHATSAPP_SERVICE_URL', 'http://127.0.0.1:8097'),
        'client_id' => env('WHATSAPP_SERVICE_CLIENT_ID'),
        'client_secret' => env('WHATSAPP_SERVICE_CLIENT_SECRET'),
        'provider' => env('WHATSAPP_PROVIDER', 'meta_cloud_api'),
        'credential_alias' => env('WHATSAPP_CREDENTIAL_ALIAS', 'propertytrail'),
        'template_id' => env('WHATSAPP_TEMPLATE_ID', 'propertytrail_notice'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
    ],

    'mail' => [
        'url' => env('MAIL_SERVICE_URL', 'http://127.0.0.1:8084'),
        'client_id' => env('MAIL_SERVICE_CLIENT_ID'),
        'client_secret' => env('MAIL_SERVICE_CLIENT_SECRET'),
        'provider' => env('MAIL_SERVICE_PROVIDER', 'resend'),
        'credential_ref' => env('MAIL_SERVICE_CREDENTIAL_REF', 'propertytrail'),
        'sender_address' => env('MAIL_FROM_ADDRESS', 'no-reply@propertytrail.co.za'),
        'sender_name' => env('MAIL_FROM_NAME', 'PropertyTrail'),
    ],

    // The browser SDK. No write key means no tag at all, so only the production host has one.
    'pulse' => [
        'api_base' => env('PULSE_API_BASE') ?: 'https://pulse.zekini.co.za',
        'write_key' => env('PULSE_WRITE_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/auth/google/callback'),
    ],
];
