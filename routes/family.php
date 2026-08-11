<?php

use App\Http\Controllers\Family\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The parent/guardian API (T-015c)
|--------------------------------------------------------------------------
|
| Mounted under /api by bootstrap/app.php, exactly like routes/admin.php.
| Everything here is addressed as /api/family/masjids/{masjid_id}/...
|
| THE STACK, and why each layer is in it (docs/t015-parent-identity-design.md §2):
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
| WHAT IS DELIBERATELY ABSENT, and must stay absent:
|
|   - `admin` / `super`. Those read `users.type` off the principal; a Contact is
|     not a staff User and this tree is not administration.
|   - `permission:`. Spatie permissions are registered under the `web` guard for
|     `App\Models\User`. Applying one to a Contact would ask the permission
|     layer to resolve a guard for a model that holds no roles — the T-015a
|     regression in a new costume. Authorization in this realm is the roster
|     (`GroupAudience`), not a permission string.
|   - `tenant` (ResolveMasjidTenant). It branches on `users.type` and leaves
|     anything else UNBOUND, i.e. unfiltered. `family.tenant` replaces it here.
|
| SCOPE, deliberately: `GET /me` and nothing else. The feed, threads, awards and
| ḥifẓ endpoints belong to T-015e, which cannot ship before
| `GroupAudience::identitiesFor()` learns to resolve a Contact — until then an
| authenticated parent has NO standing in any group, and an endpoint mounted
| here would have to invent an authorization rule to return anything. The login
| endpoint that mints these tokens is T-015d; there is intentionally no way to
| obtain one from this file.
*/

Route::prefix('family')
    ->middleware(['auth:family', 'family.active', 'family.tenant', 'crm', 'throttle:family'])
    ->group(function () {

        // Every family route is addressed per-organisation, matching the admin
        // convention (/masjids/{masjid_id}/...). The id is an assertion the
        // caller makes and `family.tenant` verifies; it is never the source of
        // the tenant binding.
        Route::prefix('masjids/{masjid_id}')->group(function () {
            Route::get('/me', [MeController::class, 'show']);
        });
    });
