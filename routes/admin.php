<?php

use App\Http\Controllers\AdminDashboard\AnnouncementsController;
use App\Http\Controllers\AdminDashboard\AuthController;
use App\Http\Controllers\AdminDashboard\AzkarController;
use App\Http\Controllers\AdminDashboard\CountriesCitiesController;
use App\Http\Controllers\AdminDashboard\DashboardSearchController;
use App\Http\Controllers\AdminDashboard\EventsController;
use App\Http\Controllers\AdminDashboard\HadithsController;
use App\Http\Controllers\AdminDashboard\IqamaTimeSettingsController;
use App\Http\Controllers\AdminDashboard\JumaaSettingsController;
use App\Http\Controllers\AdminDashboard\MasjidAboutUsController;
use App\Http\Controllers\AdminDashboard\MasjidAdminsController;
use App\Http\Controllers\AdminDashboard\MasjidDetailsController;
use App\Http\Controllers\AdminDashboard\MasjidDonationLinkController;
use App\Http\Controllers\AdminDashboard\MasjidGalleryController;
use App\Http\Controllers\AdminDashboard\MasjidsController;
use App\Http\Controllers\AdminDashboard\MasjidMobileAppFeaturesController;
use App\Http\Controllers\AdminDashboard\NotificationsController;
use App\Http\Controllers\AdminDashboard\ServicesController;
use App\Http\Controllers\AdminDashboard\TasabihController;
use App\Http\Controllers\AdminDashboard\UsersController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::controller(AuthController::class)->group(function () {
            Route::get('/user', [AuthController::class, 'user']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/profile', [AuthController::class, 'updateProfile']);
        });

        Route::prefix('users')->middleware('super')->controller(UsersController::class)->group(function () {
            // User account control
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{user_id}', 'show');
            Route::post('/{user_id}', 'update');
            Route::delete('/{user_id}', 'destroy');
            Route::delete('/{user_id}/trash', 'moveToTrash');
        });

        Route::get('search', [DashboardSearchController::class, 'searchForSuperDataRecords'])->middleware('super');

        Route::prefix('masjids')->group(function () {

            Route::controller(MasjidsController::class)->group(function () {

                // Masjid account control
                Route::get('/{masjid_id}', 'show');

                // Only for super admin
                Route::middleware('super')->group(function () {
                    // Masjid account control
                    Route::get('/', 'index');
                    Route::post('/', 'store');
                    Route::post('/{masjid_id}', 'update');
                    Route::delete('/{masjid_id}', 'destroy');
                    Route::delete('/{masjid_id}/trash', 'moveToTrash');
                });
            });

            // Masjid gallery control
            Route::prefix('{masjid_id}')->controller(MasjidGalleryController::class)->group(function () {
                Route::get('/gallery', 'index');
                Route::post('/gallery', 'store');
                Route::delete('/gallery/{media_id}', 'delete');
            });

            // Masjid related details control (phone, email, socialmedia links)
            Route::prefix('{masjid_id}/details')->controller(MasjidDetailsController::class)->group(function () {
                Route::get('/', 'getDetails');
                Route::post('/', 'updateDetails');
            });

            // Masjid announcements
            Route::prefix('{masjid_id}/announcements')->controller(AnnouncementsController::class)->group((function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{annoncement_id}', 'show');
                Route::post('/{annoncement_id}', 'update');
                Route::delete('/{annoncement_id}', 'destroy');
                Route::delete('/{annoncement_id}/trash', 'moveToTrash');
            }));

            // Masjid events
            Route::prefix('{masjid_id}/events')->controller(EventsController::class)->group((function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{event_id}', 'show');
                Route::put('/{event_id}', 'update');
                Route::delete('/{event_id}', 'destroy');
            }));

            // Masjid services
            Route::prefix('{masjid_id}/services')->controller(ServicesController::class)->group((function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{service_id}', 'show');
                Route::post('/{service_id}', 'update');
                Route::delete('/{service_id}', 'destroy');
                Route::delete('/{service_id}/trash', 'moveToTrash');
            }));

            // Masjid donation link
            Route::prefix('{masjid_id}/donation-link')->controller(MasjidDonationLinkController::class)->group((function () {
                Route::get('/', 'index');
                Route::post('/', 'save');
            }));

            // Masjid about
            Route::prefix('{masjid_id}/about')->controller(MasjidAboutUsController::class)->group((function () {
                Route::get('/', 'index');
                Route::post('/', 'save');
            }));

            // Masjid related mobile app features
            Route::prefix('{masjid_id}/features')->controller(MasjidMobileAppFeaturesController::class)->middleware('super')->group(function () {
                Route::get('/', 'index');
                Route::put('/{feature_id}', 'update');
            });

            // Masjid iqama time settings
            Route::prefix('{masjid_id}/iqama')->controller(IqamaTimeSettingsController::class)->group((function () {
                Route::get('/', 'index');
                Route::post('/', 'save');
            }));

            // Masjid iqama time settings
            Route::prefix('{masjid_id}/jumaa')->controller(JumaaSettingsController::class)->group((function () {
                Route::get('/', 'index');
                Route::post('/', 'save');
            }));

            // Masjid iqama time settings
            Route::prefix('{masjid_id}/notifications')->controller(NotificationsController::class)->group((function () {
                Route::post('/', 'save');
            }));

            Route::get('{masjid_id}/search', [DashboardSearchController::class, 'searchForMasjidDataRecords']);
        });

        Route::prefix('countries')->middleware('super')->controller(CountriesCitiesController::class)->group(function () {
            Route::get('/', 'countries');
            Route::get('/{country_id}/cities', 'countryCities');
        });

        Route::prefix('admins')->middleware('super')->group(function () {
            Route::prefix('masjid')->controller(MasjidAdminsController::class)->group(function () {
                Route::get('/', 'index');
                Route::get('/available', 'availableAdmins');
            });
        });

        Route::prefix('hadiths')->controller(HadithsController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{hadith_id}', 'show');
            Route::put('/{hadith_id}', 'update');
            Route::delete('/{hadith_id}', 'destroy');
        });

        Route::prefix('azkar')->controller(AzkarController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/categories', 'categories');
            Route::post('/', 'store');
            Route::get('/{zikr_id}', 'show');
            Route::put('/{zikr_id}', 'update');
            Route::delete('/{zikr_id}', 'destroy');
        });

        Route::prefix('tasabih')->controller(TasabihController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{tasbih_id}', 'show');
            Route::put('/{tasbih_id}', 'update');
            Route::delete('/{tasbih_id}', 'destroy');
        });
    });
});
