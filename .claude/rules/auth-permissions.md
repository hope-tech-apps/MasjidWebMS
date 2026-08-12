# Auth, permissions & 2FA conventions

Scope: `app/Models/User.php`, `app/Http/Controllers/AdminDashboard/AuthController.php`,
`app/Http/Controllers/AdminDashboard/TwoFactorController.php`, `app/Http/Middleware/*`,
`routes/admin.php`, `database/seeders/RolesAndPermissionsSeeder.php`,
`config/auth.php`, `config/permission.php`, `config/crm.php`.

The permission + 2FA layer is **strictly additive**. It must never change how an
existing admin logs in or is authorized. If a change here would alter behavior
for a non-2FA user or a pre-existing endpoint, it is wrong.

## `auth.guards.sanctum.provider` is PINNED to `users` — never unpin it

- Sanctum does not require an `auth.guards.sanctum` entry. When one is absent,
  `SanctumServiceProvider::register()` synthesizes the guard with
  `provider => null`, and `Guard::hasValidProvider()` returns `true` for a null
  provider — the "does this token's owner belong to this guard" check never runs.
  **Unpinned, every model using `HasApiTokens` is admissible on every
  `auth:sanctum` route**, with only `UserAdminMiddleware`'s `type` string check
  behind it.
- `config/auth.php` therefore defines `sanctum` explicitly with
  `provider => 'users'`. A non-`User` tokenable then resolves to **null**
  (unauthenticated) inside vendor code, **before any application middleware
  runs**. This is a structural barrier, not a policy one, and it is what makes
  a second tokenable model (a parent/guardian `Contact`, T-015c) safe to add.
- Do not delete that guard entry and do not set its provider to null. A second
  realm gets its **own** guard + provider (e.g. `family` → `contacts`), never a
  loosened `sanctum`.
- **Declaring the guard cost one real regression — it is fixed, don't re-open
  it.** `App\Models\User` now declares `protected $guard_name = 'web'`. Spatie
  derives a model's guard name from the `auth.guards` entries whose provider
  model matches, preferring `config('auth.defaults.guard')` when it is among
  them — and `AuthManager::shouldUse()` **rewrites that config value to
  `sanctum`** on every authenticated request (both `auth:sanctum` and
  `Sanctum::actingAs()` call it). Before the guard was declared, `sanctum` could
  never match and resolution fell through to `web`; declaring it made every
  `permission:`-gated CRM route 403 with *"There is no permission named
  `view contacts` for guard `sanctum`"*. The model-level `$guard_name` states
  what was previously inferred by accident and must stay in step with
  `RolesAndPermissionsSeeder`'s guard. **Any additional guard pointed at the
  `users` provider inherits this hazard** — and any NEW model given spatie roles
  must declare its own `$guard_name` too.
- `tests/Feature/StaffAuthGuardPinTest.php` pins the provider value, the
  `web` permission-guard resolution under a rewritten default, and
  `Permission::count() === 8` together.
- **The two admin middleware are the independent second layer**, and must stay
  that way: `UserAdminMiddleware` and `SuperAdminMiddleware` require
  `Auth::user() instanceof App\Models\User` **and** compare `type` strictly.
  The guard pin does not make this redundant — a non-sanctum guard (a session,
  or a future `family` guard, which rebinds the default guard `Auth::user()`
  reads) reaches these middleware without passing the sanctum provider check.
  Their 401 envelope (`{status: failed, data: 'Unauthorized.'}`) is what the
  admin SPA switches on; do not change its shape.

## There are now TWO realms — read this before adding a THIRD guard

T-015c added the parent/guardian realm. The map is now:

| guard | driver | provider | model | who |
|---|---|---|---|---|
| `web` | session | `users` | `User` | staff (SPA session) |
| `api` | sanctum | `users` | `User` | staff (legacy, unused) |
| `sanctum` | sanctum | `users` | `User` | **staff tokens — the pin above** |
| `family` | sanctum | `contacts` | `Contact` | **parents/guardians** |

`App\Models\Contact` is authenticatable and tokenable (`HasApiTokens`), holds no
password column, and is reachable ONLY through `routes/family.php`
(`/api/family/masjids/{masjid_id}/…`).

**A third realm gets its own guard AND its own provider. Never point a new guard
at `users`.** Two separate things break if you do:

