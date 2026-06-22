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

## Style

- Class-based PHPUnit with `#[Test]` attribute (preferred for new feature suites). Pest functional style is also OK if a file is already in that style — don't mix within a single file.
- Test method names in `snake_case`, one behavior per test, name reads like a sentence.
