# MasjidWebMS — working memory

Laravel 11 + PHP 8.2 backend (Vue admin SPA) for the Burlington Masjid system.
Multi-tenant by `masjid_id` over one MySQL DB (utf8mb4_bin) on DigitalOcean
droplet 480119186. Auth = Laravel Sanctum. **MySQL has NO row-level security** —
tenant isolation is app-layer only.

## Detailed state lives in

- `STATE.md` — 60-second snapshot of where the project is.
- `PLAN.md` / `LOG.md` / `NOTES.md` — plan, changelog, scratch notes.
- `.claude/rules/` — path-scoped conventions (see `tenant-scoping.md`,
  and `testing.md` in the coordination root).

## Status

- **CRM Phase 0 — tenant-isolation guardrail scaffolded** (branch
  `feat/crm-phase0-tenancy`, off `main`, local only — not pushed). Adds
  `App\Support\TenantContext` (request-scoped singleton), the
  `ResolveMasjidTenant` middleware (alias `tenant`, admin route group only),
  the `BelongsToMasjid` trait (global scope + server-derived `masjid_id`
  creating hook + `withoutMasjidScope()` bypass), and the first consumer
  `Contact` (congregant) model + `contacts` migration + factory. Proven by
  `tests/Feature/TenantIsolationTest.php`. Not yet run locally (no PHP on the
  dev machine); run on the droplet/CI with `php artisan test --filter=TenantIsolation`.
  Convention documented in `.claude/rules/tenant-scoping.md`.
- Older backend state (theming, content unification, V1 caching deploy hold)
  is tracked in `STATE.md`.

## Key tenancy rules (see `.claude/rules/tenant-scoping.md`)

- Every tenant-scoped CRM model MUST use `App\Models\Concerns\BelongsToMasjid`.
- `masjid_id` is server-derived from `TenantContext`, never client input.
- Unbound context = no filter (SuperAdmin + public mobile API preserved).
- No DB-level backstop, so a cross-tenant Feature test is mandatory per model.
