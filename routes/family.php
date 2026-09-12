<?php

use App\Http\Controllers\Family\ArabicLettersController;
use App\Http\Controllers\Family\BehaviorAwardsController;
use App\Http\Controllers\Family\FamilyAuthController;
use App\Http\Controllers\Family\FamilyPasswordController;
use App\Http\Controllers\Family\GradesController;
use App\Http\Controllers\Family\GroupPostsController;
use App\Http\Controllers\Family\GroupsController;
use App\Http\Controllers\Family\GroupThreadsController;
use App\Http\Controllers\Family\HifzEntriesController;
use App\Http\Controllers\Family\MeController;
use App\Http\Controllers\Family\ReportCardsController as FamilyReportCardsController;
use App\Http\Controllers\Family\ResourcesController;
use App\Http\Controllers\Family\StudentSessionController;
use App\Http\Controllers\Family\TranslationsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The parent/guardian API (T-015c, T-015d, T-015e)
|--------------------------------------------------------------------------
|
| Mounted under /api by bootstrap/app.php, exactly like routes/admin.php.
| Everything here is addressed as /api/family/masjids/{masjid_id}/...
|
| TWO GROUPS, and the split is the point: one for callers who do not have a
| token yet (sign-in) and one for callers who do (everything else). They share
| no middleware except `crm`, and nothing in the first group reads anything.
|
| ============================ THE AUTHENTICATED STACK ======================
|
|   auth:family    the `family` guard over the `contacts` provider. A staff
|                  token resolves to null here inside vendor code, because its
|                  tokenable is a User and the provider says Contact.
|   family.active  the caller is a Contact, enabled, not revoked, not deleted —
|                  re-checked on EVERY request, and the ONLY thing that refuses
|                  a staff member holding a live admin SPA session, which
|                  Sanctum's `sanctum.guard` session branch admits before it
|                  ever looks at the bearer token.
|   family.tenant  binds TenantContext from the TOKEN's contact, and 403s if it
|                  cannot — never falls through unbound, which for a
|                  BelongsToMasjid read would mean "every tenant".
|   crm            the same per-masjid feature gate the staff CRM sits behind. A
|                  masjid that has not switched the CRM on has no groups, no
|                  roster and no parents to serve.
|   throttle:family  60/min keyed on the contact (see AppServiceProvider).
|
| ============================ THE SIGN-IN STACK ============================
|
|   family.guest        binds TenantContext from the {masjid_id} in the URL, or
|                       404s. There is no token to bind from yet — that is what
|                       these two endpoints exist to produce — and an UNBOUND
|                       contact lookup would search every tenant in the database
|                       (.claude/rules/tenant-scoping.md), which would make
|                       "which school is this parent at?" answerable by email.
|   crm                 as above.
|   throttle:family-login / :family-verify
|                       per submitted ADDRESS and per IP, keyed on what the
|                       caller sent rather than on anything we looked up — so a
|                       429 is not an existence oracle either.
|
| WHAT IS DELIBERATELY ABSENT FROM BOTH, and must stay absent:
|
|   - `admin` / `super`. Those read `users.type` off the principal; a Contact is
|     not a staff User and this tree is not administration.
|   - `permission:`. Spatie permissions are registered under the `web` guard for
|     `App\Models\User`. Applying one to a Contact would ask the permission
|     layer to resolve a guard for a model that holds no roles — the T-015a
|     regression in a new costume. Authorization in this realm is the roster
|     (`GroupAudience`), not a permission string.
|   - `tenant` (ResolveMasjidTenant). It branches on `users.type` and refuses
|     anything else; `family.tenant` / `family.guest` replace it here.
|
| ============================ THE REALM IS READ-ONLY =======================
|
| Almost read-only, and the exceptions are counted. The two sign-in POSTs write
| a `contact_login_codes` row; T-015f adds a reply in a thread the parent may
| already read (which also moves their own read bookmark); and a parent may set
| the AVATAR of a child they are the guardian of, because this platform has no
| student login and somebody has to choose it with them. A parent may also now
| set and remove their OWN password (2026-09-08) — the only writes in this realm
| that touch a credential, and the only ones whose subject cannot be named by the
| request at all. The tenth (2026-09-12) is "Translate to Arabic", and it is the
| odd one out: it writes nothing about a family at all, only a cache row keyed on
| a hash, and it is a POST solely because the text a parent wants translated does
| not fit in a query string. Everything else is a GET. Withdrawing their own
| consent is still T-015h — absent rather than half-built.
|
| `FamilyPortalTest::the_family_realm_writes_exactly_ten_things` enumerates
| every one of them and fails on an eleventh. Adding a route here without
| updating that list is a failing build, on purpose.
*/

// --------------------------------------------------------------- signing in

