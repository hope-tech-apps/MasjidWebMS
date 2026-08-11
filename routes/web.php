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

Route::get('/{any}', function () {
    return view('vue-app-index');
})->where('any', '^(?!api).*$');
