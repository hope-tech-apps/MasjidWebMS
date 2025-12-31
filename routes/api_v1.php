<?php


use App\Http\Controllers\Api\V1\AnnouncementsController;
use App\Http\Controllers\Api\V1\ContactUsController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\PagesController;
use App\Http\Controllers\Api\V1\PhotoGalleryController;
use App\Http\Controllers\Api\V1\ServicesController;
use App\Http\Controllers\Api\V1\SettingController;
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
