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
    /*
    |--------------------------------------------------------------------------
    | Anthropic (Masjid Assistant)
    |--------------------------------------------------------------------------
    | Model is configurable so we can move between tiers without a code change.
    | Default is Sonnet 5: this workload is short-request comprehension + tool
    | selection from a small surface, not long-horizon reasoning. Opus is
    | available by changing one env var if a harder case ever justifies it.
    */
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ASSISTANT_MODEL', 'claude-sonnet-5'),
        'effort' => env('ASSISTANT_EFFORT', 'medium'),
        'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 4096),
        // Hard cap on tool-loop iterations so a confused turn cannot spiral.
        'max_tool_iterations' => (int) env('ASSISTANT_MAX_TOOL_ITERATIONS', 5),
        // Where escalated feature requests are emailed.
        // Comma-separated; the Masjid Assistant emails escalations here.
        'escalation_email' => env('ASSISTANT_ESCALATION_EMAIL', 'moneeb@hopetechapps.com,shaher@hopetechapps.com'),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | OneSignal (org-level machine-to-machine — per-masjid app provisioning)
    |--------------------------------------------------------------------------
    |
    | These are the ORG/ACCOUNT-scoped credentials used by
    | App\Services\OneSignalProvisioningService to CREATE a per-masjid OneSignal
    | app via the "Create an app" API, so onboarding needs no manual OneSignal
    | dashboard step. They are wired machine-to-machine (like the Stripe keys),
    | never hardcoded, and never touched by masjid admins.
    |
    | Distinct from config/onesignal.php: that file holds the runtime SEND
    | credentials for the current SHARED app (app id + app-scoped REST key) used
    | by OnesignalService. THIS block holds the credentials needed to MINT new
    | apps and seed their push certificates. `user_auth_key` intentionally reads
    | the same env var as config('onesignal.user_auth_key') — it is one org key.
    |
    | All optional: when unset, provisioning returns a clear error instead of
    | crashing, and the fleet keeps using the shared app unchanged.
    |
    */
    'onesignal' => [
        // Org/account-scoped "User Auth Key" (a.k.a. "Organization REST API
        // Key"). Authorizes POST https://api.onesignal.com/apps. Same key as
        // config('onesignal.user_auth_key').
        'user_auth_key' => env('ONESIGNAL_USER_AUTH_KEY'),

        // OneSignal Apps API endpoint (create/update apps).
        'apps_api_url' => env('ONESIGNAL_APPS_API_URL', 'https://api.onesignal.com/apps'),

        // Team APNs auth key (.p8 contents), its Key ID and Team ID — used to
        // seed iOS push on every newly created per-masjid app. The .p8 is the
        // raw key file contents (BEGIN PRIVATE KEY … END PRIVATE KEY).
        'apns_p8' => env('ONESIGNAL_APNS_P8'),
        'apns_key_id' => env('ONESIGNAL_APNS_KEY_ID'),
        'apns_team_id' => env('ONESIGNAL_APNS_TEAM_ID'),

        // APNs environment for created apps: 'production' or 'sandbox'.
        'apns_env' => env('ONESIGNAL_APNS_ENV', 'production'),

        // Firebase Cloud Messaging v1 service-account JSON (raw JSON string) —
        // seeds Android push on newly created per-masjid apps.
        'fcm_v1_service_account_json' => env('ONESIGNAL_FCM_V1_SERVICE_ACCOUNT_JSON'),
    ],

];
