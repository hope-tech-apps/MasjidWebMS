<?php

use App\Http\Controllers\Mobile\AnnouncementsController;
use App\Http\Controllers\Mobile\AppMenuController;
use App\Http\Controllers\Mobile\AzkarController;
use App\Http\Controllers\Mobile\ContactReasonsController;
use App\Http\Controllers\Mobile\ContactUsController;
use App\Http\Controllers\Mobile\DonationsController;
use App\Http\Controllers\Mobile\EventsController;
use App\Http\Controllers\Mobile\HadithsController;
use App\Http\Controllers\Mobile\MasjidsController;
use App\Http\Controllers\Mobile\MasjidMobileAppFeaturesController;
use App\Http\Controllers\Mobile\Member\MemberAuthController;
use App\Http\Controllers\Mobile\Member\MemberDeviceController;
use App\Http\Controllers\Mobile\Member\MemberInterestsController;
use App\Http\Controllers\Mobile\Member\MemberRecurringGivingController;
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
 * "mobile" limiter: per phone when the request names its device, under a
 * per-network ceiling sized for a venue (AppServiceProvider, config/mobile.php).
 * The contact form ("contact") and donation checkout ("mobile-checkout") get
 * tighter limits. The app's device endpoints get layered per-phone + per-network
 * limits ("device", "device-activity"), so a crowd on one shared network still
 * works (DECISIONS.md 2026-09-15).
 */

