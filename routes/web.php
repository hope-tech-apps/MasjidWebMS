<?php

use App\Http\Controllers\ConnectOnboardingLandingController;
use Illuminate\Support\Facades\Route;

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
