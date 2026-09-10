# T-040 — Staging environment coupling inventory

Derived statically (no PHP on the dev Mac) from `config/`, `app/`, `routes/`,
`resources/`, `bootstrap/app.php`, `bin/deploy`, `package.json`, `vite.config.js`
on 2026-09-09. Read-only recon: nothing in this file was changed.

Purpose: everything a STAGING environment must configure **differently** from
production so that (a) the app boots on a different hostname and (b) nothing
reaches a real person, a real device, or real money.

---

## 1. Host / URL coupling

### 1.1 `APP_URL` — the single most load-bearing value

`config/app.php:55` — `'url' => env('APP_URL', 'http://localhost')`.
There is **no `asset_url` key in `config/app.php`**, so `ASSET_URL` is read by
nothing in this codebase; `asset()` falls back to `APP_URL`. Setting `ASSET_URL`
on staging does nothing.

`config('app.url')` is read directly at:

| file:line | what it builds |
|---|---|
| `app/Http/Middleware/SecurityHeaders.php:88` | the origin injected into the CSP for the **proxied** paths (see 1.5) |
| `app/Services/Stripe/DonationService.php:174,176` | Checkout `success_url` / `cancel_url` (one-off donation) |
| `app/Services/Stripe/DonationService.php:303,305` | Checkout `success_url` / `cancel_url` (recurring donation) |
| `app/Services/Stripe/RegistrationCheckoutService.php:235,237` | registration Checkout `success_url` / `cancel_url` |
| `app/Services/Stripe/MealOrderCheckoutService.php:134` | Jummah-lunch Checkout base URL |
| `app/Http/Controllers/ConnectOnboardingLandingController.php:92` | `portalUrl` rendered on the Connect return/refresh landing |
| `app/Services/Auth/AccountAccessService.php:98` | password-reset link emailed to a human |
| `app/Jobs/SendGroupNotificationJob.php:122` | family sign-in link pushed/emailed to a parent |
| `app/Support/FormNotifier.php:297` | admin deep-link in the "new form response" email |
| `app/Services/Lunch/LunchOpeningNotifier.php:165` | public lunch-menu link in the opening broadcast |
| `app/Console/Commands/TenancyCanary.php:782,1939` | the origin the canary probes (overridable via `CANARY_BASE_URL` / `--base-url`) |
| `app/Console/Commands/BackupRun.php:397` | recorded into the backup `manifest.json` |
| `config/filesystems.php:44` | `public` disk `url` = `env('APP_URL').'/storage'` |
| `config/mail.php:49` | SMTP EHLO domain is parsed out of `APP_URL` |

Stripe **Connect** return/refresh URLs do *not* use `config('app.url')` directly —
they come from `route()` (`app/Http/Controllers/AdminDashboard/StripeConnectController.php:41-42`),
so they follow `APP_URL` via the URL generator plus `URL::forceScheme('https')`
(`app/Providers/AppServiceProvider.php:91`, applied **only** when
`APP_ENV=production`). A staging env with `APP_ENV=staging` will therefore emit
`http://` route URLs unless the proxy sets `X-Forwarded-Proto` **and** trusted
proxies are configured — and they are not (see 1.7).

### 1.2 `VITE_APP_URL` — must stay EMPTY

- `package.json:7` — `build:prod` bakes `VITE_APP_URL=https://masjid.hopetechapps.com` into the bundle. **Never run it** (STATE.md "Deploy" step 2).
- `vite.config.js:23` — `define: { 'process.env': { APP_URL: JSON.stringify(process.env.VITE_APP_URL) } }`.
- Read in the SPA at `resources/vue-app/core/constants/appConfigConstants.ts:7`,
  `core/services/FamilyApiService.ts:61`, `core/services/StudentApiService.ts:34`,
  `stores/publicLunchStore.ts:14`, typed at `core/types/declarations/env.d.ts:3`.
- Rationale recorded in `resources/vue-app/views/portal/OrgPortal.vue:191-193`: it
  is left empty deliberately so the SPA's API calls are host-relative and the
  same bundle works on every host.

**Staging action:** build with `npm run build` only. Do not introduce a
`build:staging` that bakes a host — it reintroduces the exact defect.