// whereNumber is load-bearing, not tidiness. The per-address throttle bucket is
// salted with the masjid, and `01` / `001` / `1abc` / `1.0` all int-cast to the
// same tenant while hashing to DIFFERENT buckets — so without this constraint an
// attacker mints a fresh sign-in allowance per spelling and the documented
// 5-per-hour ceiling is not enforced. Measured at 12 codes to one parent before
// it was closed. AppServiceProvider::familyLoginKey() now normalises the id too;
// both halves stay, because either one alone is a single edit from regressing.
Route::prefix('family/masjids/{masjid_id}/auth')
    ->whereNumber('masjid_id')
    ->middleware(['family.guest', 'crm'])
    ->controller(FamilyAuthController::class)
    ->group(function () {

        // Always 202, for every well-formed address. See the controller: the
        // response cannot depend on whether the address names a real, live,
        // login-enabled contact, because a roster is a list of children and
        // confirming a parent against a school is itself a disclosure.
        Route::post('/request-code', 'requestCode')->middleware('throttle:family-login');

        // 200 + a token, or an identical 410 for all six ways it can fail.
        Route::post('/verify-code', 'verifyCode')->middleware('throttle:family-verify');

        // The SECOND door: a parent who chose a password signs in with it.
        //
        // `throttle:family-verify` is the same bucket verify-code uses, on
        // purpose and not by copy-paste. Two credential doors with independent
        // allowances would give an attacker twice the guesses per hour against
        // one address, which is the same arithmetic FamilyLoginService::redeem
        // refuses when it charges EVERY live code for one wrong guess. The
        // bucket keys on the submitted address, so both doors draw down one
        // shared allowance for that address.
        Route::post('/password', 'signInWithPassword')->middleware('throttle:family-verify');
    });

// ------------------------------------------------------------ child mode
//
// The screen a child gets when a parent hands them the phone. Its token is
// minted BY a parent (the server authenticated them, not the child) but carries
// only `student:{membership}`, so `family.parent` refuses it everywhere above
// and `family.student` pins it to the one child it names. A six-year-old cannot
// be issued a password — Contact::getAuthPassword() says so — and this is how
// they get a session anyway without one.
//
// Deliberately TWO routes. Everything else a student might eventually see
// (their own points, their own hifz) is a later slice with its own standing
// computation; what is here is the narrowest thing that lets a child choose how
// they are represented.
Route::prefix('family/masjids/{masjid_id}/groups/{group_id}/members/{membership_id}/student')
    ->whereNumber(['masjid_id', 'group_id', 'membership_id'])
    ->middleware(['auth:family', 'family.active', 'family.student', 'family.tenant', 'crm', 'throttle:family'])
    ->controller(StudentSessionController::class)
    ->group(function () {
        Route::get('/me', 'show');
        Route::put('/avatar', 'updateAvatar');
    });

// ------------------------------------------------------------ the portal