- It re-opens the spatie regression documented above. Spatie picks a model's
  permission guard from the `auth.guards` entries whose provider model matches
  that model, preferring `config('auth.defaults.guard')` — which
  `AuthManager::shouldUse()` rewrites on every authenticated request. `family`
  is safe because its provider model is `Contact`; a guard over `users` would
  join the candidate list for `User` and the only thing standing between you and
  403s on every CRM route is `User::$guard_name`. Any NEW model given spatie
  roles must declare its own `$guard_name` before it is given any.
- It is a second door to the same tokens. The `sanctum` pin works by comparing
  the tokenable against the provider's model; two guards over the same provider
  are two guards that accept the same tokens.

**The guard alone does NOT separate the realms — `family.active` does.**
`Laravel\Sanctum\Guard::__invoke()` walks `config('sanctum.guard')` (`['web']`
here) FIRST and returns whatever user it finds on those guards with **no
provider comparison at all**. A staff member with a live admin SPA session
therefore satisfies `auth:family`. `App\Http\Middleware\EnsureFamilyLoginActive`
(`family.active`) is what refuses them, and it is also where revocation is
enforced — `login_enabled_at` set, `login_revoked_at` null, not trashed, checked
on **every** request rather than at mint time, so a token already sitting in a
phone dies on the next request. Do not "simplify" it away on the strength of the
provider pin.

**`family.tenant` binds or aborts — never reuse `tenant` for a non-staff
principal.** `ResolveMasjidTenant` branches on `users.type` and lets anything
else fall through UNBOUND, which tenant-scoping.md defines as *no filter*. On an
authenticated family route that is a cross-tenant read of children's records.
`App\Http\Middleware\ResolveFamilyTenant` binds `TenantContext` from
`$contact->masjid_id` — the token's tokenable, never the URL and never a header
— and 403s if the route names a different masjid or if binding is impossible.
There is no path through it that reaches the route unbound.

**No `permission:` and no `admin`/`super` in the family tree.** Those gates read
`users.type` or a spatie role, and a `Contact` has neither. Authorization for a
parent is the roster (`GroupAudience`), not a permission string. `permission:`
applied to a Contact would ask the permission layer to resolve a guard for a
model that holds no roles — the T-015a regression in a new costume.
`Permission::count()` stays 8.

**Family token abilities are `Contact::FAMILY_TOKEN_ABILITIES` (`['family']`)**,
distinct from staff's `['staff']`, and inert for the same reason (see below).

**Family tokens cannot outlive staff ones.** The design asks for 30 days;
Sanctum builds *every* guard with the single global `config('sanctum.expiration')`
(`SanctumServiceProvider::createGuard`) and enforces it against `created_at`, so
a per-token `expires_at` can only ever SHORTEN a token. Raising the global value
would move staff sessions too, which the rule at the top of this file forbids. A
longer family session needs a per-guard expiration, which is a change to how the
guard is constructed — not an `expiresAt` argument.

Pinned by `tests/Feature/FamilyAuthGuardTest.php` (both directions of refusal,
each with a control that reproduces the vulnerable configuration) and
`tests/Feature/FamilyTenantBindingTest.php` (bound-or-refused, with a control
that demonstrates `ResolveMasjidTenant` falling through unbound).

## How a family token is MINTED (T-015d) — the only way

`POST /api/family/masjids/{masjid_id}/auth/request-code` and `.../verify-code`
are the **only unauthenticated routes in the family realm**, and the only source
of a `family` credential. They sit behind `family.guest` + `crm` +
`throttle:family-login` / `throttle:family-verify` — never `auth:family` (the
caller has no token yet, which is the point), never `admin`/`super`/`tenant`,
never `permission:`.

- **`family.guest` (`ResolveFamilyGuestTenant`) binds the tenant from the URL, or
  404s.** It exists because `family.tenant` binds from the TOKEN and there is no
  token at sign-in. Do not "simplify" it away and let the controller filter by
  hand: unbound means NO filter, so a `Contact` lookup would span every tenant
  and mail a code to a parent at a different school — a cross-tenant existence
  oracle delivered by SMTP.
- **The credential is never stored and never logged.**
  `contact_login_codes.code_hash` is `hash_hmac('sha256', $code, APP_KEY)`,
  compared with `hash_equals`. Keyed, NOT a bare sha256: a bare digest of a
  6-digit code is reversible by anyone holding the table, so "hashed at rest"
  would be decoration. `FamilyLoginCodeMail` is the **one Mailable in this app
  that is not `ShouldQueue`** — `QUEUE_CONNECTION=database`, so queueing would
  spool the plaintext into `jobs.payload` and, on failure, into `failed_jobs`.