### 1.3 `CORS_ALLOWED_ORIGINS`

`config/cors.php:22-24` — `array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', '*')))`,
applied to `paths => ['api/*', 'sanctum/csrf-cookie']`, `supports_credentials => false`.

**Default is `*`.** A staging box that forgets this env var is wide open. It must
name every staging host that proxies into the app (see 1.5) or the proxied pages
render fine and every API call is refused by the browser — the failure mode called
out at `SecurityHeaders.php:72-74`.

### 1.4 `PORTAL_HOSTS`

`config/portal.php:31-37` — parsed as `host=masjidId` pairs, e.g.
`PORTAL_HOSTS=portal.alrazischool.org=14`. Consumed in Blade at
`resources/views/vue-app-index.blade.php:62-64`, which emits
`window.__PORTAL_MASJID__`.

**Staging action:** must NOT carry the production value. A staging host mapped to
masjid 14 would serve Al-Razi's portal from staging. Map a staging hostname to a
staging tenant id, or leave empty (an unlisted host just gets the ordinary app).

### 1.5 `SecurityHeaders` CSP and `$proxiedPaths`

`app/Http/Middleware/SecurityHeaders.php` (appended globally in
`bootstrap/app.php`, web + api).

- `:75` — `$proxiedPaths = ['jummah-lunch', 'portal']`. Only on these prefixes is
  `config('app.url')` added to `script-src` / `style-src` / `font-src` /
  `img-src` / `connect-src` (`:88-95`).
- Base policy (`:96-116`) hardcodes third-party allowances: `cdn.jsdelivr.net`,
  `*.pusher.com`, `js.pusher.com`, `fonts.googleapis.com`, `fonts.bunny.net`,
  `fonts.gstatic.com`, `*.supabase.co`/`.in` (**vestigial — there is no Supabase
  config in this repo any more**), `maps.gstatic.com`, `maps.googleapis.com`,
  `onesignal.com`, `*.onesignal.com`, `www.google.com`, `maps.google.com`.
- `frame-ancestors 'none'`, `form-action 'self'`, `base-uri 'self'`,
  `upgrade-insecure-requests`.

The CSP is **not** environment-aware. `upgrade-insecure-requests` means a staging
host served over plain HTTP will have its own sub-resources upgraded to `https://`
and fail. Staging must be TLS-terminated.

### 1.6 `SANCTUM_STATEFUL_DOMAINS` / session

- `config/sanctum.php:18-22` — default is `localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1` + `Sanctum::currentApplicationUrlWithPort()` (derived from `APP_URL`). Guard is `['web']` (`:36`). Token TTL `SANCTUM_EXPIRATION`, default 480 min (`:53`).
- `config/session.php:161` `SESSION_DOMAIN`, `:176` `SESSION_SECURE_COOKIE` (default **true**), `:206` `SESSION_SAME_SITE` (default `lax`).

**Staging action:** set `SANCTUM_STATEFUL_DOMAINS` to the staging host(s) and
`SESSION_DOMAIN` to the staging domain — a production `SESSION_DOMAIN` of
`.hopetechapps.com` would let a staging cookie be sent to production.

### 1.7 Trusted proxies / trusted hosts — **absent**

`bootstrap/app.php` calls `->withMiddleware(...)` but never
`$middleware->trustProxies(...)` or `$middleware->trustHosts(...)`. Consequences:

- No `TrustHosts` ⇒ nginx is `default_server` and **any Host is answered**
  (STATE.md "Infrastructure"). Staging will answer to a production Host header if
  someone points DNS at it. Consider adding `trustHosts` for the staging box, or
  fence it at nginx / basic auth.
- No `TrustProxies` ⇒ `X-Forwarded-Proto` is ignored, so HTTPS URL generation
  relies solely on `URL::forceScheme('https')` under `APP_ENV=production`
  (`AppServiceProvider.php:91`). **If staging runs `APP_ENV=staging` behind TLS
  termination, every generated URL — including Stripe `return_url`/`success_url`
  and emailed password-reset links — will be `http://`.** Either run staging with
  `APP_ENV=production`-style forcing, add trusted proxies, or widen the
  `forceHttpsInProduction()` condition. This is the single most likely staging
  boot-time surprise.

