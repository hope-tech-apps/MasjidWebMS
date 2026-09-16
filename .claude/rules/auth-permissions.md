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

## How a family login is TURNED ON — the admin half, and the only writer

Minting a token needs a contact with `login_enabled_at` set. Until this slice
**nothing in the application ever wrote that column**: the four `login_*`
columns are deliberately not fillable, and no controller set them. Production
carried 487 contacts, 0 with a `login_email`, 0 enabled — the whole portal was
built and unreachable. `App\Services\Family\FamilyAccessService` is the door,
exposed by `ContactFamilyLoginController` at
`/api/admin/masjids/{masjid_id}/contacts/{contact_id}/family-login` (GET / POST
/ DELETE), on the same `auth:sanctum` + `admin` + `tenant` + `crm` stack as the
rest of the CRM.

- **Gated by the CONTACTS permissions — no permission is minted.** `view
  contacts` reads state and history, `manage contacts` enables and revokes. Same
  call as the credentials routes: a login is an attribute OF the member
  directory. `Permission::count()` stays 8.
- **`login_email` is CHOSEN, never derived.** There is no fallback to
  `contacts.email` in the request rules or in the service, and there must never
  be one: that column is imported in bulk, is routinely a HOUSEHOLD address, was
  verified by nobody, and `GroupAudience::identitiesFor()` already reads it as a
  STAFF identity bridge. A blank field is a 422, not a default.
- **Uniqueness is case-INSENSITIVE, per tenant, and spans soft-deleted
  contacts.** `FamilyLoginService::resolveContact()` matches on
  `LOWER(login_email)` and requires EXACTLY ONE row, answering an ambiguity with
  the same silent 202 a stranger gets — so a duplicate produces a parent who can
  never sign in and no error anybody sees. The `(masjid_id, login_email)` index
  cannot prevent it alone, because production MySQL is utf8mb4_bin and
  `Parent@x.com` beside `parent@x.com` satisfies the index while breaking the
  lookup. The service therefore **normalises the stored address to lower case**
  (so the index is computed over the form the lookup compares) *and* pre-checks
  with a 422 naming the holder. Both halves stay.
- **Revocation reaches a live session two ways, and neither is redundant.**
  `family.active` re-reads liveness on every family request, so a token already
  in a phone dies on its next request; and `revoke()` additionally DELETES the
  contact's tokens, so the credential stops existing rather than merely being
  refused. Changing the `login_email` deletes tokens too — a session opened
  under the old address is one the change was meant to end. Re-typing the SAME
  address does not.
- **Every act appends to `contact_login_events`** (append-only at the model
  layer; see the model for what that does and does not stop). The actor's name
  and email are SNAPSHOT on the row, because `users` soft-deletes and a name
  read back through the foreign key prints nothing for exactly the staff member
  an audit is asked about. This grants a view of a child's records; "it was on"
  is not an answer to "who turned it on?".

