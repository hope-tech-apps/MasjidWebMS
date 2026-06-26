# Changelog

All notable user-visible changes are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning will be [Semantic](https://semver.org/spec/v2.0.0.html) once we cut a first release.

## Unreleased

### Added
- **Splash announcement feature** (`59b4bf8`). Admins can author a per-masjid splash modal (image + title + body + optional CTA, scheduled with start/end window) from a new "Splash" sidebar item in the admin portal. The same content syncs to OneSignal as an In-App Message for mobile clients, and is exposed at `GET /api/mobile/masjids/{id}/splash` for the public Nuxt site. Highest-priority active row wins on overlaps; soft-toggle without delete. See `docs/SPLASH_ANNOUNCEMENTS.md`.
- **`splash_announcements` table** with composite index `(masjid_id, is_active, starts_at, ends_at)` for the hot lookup. Migration `2026_05_24_000000_create_splash_announcements_table.php`.
- **`App\Services\OnesignalInAppMessageService`** mirroring splash rows to OneSignal's IAM REST API. Fail-soft: OneSignal outage logs but does not break admin saves or the Nuxt site.
- **GitHub Actions auto-deploy workflow** to DigitalOcean (`.github/workflows/deploy.yml`). Every push to `main` SSHes into the Droplet, pulls, runs `composer install`, `php artisan migrate --force`, `npm ci && npm run build`, warms caches, restarts queue, reloads PHP-FPM. Graceful `down`/`up` bracket. Full setup in `DEPLOY.md`.
- **`docs/SPLASH_ANNOUNCEMENTS.md`** — architecture diagram, REST contract, mobile-team integration checklist.
- **`DEPLOY.md`** — one-time setup (SSH key generation, GitHub secrets, sudoers snippet) + day-to-day operation (rollback, env var updates, failure debugging).

### Changed
- **Exception handler in `bootstrap/app.php`** now routes 5xx responses through `App\Support\Errors::publicMessage()` instead of returning `$e->getMessage()` directly. Preserves `HttpResponseException` and `HttpExceptionInterface` status codes. **No more stack traces, file paths, SQL errors, or library internals in production API responses.**
- **31 controllers** under `app/Http/Controllers/**` updated to call `Errors::publicMessage($e)` in catch blocks instead of `$e->getMessage()`.
- **17 Form Requests** under `app/Http/Requests/Admin/**` had `svg` stripped from their `mimes:` allowlist (replaced with `webp` for size parity).
- **Sanctum personal-access tokens now expire after 8 hours** (`config/sanctum.php`, env override via `SANCTUM_EXPIRATION`). Was previously `null` (never expire).
- **Session cookies are encrypted + Secure-only by default** (`SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`).
- **CORS allowlist now env-driven** via `CORS_ALLOWED_ORIGINS`. Was hardcoded `*`.
- **HTTPS forced for URL generation in production** via `URL::forceScheme('https')` in `AppServiceProvider`.
- **`/api/admin/login`** rate-limited to 5 attempts/minute keyed on email+IP.
- **Public mobile endpoints** baseline-throttled at 60/min/IP, with `throttle:contact` (10/hr/IP) on the contact form and `throttle:device` (10/hr/IP) on device registration.
- **Admin-authored HTML rendered via `<SafeHtml>`** (admin) and `useSafeHtml()` (Nuxt — see burlington-masjid-site changelog). All 8 admin `v-html` call sites updated.

### Security
- **`App\Support\Errors::publicMessage()`** added to centralize safe error reporting. Logs internally, returns generic message in production.
- **`SecurityHeaders` middleware** added (CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, COOP, X-Permitted-Cross-Domain-Policies). Registered globally in `bootstrap/app.php`.
- **Pusher webhook signature verification** in `PusherWebhookController` via HMAC-SHA256 against `PUSHER_WEBHOOK_SECRET`. Fails closed if the secret is unset.
- **Unauthenticated `/api/spa/broadcast` debug route removed.**
- **`shell_exec` arguments wrapped in `escapeshellarg`** in `PrayersController::store` (Node prayer-times script invocation). Lat/lon coerced through `(float)`, dates rendered via Carbon `format()`.
- **Composer advisories cleared:** PsySH `GHSA-4486-gxhx-5mg7`, Symfony `CVE-2025-64500`.
- **npm advisories cleared** (admin SPA in `MasjidWebMS/`): 5 → 0.

### Fixed
- N-pra-y-ers controller's date handling now uses Carbon `format('Y-m-d H:i:s')` instead of `__toString()`, removing locale-sensitive output.

## Risk deferred
- Mobile devices must set OneSignal tag `masjid_id = <id>` for splash IAMs to deliver. The existing push notification flow uses `external_id` aliases — a different mechanism. Verify a sample device has both before relying on IAM delivery in production. See `docs/SPLASH_ANNOUNCEMENTS.md` "Mobile team — what you need to do."