### 1.8 Hardcoded host literals (app/, config/, routes/, resources/ — tests and docs excluded)

Every hit below is a **comment or a documentation string**, except the two marked
**LIVE**:

| file:line | literal | live? |
|---|---|---|
| `package.json:7` | `https://masjid.hopetechapps.com` | **LIVE** (build:prod — do not use) |
| `config/services.php:122` | `moneeb@hopetechapps.com,shaher@hopetechapps.com` (`ASSISTANT_ESCALATION_EMAIL` default) | **LIVE** — real inboxes, must be overridden on staging |
| `config/services.php:62-63` | `hope-tech-apps/burlington-masjid-iOS`, `hope-tech-apps/burlington-masjid-Android` (GitHub dispatch repo defaults) | **LIVE** — would fire real CI if `GITHUB_DISPATCH_TOKEN` is set |
| `app/Enums/SectionType.php:81` | `alrazischool.org` | comment |
| `app/Http/Middleware/SecurityHeaders.php:60-61` | `burlingtonmasjid.com`, `alrazischool.org` | comment |
| `app/Http/Controllers/Api/V1/JummahLunchOrdersController.php:295` | `burlingtonmasjid.com` | comment |
| `config/form_templates.php:52,127` | `alrazischool.org` | comment |
| `config/canary.php:12` | `masjid.hopetechapps.com` | comment |
| `config/portal.php:18,27` | `alrazischool.org` | comment / example |
| `routes/web.php:31` | `portal.alrazischool.org` | comment |
| `resources/vue-app/router/routes/portalRoutes.ts:33` | `alrazischool.org` | comment |
| `resources/vue-app/views/portal/OrgPortal.vue:84,108,125-126,177-179` | `alrazischool.org`, `masjid.hopetechapps.com` | comments |
| `resources/views/vue-app-index.blade.php:53` | `alrazischool.org` | comment |

Good news: **no runtime code path hardcodes a production hostname.** The coupling
is entirely through env.

---

## 2. Third-party integrations — behaviour with EMPTY credentials

