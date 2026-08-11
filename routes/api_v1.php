<?php


use App\Http\Controllers\Api\V1\AnnouncementsController;
use App\Http\Controllers\Api\V1\AppointmentRequestsController;
use App\Http\Controllers\Api\V1\ContactUsController;
use App\Http\Controllers\Api\V1\FormSubmissionsController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\OfferingRegistrationsController;
use App\Http\Controllers\Api\V1\PagesController;
use App\Http\Controllers\Api\V1\PhotoGalleryController;
use App\Http\Controllers\Api\V1\ServicesController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\ZakatCalculatorController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/home', [HomeController::class, 'index']);
    Route::get('/settings', [SettingController::class, 'index']);

    // Services routes
    Route::prefix('services')->controller(ServicesController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{id}', 'show');
    });

    // Announcements routes
    Route::prefix('announcements')->controller(AnnouncementsController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{id}', 'show');
    });

    // Contact Us routes
    Route::prefix('contact-us')->controller(ContactUsController::class)->group(function () {
        Route::get('/reasons', 'reasonsList');
        Route::post('/', 'storeMessage');
    });

    // Public form submissions.
    //
    // The only unauthenticated DB write in this feature, so it is throttled by name.
    // The rest of /api/v1 carries no middleware at all; do not follow that example for
    // anything that writes.
    Route::post('/forms/{form_id}/responses', [FormSubmissionsController::class, 'store'])
        ->middleware('throttle:form-submit');

    // Public appointment requests (Community vertical, T-021) — the free
    // clinic's intake. An unauthenticated DB write like the form submissions
    // above, so it is throttled by name for the same reason; the tenant comes
    // from the masjid-id header inside the controller.
    Route::post('/appointment-requests', [AppointmentRequestsController::class, 'store'])
        ->middleware('throttle:appointment-request');

    // Public registrations (T-006c). The paid intake for offerings: a
    // server-priced quote, the registration itself, and a re-mint of an
    // abandoned Stripe Checkout Session.
    //
    // `register` and `checkout` are unauthenticated DB writes / money paths, so
    // they are throttled by name exactly like the form submissions above;
    // `quote` writes nothing and gets a looser limit because a registrant
    // legitimately re-prices while choosing a plan. The tenant comes from the
    // masjid-id header inside the controller.
    //
    // NOTE what is absent: no endpoint here can confirm a registration or mark
    // it paid. That happens ONLY in the signature-verified Stripe webhook
    // (.claude/rules/stripe-payments.md) — the browser redirect off Stripe's
    // hosted page proves nothing.
    Route::post('/offerings/{slug}/quote', [OfferingRegistrationsController::class, 'quote'])
        ->middleware('throttle:registration-quote');

    Route::post('/offerings/{slug}/register', [OfferingRegistrationsController::class, 'register'])
        ->middleware('throttle:registration-intake');

    Route::post('/registrations/{uuid}/checkout', [OfferingRegistrationsController::class, 'checkout'])
        ->middleware('throttle:registration-intake');

    // Public zakat calculator (T-031). The odd one out among the public POSTs
    // here: it WRITES NOTHING. It is a POST anyway because its body is a
    // person's net worth, which must not travel in a query string and from
    // there into access logs — while `nisab`, which carries nothing personal,
    // is an ordinary GET.
    //
    // Throttled by name like every other public endpoint in this file, on the
    // looser 'registration-quote' shape rather than the intake shape: a donor
    // legitimately recalculates a dozen times while filling the form in, and
    // there is no row at the end of it to abuse. The tenant comes from the
    // masjid-id header inside the controller.
    Route::prefix('zakat')->controller(ZakatCalculatorController::class)->group(function () {
        Route::get('/nisab', 'nisab')->middleware('throttle:zakat-calculator');
        Route::post('/calculate', 'calculate')->middleware('throttle:zakat-calculator');
    });

    // Photo Gallery routes
    Route::prefix('gallery')->controller(PhotoGalleryController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{id}', 'show');
    });

    // Pages routes
    Route::prefix('pages')->controller(PagesController::class)->group(function () {
        Route::get('/', 'index'); // Get all pages
        Route::get('/menu', 'menu'); // Get menu items
        Route::get('/{slug}', 'show'); // Get page by slug with sections
    });
});
