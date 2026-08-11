# tests/ — Backend test suite

This directory holds PHPUnit / Pest tests for the Laravel API. Conventions are captured in `.claude/rules/testing.md` at the coordination root:
`/Users/moneebsayed/Documents/Claude/Projects/Masjid System/.claude/rules/testing.md`

## Layout

- `tests/Feature/<Domain>/` — feature/integration tests grouped by domain.
  - `tests/Feature/Splash/` — splash announcement (model scope, OneSignal IAM service, admin CRUD, mobile public endpoint).
- `tests/Unit/` — unit tests.
- `tests/TestCase.php` — base class. Currently the stock Laravel 11 stub; add shared helpers here if multiple suites end up needing the same setup.

## Required setup per test class

- `use RefreshDatabase` (Laravel trait).
- Force sqlite-in-memory in `setUp()` — see `.claude/rules/testing.md` for the exact snippet.
- For routes behind `auth:sanctum`, use `Laravel\Sanctum\Sanctum::actingAs($user)`. Plain `$this->actingAs($user)` does NOT authenticate Sanctum-guarded routes.
- For tests that touch `OnesignalInAppMessageService`, set the three `onesignal.*` config keys in setup so the service is configured, and use `Http::fake()` to intercept every outbound call.
- For tests that upload media, call `Storage::fake('public')` in setup.

## Run a single suite

```bash
php artisan test --filter="Feature\\\\Splash"
```

## Running the suite on the droplet (there is no PHP on the dev Mac)

Tests run against an **isolated rsync'd copy** under `/tmp`, never the live app
directory. Two traps, both of which look like "I broke 48 tests":

- **`rm -f bootstrap/cache/*.php` after EVERY rsync, not just the first.** The
  repo carries a checked-out `bootstrap/cache/packages.php` that predates
  `spatie/laravel-permission`, so the discovered-package list omits its service
  provider. The `permission:` middleware then throws `UnauthorizedException` on
  every gated CRM route — ~48 failures across ContactCrud / FundCrud /
  DonationRead / CrmFeatureGate / RolePermissionBridge / SuperAdminExportScope,
  in files that alphabetically precede whatever you just changed.
- **Delete the `/tmp` copy when done** — it holds a copy of production `.env`.

`ExampleTest` ("Vite manifest not found") is the one expected failure: a
source-only copy has no `public/build`. Anything else is a regression.

## Seeding reference data in a test

`Country` and `City` declare no `$fillable`, so `Country::create([...])` throws
`MassAssignmentException`. Insert them with the query builder
(`DB::table('countries')->insertGetId([...])`) — see `ProvisionOrgTypeTest`.

## Style

- Class-based PHPUnit with `#[Test]` attribute (preferred for new feature suites). Pest functional style is also OK if a file is already in that style — don't mix within a single file.
- Test method names in `snake_case`, one behavior per test, name reads like a sentence.