| Integration | Config keys | Behaviour when blank | Recommended staging setting |
|---|---|---|---|
| **Stripe (core)** | `services.stripe.key`, `.secret`, `.webhook_secret`, `.connect_webhook_secret`, `.fee_percentage`, `.platform_fee_percentage`, `.currency`, `.registration_checkout_window_minutes`, `.registration_reaper_grace_minutes` (`config/services.php:125-183`) | `AppServiceProvider.php:39-47` builds `StripeClient` as a **singleton with `api_key => config(...) ?: null`** — deliberately lazy, so an empty secret is fine until a call is made; the call then throws from the SDK. No boot failure. | Stripe **test-mode** keys (`sk_test_…`), a *test-mode* webhook secret, and a separate test Connect webhook secret. Never blank (a blank key produces confusing SDK exceptions rather than a clean refusal). |
| **Stripe Connect** | `StripeConnectService` (`app/Services/Stripe/StripeConnectService.php`), Connect landing `ConnectOnboardingLandingController` | `return_url`/`refresh_url` from `route()` — follow `APP_URL`. Per-tenant `masjids.stripe_account_id` is a **live** `acct_…` on prod. | Scrub `masjids.stripe_account_id` to NULL on staging (see PII inventory), otherwise staging reads/writes a real connected account. |
| **OneSignal (push)** | `config/onesignal.php` (`ONESIGNAL_REST_API_URL`, `ONESIGNAL_APP_ID`, `ONESIGNAL_REST_API_KEY`, `ONESIGNAL_USER_AUTH_KEY`); provisioning extras in `config/services.php:280-301` (`ONESIGNAL_APNS_P8`, `ONESIGNAL_FCM_V1_SERVICE_ACCOUNT_JSON`, …) | **`OnesignalService::__construct` THROWS** `RuntimeException('Missing some Onesignal app configurations.')` at `app/Services/OnesignalService.php:24-25` when any of api_url/app_id/app_key is empty. This is fail-closed (nothing is sent) but it means `prayers:send-due` — which type-hints `OnesignalService` in `handle()` — **throws every minute** on a blank staging box. Individual send methods (`sendDataSync`, `sendPrayerAlert`) are fail-soft (`Log::warning` + `return null`) *once constructed*. | Leave blank and **disable the `prayers:send-due` / `prayers:daily-resync` schedule on staging** (or accept a per-minute exception in the log). Also NULL `masjid_app_publishing.onesignal_app_id` / `.onesignal_rest_api_key` (encrypted) — the per-tenant override at `OnesignalService.php:56` would otherwise push to the real production apps. |
| **Mail (Resend)** | `config/mail.php:17` `MAIL_MAILER` (**default `log`**), `config/services.php:27-29` `RESEND_KEY`, `config/mail.php:111-113` `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` | Available null-ish drivers: **`log`** (writes the message to the log channel) and **`array`** (discards). Both ship in `config/mail.php:73-80`. | `MAIL_MAILER=log` (or `array`). Leave `RESEND_KEY` unset. Note STATE.md open item: the production `RESEND_KEY` was exposed on 2026-08-26 and **is still to be rotated** — do not copy it to staging. |
| **Broadcasting (Pusher)** | `config/broadcasting.php:18` `BROADCAST_CONNECTION` (**default `null`**), pusher keys `PUSHER_APP_KEY/SECRET/ID/CLUSTER` | `null` driver ships (`:77`), plus a `log` driver (`:73`). Default is already inert. | Leave `BROADCAST_CONNECTION` unset (`null`) or set `log`. |
| **SMS (Twilio)** | `config/services.php:217-221` `SMS_DRIVER`, `SMS_DEFAULT_COUNTRY_CODE`, `SMS_MAX_BODY_LENGTH`, `SMS_OPT_OUT_LANGUAGE`; `:251-256` `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_API_BASE`, `TWILIO_WEBHOOK_URL` | Best-behaved integration in the repo. `SmsProviderFactory` (`app/Services/Sms/SmsProviderFactory.php:39-60`): blank driver ⇒ Twilio-if-configured else `none`; `twilio` without creds **degrades to `NullSmsProvider`**, which *throws at send time* so the delivery is recorded FAILED and the admin sees "SMS is not set up on this deployment" — deliberately not a silent success. `SMS_DRIVER=log` gives `LogSmsProvider` (sends nothing, logs). | `SMS_DRIVER=none` (hard off) or `SMS_DRIVER=log` (to watch messages). Never leave Twilio creds present. Also scrub `masjid_sms_senders` (see PII inventory) so no tenant has an approved sender. |
| **Anthropic assistant** | `config/services.php:113-122` `ANTHROPIC_API_KEY`, `ASSISTANT_MODEL`, `ASSISTANT_EFFORT`, `ASSISTANT_MAX_TOKENS`, `ASSISTANT_MAX_TOOL_ITERATIONS`, `ASSISTANT_ESCALATION_EMAIL` | `app/Services/Assistant/MasjidAssistantService.php:44` — `new Client(apiKey: (string) config('services.anthropic.key'))`; no guard, so a blank key fails at call time (401 from the SDK). Gated per-tenant by `masjids.assistant_enabled` via `EnsureAssistantEnabled`. | Leave `ANTHROPIC_API_KEY` blank **and** set `ASSISTANT_ESCALATION_EMAIL` to a sink address — the default is two real people. Better: set `masjids.assistant_enabled = 0` for every staging tenant. |
| **GitHub dispatch (app builds)** | `config/services.php:60-65` `GITHUB_DISPATCH_TOKEN`, `GITHUB_IOS_REPO`, `GITHUB_ANDROID_REPO`, `APPLE_DEVELOPMENT_TEAM`, `IOS_BUNDLE_PREFIX` | `GithubDispatchService` — a set token fires a real `repository_dispatch` at `hope-tech-apps/*`. | **Leave `GITHUB_DISPATCH_TOKEN` unset.** This is a real-world side effect (kicks off CI / TestFlight builds). |
| **Slack notifications** | `config/services.php:31-34` `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL` | Laravel's Slack channel no-ops/throws without a token. | Leave unset. |
| **Google geocoding** | `config/services.php:88` `GOOGLE_MAPS_GEOCODING_KEY` | Requests fail; read-only, no side effect on a real person. | Optional; a restricted staging key or blank. |
| **Filesystems / media** | `config/filesystems.php:16` `FILESYSTEM_DISK` (default `local`), `:33-38` `local` ⇒ `storage_path('app/private')`, `:41-48` `public` ⇒ `storage_path('app/public')` with `url = env('APP_URL').'/storage'`, `:50-59` `s3` (AWS_*). `config/media-library.php:9` `MEDIA_DISK` (default `public`). | **No S3/Spaces in use today** — every media row is on the local `public` disk (`.claude/rules/backups.md`: "Every row is on `public` today"). The `s3` disk exists but has no configured backup strategy and `backup:run` refuses it. | Keep `MEDIA_DISK=public`, local disks. See §4 for what must NOT be copied. |
| **Backups** | `config/backup.php:22` `BACKUP_DESTINATION` (**default `/var/backups/manara`**), `:53` `BACKUP_KEEP_SETS` (14), `:85-115` db dump binaries + `minimum_bytes`, `:152-222` media disk/prefix/strategies/`minimum_files`/regression ratios, `:237` `process_timeout` | `backup:run` resolves the media disk **first** and refuses the whole run if it cannot archive it; there is no database-only path. Manifest written last; `*.partial` = crashed run. | Point `BACKUP_DESTINATION` at a **staging-only path** so a staging run cannot prune or overwrite production sets. Or disable `backup:run` on staging entirely (recommended). |
| **Tenancy canary** | `config/canary.php:22` `CANARY_BASE_URL` (falls back to `config('app.url')`), `:24-26` timeouts, `:98-101` budgets, `:465` `CANARY_LOG_CHANNEL` | Read-only probes, but it **makes HTTP requests to whatever `CANARY_BASE_URL`/`APP_URL` says**. If staging inherits a production `.env`, the staging canary probes production and eats its `/api/mobile` rate-limit bucket. | Set `CANARY_BASE_URL` to the staging origin explicitly, or disable the schedule. |
| **Credentials documents** | `config/credentials.php:29` `CREDENTIAL_DOCUMENT_DISK` (default `local` = private), `:37` directory `credential-documents`, `:60` max size | Private disk, no public URL. | Keep `local`. Exclude the directory from any staging copy (§4). |

