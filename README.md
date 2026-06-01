# MasjidWebMS

The Laravel API + Vue admin SPA powering **`masjid.hopetechapps.com`**. Together with the Nuxt public site (`burlington-masjid-site`) and mobile apps (consuming this API + OneSignal), this is the backend of the **Masjid System** — a per-masjid content + community platform.

## Overview

- **REST API** over Sanctum auth + role middleware (`/api/admin/*`, `/api/mobile/*`, `/api/v1/*`).
- **Vue 3 admin SPA** (bundled via Vite into `public/build/`) for masjid admins to manage announcements, events, services, prayer schedules, donations, photo gallery, contact requests, splash modals, and more.
- **OneSignal integration** for both push notifications (existing) and In-App Messages (new — splash feature).
- **Pusher** for realtime web events.
- **Spatie MediaLibrary** for image uploads.
- **Per-masjid caching** in front of every public mobile endpoint via `App\Support\MobileCache`.

## Requirements

- PHP 8.2+
- Composer 2.x
- Node.js LTS (18 or 20) + npm 10+
- A MySQL/Postgres instance for the application database
- An S3-compatible bucket for media (Supabase Storage works)
- OneSignal account (push + IAM)
- Pusher account (web realtime)

## Installation (local)

```sh
# 1. Clone + install
git clone <repo-url> MasjidWebMS
cd MasjidWebMS
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate
# Edit .env with your DB, S3, OneSignal, Pusher, etc. credentials.

# 3. Database
php artisan migrate
php artisan storage:link

# 4. Build the Vue admin SPA
npm run build      # or `npm run dev` for hot reload during admin SPA work

# 5. Serve
php artisan serve
# Vue admin lives at /
# API lives at /api/*
```

## Quick start — admin portal

1. Run the migrations (`php artisan migrate`).
2. Seed an admin user, or create one via tinker:
   ```sh
   php artisan tinker
   >>> User::factory()->create(['email' => 'you@example.com', 'password' => Hash::make('secret'), 'role' => 'SuperAdmin'])
   ```
3. Browse to `http://localhost:8000`, log in, and you'll land on the admin dashboard.

## Features

| Feature | Where in the admin |
|---|---|
| Mosque details, logos, theming | `/masjid/details` |
| Announcements | `/masjid/announcements` |
| **Splash announcements** (new — see [`docs/SPLASH_ANNOUNCEMENTS.md`](./docs/SPLASH_ANNOUNCEMENTS.md)) | `/masjid/splash-announcements` |
| Events | `/masjid/events` |
| Services | `/masjid/services` |
| Prayer time settings, iqama, jumaa | `/masjid/iqama`, `/masjid/jumaa` |
| Pages + sections (CMS-lite) | `/masjid/pages` |
| Donation links | `/masjid/donation` |
| About us | `/masjid/about` |
| Photo gallery | `/masjid/gallery` |
| Contact requests | `/masjid/contact-requests` |
| Push notifications | `/masjid/notifications` |
| Mobile feature toggles (super-admin) | `/masjid/mobile-features` |

## Deployment

Production deploys automatically on every push to `main` via GitHub Actions. **First-time setup is in [`DEPLOY.md`](./DEPLOY.md)** — generate an SSH deploy key, paste the public key into the Droplet, add 4 GitHub secrets, then any future `git push origin main` deploys in ~60-120s with a graceful maintenance window.

After first-time setup, the entire flow for shipping a backend change is:

1. Open a PR.
2. Merge it.
3. Done.

## Tests

Backend smoke tests live in the project coordination root (`/Users/moneebsayed/Documents/Claude/Projects/Masjid System/test_*.php`). Copy into the repo root and run:

```sh
php test_runner.php          # Phase 1 admin endpoints
php test_caching.php         # Phase 1 cache hit/miss
php test_queue.php           # Phase 1 queue dispatch
php test_phase2_backend.php  # Phase 2 backend
php test_security.php        # Phase 4.5 security verification (19 assertions)
```

PHPUnit feature tests for the splash feature live under `tests/Feature/Splash/`. Run via `php artisan test --testsuite=Feature --filter=Splash`.

## Documentation

- [`CLAUDE.md`](./CLAUDE.md) — per-repo memory file for AI agents (entry points, conventions, pitfalls).
- [`DEPLOY.md`](./DEPLOY.md) — production deploy setup + day-to-day operation.
- [`docs/SPLASH_ANNOUNCEMENTS.md`](./docs/SPLASH_ANNOUNCEMENTS.md) — splash feature architecture + mobile-team handoff.
- [`CHANGELOG.md`](./CHANGELOG.md) — user-visible changes per release.
- **Coordination root** (`/Users/moneebsayed/Documents/Claude/Projects/Masjid System/`) — cross-repo memory: `STATE.md` (resume point), `PLAN.md`, `DECISIONS.md`, `NOTES.md`, `LOG.md`, `.claude/rules/*.md`, `SECURITY_SWEEP_REPORT.md`, plus deployment-time test files.

## Contributing

- Branch from `origin/main`. Direct pushes to `main` reserved for explicit ops.
- Commit-message style: see [`coordination root/.claude/rules/commit-style.md`](/Users/moneebsayed/Documents/Claude/Projects/Masjid%20System/.claude/rules/commit-style.md).
- Security conventions for controllers + Form Requests: see [`coordination root/.claude/rules/security.md`](/Users/moneebsayed/Documents/Claude/Projects/Masjid%20System/.claude/rules/security.md). **Do not return `$e->getMessage()` to clients; do not allow `svg` in `mimes:` allowlists.**

## License

Proprietary — Hope Tech Apps. Not open source.