Pinned by `tests/Feature/FamilyLoginEnablementTest.php` (round trip, the
uniqueness rule, both revocation mechanisms tested separately, the audit trail,
and an end-to-end pass from the admin switch through sign-in to one child's
records and not another's) and
`tests/Feature/ContactLoginEventTenantIsolationTest.php`.

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

## How an app member LEAVES — `DELETE .../me`, `DELETE .../me/device`, `/account-deletion` (2026-09-14)

**One service decides what deleting an account means:
`App\Services\Member\MemberAccountDeletion`.** The app route
(`MemberAccountController`) and the public page (`AccountDeletionController`)
both call it. Never write a second deletion path, and never clear login columns
from a controller.

- **Owner decision: remove the login, keep the office's records.** Every time:
  delete every token the contact holds; release every handset
  (`mobile_app_users.contact_id` back to NULL, rows kept); delete service
  interests; delete outstanding codes (family codes by contact, app codes by
  address); clear `verified_at`, `login_enabled_at`, `password`,
  `password_set_at`. Then hard-delete the contact ONLY when
  `signup_source = 'app'` and `reasonsToKeep()` is empty. Otherwise keep it as
  the office wrote it.
- **Never set `login_revoked_at` here.** That is the office cutting somebody off,
  and it would stop the person from ever signing up again. When a family login
  was on, append a `revoked` event with no actor: the admin panel badges any
  unknown verb "Enabled", so do not invent one.
- **"Office data" is enumerated from the schema, not guessed.** `OFFICE_RECORDS`
  (tables by contact id, guardian edges included), `OFFICE_RECORDS_BY_ADDRESS`
  (form responses and appointment requests from the same address in the same
  organisation), `OFFICE_COLUMNS` (contacts columns only the office or an
  office-granted login fills), an `email` that differs from `login_email`, and a
  broadcast snapshot naming the id. `MemberAccountDeletionCoverageTest` walks the
  schema: **a new `contact_id` / `*_contact_id` column anywhere, or any new
  column on `contacts`, fails the suite until it is classified.** In doubt,
  classify it as office data. Keeping a row is the safe direction.
- **Both routes are OUTSIDE `crm`**, still behind `auth:family` +
  `member.active` + `family.tenant`. An organisation can switch its CRM off
  after people signed up; store policy requires deletion wherever sign-up
  exists, and a sign-out must still release the phone. `POST /me/device`,
  interests and sign-in stay behind `crm`. Do not tidy these back into the
  `crm` group.
- **Every body from these routes has `data`.** Success is
  `{"status":"success","data":{}}`. Refusals built by the shared JSON renderer
  (401 from the guard or `member.active`, 403 from `family.tenant`, 429) gain
  `data: {}` through `App\Support\MobileErrorEnvelope`, hooked with
  `$exceptions->respond()` in bootstrap/app.php and matched on the route name
  `mobile.member.me.*`. A new leaving route must carry that name prefix. The
  member sign-in 410 carries `data` too. Other API routes' error bodies are
  deliberately unchanged.
- **The public page is not a directory.** The picker is `Masjid::listed()` and
  nothing else; never filter it by `crm_enabled` (denylisted from public
  payloads). The code step renders identically, and mails a code, for every
  address. Only the confirm step, after the mailbox is proven, says whether an
  account existed. Addresses are matched with the tenant bound to the chosen
  organisation, and `deleteByAddress()` returns null when nothing is bound.
- **Deletion codes live in `app_signup_codes`, with the purpose inside the
  HMAC.** A sign-in code's digest is unchanged; a deletion code is
  `hmac('account_deletion|' . code)`, so neither can stand in for the other. Same
  TTL, same attempt cap charged across every live row for the address, single
  use. The mail is `FamilyLoginCodeMail` with `purpose`, still NOT
  `ShouldQueue`.
- **The page reuses the app door's limiters BY NAME** (`throttle:member-login`,
  `throttle:member-verify`). A named limiter's cache key includes its name, so a
  copied limiter would hand out a second allowance. `familyLoginKey()` reads the
  organisation from the form body only when the route has no `{masjid_id}`, and
  `tooManyLoginAttempts()` answers a non-API request with the page (HTML 429);
  API bodies are unchanged.

Pinned by `tests/Feature/MemberAccountDeletionTest.php`,
`tests/Feature/AccountDeletionPageTest.php` and
`tests/Feature/MemberAccountDeletionCoverageTest.php`.

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

## Organisation capabilities and team access — the layered model (2026-09-10)

**Layer 1 — what an ORGANISATION has** is `config/capabilities.php` (2026-09-16 for modules). Every
entry has a `kind`, a `group` (`config/capability_groups.php`), a `label` and a `description`.

- **Grants** (`kind => grant`) are opt-in. Read ONLY through `Masjid::hasCapability()`, which
  **fails closed**: an unknown key, or one the loaded config lacks, is never granted.
  - Column-backed (`crm` → `crm_enabled`, `assistant` → `assistant_enabled`): listed so every
    capability reads from one catalogue; their own middleware (`crm`, `assistant`) and SuperAdmin
    endpoints are unchanged and stay the only writers.
  - Override-backed (`web_pages`, `jummah_lunch`, `school_calendar`, `form_editing`): stored in
    `masjids.capability_overrides` (JSON, NOT fillable, in `PUBLIC_DIRECTORY_DENYLIST`). Absent key
    → the org_type default, chosen to reproduce what each vertical reached before the catalogue.