- **Neither endpoint may become a directory.** `request-code` answers a fixed
  202 for every well-formed address — live parent, revoked, never-enabled,
  soft-deleted, or nobody — and `FamilyLoginService::issue()` returns `void` so
  there is nothing for a controller to branch on. `verify-code` collapses six
  failures (unknown address, no code, wrong, expired, replayed, locked out) into
  one 410. **Never add `exists:` to the request rules** — docs §11 names the
  staff login's `exists:users,email` as the oracle this realm must not copy. The
  throttles are keyed on the SUBMITTED address, so a 429 is not an oracle either.
- Three independent limits, and all three are required: `attempts` on the row (a
  DB column, because a cache flush must not re-arm an attacker four guesses in),
  the 10-minute TTL, and the rate limiters. `consumed_at` is written by a
  compare-and-swap inside the same transaction that mints the token, so a
  double-tap yields one token, not two.
- **Delivery is email to `login_email`, and only that.** Not `contacts.email`
  (imported, shared, unverified) and never SMS — see
  `App\Models\ContactLoginCode::CHANNELS` and `.claude/rules/broadcasts.md`
  ("a phone number is not consent"); even a fully consented number is consent to
  bulk announcements, not permission to send a credential to a number that may
  have been recycled.

Pinned by `tests/Feature/FamilyLoginCodeTest.php` and
`tests/Feature/ContactLoginCodeTenantIsolationTest.php`.

## What an authenticated parent may READ (T-015e)

`GroupAudience::identitiesFor()` resolves a live `Contact` to its own id, so the
family read endpoints in `routes/family.php` (groups, feed + attachment bytes,
threads, per-child awards and ḥifẓ) are authorized by **the roster**, never by a
permission string. See `.claude/rules/groups.md` for the disclosure rules
themselves — none of them changed. **The realm is read-only**: apart from the two
sign-in POSTs there is no verb but GET, and
`FamilyPortalTest::no_family_route_accepts_a_write_verb` fails if that stops
being true.

## Staff tokens are minted with a named ability, not `*`

- `AuthController::login()` mints with `AuthController::STAFF_TOKEN_ABILITIES`
  (`['staff']`). Previously it used `createToken()`'s default, `['*']`, which
  satisfies any ability check — including one written later to fence a staff
  token OUT of a parent/guardian surface.
- This is **inert today**: `tokenCan` and the sanctum `abilities`/`ability`
  middleware appear nowhere in `app/`, `routes/`, or the framework/spatie paths
  this app uses, so no request outcome depends on it. Naming the realm now is
  what lets a later slice add enforcement without invalidating live tokens.
- If you add ability enforcement to a route, remember every token issued before
  that change carries whatever abilities it was minted with — check the
  `personal_access_tokens` backlog before relying on it as a gate.

## `users.type` is the source of truth — spatie roles are a bridge

- The legacy `users.type` enum (`SuperAdmin` / `MasjidAdmin` / `User`) still
  drives the `admin` (`UserAdminMiddleware`) and `super` (`SuperAdminMiddleware`)
  middleware and every existing `type` check. **Do not replace or remove it.**
- `spatie/laravel-permission` is layered alongside. Each `type` maps to a role via
  `User::TYPE_ROLE_MAP` (`SuperAdmin→super-admin`, `MasjidAdmin→masjid-admin`,
  `User→member`).
- The bridge is kept in sync by `User::syncRoleFromType()`, invoked on every save
  by `App\Observers\UserObserver` (registered in `AppServiceProvider::boot`) and
  backfilled once by `RolesAndPermissionsSeeder`. `syncRoleFromType()` is
  **defensive — it never throws**, so a user write can't break if roles aren't
  seeded yet. When you change a user's `type`, the role follows automatically.
- Granular CRM permissions: `view contacts`, `manage contacts`, `view donations`,
  `manage funds`, `view donor pii`, `manage donations`. super-admin = all;
  masjid-admin = the full masjid-scoped CRM set; member = none.

## Permission gates apply ONLY to the new CRM endpoints

