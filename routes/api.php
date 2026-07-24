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
use App\Http\Controllers\Mobile\SplashAnnouncementsController;
use App\Http\Controllers\Mobile\TasabihController;
use App\Http\Controllers\ProvisioningCallbackController;
use App\Http\Controllers\PusherWebhookController;
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
 * Pusher webhook — signature-verified inside the controller. No throttle here
 * because Pusher is the legitimate caller and signature already gates abuse.
 */
Route::prefix('pusher')->group(function () {
    Route::post('notified', [PusherWebhookController::class, 'afterNotificationBroadcasted']);
});

/*
 * Stripe webhook — the SOURCE OF TRUTH for donation state. Registered OUTSIDE
 * auth + throttle (like the Pusher webhook above): the HMAC signature verified
 * inside the controller against STRIPE_WEBHOOK_SECRET is the only gate, and
 * Stripe is the legitimate caller. Handler is idempotent + dedups event ids.
 */
Route::prefix('stripe')->group(function () {
    Route::post('webhook', [StripeWebhookController::class, 'handle']);
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
 * The /api/spa/broadcast debug endpoint that fired TestNotificationEvent without
 * authentication was REMOVED in the security sweep. It allowed any unauthenticated
 * caller to broadcast arbitrary messages to a private Pusher channel. If you need
 * it during development, re-add it under a `local` env guard:
 *
 *   if (app()->environment('local')) { Route::post('/spa/broadcast', ...); }
 */

require 'api_v1.php';