---

## 3. Scheduled tasks (`routes/console.php`)

| line | command | cadence | external side effect |
|---|---|---|---|
| 16 | `sanctum:prune-expired --hours=24` | daily | deletes expired tokens — local only, safe |
| 23 | `prayers:send-due` | **every minute**, withoutOverlapping | **PUSH to real devices via OneSignal.** Also constructs `OnesignalService`, which throws when creds are blank |
| 29 | `prayers:daily-resync` | daily 07:00 | **silent PUSH to every device** to re-pull prayer times. It does not inject `OnesignalService` itself — it reads `mobile_app_users.onesignal_subscription_id` per masjid and dispatches a queued `SendPrayerSyncJob`, which resolves the service inside the worker (so a blank-cred failure lands in `failed_jobs`, not the console) |
| 69 | `groups:purge-feed` | daily 03:10 | **FORCE-DELETES** group feed posts, messaging threads and behaviour awards past retention — i.e. records about children |
| 77 | `registrations:reap-expired` | every 15 min | releases held seats; reads/writes registration state tied to Stripe Checkout sessions |
| 88 | `family:prune-login-codes` | daily 03:25 | deletes expired parent sign-in codes — local only |
| 294 | `tenancy:canary --json` | hourly at :47, withoutOverlapping(15) | **outbound HTTP** to `CANARY_BASE_URL` / `APP_URL` (read-only probes, but it consumes the target's rate limit) |
| 486 | `media:verify --json` | `17 */6 * * *`, withoutOverlapping(30) | read-only disk/DB verification — safe |
| 559 | `backup:run` | daily 02:40, withoutOverlapping(60) | **writes and PRUNES** backup sets under `BACKUP_DESTINATION` |

**The real safety belt is the scrub, not the env.** Both prayer commands select on
`mobile_app_users.onesignal_subscription_id`; if the scrub NULLs that column,
`prayers:send-due` and `prayers:daily-resync` find zero devices and push nothing
even with live OneSignal credentials present. Do both.

**Staging recommendation:** disable `prayers:send-due`, `prayers:daily-resync`,
and `backup:run`; repoint or disable `tenancy:canary`. The three delete/purge
sweeps are safe on staging (that is arguably where they should be exercised), but
`groups:purge-feed` will permanently remove rows — do not expect a staging copy to
stay complete over time.

---

## 4. Private-disk uploads — what a staging copy must NOT carry

Private disk is `local` = `storage/app/private` (`config/filesystems.php:35`); it
has **no `url`** key, deliberately (`.claude/rules/private-uploads.md`).

| config key | default disk | default directory | content |
|---|---|---|---|
| `config/forms.php:32,40` | `local` | `form-attachments` | résumés, admissions documents on Schools forms |
| `config/groups.php:226,234` | `local` | `group-media` | **classroom feed photos — photographs of children** |
| `config/groups.php:379,380` | `local` | `group-resources` | teacher-uploaded class resources |
| `config/flyer.php:96,97` | `local` | `flyers/cutouts` | janazah photos being worked on |
| `config/credentials.php:29,37` | `local` | `credential-documents` | staff certification documents |
| — | local fs | `storage/app/private/mpdf` | report-card PDF scratch (`app/Services/Schools/ReportCardPdfService.php:239`) |

**Exclude all six directories from any staging copy.** The scrub script must also
null the DB columns that point at them (see the PII inventory) so staging does not
render broken links to files it should never have had.

Public media (`storage/app/public`, Spatie `media` table) is logos, announcement
images and feature icons — copyable, though a staging copy inherits real
organisation branding.

---

## 5. Deploy tooling

### `bin/deploy` (run as root on prod; `set -euo pipefail` at line 19)

1. `cd $APP_DIR` (`/var/www/html/Masjids_App_Management_System/MasjidsManagementSystem`, overridable via `APP_DIR`; `WEB_USER=www-data`, `QUEUE_SERVICE=masjid-queue.service`).
2. `git fetch --quiet origin main` then **`git merge --quiet --ff-only origin/main`** — a diverged prod checkout blocks the deploy (it did, silently, on 2026-09-08).
3. `composer install --no-interaction --no-progress --no-dev --optimize-autoloader` — the `-o` classmap regeneration is the whole reason the script exists.
4. `chown -R www-data:www-data app bootstrap/cache config database resources routes storage vendor/composer`.
5. `php artisan migrate --force` (as `www-data`, `HOME=/tmp`).
6. Caches: `config:clear`, `route:clear`, `view:clear`, then `config:cache`, `route:cache`. (Note the tension with STATE.md's "Do NOT run `optimize:clear` casually" and the memory that prod once could not `route:cache` because of a closure route — verify before reusing this verbatim on staging.)
7. `systemctl restart masjid-queue.service`, `sleep 2`, `systemctl is-active`.
8. **Class-load check**: an inline `php -r` walk of `app/` that derives each file's FQCN and asserts the autoloader resolves it; exits 1 listing every unresolvable class and telling you to run `composer dump-autoload -o`.

It ships **no frontend** — `public/build/` is rsynced separately, without `--delete`.

**CI:** `.github/workflows/tests.yml` is the only workflow on `main` — a sqlite
PHP suite (PHP 8.3, Node 22, `APP_ENV=testing`, with a guard step that fails if
the suite did not actually run) plus a second job that migrates against **MySQL**,
"as production runs them". There is **no deploy workflow on `main`** (the
`chore/github-actions-deploy` branch is unmerged), yet `deploy/README.md:4-5,38-40`
describes a GitHub Actions deploy that runs `queue:restart` — that documentation
is stale, and a staging pipeline should not be built on the assumption it exists.

### Other tooling

- `deploy/` — `README.md` (cron/queue setup) and `masjid-queue.service`.
- `scripts/` — `cutout` and `provision-cutout.sh` (flyer cut-out worker provisioning).
- `bin/backup` — the backup wrapper.
- `package.json` — `build` (correct), `dev`, `build:prod` (**forbidden**, bakes the host).
- `vite.config.js` — `base` is **commented out** (line 27: `// base: '/admin/'`), no custom `outDir` (laravel-vite-plugin default `public/build`), alias `@` → `resources/vue-app`, entries `resources/js/app.js` + `resources/css/app.css`.

### Environment indicator in the SPA — **there is none**

`resources/views/vue-app-index.blade.php` emits assets with a bare
`@vite('resources/js/app.js')` (line 67), fonts/icons via `asset()` (lines 29-43),
and the only injected global is `window.__PORTAL_MASJID__` (lines 62-64) when the
request host is in `config('portal.hosts')`.

`APP_ENV` is read in exactly three places repo-wide:
`config/app.php:29`, `app/Providers/AppServiceProvider.php:91`
(`forceHttpsInProduction`), and `app/Console/Commands/SeedDemoSchool.php:60`
(production guard). **Nothing surfaces the environment to a human.**

A staging banner therefore needs new work: emit e.g.
`window.__APP_ENV__ = @json(config('app.env'))` from the Blade and render a
banner in the SPA shell when it is not `production`. Without it a staging tab is
visually indistinguishable from production — which, given that this app takes
real donations, is the highest-value small change on this list.

---

## 6. Seeders and reset/seed commands

### `database/seeders/`

| seeder | what it does | safe on an empty DB? |
|---|---|---|
| `DatabaseSeeder` | **DESTRUCTIVE.** Truncates 15 tables (`masjid_mobile_app_features`, `announcements`, `events`, `services`, `tasabih`, `azkar`, `azkar_categories`, `hadiths`, `contact_us_reasons`, `mobile_app_users`, `masjid_abouts`, `iqama_time_settings`, `mobile_app_features`, `masjids`, `users`) with FK checks off, then calls MobileAppFeatures → Users → MasjidData → AppLevelData → Pages → RolesAndPermissions. Guarded: refuses under `APP_ENV=production` unless `ALLOW_DESTRUCTIVE_SEED` (`DatabaseSeeder.php:19-22`) | Yes on empty; **never** on a staging DB you want to keep |
| `UsersSeeder` | Creates three factory users with **known passwords**: `test@admin.com`/`password` (SuperAdmin), `test@masjid.com`/`12345678` (MasjidAdmin), `test@user.com`/`12345678` | Yes — but these are weak, well-known credentials; if used on staging, staging must not be internet-reachable without a second gate |
| `MasjidDataSeeder` | Creates one fictional masjid "Al Fatih" (`alfatih@mosque.com`, `+2012345678`, Alexandria EG address) plus donation link, about, pages | Yes — fictional, safe |
| `AppLevelDataSeeder` | Global app-level reference data | Yes |
| `MobileAppFeaturesSeeder` | The mobile feature catalogue | Yes |
| `CountriesCitiesSeeder` | Countries/cities reference data (commented out of `DatabaseSeeder`) | Yes |
| `PagesSeeder` | Pages + sections for all masjids, or `MASJID_ID=n` for one | Yes |
| `ContentLibrarySeeder` | Global curated hadith/tasbeeh/azkar library; idempotent `updateOrCreate` on a natural slug; explicitly safe to re-run on prod | Yes |
| `FlyerTemplateSeeder` | Installs system flyer designs from `resources/flyer-templates/index.json`; idempotent upsert by `key`, truncates nothing | Yes |
| `RolesAndPermissionsSeeder` | Spatie roles/permissions bridged to legacy `users.type`; `firstOrCreate` + `syncPermissions`, idempotent | Yes |

### `app/Support/DemoSchoolSeeder.php`

Builds (and removes) the **Al-Razi Islamic School demo tenant** — a complete,
fictional Schools-vertical organisation: masjid + users + contacts + groups +
memberships + posts + threads + messages + attachments + hifz entries + behaviour
awards + forms + form responses + offerings + fee plans + registrations. It lives
in `App\Support`, *not* `Database\Seeders`, precisely so it can never be reached
by `db:seed --class` or a stray `$this->call()`. Driven only by
`php artisan demo:seed-school`, which refuses under `APP_ENV=production` without
`--force` (`SeedDemoSchool.php:60`).

**This is the right primitive for staging content** — fictional by construction,
and reversible.

### `app/Console/Commands/` (all 20)

| command | description |
|---|---|
| `demo:seed-school` | Seed or remove the Al-Razi demo tenant (fictional). **Use this on staging.** |
| `seed:pages` | Seed pages/sections for all masjids or one (`--masjid_id`) |
| `events:seed-summer-2026` | Seed June–July 2026 summer programs into Events + Announcements; `--push` **also sends a push** |
| `form:apply-templates` | Seed a tenant's vertical form templates, skipping slugs it already has |
| `form:import` | Import/update a sign-up form definition from JSON |
| `curriculum:import` | Import a school's weekly pacing guide into `curriculum_weeks` |
| `schools:import-roster` | Import a school roster CSV — **children, guardians and edges** (reversible) |
| `crm:import-ledger` | Import a historical donor/rent ledger CSV into the CRM (reversible) |
| `masjids:reconcile-owners` | Report (and with `--fix`, resolve) users owning more than one live masjid |
| `app:features-ensure-icons` | Re-attach missing mobile feature icons from `storage/app/public/icons` (idempotent) |
| `media:verify` | Verify every media row still has its file and every listed org has a logo (read-only) |
| `backup:run` | Back up database + media disk as one restorable set |
| `backup:restore` | Restore a set — database and media together, or not at all |
| `groups:purge-feed` | Force-delete group posts/threads/behaviour awards past retention (`--dry-run`, `--masjid=`) |
| `family:prune-login-codes` | Delete long-expired parent sign-in codes (`--days=30`) |
| `registrations:reap-expired` | Release seats held by pending registrations whose Checkout window expired |
| `prayers:send-due` | Push adhan/iqama at prayer time to devices gone dark |
| `prayers:daily-resync` | Daily silent push to re-arm device notification windows (`--dry-run`) |
| `prayers:test-push` | Send one immediate push to a single device — **sends to a real device** |
| `tenancy:canary` | Probe the running public API for cross-tenant leakage (read-only) |

**No `db:reset` / `migrate:fresh` wrapper exists.** A staging reset is
`php artisan migrate:fresh` + `db:seed` + `demo:seed-school`, or a scrubbed
production restore.

---

## 7. The 12 things staging MUST set

1. `APP_URL` — the staging origin (`config/app.php:55`; feeds 15+ call sites listed in §1.1).
2. `APP_ENV` — decide deliberately: `staging` disables `URL::forceScheme('https')` (`app/Providers/AppServiceProvider.php:91`) and every generated URL becomes `http://`. Either add trusted proxies or widen that guard.
3. `VITE_APP_URL` — leave **empty**; build with `npm run build`, never `build:prod` (`package.json:7`).
4. `CORS_ALLOWED_ORIGINS` — explicit staging hosts. Default is `*` (`config/cors.php:23`).
5. `PORTAL_HOSTS` — empty, or a staging host mapped to a staging tenant id (`config/portal.php:31`).
6. `SANCTUM_STATEFUL_DOMAINS` + `SESSION_DOMAIN` — staging hosts only (`config/sanctum.php:18`, `config/session.php:161`).
7. `MAIL_MAILER=log` (or `array`) and no `RESEND_KEY` (`config/mail.php:17`, `config/services.php:28`).
8. `SMS_DRIVER=none` (or `log`), no Twilio creds (`config/services.php:218`, `app/Services/Sms/SmsProviderFactory.php:39`).
9. OneSignal keys blank **and** the two prayer schedules disabled (`config/onesignal.php`, `routes/console.php:23,29`, `app/Services/OnesignalService.php:24`).
10. Stripe **test-mode** `STRIPE_KEY`/`STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET`/`STRIPE_CONNECT_WEBHOOK_SECRET` (`config/services.php:127-140`).
11. `ANTHROPIC_API_KEY` blank + `ASSISTANT_ESCALATION_EMAIL` to a sink (its default is two real inboxes, `config/services.php:122`); `GITHUB_DISPATCH_TOKEN` unset (`:61`).
12. `BACKUP_DESTINATION` to a staging path, or drop `backup:run` from cron (`config/backup.php:22`, `routes/console.php:559`); `CANARY_BASE_URL` to the staging origin (`config/canary.php:22`).

Plus two pieces of new work, neither of which exists today:
- **No `TrustHosts`/`TrustProxies`** in `bootstrap/app.php` (§1.7).
- **No environment indicator** anywhere in the Blade or the SPA (§5).
