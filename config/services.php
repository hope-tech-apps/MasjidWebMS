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
    | Stripe (CRM donations — Connect Standard + direct charges)
    |--------------------------------------------------------------------------
    |
    | The platform uses Stripe Connect STANDARD accounts with DIRECT charges:
    | each masjid is its own merchant of record, the charge is created ON the
    | connected account (Stripe-Account header), funds land in the org's
    | balance, and the platform takes only `application_fee_amount`. The org
    | bears its own refunds/disputes. See app/Services/Stripe and
    | .claude/rules/stripe-payments.md.
    |
    */
    'stripe' => [
        // Publishable + secret API keys (test-mode until the org goes live).
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        // Signing secret used to verify inbound webhooks (the ONLY gate on the
        // webhook route — it is intentionally outside auth/throttle).
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Stripe's standard processing fee. Used ONLY to gross up a charge when
        // the donor elects to cover fees so the org still nets the intended
        // amount — the platform never bills this. 2.9% + 30¢ (minor units).
        'fee_percentage' => (float) env('STRIPE_FEE_PERCENTAGE', 0.029),
        'fee_fixed' => (int) env('STRIPE_FEE_FIXED', 30),

        // The platform's own cut on each direct charge, taken via
        // application_fee_amount (share of the intended amount, minor units).
        // 0 = no platform fee (spike default); application_fee_amount is only
        // sent to Stripe when > 0 (Stripe rejects a zero fee).
        'platform_fee_percentage' => (float) env('STRIPE_PLATFORM_FEE_PERCENTAGE', 0),

        // Default settlement currency (ISO-4217, lower-case) for new donations.
        'currency' => env('STRIPE_CURRENCY', 'usd'),
    ],

];
