<?php

use App\Http\Controllers\Mobile\AnnouncementsController;
use App\Http\Controllers\Mobile\AzkarController;
use App\Http\Controllers\Mobile\ContactReasonsController;
use App\Http\Controllers\Mobile\ContactUsController;
use App\Http\Controllers\Mobile\DonationsController;
use App\Http\Controllers\Mobile\EventsController;
use App\Http\Controllers\Mobile\HadithsController;
use App\Http\Controllers\Mobile\MasjidsController;
use App\Http\Controllers\Mobile\MasjidMobileAppFeaturesController;
use App\Http\Controllers\Mobile\MobileAppUsersController;
use App\Http\Controllers\Mobile\NotificationsController;
use App\Http\Controllers\Mobile\PrayersController;
use App\Http\Controllers\Mobile\AppConfigController;
use App\Http\Controllers\Mobile\ServicesController;
use App\Http\Controllers\Mobile\SignageController;
use App\Http\Controllers\Mobile\SplashAnnouncementsController;
use App\Http\Controllers\Mobile\TasabihController;
use App\Http\Controllers\Mobile\TvConfigController;
use App\Http\Controllers\ProvisioningCallbackController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Security: every public mobile/v1 endpoint is rate-limited via the named
 * "mobile" limiter (60/min/IP, configured in AppServiceProvider). The contact
 * form and device registration get tighter limits ("contact", "device") because
 * those write to the database and are the most attractive spam vectors.
 */

Route::prefix('mobile')->middleware('throttle:mobile')->group(function () {

    // Identify and save mobile app user device — tighter limit (DB-writing endpoint).
    Route::prefix('user')->controller(MobileAppUsersController::class)
        ->middleware('throttle:device')->group(function () {
        Route::post('/', 'store');
        Route::put('/', 'update');
        Route::post('/heartbeat', 'heartbeat');
        Route::get('/masjid', 'masjidDetails');
    });

    // Per-masjid read routes (cached server-side from Phase 1).
    Route::prefix('masjids')->group(function () {

        Route::controller(MasjidsController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{masjid_id}', 'show');
            Route::get('/{masjid_id}/gallery', 'gallery');
            Route::get('/{masjid_id}/donation-link', 'donationLink');
            Route::get('/{masjid_id}/about', 'about');
        });

        // Emergency app-version gate. iOS + Android read this on launch to decide
        // whether to force-update, show maintenance, or soft-prompt. Per-masjid
        // now — each white-labeled listing carries its own build gate.
        Route::get('/{masjid_id}/app-config', [AppConfigController::class, 'index']);

        Route::get('/{masjid_id}/prayers', [PrayersController::class, 'index']);
        Route::get('/{masjid_id}/prayers/settings', [PrayersController::class, 'prayersSettings']);
        Route::get('/{masjid_id}/announcements', [AnnouncementsController::class, 'index']);
        Route::get('/{masjid_id}/events', [EventsController::class, 'index']);
        Route::get('/{masjid_id}/notifications', [NotificationsController::class, 'index']);
        Route::get('/{masjid_id}/services', [ServicesController::class, 'index']);
        Route::get('/{masjid_id}/contact-reasons', [ContactReasonsController::class, 'index']);

        // Splash / in-app announcement — single active row, 204 when nothing's live.
        // Web (Nuxt) reads this; mobile apps get the same content via OneSignal IAM.
        Route::get('/{masjid_id}/splash', [SplashAnnouncementsController::class, 'current']);

        // tvOS signage board. The tvOS client has always asked for a board
        // endpoint that did not exist (docs/recon-2026-08-11.md); this is it.
        // Serves the broadcasts whose signage channel was selected and whose
        // display window is open — see App\Services\Broadcast\Channels\SignageChannel.
        // Additive: no existing endpoint changes shape or behaviour.
        Route::get('/{masjid_id}/signage', [SignageController::class, 'index']);

        // tvOS display CONFIG — how the board renders, as opposed to /signage
        // above, which is what it renders. The tvOS client has called this exact
        // path since it was written (MasjidKit MasjidEndpoint.tvConfig) and got a
        // 404, which SignageStore.refreshTVConfig() swallows while keeping
        // TVConfig.defaults — so nothing an admin did could ever change a board.
        // The payload matches TVConfig's Codable field-for-field; see the
        // controller. Additive: /signage is unchanged.
        Route::get('/{masjid_id}/tv-config', [TvConfigController::class, 'index']);

        // Contact form: writes to DB, public to anonymous callers — strict throttle.
        Route::prefix('{masjid_id}/contact-us')->controller(ContactUsController::class)->group(function () {
            Route::get('/reasons', 'reasonsList');
            Route::post('/', 'storeMessage')->middleware('throttle:contact');
        });

        // Public donation entry: create a Stripe Checkout Session for a gift to
        // one of the masjid's active funds. Runs UNBOUND (no tenant middleware);
        // the controller filters the fund by masjid_id explicitly. The donation
        // is persisted `pending` here and only finalized by the Stripe webhook.
        Route::post('/{masjid_id}/donations/checkout', [DonationsController::class, 'createCheckoutSession']);

        // Public list of active donation funds — the native donate screen offers
        // these as designations before opening hosted checkout.
        Route::get('/{masjid_id}/funds', [\App\Http\Controllers\Mobile\FundsController::class, 'index']);

        Route::prefix('{masjid_id}/features')->controller(MasjidMobileAppFeaturesController::class)->group(function () {
            Route::get('/', 'index');
        });

    });

    // Global non-masjid library content.
    Route::prefix('azkar')->controller(AzkarController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/categorized', 'azkarCategorized');
    });
    Route::prefix('hadiths')->controller(HadithsController::class)->group(function () {
        Route::get('/today', 'todayHadith');
        Route::get('/', 'index');
    });
    Route::prefix('tasabih')->controller(TasabihController::class)->group(function () {
        Route::get('/', 'index');
    });
});

