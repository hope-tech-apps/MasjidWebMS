# CLAUDE.md — MasjidWebMS

## Summary

MasjidWebMS is the **backend + admin panel** for the Burlington Masjid
system (hope-tech-apps org). It is a Laravel 11 API server plus an
embedded Vue 3 admin SPA. It is the single source of truth for masjid
content — About/Mission/Vision, theme colors, donation link, events,
announcements, services, prayer/iqama settings — served over three HTTP
surfaces: the **Admin** dashboard (Vue SPA), the **V1** API (consumed by
the separate Nuxt public site `burlington-masjid-site`), and the
**Mobile** API (consumed by the iOS/Android/tvOS apps). Content is
backend-driven, not hardcoded per masjid: the system is multi-tenant by
`masjid_id` (Burlington = masjid 1). Deployed to a DigitalOcean droplet.

## Stack

- **PHP 8.2**, Laravel 11 (`laravel/framework ^11.31`).
- Auth: `laravel/sanctum`. Media: `spatie/laravel-medialibrary`.
  Realtime: `pusher/pusher-php-server`. Email: `resend/resend-php`.
- **Admin SPA**: Vue 3 + Vite 6 + TypeScript + Pinia + vue-router,
  Bootstrap 5, vee-validate/yup, DOMPurify, adhan.js. Built by
  `laravel-vite-plugin` into `public/build`.
- Tests: Pest 3 / PHPUnit. Lint: Laravel Pint.
- DB: MySQL in prod; **sqlite** default locally and sqlite-in-memory in
  tests. Queue: `database`. Package managers: Composer + npm.

## Layout

- `app/` — Laravel application code (see Http/, Models/, Support/ below).
- `app/Http/Controllers/{AdminDashboard,Api,Mobile}` — the three API
  surfaces over one DB (Admin SPA, V1/web, Mobile/apps).
- `app/Http/Resources/Api` — V1 JSON Resources (the web contract shape).
- `app/Support/` — cross-cutting helpers: `SectionContentBinder` (entity
  binding), `MobileCache`, `ArabicText`, `Errors`.
- `app/Enums/`, `app/Models/`, `app/Jobs/`, `app/Mail/`, `app/Services/`.
- `resources/vue-app/` — the Vue 3 admin SPA source (`main.ts`,
  components, stores, router, layouts).
- `resources/{css,sass,js,views}` — Blade shell + email templates.
- `routes/` — `admin.php`, `api.php` (mobile), `api_v1.php` (web),
  `web.php`, `channels.php`, `console.php`.
- `database/` — migrations, seeders (incl. `PagesSeeder`), factories.
- `deploy/` — droplet ops artifacts (systemd queue unit, ops README).
- `tests/` — Pest/PHPUnit suite (has its own `tests/CLAUDE.md`).
- `config/`, `bootstrap/`, `public/`, `storage/`, `vendor/`.

## Commands

- Install: `composer install` && `npm ci`.
- Serve (all-in-one): `composer dev` (server + queue + pail + vite).
- Build admin SPA: `npm run build` (→ `public/build`). Dev: `npm run dev`.
- Migrate: `php artisan migrate`. Seed: `php artisan db:seed`.
- Test (all): `php artisan test`. One suite:
  `php artisan test --filter="Feature\\Splash"`.
- Lint/format: `./vendor/bin/pint`.

## Global conventions

- **Backend-driven content over hardcoding.** Masjid name, theme,
  About/Mission/Vision, donation link, etc. come from the DB per
  `masjid_id`; never hardcode a masjid's brand in code. See DECISIONS.md.
- **Three surfaces, one DB.** Admin (SPA), V1 (web via `masjid-id`
  header, JSON Resources), Mobile (apps via URL path, `MobileCache`d).
  A content change must be considered against all three consumers.
- **Enums are exhaustive matches** — adding a `SectionType` (e.g.
  `events`) requires updating every `match` arm over it.
- **Single-source binding**: `about_us`/`mission_vision`/`donation`/
  `contact_form` sections read dedicated models via
  `app/Support/SectionContentBinder`; do not re-add duplicate editors.
- Tests: sqlite-in-memory, `RefreshDatabase`, `Sanctum::actingAs` for
  guarded routes. See `tests/CLAUDE.md`.
- Deploy is **git-pull on the droplet** (GitHub Actions: pull + migrate +
  SPA rebuild), NOT Vercel. Vercel hosts the separate Nuxt site repo.

## Status

- API V1 (web contract) — **done** (live; uncached — see NOTES).
- Mobile API (apps) — **done** (live, `MobileCache`d).
- Admin SPA (page builder, theme tab, content library) — **done** (live).
- Burlington rebrand (backend-driven) — **done**.
- Per-masjid theming (`theme_settings` → web/mobile) — **done**.
- Content unification (SectionContentBinder + settings.bind) — **done**.
- Events section — **done** (`SectionType::events`, seeded home page).
- Donation link from settings — **done**.
- V1 response caching — **blocked** (staged, deploy held — see STATE).