Route::prefix('family')
    ->middleware(['auth:family', 'family.active', 'family.parent', 'family.tenant', 'crm', 'throttle:family'])
    ->group(function () {

        // Every family route is addressed per-organisation, matching the admin
        // convention (/masjids/{masjid_id}/...). The id is an assertion the
        // caller makes and `family.tenant` verifies; it is never the source of
        // the tenant binding.
        Route::prefix('masjids/{masjid_id}')->group(function () {

            Route::get('/me', [MeController::class, 'show']);

            // A parent CHOOSING their own password, and removing it. The third
            // and fourth writes in this realm, and the only ones that touch a
            // credential.
            //
            // Neither takes a contact identifier of any kind — the subject is
            // `Auth::user()` and nothing else — so this pair cannot be aimed at
            // another family, and there is deliberately no admin twin: an office
            // may enable or revoke a family's ACCESS (ContactFamilyLoginController,
            // behind `manage contacts`) but may never set, read or reset their
            // password. See FamilyPasswordService.
            Route::put('/password', [FamilyPasswordController::class, 'update']);
            Route::delete('/password', [FamilyPasswordController::class, 'destroy']);

            // The forty drawings a family can choose from.
            Route::get('/avatars', [GroupsController::class, 'avatarCatalogue']);

            // "Translate to Arabic", over whatever is on the parent's screen.
            //
            // The TENTH write in this realm, and the only one that writes nothing
            // about a family: it takes TEXT rather than record ids, so it has no
            // audience gate to get wrong and cannot be aimed at another family's
            // child — see the controller, which argues that trade at length. It
            // is a POST because the text is far too long for a query string, not
            // because it mutates anything a parent can see; the only row it
            // writes is a cache entry keyed on a hash.
            //
            // TWO throttles apply, and the second is not redundant. This is the
            // one endpoint in the realm that SPENDS MONEY per call, so 60/min of
            // ordinary family allowance is far too generous for it;
            // `family-translate` narrows that to 20/min on the same contact key.
            Route::post('/translations', [TranslationsController::class, 'store'])
                ->middleware('throttle:family-translate');

            // The entry point: which groups this parent stands in, and which
            // children they hold in each. Every route below is addressed with
            // ids discovered here.
            Route::get('/groups', [GroupsController::class, 'index']);
            Route::get('/groups/{group_id}', [GroupsController::class, 'show']);

            // The class story. Consent-gated exactly like the staff feed, and
            // the attachment route serves BYTES rather than a signed URL, on
            // purpose — see the controller.
            Route::prefix('groups/{group_id}/posts')
                ->controller(GroupPostsController::class)
                ->group(function () {
                    Route::get('/', 'index');
                    Route::get('/{post_id}', 'show');
                    Route::get('/{post_id}/attachments/{attachment_id}', 'downloadAttachment');
                });

            // Handouts the class has chosen to share. BOTH are GETs — nothing
            // here widens what a parent may write, so the realm's counted-
            // exceptions docblock above is untouched. Visibility is applied as a
            // SCOPE, so a staff-only file is a 404 rather than a 403 that would
            // confirm it exists.
            Route::prefix('groups/{group_id}/resources')
                ->controller(ResourcesController::class)
                ->group(function () {
                    Route::get('/', 'index');
                    Route::get('/{resource_id}/download', 'download');
                });

            // Conversations this parent is a party to. Group-wide threads are
            // consent-gated; a participant thread reaches only the guardian of
            // the member it names.
            Route::prefix('groups/{group_id}/threads')
                ->controller(GroupThreadsController::class)
                ->group(function () {
                    Route::get('/', 'index');
                    Route::get('/{thread_id}', 'show');

                    // A parent OPENS a conversation. The SECOND write this realm
                    // has, and deliberately narrower than the staff one: scope is
                    // forced to participant in the controller (never read from the
                    // payload), the subject must be the caller's own ward, and it
                    // is throttled per contact — replying is not, because a parent
                    // mid-conversation should never be told to slow down.
                    Route::post('/', 'store')->middleware('throttle:family-thread');

                    // T-015f — the one write in this realm besides sign-in.
                    // Authorised by the same `mayReceiveThread()` the reads use,
                    // and the author comes from the TOKEN, never the payload.
                    Route::post('/{thread_id}/messages', 'storeMessage');
                });

            // Per-CHILD records, addressed by the ward's own participant
            // membership id. There is deliberately no group-wide variant of
            // either: a class-wide view of points or of who has memorised most
            // is the ranking these modules exist to refuse.
            Route::prefix('groups/{group_id}/members/{membership_id}')->group(function () {
                // A parent choosing their OWN child's avatar. Manara has no
                // student login, so this is as close as the platform gets to the
                // student picking their own face. Authorised by the ward edge.
                Route::put('/avatar', [GroupsController::class, 'updateAvatar']);

                // Hand the device to the child. Mints a token scoped to THIS
                // child and nothing else — see StudentSessionController.
                Route::post('/student-session', [StudentSessionController::class, 'store']);

                // Report cards and progress reports. BOTH ARE GETs, so the
                // realm's counted write list is untouched.
                //
                // `published()` is applied as a SCOPE rather than as a check
                // after the fetch, so a draft is a 404 in exactly the way a
                // nonexistent card is — a parent must not be able to learn that
                // a report about their child exists but is being withheld while
                // a teacher is still writing it.
                Route::get('/report-cards', [FamilyReportCardsController::class, 'index']);
                Route::get('/report-cards/{report_card_id}', [FamilyReportCardsController::class, 'show']);

                // The same document, as a file to keep. A GET, so still nothing
                // added to the counted write list, and it goes through the same
                // two gates — a draft is a 404 here exactly as it is above.
                Route::get('/report-cards/{report_card_id}/pdf', [FamilyReportCardsController::class, 'pdf']);

                // The child's MARKS — the read the platform was already
                // promising. Saving a mark mails this child's guardians a
                // GRADE_POSTED nudge whose only link is the family sign-in page
                // (Teacher\GradebookController::announceMarks), and until this
                // route existed that link led to a portal with nowhere to read
                // the thing the mail was about.
                //
                // A GET, so the realm's counted write list above is untouched:
                // there is no acknowledgement, no "mark as seen" and no reply.
                // The ward edge is the same one awards, ḥifẓ and letters use —
                // FamilyController::subject(), i.e. GroupAudience — and there is
                // deliberately no group-wide variant, for the reason this block
                // already states: a class-wide view of marks is the ranking
                // these modules exist to refuse.
                Route::get('/grades', [GradesController::class, 'forMember']);

                Route::get('/awards', [BehaviorAwardsController::class, 'forMember']);
                Route::get('/awards/summary', [BehaviorAwardsController::class, 'summary']);
                Route::get('/hifz', [HifzEntriesController::class, 'forMember']);
                Route::get('/letters', [ArabicLettersController::class, 'forMember']);
                Route::get('/hifz/progress', [HifzEntriesController::class, 'progress']);
            });
        });
    });