- Use the spatie `permission:` middleware **per-route**, and only on the CRM
  endpoints added on this branch (`contacts`, `funds`, `donations`, `connect` in
  `routes/admin.php`). It runs after `auth:sanctum` + `admin` + `tenant`.
- **Never** add a permission gate to a pre-existing endpoint — that risks locking
  admins out.
- Because the spatie `UnauthorizedException` is an `HttpException(403)`, the JSON
  renderer in `bootstrap/app.php` returns a clean 403 with the standard envelope —
  no changes needed there. (Do NOT switch to Laravel's `can:` middleware: its
  `AuthorizationException` is not an `HttpException` and would fall through to a
  500 in this app's renderer.)
- Any HTTP test that acts as a `MasjidAdmin` against a gated CRM route must seed
  `RolesAndPermissionsSeeder` in `setUp` (as `ContactCrudTest` / `DonationFlowTest`
  now do) so the bridged role carries the permissions.

## The CRM is gated per-masjid by `masjids.crm_enabled` — SuperAdmin toggles it

- The whole CRM (member directory + money path) is OFF by default:
  `masjids.crm_enabled` is a boolean column **defaulting to false** (fillable +
  `boolean` cast on `App\Models\Masjid`). It rides along in the raw masjid
  payload the admin SPA loads (`MasjidsController::show`, no Resource), so the
  Vue side reads `masjidStore.masjid.crm_enabled`.
- The `crm` middleware (`App\Http\Middleware\EnsureCrmEnabled`, alias in
  `bootstrap/app.php`) 403s unless the tenant-bound masjid has `crm_enabled =
  true`. It is applied to the **CRM route group ONLY** (the `contacts`, `funds`,
  `donations`, `connect` group in `routes/admin.php`), layered on TOP of the
  per-route `permission:` gates — so a MasjidAdmin with the full CRM permission
  set still gets 403 while their masjid's CRM is off. It runs after `tenant`, so
  the target masjid is already resolved.
- **Do NOT gate** with `crm`: the 2FA endpoints, the SuperAdmin crm-access toggle
  itself (a super needs it to turn the gate on), or any pre-existing endpoint.
- Only a SuperAdmin flips it: `PATCH /api/admin/masjids/{masjid_id}/crm-access`
  `{ "enabled": true|false }` → `MasjidsController::setCrmAccess`. Super-ness is
  enforced **in the controller with `abort(403)`** (an `HttpException` → clean
  403 via the app renderer), NOT the shared `super` middleware — that middleware
  answers non-super callers with 401, but the CRM-access contract is a 403 for
  anyone non-super (and a MasjidAdmin must never enable the CRM on their own
  masjid). Same reason `can:`/FormRequest `authorize()` are avoided: their
  `AuthorizationException` would 500 in this app's renderer.
- Tests act as a MasjidAdmin against the gated routes, so any such test enables
  the gate in `setUp` (`ContactCrudTest`/`DonationFlowTest` set `crm_enabled =>
  true` in `makeMasjid`); the default-off + gate behavior lives in
  `tests/Feature/CrmFeatureGateTest.php`.

## 2FA is enrollable and enforced ONLY when confirmed — never a lockout

- TOTP via `pragmarx/google2fa` (+ `bacon/bacon-qr-code` for the QR), wrapped by
  `App\Services\TwoFactorService`. **Not** Laravel Fortify (it would restructure
  the existing Sanctum auth).
- Columns on `users`: `two_factor_secret` (nullable, **`encrypted`** cast — keep it
  in `$hidden`) and `two_factor_confirmed_at` (nullable). `two_factor_confirmed_at`
  set == 2FA active.
- Enrollment handshake: `POST /2fa/enroll` (rotate secret + return otpauth URI +
  QR data-uri, does NOT enable), `POST /2fa/confirm` (verify a live code → set
  `two_factor_confirmed_at`; bad code → 422), `DELETE /2fa` (requires a valid code).
- Login (`AuthController::login`): after valid email+password, require a TOTP code
  **only if** `hasTwoFactorEnabled()`. Missing code → `status: two_factor_required`
  challenge at 200 with **no token**; wrong code → 422; correct code → normal
  success. Users without confirmed 2FA skip the block entirely — unchanged login.
- `config('crm.require_admin_2fa')` (default **false**) is a forward-looking
  enforcement flag. It must stay false and must NOT be consulted in `login()` today
  (turning it on would require an enrollment UX first).
