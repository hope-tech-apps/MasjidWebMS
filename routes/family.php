<?php

use App\Http\Controllers\Family\BehaviorAwardsController;
use App\Http\Controllers\Family\FamilyAuthController;
use App\Http\Controllers\Family\GroupPostsController;
use App\Http\Controllers\Family\GroupsController;
use App\Http\Controllers\Family\GroupThreadsController;
use App\Http\Controllers\Family\HifzEntriesController;
use App\Http\Controllers\Family\MeController;
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
| student login and somebody has to choose it with them. Everything else is a GET. A parent cannot START a thread (that would route around
| the teacher who decides what is discussed and where), and withdrawing their
| own consent is still T-015h — absent rather than half-built.
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
    });

// ------------------------------------------------------------ the portal

Route::prefix('family')
    ->middleware(['auth:family', 'family.active', 'family.tenant', 'crm', 'throttle:family'])
    ->group(function () {

        // Every family route is addressed per-organisation, matching the admin
        // convention (/masjids/{masjid_id}/...). The id is an assertion the
        // caller makes and `family.tenant` verifies; it is never the source of
        // the tenant binding.
        Route::prefix('masjids/{masjid_id}')->group(function () {

            Route::get('/me', [MeController::class, 'show']);

            // The forty drawings a family can choose from.
            Route::get('/avatars', [GroupsController::class, 'avatarCatalogue']);

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

            // Conversations this parent is a party to. Group-wide threads are
            // consent-gated; a participant thread reaches only the guardian of
            // the member it names.
            Route::prefix('groups/{group_id}/threads')
                ->controller(GroupThreadsController::class)
                ->group(function () {
                    Route::get('/', 'index');
                    Route::get('/{thread_id}', 'show');

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

                Route::get('/awards', [BehaviorAwardsController::class, 'forMember']);
                Route::get('/awards/summary', [BehaviorAwardsController::class, 'summary']);
                Route::get('/hifz', [HifzEntriesController::class, 'forMember']);
                Route::get('/hifz/progress', [HifzEntriesController::class, 'progress']);
            });
        });
    });
