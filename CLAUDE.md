# MasjidWebMS — Repo Memory

This repo is part of the **Masjid System**. Cross-repo coordination memory lives at:

> `/Users/moneebsayed/Documents/Claude/Projects/Masjid System/`

Read `STATE.md` there first to resume any in-flight work. Read `CLAUDE.md` there for the full project context, stack, layout, and conventions. Read `.claude/rules/security.md` before touching any controller, Form Request, route file, or `bootstrap/app.php`.

## 1. Purpose

The Laravel API + bundled Vue admin SPA for the Masjid System. Hosts:
- `/api/admin/*` — admin CRUD over Sanctum + role middleware
- `/api/mobile/*` — public per-masjid reads for the Nuxt site + mobile apps (rate-limited, server-cached)
- `/api/v1/*` — alternative public read namespace using `Masjid-Id` header
- Vue admin SPA — bundled via Vite into `public/build/`, served at `/`

Production: `masjid.hopetechapps.com`, DigitalOcean Droplet.

## 2. Entry points

- `routes/admin.php` — admin route table
- `routes/api.php` — public mobile route table
- `routes/api_v1.php` — public v1 route table
- `app/Http/Controllers/AdminDashboard/` — admin CRUD controllers
- `app/Http/Controllers/Mobile/` — public mobile read controllers
- `app/Services/OnesignalService.php` — push notification REST client
- `app/Services/OnesignalInAppMessageService.php` — IAM REST client (splash)
- `resources/vue-app/` — Vue admin SPA root (router, stores, views, components)
- `bootstrap/app.php` — middleware registration, exception handler
- `app/Providers/AppServiceProvider.php` — rate limiters, force-HTTPS, response macros
- `.github/workflows/deploy.yml` — auto-deploy on push to `main`
- `DEPLOY.md` — first-time deploy setup + day-to-day operation
- `docs/SPLASH_ANNOUNCEMENTS.md` — splash feature architecture + mobile handoff

## 3. Local conventions (specific to this repo)

- **Admin SPA build artifacts go in `public/build/`** (Vite default). Don't commit them — `.gitignore` excludes the build dir.
- **`safe()->only([...])`** is the canonical way to pull validated input in controllers. Don't use `$request->all()`.
- **Test files (`test_*.php`)** at the repo root are deployment-time scripts, not PHPUnit. They live canonically in the coordination root and are copied here for execution.
- **PHPUnit tests** (when added) live under `tests/Feature/<Domain>/` and `tests/Unit/<Domain>/`. The splash feature is the first to add them under `tests/Feature/Splash/`.

## 4. Pitfalls

- **Don't catch `\Exception` — catch `\Throwable`.** The new exception handler patterns rely on `\Throwable`. `\Exception` misses `Error` subclasses.
- **Don't return `$e->getMessage()` to clients.** Use `App\Support\Errors::publicMessage($e)`. Static-checked by the security sweep convention; see `.claude/rules/security.md`.
- **Don't add `svg` to `mimes:` allowlists.** Stored XSS via inline JS. See `.claude/rules/security.md`.
- **MobileCache flushes are required after admin mutations.** Otherwise the public endpoint serves stale data for up to TTL_SHORT (5 min). See `app/Support/MobileCache.php`.
- **OneSignal IAM derives its URL from the notifications config.** No separate env var. Don't change the notifications URL pattern without updating `OnesignalInAppMessageService::__construct`.

## 5. Open items

- `tests/Feature/Splash/` — newly added, never executed locally (no PHP env). Run on first prod deploy or set up PHP locally.
- Local `main` branch is stale relative to `origin/main` — branch from `origin/main` for new work until reconciled. See coordination `NOTES.md` N-001.
