<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Regional price currencies
    |--------------------------------------------------------------------------
    |
    | Plans carry explicit prices in these currencies (plan_prices). Visitors
    | can switch between them on the pricing page; other display currencies
    | are converted for display only and cannot be billed.
    |
    */
    'regions' => [
        'EGP' => ['ar' => 'مصر', 'en' => 'Egypt'],
        'SAR' => ['ar' => 'السعودية', 'en' => 'Saudi Arabia'],
        'AED' => ['ar' => 'الإمارات', 'en' => 'United Arab Emirates'],
        'USD' => ['ar' => 'دولي', 'en' => 'International'],
    ],

    'gateways' => [
        'stripe' => [
            'enabled' => env('BILLING_STRIPE_ENABLED', false),
            'secret_key' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'success_url' => env('STRIPE_SUCCESS_URL', env('APP_URL').'/app/billing?checkout=success'),
            'cancel_url' => env('STRIPE_CANCEL_URL', env('APP_URL').'/app/pricing?checkout=canceled'),
        ],
        'paypal' => [
            'enabled' => env('BILLING_PAYPAL_ENABLED', false),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'sandbox' => env('PAYPAL_SANDBOX', true),
            'success_url' => env('PAYPAL_SUCCESS_URL', env('APP_URL').'/app/billing?checkout=success'),
            'cancel_url' => env('PAYPAL_CANCEL_URL', env('APP_URL').'/app/pricing?checkout=canceled'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        ],
        // Paymob (Egypt, Jordan, Pakistan, Morocco, Saudi Arabia, UAE)
        // MIT save-card pattern — merchant-initiated recurring via scheduler.
        'paymob' => [
            'enabled' => env('BILLING_PAYMOB_ENABLED', false),
            'api_key' => env('PAYMOB_API_KEY'),
            'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
            'integration_id' => (int) env('PAYMOB_INTEGRATION_ID', 0),
            'iframe_id' => env('PAYMOB_IFRAME_ID'),
            'success_url' => env('PAYMOB_SUCCESS_URL', env('APP_URL').'/app/billing?checkout=success'),
            'cancel_url' => env('PAYMOB_CANCEL_URL', env('APP_URL').'/app/pricing?checkout=canceled'),
        ],
        // MyFatoorah (Kuwait, Saudi Arabia, UAE, Bahrain, Oman, Qatar, Jordan)
        // MIT save-token pattern — merchant-initiated recurring via scheduler.
        'myfatoorah' => [
            'enabled' => env('BILLING_MYFATOORAH_ENABLED', false),
            'api_key' => env('MYFATOORAH_API_KEY'),
            'sandbox' => env('MYFATOORAH_SANDBOX', true),
            'success_url' => env('MYFATOORAH_SUCCESS_URL', env('APP_URL').'/app/billing?checkout=success'),
            'cancel_url' => env('MYFATOORAH_CANCEL_URL', env('APP_URL').'/app/pricing?checkout=canceled'),
        ],
        // Fawry (Egypt) — one-time checkout: card, mobile wallet or cash at a Fawry outlet.
        'fawry' => [
            'enabled' => env('BILLING_FAWRY_ENABLED', false),
            'merchant_code' => env('FAWRY_MERCHANT_CODE'),
            'secure_key' => env('FAWRY_SECURE_KEY'),
            'sandbox' => env('FAWRY_SANDBOX', true),
            'success_url' => env('FAWRY_SUCCESS_URL', env('APP_URL').'/app/billing?checkout=success'),
            'cancel_url' => env('FAWRY_CANCEL_URL', env('APP_URL').'/app/pricing?checkout=canceled'),
        ],
        // Tap Payments (Kuwait, Saudi Arabia, UAE, Bahrain, Oman, Qatar, Jordan, Egypt)
        // MIT save-card pattern — merchant-initiated recurring via scheduler.
        'tap' => [
            'enabled' => env('BILLING_TAP_ENABLED', false),
            'api_key' => env('TAP_API_KEY'),
            'success_url' => env('TAP_SUCCESS_URL', env('APP_URL').'/app/billing?checkout=success'),
            'cancel_url' => env('TAP_CANCEL_URL', env('APP_URL').'/app/pricing?checkout=canceled'),
            'webhook_url' => env('TAP_WEBHOOK_URL', env('APP_URL').'/webhooks/tap'),
        ],
    ],
];