/*
 * Stripe webhook — the SOURCE OF TRUTH for donation state. Registered OUTSIDE
 * auth + throttle: the HMAC signature verified
 * inside the controller against STRIPE_WEBHOOK_SECRET is the only gate, and
 * Stripe is the legitimate caller. Handler is idempotent + dedups event ids.
 */
Route::prefix('stripe')->group(function () {
    Route::post('webhook', [StripeWebhookController::class, 'handle']);
});

/*
 * Inbound SMS webhook (T-009) — where STOP and START arrive. Registered OUTSIDE
 * auth + throttle for the same reason as the Stripe webhook above: the provider
 * is not a logged-in user, and the HMAC signature verified inside the controller
 * against the account auth token is the only gate. It fails CLOSED — with no
 * token configured nothing is ever accepted, because an unverified endpoint that
 * takes opt-IN keywords would let an attacker re-subscribe numbers that opted
 * out.
 *
 * Outside throttle deliberately: an opt-out that gets rate-limited is an
 * unhonoured opt-out, which under the TCPA is a per-message statutory liability
 * for the organisation. The handler is idempotent (suppressing a suppressed
 * number updates one row), so provider retries cost nothing.
 *
 * Named so the operator checklist in .claude/rules/broadcasts.md can point at
 * route('sms.webhook') as the URL to configure on the Messaging Service.
 */
Route::prefix('sms')->group(function () {
    Route::post('webhook', [\App\Http\Controllers\SmsWebhookController::class, 'handle'])
        ->name('sms.webhook');
});

/*
 * App-provisioning callback — the SELF-HOSTED RUNNER reports job progress here.
 * Registered OUTSIDE auth:sanctum/super (the runner is not a logged-in user),
 * like the Stripe/Pusher webhooks above. It is authenticated per-request by the
 * per-job `callback_token`: the controller looks up the job by `job_id` and
 * constant-time compares it against the `Authorization: Bearer` header, so no
 * session/PAT is involved. Named so route('provisioning.callback') resolves the
 * absolute callback_url baked into each dispatch payload.
 */
Route::prefix('provisioning')->group(function () {
    Route::post('callback', [ProvisioningCallbackController::class, 'handle'])
        ->name('provisioning.callback');
});

/*
 * The Pusher realtime path was REMOVED on 2026-08-11 after an audit found every
 * part of it inert: no event was ever dispatched (the /api/spa/broadcast debug
 * endpoint that fired one had already been deleted in the security sweep, for
 * allowing unauthenticated broadcasts to a private channel); the webhook that
 * confirmed delivery could never authenticate, because PUSHER_WEBHOOK_SECRET was
 * never set in production and it fails closed; and the flag it wrote,
 * `notifications.is_broadcasted`, does not exist as a column and was not
 * fillable, so Eloquent silently dropped the write while the endpoint answered
 * "Notification broadcast confirmed".
 *
 * Per-channel delivery is now recorded properly by T-008: see `broadcasts` and
 * `broadcast_deliveries`, and .claude/rules/broadcasts.md.
 *
 * Production still has BROADCAST_CONNECTION=pusher and PUSHER_* credentials in
 * its .env; nothing reads them any more, so they can be retired at leisure.
 */

require 'api_v1.php';
