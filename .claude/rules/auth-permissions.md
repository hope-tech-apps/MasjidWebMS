# Auth, permissions & 2FA conventions

Scope: `app/Models/User.php`, `app/Http/Controllers/AdminDashboard/AuthController.php`,
`app/Http/Controllers/AdminDashboard/TwoFactorController.php`, `app/Http/Middleware/*`,
`routes/admin.php`, `database/seeders/RolesAndPermissionsSeeder.php`,
`config/permission.php`, `config/crm.php`.

The permission + 2FA layer is **strictly additive**. It must never change how an
existing admin logs in or is authorized. If a change here would alter behavior
for a non-2FA user or a pre-existing endpoint, it is wrong.

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
