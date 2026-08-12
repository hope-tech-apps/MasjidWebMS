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
    | GitHub (app-provisioning control plane — repository_dispatch)
    |--------------------------------------------------------------------------
    |
    | The Super-Admin "Generate apps" action fires a GitHub repository_dispatch
    | (App\Services\GithubDispatchService) to a mobile repo, whose workflow runs
    | on a self-hosted runner to scaffold + build + upload a masjid's app.
    |
    |  - dispatch_token : org/fine-grained PAT authorizing POST .../dispatches on
    |    the two mobile repos. An org secret the operator wires; NEVER hardcoded
    |    or echoed. When unset, a dispatch returns a clear error (fail-soft) and
    |    the job is marked failed instead of crashing.
    |  - ios_repo / android_repo : the full "owner/repo" each platform dispatches
    |    to (the URL is https://api.github.com/repos/{owner/repo}/dispatches).
    |  - development_team : platform-default Apple Developer Team ID used to sign
    |    managed-tier iOS builds. Overridden per-masjid by
    |    masjid_app_publishing.development_team (BYO) when present.
    |  - ios_bundle_prefix : default reverse-DNS prefix for a derived iOS
    |    bundle id when the request supplies no explicit bundle_id.
    |
    */
    'github' => [
        'dispatch_token' => env('GITHUB_DISPATCH_TOKEN'),
        'ios_repo' => env('GITHUB_IOS_REPO', 'hope-tech-apps/burlington-masjid-iOS'),
        'android_repo' => env('GITHUB_ANDROID_REPO', 'hope-tech-apps/burlington-masjid-Android'),
        'development_team' => env('APPLE_DEVELOPMENT_TEAM'),
        'ios_bundle_prefix' => env('IOS_BUNDLE_PREFIX', 'com.hopetechapps'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google (server-side Geocoding — onboarding wizard)
    |--------------------------------------------------------------------------
    |
    | A SERVER-SIDE Google Geocoding API key used by
    | App\Services\Onboarding\GeocodingService to turn a typed street address
    | into latitude/longitude during Super-Admin onboarding, so the operator no
    | longer has to look coordinates up by hand. This is DISTINCT from the
    | browser Maps key baked into the public Nuxt site — a browser key is
    | HTTP-referrer restricted and unusable server-side.
    |
    | Optional: when unset, the geocode endpoint fails soft (returns a clear
    | "not configured" message) and the wizard falls back to its manual
    | latitude/longitude inputs — nothing crashes and existing behavior is
    | unchanged. Provision GOOGLE_MAPS_GEOCODING_KEY (a key with the Geocoding
    | API enabled) to turn the feature on. NEVER hardcode the key here.
    |
    */
    'google' => [
        'geocoding_key' => env('GOOGLE_MAPS_GEOCODING_KEY'),
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
        // The CONNECT endpoint's own signing secret. Events raised on a
        // connected account (every registration charge — direct charges live on
        // the ORG's account) are delivered by a separate Stripe webhook
        // endpoint, which signs with a DIFFERENT secret than the platform
        // endpoint above. Both are verified fail-closed by
        // StripeWebhookController; an unset secret simply verifies nothing, it
        // never waves an event through (docs/t006-registration-billing-design.md
        // flagged this as a latent gap in all three proposals).
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),

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

        // How long a paid PENDING registration holds its reserved seat while
        // its Checkout is outstanding (minutes). Set on registrations at
        // intake (T-006b, RegistrationService); consumed by the
        // checkout.session.expired handler (T-006c) and the seat-release
        // reaper (T-006f). The free path never sets a window — it has no
        // Stripe leg to wait on.
        'registration_checkout_window_minutes' => (int) env('STRIPE_REGISTRATION_CHECKOUT_WINDOW_MINUTES', 30),

        // GRACE MARGIN for the expired-checkout reaper (T-006f,
        // registrations:reap-expired), minutes ON TOP OF checkout_expires_at.
        // The reaper only sweeps holds older than expiry + this.
        //
        // Why 15, and why a margin at all: a donor can complete payment in the
        // final second of their checkout window. Stripe then has to DELIVER
        // checkout.session.completed / payment_intent.succeeded to us, and that
        // delivery can lag or need a retry (Stripe's retries start minutes
        // apart). Sweeping inside that gap cancels a seat somebody has already
        // paid for, and the only fix is a human refund from the org's own
        // Stripe dashboard — the org is merchant of record. Holding a dead seat
        // 15 minutes longer merely delays a waitlist opening. Asymmetric costs,
        // so the margin is generous. 15 also stays BELOW the 30-minute minimum
        // checkout window, so a stale hold is never held for more than double
        // its window.
        'registration_reaper_grace_minutes' => (int) env('STRIPE_REGISTRATION_REAPER_GRACE_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS (broadcast channel — provider-agnostic settings)
    |--------------------------------------------------------------------------
    |
    | Settings that belong to the CHANNEL rather than to any one provider. The
    | provider itself is chosen by App\Services\Sms\SmsProviderFactory.
    |
    |  - driver : unset (the default, everywhere, until an operator provisions
    |    credentials) means "twilio if its credentials are present, otherwise the
    |    refusing null adapter". Explicit values are `twilio`, `log` (local
    |    development — accepts and logs, sends nothing) and `none` (always
    |    refuse). It is `none` and NOT `null` because Laravel's env() helper
    |    turns the literal string "null" into PHP null, which would be
    |    indistinguishable from unset and would silently re-enable auto-detect.
    |    phpunit.xml and .env.testing pin `none`, so the suite can never select a
    |    network adapter.
    |  - default_country_code : the country assumed for a bare national number
    |    when normalising to E.164 (App\Services\Sms\PhoneNumber). Numbers that
    |    cannot be resolved with confidence are REFUSED, never guessed — a wrong
    |    normalisation reaches a stranger and matches no suppression row.
    |  - max_body_length : a cost and readability ceiling, not a protocol limit.
    |    480 ~ three GSM-7 segments. The sender identity, the link and the
    |    opt-out sentence are never what gets truncated to fit it.
    |  - opt_out_language : appended by code to EVERY outbound message
    |    (App\Services\Sms\SmsBodyComposer). Carrier rules require it and an
    |    admin must not be able to compose it away.
    |
    | There is deliberately NO platform-wide "from" number here. Which number a
    | tenant sends from is a per-tenant fact with a carrier registration behind
    | it (App\Models\MasjidSmsSender); a shared default is exactly what gets a
    | whole fleet's provider account suspended.
    |
    */
    'sms' => [
        'driver' => env('SMS_DRIVER'),
        'default_country_code' => env('SMS_DEFAULT_COUNTRY_CODE', '1'),
        'max_body_length' => (int) env('SMS_MAX_BODY_LENGTH', 480),
        'opt_out_language' => env('SMS_OPT_OUT_LANGUAGE', 'Reply STOP to unsubscribe.'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio (the first SMS provider adapter — US A2P 10DLC)
    |--------------------------------------------------------------------------
    |
    | Machine-to-machine account credentials, in the same shape as the Stripe and
    | OneSignal blocks: env-driven, UNSET by default, never hardcoded. Unset
    | means the channel fails soft with a clear "not configured" message on the
    | request that asked to send — it never errors at boot and never affects any
    | other channel.
    |
    | The auth token does double duty, as it does at Twilio: it authenticates our
    | outbound API calls AND it is the key their inbound webhook signature is
    | computed with. Unset therefore also means the webhook rejects everything,
    | which is the fail-closed posture the Stripe webhook already takes.
    |
    | `webhook_url` is an optional override for the URL the signature is verified
    | against. Twilio signs the exact URL it dialled, so behind a load balancer
    | that rewrites scheme or host, the URL this application reconstructs will
    | not match and every delivery would fail verification. Set it to the public
    | HTTPS URL of the route when that is the case; leave it unset otherwise.
    |
    | Only ACCOUNT-level credentials live here. A tenant's own number, messaging
    | service and 10DLC registration state live per-masjid in
    | `masjid_sms_senders`.
    |
    */
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'api_base' => env('TWILIO_API_BASE', 'https://api.twilio.com/2010-04-01'),
        'webhook_url' => env('TWILIO_WEBHOOK_URL'),
        'timeout' => (int) env('TWILIO_HTTP_TIMEOUT', 15),
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
