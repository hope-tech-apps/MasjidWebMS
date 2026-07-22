<?php

use App\Http\Controllers\AdminDashboard\AnnouncementsController;
use App\Http\Controllers\AdminDashboard\AssistantController;
use App\Http\Controllers\AdminDashboard\AuthController;
use App\Http\Controllers\AdminDashboard\AzkarCategoriesController;
use App\Http\Controllers\AdminDashboard\AzkarController;
use App\Http\Controllers\AdminDashboard\ContactReasonsController;
use App\Http\Controllers\AdminDashboard\ContactRequestsController;
use App\Http\Controllers\AdminDashboard\ContactsController;
use App\Http\Controllers\AdminDashboard\CountriesCitiesController;
use App\Http\Controllers\AdminDashboard\DashboardSearchController;
use App\Http\Controllers\AdminDashboard\AnnualStatementsController;
use App\Http\Controllers\AdminDashboard\DonationsController;
use App\Http\Controllers\AdminDashboard\RecurringDonationsController;
use App\Http\Controllers\AdminDashboard\EventsController;
use App\Http\Controllers\AdminDashboard\FundsController;
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
use App\Http\Controllers\AdminDashboard\StripeConnectController;
use App\Http\Controllers\AdminDashboard\TasabihController;
use App\Http\Controllers\AdminDashboard\ThemeSettingsController;
use App\Http\Controllers\AdminDashboard\TwoFactorController;
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

        // Admin self-service TOTP two-factor auth (enroll / confirm / disable).
        // Behind the existing auth:sanctum + admin group; NOT permission-gated —
        // any admin manages 2FA for their own account. Enrollment is opt-in and
        // only takes effect at login once confirmed. See TwoFactorController.
        Route::prefix('2fa')->controller(TwoFactorController::class)->group(function () {
            Route::post('/enroll', 'enroll');
            Route::post('/confirm', 'confirm');
            Route::delete('/', 'disable');
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

            // SuperAdmin-only switch for the per-masjid CRM feature gate. The whole
            // CRM (member directory + money path) is OFF by default
            // (masjids.crm_enabled defaults to false) and only a SuperAdmin can
            // flip it — enforced in MasjidsController::setCrmAccess (abort 403 for
            // anyone non-super). Deliberately OUTSIDE the `crm` group below: a
            // SuperAdmin needs this to turn the gate ON. See
            // .claude/rules/auth-permissions.md.
            Route::patch('{masjid_id}/crm-access', [MasjidsController::class, 'setCrmAccess']);
            // SuperAdmin-only Masjid Assistant gate toggle. Like crm-access, this is
            // deliberately OUTSIDE the `assistant` gate — it is how the gate is opened.
            Route::patch('{masjid_id}/assistant-access', [MasjidsController::class, 'setAssistantAccess']);

            // Masjid Assistant chat. Behind the per-masjid `assistant` gate (which
            // runs after `tenant`, so the caller is already proven to act on this
            // masjid). Which capabilities the AI is actually offered is decided
            // per-tool in ToolRegistry and re-checked at execution.
            // Throttled because every call costs money at Anthropic. 20/min is far
            // above what a person types and far below what a stuck retry loop in the
            // browser would do overnight.
            Route::post('{masjid_id}/assistant/chat', [AssistantController::class, 'chat'])
                ->middleware(['assistant', 'throttle:20,1']);

            // The CRM route group — every endpoint gated by `crm`
            // (EnsureCrmEnabled): 403 unless this masjid's crm_enabled is true.
            // Layered on TOP of the per-route `permission:` checks; never touches
            // the 2FA endpoints, the crm-access toggle above, or any pre-existing
            // route.
            Route::middleware('crm')->group(function () {

                // Masjid member directory (CRM congregant contacts). Keeps the
                // {masjid_id} param by convention, but isolation is enforced by the
                // `tenant` middleware + BelongsToMasjid trait — the controller never
                // hand-filters by masjid_id. See .claude/rules/tenant-scoping.md.
                // Granular CRM authorization (spatie) is layered ONLY on these new
                // endpoints, per-route. It runs AFTER auth:sanctum + admin + tenant,
                // so a MasjidAdmin (bridged to the masjid-admin role, which holds the
                // full CRM permission set) keeps the exact access they have today.
                Route::prefix('{masjid_id}/contacts')->controller(ContactsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view contacts');
                    Route::post('/', 'store')->middleware('permission:manage contacts');
                    Route::get('/{contact_id}', 'show')->middleware('permission:view contacts');
                    Route::put('/{contact_id}', 'update')->middleware('permission:manage contacts');
                    Route::delete('/{contact_id}', 'destroy')->middleware('permission:manage contacts');
                });

                // CRM money path (Phase-0 spike). All tenant-scoped by the `tenant`
                // middleware + BelongsToMasjid — controllers never hand-filter by
                // masjid_id. See .claude/rules/stripe-payments.md.

                // Stripe Connect (Standard account) onboarding for this masjid.
                Route::prefix('{masjid_id}/connect')->controller(StripeConnectController::class)->group(function () {
                    Route::post('/onboarding', 'startOnboarding')->middleware('permission:manage donations');
                    Route::get('/return', 'onboardingReturn')->middleware('permission:manage donations');
                });

                // Donation funds (designations). Viewing is gated by
                // `view donations` (funds are the read side of the money path);
                // any mutation requires `manage funds`.
                Route::prefix('{masjid_id}/funds')->controller(FundsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view donations');
                    Route::post('/', 'store')->middleware('permission:manage funds');
                    Route::get('/{fund_id}', 'show')->middleware('permission:view donations');
                    Route::put('/{fund_id}', 'update')->middleware('permission:manage funds');
                    Route::delete('/{fund_id}', 'destroy')->middleware('permission:manage funds');
                });

                // Donations ledger — READ-ONLY. Rows are created and advanced
                // exclusively by Stripe webhooks, so there is deliberately NO
                // store / update / destroy route here.
                Route::prefix('{masjid_id}/donations')->controller(DonationsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view donations');
                    Route::get('/{donation_id}', 'show')->middleware('permission:view donations');
                });

                // Recurring donations (standing commitments). Read side is
                // `view donations`; canceling a gift is a money mutation, so it
                // requires `manage donations`.
                Route::prefix('{masjid_id}/recurring-donations')->controller(RecurringDonationsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view donations');
                    Route::get('/{subscription_id}', 'show')->middleware('permission:view donations');
                    Route::post('/{subscription_id}/cancel', 'cancel')->middleware('permission:manage donations');
                });

                // Year-end (annual) giving statements. Computed on the fly from
                // receipted donations; the report is read-only, emailing a
                // statement is `manage donations`.
                Route::prefix('{masjid_id}/annual-statements')->controller(AnnualStatementsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view donations');
                    Route::get('/{contact_id}', 'show')->middleware('permission:view donations');
                    Route::post('/{contact_id}/send', 'send')->middleware('permission:manage donations');
                    Route::post('/send-all', 'sendAll')->middleware('permission:manage donations');
                });
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
        /*
         * GLOBAL LIBRARY CONTENT — SuperAdmin only.
         *
         * hadiths / azkar / tasabih (and their categories) are NOT masjid-scoped:
         * they have no masjid_id and the mobile API serves them globally at
         * /api/mobile/{hadiths,azkar,tasabih}, so one row is shown in EVERY
         * masjid's app. They were previously reachable by any admin type, which
         * meant a MasjidAdmin could edit or delete content every other masjid
         * depends on. Gated to `super` to match the other platform-wide
         * resources (users, countries, app-config, features, admins).
         */
        Route::prefix('hadith-categories')->middleware('super')->controller(HadithCategoriesController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{category_id}', 'show');
            Route::put('/{category_id}', 'update');
            Route::delete('/{category_id}', 'destroy');
        });

        Route::prefix('hadiths')->middleware('super')->controller(HadithsController::class)->group(function () {
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
        Route::prefix('azkar-categories')->middleware('super')->controller(AzkarCategoriesController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{category_id}', 'show');
            Route::put('/{category_id}', 'update');
            Route::delete('/{category_id}', 'destroy');
        });

        Route::prefix('azkar')->middleware('super')->controller(AzkarController::class)->group(function () {
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

        Route::prefix('tasabih')->middleware('super')->controller(TasabihController::class)->group(function () {
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
