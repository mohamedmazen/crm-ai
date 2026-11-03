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

    /*
    |--------------------------------------------------------------------------
    | Meta / Facebook Graph API
    |--------------------------------------------------------------------------
    |
    | Graph API version used by every WhatsApp, Messenger, Instagram and social
    | posting call. Meta deprecates versions on a fixed schedule, so the version
    | lives in one place (here) instead of being hardcoded per call site. Override
    | with META_GRAPH_VERSION in .env.
    */
    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Socialite Providers
    |--------------------------------------------------------------------------
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI', '/auth/github/callback'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI', '/auth/microsoft/callback'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'),
    ],

    'onesignal' => [
        'app_id' => env('ONESIGNAL_APP_ID', ''),
        'rest_api_key' => env('ONESIGNAL_REST_API_KEY', ''),
    ],

    'qdrant' => [
        'url' => env('QDRANT_URL', ''),
        'api_key' => env('QDRANT_API_KEY', ''),
    ],

    'telnyx' => [
        'webhook_secret' => env('TELNYX_WEBHOOK_SECRET'),
    ],

    'clicksend' => [
        'webhook_secret' => env('CLICKSEND_WEBHOOK_SECRET'),
    ],

    'sms_misr' => [
        'webhook_secret' => env('SMS_MISR_WEBHOOK_SECRET'),
    ],

    'cequens' => [
        'webhook_secret' => env('CEQUENS_WEBHOOK_SECRET'),
    ],

    'unifonic' => [
        'webhook_secret' => env('UNIFONIC_WEBHOOK_SECRET'),
    ],

    'taqnyat' => [
        'webhook_secret' => env('TAQNYAT_WEBHOOK_SECRET'),
    ],

    'msegat' => [
        'webhook_secret' => env('MSEGAT_WEBHOOK_SECRET'),
    ],

    'infobip' => [
        'webhook_secret' => env('INFOBIP_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Form Spam Protection
    |--------------------------------------------------------------------------
    |
    | Per-form CAPTCHA providers. The site key is exposed to the public form so
    | the browser can render the widget; the secret is only used server-side to
    | verify the token the widget returns.
    */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET_KEY'),
    ],

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret' => env('RECAPTCHA_SECRET_KEY'),
    ],

];