- **Modules** (`kind => module`) are what a SuperAdmin switches per organisation; same overrides
  column. **Not "screens" any more** — since 2026-09-17 a module decides an admin screen AND, where
  it has one, a row of the MOBILE APP's menu, and five of them decide only the app row. Twenty-four,
  in `Masjid::MODULE_KEYS` order: `website`, `announcements`, `events`, `about_us`, `gallery`,
  `push_notifications`, `contact_requests`, `programs`, `zakat`, `broadcasts`, `flyer_studio`,
  `impact_report` (2026-09-16), then `prayer_times`, `splash`, `services`, `donation_link`,
  `giving`, `properties`, `appointment_requests` (switches wave 2), then the five app-only worship
  modules `quran`, `hadith`, `adhkar`, `qibla`, `tasbih` (app menu, 2026-09-17).
  - **The app-only five carry `surface => 'app'`** and have NO admin screen and no sidebar item:
    their whole effect is one row of `GET /mobile/masjids/{id}/menu`. `surface` is what lets the
    switch panel place a row for something with nowhere to live in the admin — see the sidebar /
    `where` rule below, which they are the exception to.
  - **Defaults are per org type** (`Masjid::MODULE_DEFAULTS`, a code copy of each entry's
    `defaults`). Every module is ON for a masjid. `splash`, `services`, `donation_link`, `giving`
    and `properties` are NOT OFFERED to a school or community organisation: off there until a
    SuperAdmin switches one ON. The rest are on for every type. `Masjid::moduleOfferedByDefault()`
    reads the table.
  - **Read them ONLY through `Masjid::moduleIsOff()`, which fails OPEN.** An unknown key is never
    off. For a key the loaded config knows as a module it reads the override, else the config
    default. For a `MODULE_KEYS` key the loaded config does NOT know (a stale cache) it answers
    `MODULE_DEFAULTS[key][orgType]` with overrides unread: a masjid keeps every screen, and a
    school gains no masjid screen. **Never `hasCapability()` on a module key.** bin/deploy runs
    the new PHP against the previous config cache until `config:cache`; a fail-closed read in that
    window refuses every organisation's contact form, program sign-up and announcements, and keeps
    refusing if the deploy aborts. `ModulesFailOpenTest` reproduces the window.
  - Every module check uses it: the gate, the Broadcasts channels (compose AND delivery), the
    Assistant's tools, the admin search, public contact-us, program and appointment-request intake,
    the app's donation checkout (403) and funds list (`[]`) while `giving` is off, Manara's prayer
    pushes (`App\Support\PrayerPushes::allowedFor`: `prayers:send-due`, `prayers:daily-resync`, the
    iqama-save sync), and the page builder's `module_off_note`. **Side doors follow the
    organisation with no SuperAdmin bypass** (Broadcasts, the Assistant, public intake, prayer
    pushes) — except the admin header search, which hides switched-off announcements, About Us and
    services from the organisation's own admins only, so a SuperAdmin still finds them.
    Public and mobile READS never follow a module, **with exactly one exception since 2026-09-17**:
    `GET /mobile/masjids/{id}/menu` IS a switch-derived read — the app's side menu and tab bar are
    the switches, which is the whole endpoint (`App\Support\AppMenu`). It is switch-ONLY (never "and
    the link has a URL", never "and Stripe is onboarded"), because the apps fall back to the legacy
    `/features` when it is unavailable and that fallback cannot know those things. Every other
    public and mobile read is unchanged, `/features` included. **Money already charged never follows
    one either**: the Stripe webhook, receipts and receipt emails run as for any organisation, and
    `App\Support\GivingSwitch::noteArrivalIfOff` only logs a warning, once per donation or
    commitment (`.claude/rules/stripe-payments.md`).
  - `Masjid::MODULE_KEYS` equals the config's module keys, in order, and `Masjid::MODULE_DEFAULTS`
    equals their `defaults` (`CapabilityGateTest`); the SPA's copies in `Capability.ts` are pinned
    by `CapabilityTsMirrorTest`. Every non-column entry names all of `Masjid::ORG_TYPES` in
    `defaults` (`?? false` otherwise). A module needs a sidebar item (`requiresModule`), a config
    `where` (Prayer times: tabs on the Details screen) or a config `surface` (the five worship
    modules: `app`), or the switch panel cannot place its row.
  - **No override outlives its code.** To remove a module: flip it back ON for every organisation
    while the code is live (audited), then revert with a data migration in the same commit that
    strips the key from `capability_overrides` and writes a NULL-actor ledger row per key removed.
    Before any re-ship, check that no override names the key.

**The gate.** `capability:<key>` (`EnsureOrgCapability`, after `tenant`). A module key passes
unless `moduleIsOff`; any other key passes only when `hasCapability`. A refused module answers 403
`{status:'error', message}`: "{label} is switched off for this organisation." when the org type is
offered it, "{label} is not switched on for this organisation." when it is not. `capability:a,b` is
**any-of**: the forms WRITE routes take `capability:web_pages,form_editing`, while form reads,
responses, staff codes and the public submit stay ungated. **SuperAdmins pass every `capability:`
gate.** `CapabilityGateTest` lints every key in the route table (split on commas) against the
catalogue and fails if a module gates no route; `OrganisationModulesTest` pins which prefixes carry
which module.
- `giving` (funds, donations with its export and stats, recurring-donations, annual-statements),
  `properties` and `appointment_requests` sit INSIDE `crm`, per prefix, so an organisation without
  the CRM hears the CRM sentence first.
- `services` gates every services route EXCEPT the index: BroadcastComposerView, jummahLunchStore
  and AboutUsView read `GET /services` and keep listing what is published while Services is off.
- Never behind a wave-2 module: Stripe Connect (`connect/*`, the forms-card Stop button), zakat
  settings, offerings and fee plans, contacts show (a member's giving history), the Impact Report,
  Mobile App Features, `prayer-calculation/options`.
- Switching `giving` OFF is refused while `GivingSwitch::liveSubscriptionCount()` > 0 (gifts Stripe
  can bill, including a cancelled row Stripe says it is still billing): 422
  `{status:'failed', data:{capability:[sentence]}}`, no ledger row. Monthly-gift checkout pages
  still open (`openCheckoutCount()`) refuse too, with the same envelope and a sentence that says
  wait. Neither has an override (owner, Q4: block). Switching ON is never refused. A switch never
  cancels, pauses or changes a donor's gift.

**The payload.** `ADMIN_APPENDS` carries `capabilities` — **grants only** — `modules_off`, the
modules this org type is offered that are switched off, and `modules_on`, the modules it is NOT
offered that a SuperAdmin switched on. Both are in catalogue order and `[]` for a fresh organisation
of any type. A not-offered module that is still off rides neither list (nobody took it away), so
Team `screens_off` and `OrganisationAccess` never fill a school's sentences with masjid screens.

**In the SPA, one flag per kind.**
- `requiresCapability: '<grant>'` hides unless `capabilities[key] === true`. Grants only.
- `requiresModule: '<module>'` hides only when `modules_off` includes it. An item that also carries
  `requiresOrgTypes` passes the org-type check for another type ONLY when `modules_on` names its
  module (`menuItemState` step 1: a school given Giving); otherwise it stays hidden for everyone,
  never "switched off". The header search's page links apply the same step (`itemFitsOrgType`,
  matched to the sidebar item by `to`), so a school is never offered a link to Services or
  Donation link. Routes carry no org-type guard, so a typed URL reaches the screen and its
  API answers with the "not switched on" sentence. **Never put a module key
  on `requiresCapability`:** its strict `=== true` hides a default-on screen whenever the payload
  lacks the key, which is every payload from a backend older than the SPA — and the built assets
  travel separately from the PHP, so the SPA can reach production first.
- `requiresAnyCapability: [...]` (route meta) passes when any listed grant is `true`.
- A SuperAdmin's sidebar reflects the ORGANISATION: switched-off items move to "Switched off for
  {org}" instead of vanishing.

**`website` is not `web_pages`.** `web_pages` means "this organisation's OWN admins may edit the
site" (off by default). `website` means "this organisation has a website" (on by default). The Web
Pages routes carry both gates, and the menu item carries both flags; for a SuperAdmin the module
decides. Burlington's site is run by the owner with `web_pages` off, BISS has no site: one key
could not tell them apart.

**The writer and the ledger.** The only writer is `PATCH .../capabilities/{key}` (in-controller 403
for non-super, outside every gate, refuses column-backed and unknown keys with 422).
`GET .../capabilities` (SuperAdmin only) serves the switch panel. Every flip — `setCapability`,
`setCrmAccess`, `setAssistantAccess`, `setDirectoryListing` (`directory_listing`), no-ops included
— writes an append-only `masjid_capability_changes` row inside the save's transaction
(`App\Support\CapabilityLedger`) plus `Log::warning('Organisation capability changed')`: production
is LOG_LEVEL=warning. Do not constrain `{capability}` in the route: `FamilyAuthGuardTest` sweeps
admin routes with a dummy value and needs auth to answer first.

**Layer 2 — what a PERSON in the organisation can do** is the Team screen (`TeamController`,
`/api/admin/masjids/{id}/team`), outside `crm` and without `permission:`:
- `admin` (MasjidAdmin) = everything the organisation has; `jummah_lunch` (LunchStaff) = the lunch
  board only; `teacher` is listed but managed on the Teachers screen.
- Its chips are GRANTS only (a grant with `listed_when_off => false`, like `form_editing`, shows
  only while on, so a new grant adds no "off" chip anywhere); `screens_off` names switched-off
  modules. `App\Support\OrganisationAccess` rows (the SuperAdmin's user screen) likewise carry grant
  `capabilities` and `modules_off`. The SPA sentences stay byte-identical when those lists are
  empty.
- It creates only those two, never reads `type`, binds to the BOUND tenant, refuses the owner /
  yourself / teachers on removal, deletes tokens, and retires a login left with no organisation.
- This REPLACED the per-account `users.can_manage_web_pages` grant (6f5dbd6), which never reached
  the server; the fold migration gave `web_pages` to every organisation where someone held it.
- `Permission::count()` stays 8: neither layer is a spatie permission.
