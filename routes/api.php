<?php

use App\Events\TestNotificationEvent;
use App\Http\Controllers\Mobile\AnnouncementsController;
use App\Http\Controllers\Mobile\AzkarController;
use App\Http\Controllers\Mobile\ContactUsController;
use App\Http\Controllers\Mobile\EventsController;
use App\Http\Controllers\Mobile\HadithsController;
use App\Http\Controllers\Mobile\MasjidsController;
use App\Http\Controllers\Mobile\MasjidMobileAppFeaturesController;
use App\Http\Controllers\Mobile\MobileAppUsersController;
use App\Http\Controllers\Mobile\PrayersController;
use App\Http\Controllers\Mobile\ServicesController;
use App\Http\Controllers\Mobile\TasabihController;
use App\Http\Controllers\PusherWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->group(function () {

    // Identify and save mobile app user device
    Route::prefix('user')->controller(MobileAppUsersController::class)->group(function () {
        Route::post('/', 'store'); // Save user device
        Route::put('/', 'update'); // This route do nothing till now
        Route::get('/masjid', 'masjidDetails'); // Get user masjid details
    });

    // Specific masjid routes
    Route::prefix('masjids')->group(function () {

        Route::controller(MasjidsController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{masjid_id}', 'show');
            Route::get('/{masjid_id}/gallery', 'gallery');
            Route::get('/{masjid_id}/donation-link', 'donationLink');
            Route::get('/{masjid_id}/about', 'about');
        });

        Route::get('/{masjid_id}/prayers', [PrayersController::class, 'index']); // Get masjid prayers
        Route::get('/{masjid_id}/prayers/settings', [PrayersController::class, 'prayersSettings']); // Get masjid prayers
        Route::get('/{masjid_id}/announcements', [AnnouncementsController::class, 'index']); // Get masjid announcements
        Route::get('/{masjid_id}/events', [EventsController::class, 'index']); // Get masjid events
        Route::get('/{masjid_id}/services', [ServicesController::class, 'index']); // Get masjid services

        // Masjid contact us
        Route::prefix('{masjid_id}/contact-us')->controller(ContactUsController::class)->group(function () {
            Route::get('/reasons', 'reasonsList');
            Route::post('/', 'storeMessage');
        });

        // Masjid mobile app features
        Route::prefix('{masjid_id}/features')->controller(MasjidMobileAppFeaturesController::class)->group(function () {
            Route::get('/', 'index');
        });

    });

    // Not-Masjid-Related routes: Azkar, Hadiths, Tasabih
    Route::prefix('azkar')->controller(AzkarController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/categorized', 'azkarCategorized');
    });
    Route::prefix('hadiths')->controller(HadithsController::class)->group(function () {
        Route::get('/today', 'todayHadith');
    });
    Route::prefix('tasabih')->controller(TasabihController::class)->group(function () {
        Route::get('/', 'index');
    });

});

// Broadcasting route
Route::prefix('spa')->group(function () {
    Route::post('/broadcast', function (Request $request) {
        $request->validate(['message' => 'required|string']);
        $event = event(new TestNotificationEvent($request->message));
        return response()->json(['event-broadcasting' => $event, 'message' => $request->message]);
    });
});

Route::prefix('pusher')->group(function () {
    Route::post('notified', [PusherWebhookController::class, 'afterNotificationBroadcasted']);
});

require 'api_v1.php';
