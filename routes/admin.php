<?php

use App\Http\Controllers\AdminDashboard\AnnouncementsController;
use App\Http\Controllers\AdminDashboard\AuthController;
use App\Http\Controllers\AdminDashboard\AzkarCategoriesController;
use App\Http\Controllers\AdminDashboard\AzkarController;
use App\Http\Controllers\AdminDashboard\ContactReasonsController;
use App\Http\Controllers\AdminDashboard\ContactRequestsController;
use App\Http\Controllers\AdminDashboard\ContactsController;
use App\Http\Controllers\AdminDashboard\CountriesCitiesController;
use App\Http\Controllers\AdminDashboard\DashboardSearchController;
use App\Http\Controllers\AdminDashboard\EventsController;
use App\Http\Controllers\AdminDashboard\HadithCategoriesController;
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
use App\Http\Controllers\AdminDashboard\PagesController;
use App\Http\Controllers\AdminDashboard\PageSectionsController;
use App\Http\Controllers\AdminDashboard\PrayerCalculationSettingsController;
use App\Http\Controllers\AdminDashboard\SectionsController;
use App\Http\Controllers\AdminDashboard\ServicesController;
use App\Http\Controllers\AdminDashboard\SplashAnnouncementsController;
use App\Http\Controllers\AdminDashboard\TasabihController;
use App\Http\Controllers\AdminDashboard\ThemeSettingsController;
use App\Http\Controllers\AdminDashboard\UsersController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    // Security: brute-force defense — 5 attempts per minute keyed on email+IP.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // `tenant` (ResolveMasjidTenant) runs after auth: it binds TenantContext to
    // a MasjidAdmin's masjid and is a no-op for SuperAdmin. Only BelongsToMasjid
    // models consult that context, so existing endpoints are unaffected today.
    Route::middleware(['auth:sanctum', 'admin', 'tenant'])->group(function () {
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

        // Emergency app-version gate (super-admin only). The lever: flip
        // force_update + bump minimum_build to wall off stale installs.
        Route::prefix('app-config')->middleware('super')->controller(\App\Http\Controllers\AdminDashboard\AppConfigController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/{platform}', 'update');
        });

        Route::prefix('masjids')->group(function () {

            // Get timezones list (must be before /{masjid_id} route)
            Route::get('timezones', [MasjidDetailsController::class, 'getTimezones']);

            // Archived (soft-deleted) masjids — must be before the /{masjid_id}
            // show route or "trashed" would be read as a masjid id. Super only.
            Route::get('trashed', [MasjidsController::class, 'trashed'])->middleware('super');

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
                    Route::post('/{masjid_id}/restore', 'restore');
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

            // Masjid general settings (logos, copyright, app links, api keys)
            Route::prefix('{masjid_id}/general-settings')->controller(MasjidDetailsController::class)->group(function () {
                Route::get('/', 'getGeneralSettings');
                Route::post('/', 'updateGeneralSettings');
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

            // Masjid splash announcements (in-app message / splash modal)
            Route::prefix('{masjid_id}/splash-announcements')->controller(SplashAnnouncementsController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{splash_id}', 'show');
                Route::post('/{splash_id}', 'update');
                Route::delete('/{splash_id}', 'destroy');
                Route::delete('/{splash_id}/trash', 'moveToTrash');
            });

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

            // Masjid jumaa time settings
            Route::prefix('{masjid_id}/jumaa')->controller(JumaaSettingsController::class)->group((function () {
                Route::get('/', 'index');
                Route::post('/', 'save');
            }));

            // Masjid prayer calculation settings
            Route::prefix('{masjid_id}/prayer-calculation')->controller(PrayerCalculationSettingsController::class)->group((function () {
                Route::get('/', 'index');
                Route::post('/', 'save');
            }));

            // Masjid color theme settings
            Route::prefix('{masjid_id}/theme')->controller(ThemeSettingsController::class)->group((function () {
                Route::get('/', 'index');
                Route::post('/', 'save');
            }));

            // Get prayer calculation options (methods, madhabs, high latitude rules)
            Route::get('prayer-calculation/options', [PrayerCalculationSettingsController::class, 'getOptions']);

            // Masjid notifications
            Route::prefix('{masjid_id}/notifications')->controller(NotificationsController::class)->group((function () {
                Route::post('/', 'save');
            }));

            // Pages & Sections Management
            Route::prefix('{masjid_id}/pages')->controller(PagesController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::post('/reorder', 'reorder'); // Reorder pages
                Route::get('/{page_id}', 'show');
                Route::put('/{page_id}', 'update');
                Route::delete('/{page_id}', 'destroy');
            });

            // Sections Library Management
            Route::prefix('{masjid_id}/sections')->controller(SectionsController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{section_id}', 'show');
                Route::put('/{section_id}', 'update');
                Route::delete('/{section_id}', 'destroy');
            });

            // Page Sections Management (attach/detach sections to pages)
            Route::prefix('{masjid_id}/pages/{page_id}/sections')->controller(PageSectionsController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store'); // Create new section and attach to page
                Route::post('/attach', 'attach'); // Attach existing section to page
                Route::get('/{section_id}', 'show');
                Route::put('/{section_id}', 'update');
                Route::delete('/{section_id}', 'destroy'); // Detach section from page
            });

            // Get available section types
            Route::get('{masjid_id}/section-types', [PageSectionsController::class, 'sectionTypes']);

            // Contact Requests Management
            Route::prefix('{masjid_id}/contact-requests')->controller(ContactRequestsController::class)->group(function () {
                Route::get('/', 'index');
                Route::get('/{message_id}', 'show');
                Route::post('/{message_id}/reply', 'reply');
                Route::delete('/{message_id}', 'destroy');
            });

            // Masjid contact reasons
            Route::prefix('{masjid_id}/contact-reasons')->controller(ContactReasonsController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{contact_reason_id}', 'show');
                Route::put('/{contact_reason_id}', 'update');
                Route::delete('/{contact_reason_id}', 'destroy');
            });

            // Masjid member directory (CRM congregant contacts). Keeps the
            // {masjid_id} param by convention, but isolation is enforced by the
            // `tenant` middleware + BelongsToMasjid trait — the controller never
            // hand-filters by masjid_id. See .claude/rules/tenant-scoping.md.
            Route::prefix('{masjid_id}/contacts')->controller(ContactsController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{contact_id}', 'show');
                Route::put('/{contact_id}', 'update');
                Route::delete('/{contact_id}', 'destroy');
            });

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

        // Hadith categories (global library content). Registered as its own prefix
        // (not nested under /hadiths) so it never collides with /hadiths/{hadith_id}.
        Route::prefix('hadith-categories')->controller(HadithCategoriesController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{category_id}', 'show');
            Route::put('/{category_id}', 'update');
            Route::delete('/{category_id}', 'destroy');
        });

        Route::prefix('hadiths')->controller(HadithsController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            // Curated library: list presets + copy one into the live collection.
            // Declared before /{hadith_id} so "library" isn't captured as an id.
            Route::get('/library', 'library');
            Route::post('/library/add', 'addFromLibrary');
            Route::get('/{hadith_id}', 'show');
            Route::put('/{hadith_id}', 'update');
            Route::delete('/{hadith_id}', 'destroy');
        });

        // Azkar categories (global library content). Registered as its own prefix
        // (not nested under /azkar) so it never collides with /azkar/{zikr_id}.
        Route::prefix('azkar-categories')->controller(AzkarCategoriesController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{category_id}', 'show');
            Route::put('/{category_id}', 'update');
            Route::delete('/{category_id}', 'destroy');
        });

        Route::prefix('azkar')->controller(AzkarController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/categories', 'categories');
            Route::post('/', 'store');
            // Curated library: list presets + copy one into the live collection.
            // Declared before /{zikr_id} so "library" isn't captured as an id.
            Route::get('/library', 'library');
            Route::post('/library/add', 'addFromLibrary');
            Route::get('/{zikr_id}', 'show');
            Route::put('/{zikr_id}', 'update');
            Route::delete('/{zikr_id}', 'destroy');
        });

        Route::prefix('tasabih')->controller(TasabihController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            // Curated library: list presets + copy one into the live collection.
            // Declared before /{tasbih_id} so "library" isn't captured as an id.
            Route::get('/library', 'library');
            Route::post('/library/add', 'addFromLibrary');
            Route::get('/{tasbih_id}', 'show');
            Route::put('/{tasbih_id}', 'update');
            Route::delete('/{tasbih_id}', 'destroy');
        });
    });
});
