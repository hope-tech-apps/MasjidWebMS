<?php

use App\Http\Controllers\AdminDashboard\AnnouncementsController;
use App\Http\Controllers\AdminDashboard\AppointmentRequestsController;
use App\Http\Controllers\AdminDashboard\AssistantController;
use App\Http\Controllers\AdminDashboard\AuthController;
use App\Http\Controllers\AdminDashboard\AzkarCategoriesController;
use App\Http\Controllers\AdminDashboard\AzkarController;
use App\Http\Controllers\AdminDashboard\BehaviorAwardsController;
use App\Http\Controllers\AdminDashboard\BehaviorSkillsController;
use App\Http\Controllers\AdminDashboard\BroadcastsController;
use App\Http\Controllers\AdminDashboard\ContactCredentialsController;
use App\Http\Controllers\AdminDashboard\ContactReasonsController;
use App\Http\Controllers\AdminDashboard\ContactRequestsController;
use App\Http\Controllers\AdminDashboard\ContactsController;
use App\Http\Controllers\AdminDashboard\CountriesCitiesController;
use App\Http\Controllers\AdminDashboard\DashboardSearchController;
use App\Http\Controllers\AdminDashboard\AnnualStatementsController;
use App\Http\Controllers\AdminDashboard\DonationExportController;
use App\Http\Controllers\AdminDashboard\DonationsController;
use App\Http\Controllers\AdminDashboard\DonationStatsController;
use App\Http\Controllers\AdminDashboard\FlyerCutoutController;
use App\Http\Controllers\AdminDashboard\FlyersController;
use App\Http\Controllers\AdminDashboard\FlyerTemplatesController;
use App\Http\Controllers\AdminDashboard\PropertiesController;
use App\Http\Controllers\AdminDashboard\RecurringDonationsController;
use App\Http\Controllers\AdminDashboard\RegistrationsController;
use App\Http\Controllers\AdminDashboard\EventsController;
use App\Http\Controllers\AdminDashboard\FeePlansController;
use App\Http\Controllers\AdminDashboard\FundsController;
use App\Http\Controllers\AdminDashboard\GroupConsentController;
use App\Http\Controllers\AdminDashboard\GroupMembershipsController;
use App\Http\Controllers\AdminDashboard\GroupPostsController;
use App\Http\Controllers\AdminDashboard\GroupsController;
use App\Http\Controllers\AdminDashboard\GroupThreadsController;
use App\Http\Controllers\AdminDashboard\HifzEntriesController;
use App\Http\Controllers\AdminDashboard\HadithCategoriesController;
use App\Http\Controllers\AdminDashboard\ImpactMetricsController;
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
use App\Http\Controllers\AdminDashboard\MasjidOneSignalController;
use App\Http\Controllers\AdminDashboard\NotificationsController;
use App\Http\Controllers\AdminDashboard\OfferingsController;
use App\Http\Controllers\AdminDashboard\OnboardingController;
use App\Http\Controllers\AdminDashboard\OnboardingIntakeController;
use App\Http\Controllers\AdminDashboard\FormInsightsController;
use App\Http\Controllers\AdminDashboard\FormResponsesController;
use App\Http\Controllers\AdminDashboard\FormsController;
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

        // Emergency app-version gate (super-admin only). Per-masjid now: each
        // masjid's white-labeled listing has its own rows. The lever: flip
        // force_update + bump minimum_build to wall off stale installs.
        Route::prefix('masjids/{masjid_id}/app-config')->middleware('super')->controller(\App\Http\Controllers\AdminDashboard\AppConfigController::class)->group(function () {
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

            // Unified publish composer (T-008). ONE compose action reaching the
            // announcements feed, push, the TV signage board and email — each
            // channel opt-in per send, each with its own recorded outcome.
            //
            // It ORCHESTRATES the routes above and below; it does not replace
            // them. Every existing announcement / notification / splash endpoint
            // keeps working byte-for-byte as it does today
            // (BroadcastChannelRegressionTest pins that).
            //
            // Deliberately OUTSIDE the `crm` group and with NO `permission:`
            // gate — same reasoning as the Flyer Studio further down: publishing
            // a Jumu'ah notice is content authoring, not the CRM money path, and
            // gating it on masjids.crm_enabled would take announcements and push
            // away from every masjid that has not bought the CRM. The one
            // channel that DOES read the CRM (email, whose recipients are
            // contacts) is checked inside the controller, up front, and 403s the
            // whole request rather than half-sending.
            Route::prefix('{masjid_id}/broadcasts')->controller(BroadcastsController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{broadcast_id}', 'show');
            });

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

            // Per-masjid OneSignal push config. SuperAdmin-only: provisioning
            // mints a new OneSignal app (an org-level action) and the read
            // exposes only the app id + a has_onesignal_key boolean (never the
            // REST key). The masjid is server-derived from {masjid_id}.
            Route::prefix('{masjid_id}/onesignal')->middleware('super')->controller(MasjidOneSignalController::class)->group(function () {
                Route::get('/', 'show');
                Route::post('/provision', 'provision');
            });

            // Per-masjid SMS sender identity + A2P 10DLC registration state
            // (T-009). SuperAdmin-only for the same reason as OneSignal above,
            // only more so: 10DLC registration is a commercial act performed on
            // the organisation's behalf using the platform's provider account,
            // and a masjid admin who could self-declare "approved" would be
            // putting unregistered traffic on the carriers in the platform's
            // name. Nothing here calls a provider — registration is a human step
            // with days of latency, and this records its outcome so the sending
            // path can refuse until it says `approved`.
            Route::prefix('{masjid_id}/sms-sender')->middleware('super')
                ->controller(\App\Http\Controllers\AdminDashboard\MasjidSmsSenderController::class)
                ->group(function () {
                    Route::get('/', 'show');
                    Route::put('/', 'update');
                });

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

            // Sign-up Forms Management (event RSVPs, membership, camp registration).
            // Open to MasjidAdmin as well as SuperAdmin — a masjid builds its own forms.
            Route::prefix('{masjid_id}/forms')->controller(FormsController::class)->group(function () {
                Route::get('/', 'index');
                Route::get('/options', 'options');        // literal paths first, so they
                Route::get('/field-types', 'fieldTypes'); // are not captured as {form_id}
                Route::post('/', 'store');
                Route::get('/{form_id}', 'show');
                Route::put('/{form_id}', 'update');
                Route::delete('/{form_id}', 'destroy');
            });

            // Form Responses — the "who filled this out" list, with search/filter/sort.
            Route::prefix('{masjid_id}/forms/{form_id}/responses')->controller(FormResponsesController::class)->group(function () {
                Route::get('/export', 'export'); // literal paths before /{response_id}
                // The attendee roster: one row per PERSON, not per submission.
                Route::get('/roster/export', 'rosterExport');
                Route::get('/roster', 'roster');
                Route::get('/', 'index');
                Route::get('/{response_id}', 'show');
                // Uploaded files (a careers form's résumé) live on a PRIVATE disk with
                // no public URL; this is the only way to read one. Same middleware as
                // the rest of the group — auth:sanctum + admin + tenant — so an admin
                // can only ever download an attachment their own masjid collected.
                Route::get('/{response_id}/attachments/{attachment_id}', 'downloadAttachment');
                Route::put('/{response_id}', 'update');
                Route::delete('/{response_id}', 'destroy');
            });

            // Manara Insights — gated on the Assistant entitlement. `assistant` must run
            // AFTER `tenant` (the enclosing group already supplies auth + admin + tenant).
            // Read-only and computed on this server; it deliberately does NOT go through
            // MasjidAssistantService, whose tool surface includes writes.
            Route::get('{masjid_id}/forms/{form_id}/insights', [FormInsightsController::class, 'show'])
                ->middleware('assistant');

            // Flyer Studio. Rendering happens CLIENT-SIDE in the browser — the
            // droplet has no Node, and DomPDF cannot do flexbox, grid or Arabic
            // shaping — so these endpoints serve the designs and store the data;
            // they never rasterise anything. Background removal is the one server
            // -side step, and it runs as a queued job (a 3s inference on the only
            // vCPU would stall the prayer-time API for three tenants' apps).
            //
            // Deliberately OUTSIDE the `crm` group: flyers are content authoring
            // like forms and pages, not the CRM money path, so gating them on
            // masjids.crm_enabled would hide the Studio from every masjid that has
            // not bought the CRM. Same reasoning (and same lack of a `permission:`
            // middleware) as the forms group above.
            Route::prefix('{masjid_id}/flyer-templates')->controller(FlyerTemplatesController::class)->group(function () {
                Route::get('/', 'index');
                Route::get('/{template_id}', 'show');
            });

            Route::prefix('{masjid_id}/flyers')->group(function () {
                Route::controller(FlyersController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::post('/', 'store');
                    Route::get('/{flyer_id}', 'show');
                    Route::put('/{flyer_id}', 'update');
                    Route::delete('/{flyer_id}', 'destroy');
                });

                // Source photo + background removal. `image` streams the stored
                // file through the app rather than a public symlink: these live on
                // the private disk, because a janazah photo above all must not be
                // world-readable by guessing a URL.
                Route::controller(FlyerCutoutController::class)->group(function () {
                    Route::post('/{flyer_id}/photo', 'store');
                    Route::get('/{flyer_id}/cutout', 'show');
                    Route::post('/{flyer_id}/cutout/retry', 'retry');
                    Route::get('/{flyer_id}/image/{variant?}', 'image');
                    Route::delete('/{flyer_id}/photo', 'destroy');
                });
            });

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
            // SuperAdmin-only public-directory toggle (masjids.listed_at). A newly
            // provisioned organisation is NOT listed, so this is how one is
            // published to the mobile app's picker once it is actually ready.
            // Same 403-for-non-super contract as the two toggles above.
            Route::patch('{masjid_id}/directory-listing', [MasjidsController::class, 'setDirectoryListing']);

            // App-provisioning control plane (SuperAdmin only). "Generate apps"
            // dispatches a GitHub Actions workflow (self-hosted runner) that
            // scaffolds + builds + uploads this masjid's iOS/Android apps; the
            // status panel polls provisioning-jobs. The masjid is server-derived
            // from {masjid_id}. The workflow reports progress via the UNAUTHed
            // /api/provisioning/callback route (registered in routes/api.php),
            // authenticated by a per-job callback token — NOT this group.
            Route::middleware('super')->controller(\App\Http\Controllers\AdminDashboard\AppProvisioningController::class)->group(function () {
                Route::post('{masjid_id}/provision-apps', 'provision');
                Route::get('{masjid_id}/provisioning-jobs', 'index');
            });

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
                    // Merge a placeholder (unidentified-card) contact into a member.
                    Route::post('/{contact_id}/merge', 'merge')->middleware('permission:manage contacts');
                    // Undelete. `Contact` soft-deletes so a mis-click on a
                    // congregant record is recoverable, and until this route
                    // existed nothing in the application could recover one —
                    // which also put the `revoked` audit row that `destroy`
                    // writes permanently beyond every screen. `manage contacts`,
                    // the same gate as the delete it undoes; the listing finds a
                    // deleted member with `?trashed=only`.
                    Route::post('/{contact_id}/restore', 'restore')->middleware('permission:manage contacts');
                });

                // SMS consent for one contact (T-009). Two verbs rather than one
                // boolean toggle, and deliberately so: granting refuses for a
                // number that has opted out (only the subscriber can undo that,
                // by texting START), while withdrawing additionally writes the
                // durable suppression list so it survives a merge or a
                // re-import. `manage contacts` — writing consent is editing the
                // contact record. No permission is minted.
                Route::prefix('{masjid_id}/contacts/{contact_id}/sms-consent')
                    ->controller(\App\Http\Controllers\AdminDashboard\ContactSmsConsentController::class)
                    ->group(function () {
                        Route::post('/', 'store')->middleware('permission:manage contacts');
                        Route::delete('/', 'destroy')->middleware('permission:manage contacts');
                    });

                // Family sign-in for one contact (T-015d, admin half) — THE
                // ON-SWITCH for the parent portal.
                //
                // Everything the portal needs has existed since T-015c/d/e: the
                // `family` guard, `contact_login_codes`, the OTP endpoints,
                // GroupAudience's guardian branch, the whole read surface. None
                // of it was reachable, because nothing in the application ever
                // wrote `contacts.login_enabled_at` — the four `login_*` columns
                // are deliberately not fillable and no controller set them.
                // These three routes are the only thing that does.
                //
                // Three verbs rather than a toggle, for the reason the
                // sms-consent block above records: enabling and revoking are not
                // inverses. Revoking additionally ends live sessions, and both
                // append to an immutable trail (`contact_login_events`) because
                // what is being granted is a stranger's view of a specific
                // child's photographs and safeguarding conversations, and "it
                // was on" is not an answer to "who turned it on?".
                //
                // Gated by the CONTACTS permissions, the same call the
                // credentials routes below make: a login is an attribute OF the
                // member directory, so whoever may manage a person's record may
                // manage how that person signs in. READING is `view contacts`
                // (the trail names staff and one family address); WRITING is
                // `manage contacts`. No permission is minted — Permission::count()
                // stays at 8 and StaffAuthGuardPinTest pins it.
                Route::prefix('{masjid_id}/contacts/{contact_id}/family-login')
                    ->controller(\App\Http\Controllers\AdminDashboard\ContactFamilyLoginController::class)
                    ->group(function () {
                        Route::get('/', 'show')->middleware('permission:view contacts');
                        Route::post('/', 'store')->middleware('permission:manage contacts');
                        Route::delete('/', 'destroy')->middleware('permission:manage contacts');
                    });

                // Volunteer credentials (T-023, Community vertical) — a
                // contact's medical licenses, background checks and
                // certifications, nested under the contact they belong to.
                // Same guardrail as contacts: isolation comes from `tenant` +
                // BelongsToMasjid, and the controller re-resolves the
                // masjid -> contact -> credential chain so a foreign id
                // anywhere in it is a 404.
                //
                // Gated by the CONTACTS permissions on purpose: a credential is
                // an attribute OF the member directory, and anyone trusted to
                // see or manage a person's record is trusted to see or manage
                // what they are licensed to do. Minting `view/manage
                // credentials` would change the seeded permission set that
                // RolesAndPermissionsSeeder and RolePermissionBridgeTest pin
                // (Permission::count() === 8), which this additive slice must
                // not do — the same call .claude/rules/groups.md records for
                // groups. The document download sits behind the SAME stack
                // (auth:sanctum + admin + tenant + crm + permission): a scanned
                // license is PII and never gets a public URL
                // (.claude/rules/private-uploads.md).
                Route::prefix('{masjid_id}/contacts/{contact_id}/credentials')
                    ->controller(ContactCredentialsController::class)
                    ->group(function () {
                        Route::get('/', 'index')->middleware('permission:view contacts');
                        Route::post('/', 'store')->middleware('permission:manage contacts');
                        Route::get('/{credential_id}', 'show')->middleware('permission:view contacts');
                        Route::put('/{credential_id}', 'update')->middleware('permission:manage contacts');
                        Route::delete('/{credential_id}', 'destroy')->middleware('permission:manage contacts');
                        Route::get('/{credential_id}/document', 'downloadDocument')->middleware('permission:view contacts');
                    });

                // Groups — the second scoping level of the core (org -> group ->
                // member): classrooms for a School, halaqat / weekend-school
                // circles for a Masjid, volunteer teams for a Community org.
                // Same guardrail as contacts: {masjid_id} stays in the path by
                // convention, isolation comes from `tenant` + BelongsToMasjid,
                // and the controllers never hand-filter.
                //
                // Gated by the CONTACTS permissions on purpose. A group is a
                // structure OVER the member directory — it holds no data of its
                // own beyond names and roles, and anyone trusted to manage the
                // directory is trusted to arrange it. Minting `view/manage
                // groups` would also change the seeded permission set that
                // RolesAndPermissionsSeeder and RolePermissionBridgeTest pin,
                // which this additive slice must not do. Splitting the two is a
                // deliberate later step. See .claude/rules/groups.md.
                Route::prefix('{masjid_id}/groups')->controller(GroupsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view contacts');
                    Route::post('/', 'store')->middleware('permission:manage contacts');
                    Route::get('/{group_id}', 'show')->middleware('permission:view contacts');
                    Route::put('/{group_id}', 'update')->middleware('permission:manage contacts');
                    Route::delete('/{group_id}', 'destroy')->middleware('permission:manage contacts');
                });

                // Group rosters. A membership links an existing Contact to a
                // group with a role; a guardian membership additionally names the
                // member it is a guardian OF, within that group.
                Route::prefix('{masjid_id}/groups/{group_id}/members')
                    ->controller(GroupMembershipsController::class)
                    ->group(function () {
                        Route::get('/', 'index')->middleware('permission:view contacts');
                        Route::post('/', 'store')->middleware('permission:manage contacts');
                        // Standing behind the claims a PUBLIC registration form
                        // put on this roster. Declared BEFORE the {membership_id}
                        // routes so `confirm` is a verb and not an id.
                        //
                        // `manage contacts`, minting no permission: confirming is
                        // the same accountable roster administration as typing
                        // the row in by hand, and it is literally the same write
                        // (`GroupMembership::confirmedByStaff`).
                        Route::post('/confirm', 'confirm')->middleware('permission:manage contacts');
                        Route::delete('/{membership_id}', 'destroy')->middleware('permission:manage contacts');
                    });

                // Guardian consent, recorded against ONE guardian edge — the
                // obligation .claude/rules/groups.md records: "a guardian edge
                // records a relationship, NOT consent." Written here, CHECKED at
                // the point of disclosure by App\Support\GroupAudience. Managing
                // consent is roster administration, so it takes the same
                // `manage contacts` permission the roster does.
                Route::prefix('{masjid_id}/groups/{group_id}/members/{membership_id}/consent')
                    ->controller(GroupConsentController::class)
                    ->group(function () {
                        Route::get('/', 'show')->middleware('permission:view contacts');
                        Route::put('/', 'update')->middleware('permission:manage contacts');
                        Route::delete('/', 'destroy')->middleware('permission:manage contacts');
                    });

                // The group's PRIVATE activity feed — the "class story". Two
                // different questions, two different gates:
                //   - WRITING is `manage contacts`, like the roster beside it;
                //     the post records which account published it.
                //   - READING additionally requires being IN the group, decided
                //     by App\Support\GroupAudience. `view contacts` is necessary
                //     but NOT sufficient: these posts are about children, and
                //     .claude/rules/groups.md forbids a group-scoped read from
                //     reaching the whole tenant.
                // Images live on the PRIVATE disk with no public URL; the
                // attachment route is the only way to read one, and it refuses a
                // guardian whose consent does not cover media.
                Route::prefix('{masjid_id}/groups/{group_id}/posts')
                    ->controller(GroupPostsController::class)
                    ->group(function () {
                        Route::get('/', 'index')->middleware('permission:view contacts');
                        Route::post('/', 'store')->middleware('permission:manage contacts');
                        Route::get('/{post_id}', 'show')->middleware('permission:view contacts');
                        Route::get('/{post_id}/attachments/{attachment_id}', 'downloadAttachment')
                            ->middleware('permission:view contacts');
                        Route::put('/{post_id}', 'update')->middleware('permission:manage contacts');
                        Route::delete('/{post_id}', 'destroy')->middleware('permission:manage contacts');
                    });

                // Appointment requests (Community vertical, T-021) — the free
                // clinic's triage queue. Same guardrail as contacts/groups:
                // {masjid_id} stays in the path by convention, isolation comes
                // from `tenant` + BelongsToMasjid, and the controller never
                // hand-filters. date_of_birth / reason / note bodies are
                // encrypted at rest and surface ONLY here, never publicly.
                //
                // Gated by the CONTACTS permissions on purpose, the same
                // precedent as groups above: these are people records in the
                // member directory's trust domain, and minting `view/manage
                // appointments` would change the seeded permission set that
                // RolesAndPermissionsSeeder and RolePermissionBridgeTest pin.
                // Splitting them out is a deliberate later step.
                Route::prefix('{masjid_id}/appointment-requests')->controller(AppointmentRequestsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view contacts');
                    Route::get('/{appointment_request_id}', 'show')->middleware('permission:view contacts');
                    Route::patch('/{appointment_request_id}/status', 'updateStatus')->middleware('permission:manage contacts');
                    Route::post('/{appointment_request_id}/notes', 'storeNote')->middleware('permission:manage contacts');
                });
                // Group messaging threads — the teacher <-> parent channel
                // (T-005c). Same permission arrangement as the feed above,
                // with one deliberate addition: posting a MESSAGE carries
                // `manage contacts` here AND a read-entitlement check in the
                // controller (App\Support\GroupAudience::mayReceiveThread) —
                // a conversation is only writable by people who may see it,
                // unlike an announcement, which an off-roster admin may
                // publish without being able to read back. A
                // participant-scoped thread is readable ONLY by the group's
                // leaders and the specific member/guardian it concerns; the
                // list endpoint pre-filters to the same rule.
                Route::prefix('{masjid_id}/groups/{group_id}/threads')
                    ->controller(GroupThreadsController::class)
                    ->group(function () {
                        Route::get('/', 'index')->middleware('permission:view contacts');
                        Route::post('/', 'store')->middleware('permission:manage contacts');
                        Route::get('/{thread_id}', 'show')->middleware('permission:view contacts');
                        Route::post('/{thread_id}/messages', 'storeMessage')->middleware('permission:manage contacts');
                        Route::post('/{thread_id}/close', 'close')->middleware('permission:manage contacts');
                        Route::post('/{thread_id}/reopen', 'reopen')->middleware('permission:manage contacts');
                        Route::delete('/{thread_id}', 'destroy')->middleware('permission:manage contacts');
                    });

                // Behaviour / recognition — the Classroom module (T-013).
                //
                // The VOCABULARY first: per-tenant skills ("Participation",
                // "Kindness", "Disruption"). Not group-scoped and not about any
                // child, so it takes the plain contacts permissions with no
                // GroupAudience check — reading a drop-down discloses nothing.
                Route::prefix('{masjid_id}/behavior-skills')
                    ->controller(BehaviorSkillsController::class)
                    ->group(function () {
                        Route::get('/', 'index')->middleware('permission:view contacts');
                        Route::post('/', 'store')->middleware('permission:manage contacts');
                        Route::get('/{skill_id}', 'show')->middleware('permission:view contacts');
                        Route::put('/{skill_id}', 'update')->middleware('permission:manage contacts');
                        Route::delete('/{skill_id}', 'destroy')->middleware('permission:manage contacts');
                    });

                // The AWARDS themselves — a child's behaviour record. Same two
                // gates as the feed: WRITING (award/revoke) is `manage
                // contacts`, roster administration; READING additionally
                // requires standing in the group, decided by
                // App\Support\GroupAudience.
                //
                // A CHILD'S RECORD IS PRIVATE BY DESIGN, and that is the point
                // of this slice: an award reaches the group's leaders, the
                // student, and THAT student's own guardians — never another
                // guardian in the same group, and never a class-wide ranking.
                // There is deliberately no leaderboard route below, and the
                // listings are constrained by the audience query so a forbidden
                // award is never fetched. Nothing here is paywalled. See
                // .claude/rules/groups.md.
                //
                // The per-student routes sit under .../members/{membership_id}
                // because a student IS their membership edge — the same shape a
                // guardian edge and a participant thread already use.
                Route::prefix('{masjid_id}/groups/{group_id}')
                    ->controller(BehaviorAwardsController::class)
                    ->group(function () {
                        Route::get('/awards', 'index')->middleware('permission:view contacts');
                        Route::post('/awards', 'store')->middleware('permission:manage contacts');
                        Route::delete('/awards/{award_id}', 'destroy')->middleware('permission:manage contacts');

                        // Per-student. The summary route is registered FIRST, so
                        // the more specific path wins the match — the same
                        // ordering discipline as the donations/stats block
                        // above, where a `/{id}` pattern would otherwise
                        // swallow a named sub-resource.
                        Route::get('/members/{membership_id}/awards/summary', 'summary')
                            ->middleware('permission:view contacts');
                        Route::get('/members/{membership_id}/awards', 'forMember')
                            ->middleware('permission:view contacts');
                    });

                // Qur'an memorization — hifz tracking (T-014).
                //
                // The halaqa's daily record: sabak (the new lesson), sabqi
                // (recent memorisation under revision), manzil (the long
                // rotation). Same two gates as the awards above: WRITING
                // (record/strike) is `manage contacts`, teaching the halaqa;
                // READING additionally requires standing in the group, decided
                // by App\Support\GroupAudience — through the SAME code path the
                // awards use, so a child's Qur'an record and their behaviour
                // record can never disagree about who may see them.
                //
                // A CHILD'S RECORD IS PRIVATE: an entry reaches the halaqa's
                // leaders, the student, and THAT student's own guardians —
                // never another guardian in the same halaqa. There is
                // deliberately no group-wide progress or "top memorisers"
                // route, and the listings are constrained by the audience query
                // so a forbidden entry is never fetched. Nothing here is
                // paywalled. See .claude/rules/groups.md.
                //
                // The per-student routes sit under .../members/{membership_id}
                // because a student IS their membership edge — the same shape a
                // guardian edge, a participant thread and an award already use.
                Route::prefix('{masjid_id}/groups/{group_id}')
                    ->controller(HifzEntriesController::class)
                    ->group(function () {
                        Route::get('/hifz', 'index')->middleware('permission:view contacts');
                        Route::post('/hifz', 'store')->middleware('permission:manage contacts');
                        Route::delete('/hifz/{entry_id}', 'destroy')->middleware('permission:manage contacts');

                        // Per-student. The progress route is registered FIRST so
                        // the more specific path wins the match — the same
                        // ordering discipline as the awards block above.
                        Route::get('/members/{membership_id}/hifz/progress', 'progress')
                            ->middleware('permission:view contacts');
                        Route::get('/members/{membership_id}/hifz', 'forMember')
                            ->middleware('permission:view contacts');
                    });

                // CRM money path (Phase-0 spike). All tenant-scoped by the `tenant`
                // middleware + BelongsToMasjid — controllers never hand-filter by
                // masjid_id. See .claude/rules/stripe-payments.md.

                // Stripe Connect (Standard account) onboarding for this masjid.
                // NOTE: Stripe's own redirect targets are the PUBLIC landings in
                // routes/web.php (connect.return / connect.refresh) — the admin's
                // browser arrives there with no token. `/status` is the authed
                // JSON view of the same state, for the SPA.
                Route::prefix('{masjid_id}/connect')->controller(StripeConnectController::class)->group(function () {
                    Route::post('/onboarding', 'startOnboarding')->middleware('permission:manage donations');
                    Route::get('/status', 'status')->middleware('permission:manage donations');
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

                // Giving dashboard numbers, and the ledger CSV.
                //
                // These MUST stay above the `{masjid_id}/donations` group below:
                // that group ends in `/{donation_id}`, which would otherwise match
                // "export" and "stats" as ids and 404 on a cast. Laravel matches in
                // declaration order, so position here is load-bearing, not stylistic.
                //
                // The export is driven by the same filter contract as the ledger
                // index (DonationsController::filteredQuery), so what the accountant
                // downloads is exactly what the admin is looking at.
                Route::get('{masjid_id}/donations/export', [DonationExportController::class, 'export'])
                    ->middleware('permission:view donations');
                Route::prefix('{masjid_id}/donations/stats')->controller(DonationStatsController::class)->group(function () {
                    Route::get('/summary', 'summary')->middleware('permission:view donations');
                    Route::get('/by-fund', 'byFund')->middleware('permission:view donations');
                });

                // Donations ledger — READ-ONLY. Rows are created and advanced
                // exclusively by Stripe webhooks, so there is deliberately NO
                // store / update / destroy route here.
                Route::prefix('{masjid_id}/donations')->controller(DonationsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view donations');
                    // Manual/offline gift entry (cash/check/Zelle/…). Stripe gifts
                    // remain webhook-only; this is the only write path for donations.
                    Route::post('/', 'store')->middleware('permission:manage donations');
                    Route::get('/{donation_id}', 'show')->middleware('permission:view donations');
                    // Issue the tax receipt for an OFFLINE gift — a deliberate
                    // treasurer action, never automatic on entry (see the
                    // controller). Consumes a serial from the masjid's gap-free
                    // sequence, so it is `manage donations` like the offline
                    // store route above. Stripe gifts stay webhook-only.
                    Route::post('/{donation_id}/receipt', 'issueReceipt')
                        ->middleware('permission:manage donations');
                    // Printable copy of the receipt this gift already issued.
                    // Read-only rendering of the stored receipt row, so it is
                    // `view donations` like the rest of the ledger's read side.
                    Route::get('/{donation_id}/receipt/pdf', 'receiptPdf')
                        ->middleware('permission:view donations');
                });

                // Recurring donations (standing commitments). Read side is
                // `view donations`; canceling a gift is a money mutation, so it
                // requires `manage donations`.
                Route::prefix('{masjid_id}/recurring-donations')->controller(RecurringDonationsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view donations');
                    Route::get('/{subscription_id}', 'show')->middleware('permission:view donations');
                    Route::post('/{subscription_id}/cancel', 'cancel')->middleware('permission:manage donations');
                });

                // Registration + billing engine (T-006d admin surface) —
                // offerings, their IMMUTABLE fee plans, and the roster of one
                // offering with the three explicit admin actions on a
                // registration. See docs/t006-registration-billing-design.md.
                // Same guardrail as everything above: {masjid_id} stays in the
                // path by convention, isolation comes from `tenant` +
                // BelongsToMasjid, and the controllers never hand-filter.
                //
                // TWO permission families on purpose, and the split is where
                // the MONEY is:
                //   - An OFFERING is a program structure over the member
                //     directory — a semester, a halaqa, an admission round. It
                //     holds no price of its own, so it takes the CONTACTS
                //     permissions, the same call groups and appointment
                //     requests already made. Reading a roster and promoting
                //     somebody off the waitlist are likewise roster
                //     administration, not money.
                //   - A FEE PLAN *is* a price, an ADJUSTMENT waives part of
                //     one, and CANCEL stops a live Stripe subscription — those
                //     take the DONATIONS permissions, the same trust level as
                //     RecurringDonationsController::cancel, which is the
                //     closest existing precedent for "an admin stopping money".
                // Reusing both families rather than minting `view/manage
                // offerings` keeps the seeded permission set that
                // RolesAndPermissionsSeeder and RolePermissionBridgeTest pin
                // (Permission::count() === 8) unchanged, exactly as the groups
                // and credentials slices did before this one.
                Route::prefix('{masjid_id}/offerings')->controller(OfferingsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view contacts');
                    // Literal path BEFORE /{offering_id}, or it is captured as
                    // an id — the same ordering routes/admin.php already keeps
                    // for forms/options and forms/field-types.
                    Route::get('/options', 'options')->middleware('permission:view contacts');
                    Route::post('/', 'store')->middleware('permission:manage contacts');
                    Route::get('/{offering_id}', 'show')->middleware('permission:view contacts');
                    Route::put('/{offering_id}', 'update')->middleware('permission:manage contacts');
                    // Soft-delete, and refused outright while live registrations
                    // would be stranded — deactivate (is_active=false) instead.
                    Route::delete('/{offering_id}', 'destroy')->middleware('permission:manage contacts');
                });

                // Fee plans — CREATE and DEACTIVATE only. Plans are IMMUTABLE:
                // registrations snapshot their price at intake, so editing a
                // live plan would retroactively restate what somebody agreed to
                // pay. `update` exists solely to REFUSE with a clear 422 rather
                // than accept an edit and silently ignore the fields.
                Route::prefix('{masjid_id}/offerings/{offering_id}/fee-plans')
                    ->controller(FeePlansController::class)
                    ->group(function () {
                        Route::get('/', 'index')->middleware('permission:view donations');
                        Route::post('/', 'store')->middleware('permission:manage donations');
                        Route::put('/{fee_plan_id}', 'update')->middleware('permission:manage donations');
                        Route::patch('/{fee_plan_id}', 'update')->middleware('permission:manage donations');
                        // Deactivate, never delete: registrations reference the
                        // plan with a RESTRICT FK and read their currency
                        // through it.
                        Route::delete('/{fee_plan_id}', 'destroy')->middleware('permission:manage donations');
                    });

                // The roster of one offering + the explicit admin actions.
                // Registrations are created by the PUBLIC endpoints (T-006c)
                // and advanced by webhooks; there is deliberately no store or
                // update route here.
                Route::prefix('{masjid_id}/offerings/{offering_id}/registrations')
                    ->controller(RegistrationsController::class)
                    ->group(function () {
                        Route::get('/', 'index')->middleware('permission:view contacts');
                        Route::get('/{registration_id}', 'show')->middleware('permission:view contacts');
                        // Granting aid decides what a family is charged.
                        Route::post('/{registration_id}/adjustments', 'storeAdjustment')
                            ->middleware('permission:manage donations');
                        // Seat administration: capacity is re-checked under the
                        // offering's row lock, so this can never oversell.
                        Route::post('/{registration_id}/promote', 'promote')
                            ->middleware('permission:manage contacts');
                        // Cancels the Stripe subscription too when one exists.
                        Route::post('/{registration_id}/cancel', 'cancel')
                            ->middleware('permission:manage donations');
                    });

                // Impact report (T-024) — the numbers a grant application or a
                // funder report asks for, computed from the rows this
                // organization already holds. READ-ONLY, and it writes nothing
                // anywhere: the `impact_stats` page section (T-020) stays the
                // authoritative source of what is PUBLISHED, and its values
                // stay display text an admin typed. An admin who wants a
                // computed figure on the public page copies it across by hand.
                //
                // Gated by `view contacts`: the report is mostly about people
                // and program activity, and the CONTACTS family is the same
                // call groups, credentials and appointment requests already
                // made. The MONEY-bearing metrics inside it additionally
                // require `view donations`, checked in the controller — a
                // caller without it gets the report with those metrics listed
                // in `meta.omitted`. Reusing both families rather than minting
                // `view impact` keeps the pinned Permission::count() === 8.
                Route::prefix('{masjid_id}/impact')->controller(ImpactMetricsController::class)->group(function () {
                    Route::get('/report', 'report')->middleware('permission:view contacts');
                });

                // Rental properties + rent payments. A separate component from the
                // donor CRM (rent is not a gift). Viewing is `view properties`;
                // any mutation is `manage properties`.
                Route::prefix('{masjid_id}/properties')->controller(PropertiesController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view properties');
                    Route::post('/', 'store')->middleware('permission:manage properties');
                    Route::get('/{property_id}', 'show')->middleware('permission:view properties');
                    Route::put('/{property_id}', 'update')->middleware('permission:manage properties');
                    Route::delete('/{property_id}', 'destroy')->middleware('permission:manage properties');
                    Route::post('/{property_id}/rent', 'storeRent')->middleware('permission:manage properties');
                    Route::delete('/{property_id}/rent/{rent_id}', 'destroyRent')->middleware('permission:manage properties');
                });

                // Year-end (annual) giving statements. Computed on the fly from
                // receipted donations; the report is read-only, emailing a
                // statement is `manage donations`.
                Route::prefix('{masjid_id}/annual-statements')->controller(AnnualStatementsController::class)->group(function () {
                    Route::get('/', 'index')->middleware('permission:view donations');
                    Route::get('/{contact_id}', 'show')->middleware('permission:view donations');
                    Route::get('/{contact_id}/pdf', 'downloadPdf')->middleware('permission:view donations');
                    Route::post('/{contact_id}/send', 'send')->middleware('permission:manage donations');
                    Route::post('/send-all', 'sendAll')->middleware('permission:manage donations');
                });
            });

            Route::get('{masjid_id}/search', [DashboardSearchController::class, 'searchForMasjidDataRecords']);
        });

        // Super-Admin masjid onboarding wizard. SuperAdmin-only (the `super`
        // middleware = SuperAdminMiddleware). Its own prefix (NOT under
        // `masjids/`) so `provision` can never be captured as a {masjid_id}. The
        // wizard creates a brand-new tenant, so there is no masjid_id yet.
        Route::prefix('onboarding')->middleware('super')->controller(OnboardingController::class)->group(function () {
            Route::get('/options', 'options');
            Route::post('/provision', 'provision');

            // Intake helper: server-side address -> lat/lng for the wizard's
            // "Find coordinates" button (OnboardingIntakeController, a distinct
            // controller from the group default). Fails soft — responds
            // { status:'error' } (HTTP 200) with a clear message when
            // GOOGLE_MAPS_GEOCODING_KEY isn't provisioned, so the wizard keeps
            // its manual latitude/longitude inputs as the fallback.
            Route::post('/intake/geocode', [OnboardingIntakeController::class, 'geocode']);
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
