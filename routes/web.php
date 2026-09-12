<?php

use App\Http\Controllers\ConnectOnboardingLandingController;
use App\Http\Controllers\UnsubscribeController;
use App\Support\Environment;
use Illuminate\Support\Facades\Route;

/*
 * robots.txt for a NON-production deployment: refuse everything.
 *
 * Paired with the X-Robots-Tag header in SecurityHeaders — that one tells a
 * crawler not to keep a page it already fetched, this one asks it not to fetch
 * at all. A staging box is a scrubbed copy of production carrying the same
 * organisation names and the same donation pages; it must never appear in a
 * congregant's search results.
 *
 * Production 404s here rather than answering, because production already has a
 * STATIC public/robots.txt ("User-agent: * / Disallow:") and that file must
 * remain the single answer there. Note that nginx's `try_files $uri ... ` serves
 * that static file BEFORE PHP is reached, so on any box where the file is
 * present this route is only reachable in tests and via a `location = /robots.txt`
 * that skips straight to index.php — which the staging nginx site must add (or
 * the staging deploy must remove public/robots.txt). Recorded in the T-040
 * handoff; deliberately not solved by deleting the production file here.
 *
 * Declared BEFORE the SPA catch-all below, which would otherwise swallow it.
 */
Route::get('/robots.txt', function () {
    abort_if(Environment::isProduction(), 404);

    return response("User-agent: *\nDisallow: /\n", 200, [
        'Content-Type' => 'text/plain; charset=UTF-8',
    ]);
});

/*
 * Public Stripe Connect onboarding landings.
 *
 * Stripe redirects the org admin's BROWSER to these (the Account Link's
 * return_url / refresh_url), and that browser has no Sanctum token — so they
 * must NOT sit behind auth. See ConnectOnboardingLandingController for why this
 * exists and what it is allowed to expose.
 *
 * Throttled because each `return` hit triggers one Stripe account retrieve.
 * Declared BEFORE the SPA catch-all below, which would otherwise swallow them.
 */
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/connect/{masjid_id}/return', [ConnectOnboardingLandingController::class, 'complete'])
        ->whereNumber('masjid_id')
        ->name('connect.return');

    Route::get('/connect/{masjid_id}/refresh', [ConnectOnboardingLandingController::class, 'expired'])
        ->whereNumber('masjid_id')
        ->name('connect.refresh');
});

/*
 * Public unsubscribe landing for broadcast email (T-042c).
 *
 * No auth, no session, no tenant binding: a person who cannot sign in must still
 * be able to leave, and a mailbox provider's one-click POST carries neither a
 * session nor a CSRF token. The encrypted token in the URL is the whole
 * credential — see App\Http\Controllers\UnsubscribeController and
 * App\Services\Broadcast\EmailSuppressionService for the format, and
 * bootstrap/app.php for the matching CSRF exemption.
 *
 * GET renders a confirmation and changes nothing; POST acts. Mail scanners and
 * SafeLinks follow links in email, so a GET that suppressed would unsubscribe
 * people who never clicked.
 *
 * `throttle:unsubscribe` is deliberately generous and is keyed on the TOKEN
 * rather than on the caller's IP (see AppServiceProvider): the one-click POST is
 * sent by the mailbox provider's infrastructure, not by the person's device, so
 * an IP key would pool every tenant's unsubscribes onto Google's egress and
 * refuse real opt-outs. The link is not guessable, and a rate-limited opt-out is
 * an unhonoured opt-out. The limit exists to bound a bot replaying one link, not
 * to police congregants.
 *
 * Declared BEFORE the SPA catch-all below, which would otherwise swallow them.
 */
Route::middleware('throttle:unsubscribe')->group(function () {
    Route::get('/unsubscribe/{masjid_id}/{token}', [UnsubscribeController::class, 'show'])
        ->whereNumber('masjid_id')
        ->name('unsubscribe.show');

    Route::post('/unsubscribe/{masjid_id}/{token}', [UnsubscribeController::class, 'store'])
        ->whereNumber('masjid_id')
        ->name('unsubscribe.store');

    Route::post('/unsubscribe/{masjid_id}/{token}/resubscribe', [UnsubscribeController::class, 'resubscribe'])
        ->whereNumber('masjid_id')
        ->name('unsubscribe.resubscribe');
});

/*
 * On a hostname a school has pointed at this application, the root of that
 * hostname is the school's portal — not this app's dashboard.
 *
 * A parent told "go to portal.alrazischool.org" types exactly that and nothing
 * more, and landing them on a staff-shaped app root would be a dead end. Hosts
 * that are not mapped are untouched and keep serving the SPA at `/`.
 */
Route::get('/', function () {
    $masjidId = config('portal.hosts')[strtolower(request()->getHost())] ?? null;

    return $masjidId ? redirect('/portal') : view('vue-app-index');
});

Route::get('/{any}', function () {
    return view('vue-app-index');
})->where('any', '^(?!api).*$');