Route::prefix('mobile')->middleware('throttle:mobile')->group(function () {

    // Identify and save the mobile app user's device.
    Route::prefix('user')->controller(MobileAppUsersController::class)->group(function () {
        // Register / update: INSERTs rows, so the tighter bucket.
        Route::middleware('throttle:device')->group(function () {
            Route::post('/', 'store');
            Route::put('/', 'update');
        });

        // Heartbeat + device→masjid lookup: create no rows, and they follow
        // launches rather than installs, so they get their own looser bucket.
        Route::middleware('throttle:device-activity')->group(function () {
            Route::post('/heartbeat', 'heartbeat');
            Route::get('/masjid', 'masjidDetails');
        });
    });

    // Backward-compat: apps already installed call the GLOBAL app-config on
    // launch (the version gate is per-masjid now, below). A 404 here hangs those
    // installs on the splash screen, so answer it and fail open.
    Route::get('/app-config', [AppConfigController::class, 'platformConfig']);

    // Per-masjid read routes (cached server-side from Phase 1).
    Route::prefix('masjids')->group(function () {

        Route::controller(MasjidsController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{masjid_id}', 'show');
            Route::get('/{masjid_id}/gallery', 'gallery');
            Route::get('/{masjid_id}/donation-link', 'donationLink');
            Route::get('/{masjid_id}/about', 'about');
            // The organisations this app may switch into: this one + its
            // published children. See MasjidsController::orgs.
            Route::get('/{masjid_id}/orgs', 'orgs');
        });

        // The app's side menu, derived from this organisation's switches: one
        // profile per organisation the app may switch into, each with its own
        // sections, tab bar and theme. Public and unauthenticated like its
        // neighbours; 404 when the org is unknown or the kill row is set, which
        // the apps answer by falling back to /features + /orgs.
        Route::get('/{masjid_id}/menu', [AppMenuController::class, 'show'])->name('mobile.menu');

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
        // `mobile-checkout` keeps this at 60/min per IP: the group's `mobile`
        // ceiling was raised for crowds, and every call here creates a Stripe
        // session and a pending row.
        Route::post('/{masjid_id}/donations/checkout', [DonationsController::class, 'createCheckoutSession'])
            ->middleware('throttle:mobile-checkout');

        // Public list of active donation funds — the native donate screen offers
        // these as designations before opening hosted checkout.
        Route::get('/{masjid_id}/funds', [\App\Http\Controllers\Mobile\FundsController::class, 'index']);

        // The LEGACY feature list, which every installed build still reads and
        // which S3b removes. `CountLegacyFeaturesHit` counts each served
        // response per organisation per day, split by whether the caller sent
        // an `X-Manara-App` header — i.e. whether it is an R1 build falling
        // back, or a build shipped before R1 that has nowhere else to go. Our
        // own canary's probes (`X-Canary`) are not counted. It runs in
        // terminate(), after the response, inside a catch-all: the
        // counting can never change or fail this payload. Read it with
        // `php artisan app:legacy-features-report`.
        Route::prefix('{masjid_id}/features')
            ->middleware(\App\Http\Middleware\CountLegacyFeaturesHit::class)
            ->controller(MasjidMobileAppFeaturesController::class)
            ->group(function () {
                Route::get('/', 'index');
            });

        /*
        |--------------------------------------------------------------------
        | App member identity — self-serve sign-up and sign-in
        |--------------------------------------------------------------------
        |
        | The three UNAUTHENTICATED member endpoints. A caller with no token is
        | exactly who they are for, so they cannot sit behind a guard; what they
        | carry instead is `family.guest`, which binds TenantContext from the
        | {masjid_id} in the URL or 404s.
        |
        | THAT MIDDLEWARE IS NOT OPTIONAL. The rest of this file runs UNBOUND
        | (.claude/rules/tenant-scoping.md), and unbound means the global scope
        | adds NO filter — so a Contact lookup here would search every masjid in
        | the database and the mailer would become a cross-tenant existence
        | oracle. ResolveFamilyGuestTenant documents that trap in full.
        |
        | `whereNumber` is load-bearing for the same reason routes/family.php
        | says it is: the per-address throttle bucket is keyed on (int) masjid,
        | so "1", "01" and "1abc" must not be three different doors.
        |
        | There is deliberately no /register. Creating an account is
        | request-code, then verify-code with a name and a password; see
        | MemberAuthController.
        */
        Route::prefix('{masjid_id}/auth')
            ->controller(MemberAuthController::class)
            ->middleware(['family.guest', 'crm'])
            ->whereNumber('masjid_id')
            ->group(function () {
                Route::post('/request-code', 'requestCode')->middleware('throttle:member-login');
                Route::post('/verify-code', 'verifyCode')->middleware('throttle:member-verify');

                // Address + password. `throttle:member-verify` is verify-code's
                // own bucket, on purpose, exactly as the family realm shares
                // `family-verify` between its two doors: separate allowances
                // would give a guesser twice the tries per hour at one address.
                Route::post('/password', 'signInWithPassword')->middleware('throttle:member-verify');
            });

        /*
        | The authenticated member realm.
        |
        | `family.tenant` binds the tenant from the TOKEN's contact, not from
        | the URL — the {masjid_id} segment is kept only so these paths match
        | every other mobile endpoint, and nothing downstream reads it. That is
        | what stops a member's token being pointed at another organisation by
        | editing the path.
        |
        | `member.active` gates on `verified_at`, where the family realm's
        | `family.active` gates on `login_enabled_at`. A self-registered member
        | therefore reaches these routes and NO family route.
        |
        | Two groups. This first one is how a member LEAVES: deleting the
        | account, and releasing the handset on sign-out.
        |
        | The same three gates as the group below and deliberately NOT `crm`.
        | An organisation can switch its CRM off after people signed up, and
        | both of these must still work then: App Store 5.1.1(v) and Google
        | Play both require deletion wherever sign-up exists, and a sign-out
        | that cannot release the phone leaves it receiving that member's
        | notifications. Sign-in and claiming a handset stay behind `crm`.
        |
        | Named `mobile.member.me.*` so every refusal from these two carries
        | an empty `data` object (App\Support\MobileErrorEnvelope, hooked in
        | bootstrap/app.php): the iPhone app cannot decode a body without one.
        | What deleting means is App\Services\Member\MemberAccountDeletion.
        |
        | `member.token` in BOTH groups: the three gates above ask about the
        | contact, not the credential, and a parent's contact can also hold a
        | family-portal token and a child's hand-off token. Only the app's own
        | `member` token may act here (EnsureMemberToken).
        */
        Route::prefix('{masjid_id}')
            ->middleware(['auth:family', 'member.active', 'member.token', 'family.tenant'])
            ->whereNumber('masjid_id')
            ->name('mobile.member.me.')
            ->group(function () {
                Route::delete('/me', [\App\Http\Controllers\Mobile\Member\MemberAccountController::class, 'destroy'])
                    ->name('destroy');
                Route::delete('/me/device', [MemberDeviceController::class, 'destroy'])
                    ->name('device.destroy');
            });

        Route::prefix('{masjid_id}')
            ->middleware(['auth:family', 'member.active', 'member.token', 'family.tenant', 'crm'])
            ->whereNumber('masjid_id')
            ->group(function () {
                Route::get('/interests', [MemberInterestsController::class, 'index']);
                Route::put('/interests', [MemberInterestsController::class, 'update']);

                // Claiming the handset. Without this a service audience
                // resolves to no devices at all, however many members opted in.
                // The app calls store() on sign-in; releasing it (destroy) is in
                // the non-`crm` group above — see MemberDeviceController for why
                // the second half matters.
                Route::post('/me/device', [MemberDeviceController::class, 'store']);

                /*
                | "Your monthly giving" — the donor acting on their OWN standing
                | commitments. The first MONEY verbs in this realm.
                |
                | Addressed by `uuid`, never by `id`. `donation_subscriptions`
                | already mints one, and an incrementing integer in a
                | donor-facing URL is an invitation to walk the range. The
                | pattern constraint means a junk handle is a 404 from the
                | router, before anything queries a money table.
                |
                | Ownership is `uuid` AND `contact_id`, enforced in the
                | controller, and a miss is a 404 — the tenant scope fences off
                | other ORGANISATIONS and would still leave every member of this
                | one inside it. See MemberRecurringGivingController.
                |
                | EVERY verb here carries its OWN throttle on top of the file's
                | `mobile` limiter, because every verb here reaches Stripe.
                | Authenticated, an inline throttle bucket is keyed on the CALLER
                | rather than the address, so a donor on a congested mosque wifi
                | cannot be locked out of cancelling a gift by somebody else's
                | traffic — and nobody can hammer Stripe's API on a connected
                | account through this door.
                |
                | The GET is on that budget too, and it is not the cheap door it
                | looks like: it asks Stripe for the pause state of every live
                | commitment the caller holds (a pause has nowhere local to live —
                | see DonationService), so one request is one `subscriptions.retrieve`
                | per commitment on the ORG's connected account, competing for the
                | same Stripe rate limit as that org's live checkouts. Left on the
                | realm's generic 60/min it would also be the one verb keyed on the
                | IP — the shared bucket the mutating verbs were moved off — which
                | is the opposite of what its cost deserves. 30/min is a screen a
                | donor can refresh freely and no more.
                */
                Route::prefix('/me/recurring-giving')
                    ->controller(MemberRecurringGivingController::class)
                    ->where(['uuid' => '[0-9a-fA-F-]{36}'])
                    ->group(function () {
                        Route::middleware('throttle:30,1')->get('/', 'index');

                        Route::middleware('throttle:20,1')->group(function () {
                            Route::post('/{uuid}/pause', 'pause');
                            Route::post('/{uuid}/resume', 'resume');
                            Route::post('/{uuid}/cancel', 'cancel');
                            Route::patch('/{uuid}', 'updateAmount');
                        });
                    });
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
