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
- For tests that upload a **form attachment**, **group media**, or any other
  private file, fake the disk the FEATURE's config names —
  `Storage::fake(config('forms.attachments.disk'))`,
  `Storage::fake(config('groups.media.disk'))` — not `'public'`. See
  `FormAttachmentTest` / `GroupFeedTest` and `.claude/rules/private-uploads.md`.
  `UploadedFile::fake()->create($name, $kb, $mime)` reports that mime and size
  without writing the bytes, so an "oversized" case costs nothing — and unlike
  `->image()` it needs no GD, so it works on the droplet.

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
source-only copy has no `public/build`. Run `npm run build` before the rsync and it
passes with everything else. Anything else is a regression.

## A new tenant-scoped model needs BOTH kinds of suite

`.claude/rules/tenant-scoping.md` makes a cross-tenant Feature test mandatory per
model, and `TenantScopingCoverageTest` **enforces that mechanically** — it
discovers every `BelongsToMasjid` model by reflection and fails the build if one
has no test that asserts a refusal. If it fails on your new model, read the
failure: it prints the file to create and the assertions to write. Do not add an
exemption; exemptions are for models that genuinely are not tenant-scoped.

That suite is also the one file in `tests/` that must exclude itself from its own
scan (`testSources()`) — it names every model in its rosters, so without the
exclusion it would count as coverage for all of them and always pass.

The Groups slice is the reference shape for a model with a roster:

- `GroupTenantIsolationTest` — model layer, binds `TenantContext` directly
  (scope, creating hook, `withoutMasjidScope()`), plus the invariants the MODEL
  owns rather than the controller (here: a guardian edge never outliving the
  membership it was granted over).
- `GroupCrudTest` — the same guarantee end to end over HTTP: another tenant's id
  under your own route is a **404**, their masjid in the route is a **403**.

Both must force sqlite-in-memory, and any suite acting as a `MasjidAdmin` on a
CRM route must seed `RolesAndPermissionsSeeder` AND set `crm_enabled => true`.

`GroupFeedTenantIsolationTest` / `GroupFeedTest` extend that shape for a model
that also owns BYTES and a DISCLOSURE RULE. Two things to copy:

- **A suite whose subject is who-may-see-what needs deterministic identities.**
  `App\Support\GroupAudience` resolves a caller's person by matching their login
  email to exactly one tenant Contact, so every contact that must NOT be the
  caller is created with `'email' => null`. `ContactFactory` uses a non-unique
  `fake()->safeEmail()`, and a chance collision would silently flip an
  authorization assertion into a pass.
- **Assert on the disk, not only the row.** A soft delete must leave the bytes
  (`assertExists`) and the purge must remove them (`assertMissing`) — deleting
  the row while orphaning the file is exactly the bug the model-layer hooks
  exist to prevent, and it is invisible to `assertDatabaseMissing`.

## The baseline number in STATE.md goes stale

Quote a test count only after re-measuring. STATE.md said 427/1179 while the
branch was actually at 444/1302 (commit `d3c4782` landed in between), which makes
a clean run look like it grew 17 phantom tests.

## Seeding reference data in a test

`Country` and `City` declare no `$fillable`, so `Country::create([...])` throws
`MassAssignmentException`. Insert them with the query builder
(`DB::table('countries')->insertGetId([...])`) — see `ProvisionOrgTypeTest`.

## `Http::fake()` MERGES — a catch-all in `setUp()` outranks a later stub

`Http::fake()` appends to the stub list; it does not replace it. A bare
`Http::fake()` in `setUp()` therefore keeps answering *after* a test adds
`Http::fake(['*' => Http::response([...], 200)])`, and the specific stub never
runs — the call returns an empty 200 and `$response->json()` is `null`. It fails
as "Trying to access array offset on null", which reads like a bug in the code
under test.

Fake **per test** (a small private helper) whenever one case in the file needs a
real body back. `OnesignalUnconfiguredTest` is the worked example.

## Asserting environment-dependent behaviour

Anything that changes when `APP_ENV` is not `production` — the `X-Robots-Tag`
header, `/robots.txt`, the SPA ribbon — is asserted by setting
`config(['app.env' => 'staging'])`, never by touching the real environment.
`App\Support\Environment` reads `config('app.env')` precisely so this works
under a cached config. Three things that suite (`StagingSafetyTest`) settled:

- **Always write the production twin.** A middleware that stamped the header
  unconditionally passes every staging assertion while de-indexing the live
  site. One-sided tests cannot see it.
- **To probe a GLOBAL middleware, hit a nonexistent `/api/...` route.** The JSON
  404 renderer answers through the full middleware stack, needs no database, and
  — unlike any page route — needs no Vite manifest, so it works on a source-only
  droplet copy where `ExampleTest` cannot.
- **A decision made in a provider's `boot()` cannot be re-made by setting
  config afterwards.** `URL::forceScheme()` already ran. Replay just that
  private method by reflection (`ReflectionMethod::setAccessible`); calling
  `boot()` instead re-registers a listener and re-observes `User`. Pin the root
  with `URL::forceRootUrl('http://…')` first, or a CI box whose `APP_URL` is
  already `https://` makes the assertion pass for the wrong reason.

## Style

- Class-based PHPUnit with `#[Test]` attribute (preferred for new feature suites). Pest functional style is also OK if a file is already in that style — don't mix within a single file.
- Test method names in `snake_case`, one behavior per test, name reads like a sentence.
