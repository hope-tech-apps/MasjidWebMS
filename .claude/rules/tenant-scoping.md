---
paths:
  - "app/Models/**"
---
# Tenant scoping (CRM models)

The CRM is multi-tenant by `masjid_id`. **MySQL has no row-level security**, so
tenant isolation lives entirely in the app layer. These rules are mandatory for
every tenant-scoped CRM model.

## The convention

- **Every tenant-scoped CRM model MUST use `App\Models\Concerns\BelongsToMasjid`.**
  It adds (1) a global scope that filters by the bound tenant and (2) a
  `creating` hook that stamps `masjid_id`.
- **`masjid_id` is server-derived from `App\Support\TenantContext`, never client
  input.** When a tenant is bound, the creating hook OVERRIDES any supplied
  `masjid_id` — a MasjidAdmin cannot create or move a row into another masjid.
- **Unbound context = no filter.** When `TenantContext` is not bound, the global
  scope adds no constraint. This is deliberate and preserves two behaviors:
  - **SuperAdmin** — left unbound by `ResolveMasjidTenant`, so cross-masjid
    dashboards keep working.
  - **Public mobile API** (`routes/api.php`, unauthenticated) — never runs the
    tenant middleware, passes `masjid_id` explicitly in the URL.
- **Bypass explicitly** with `Model::withoutMasjidScope()` or
  `TenantContext::runWithout()` for super/system/reporting code. Never remove the
  trait to "make a query work".

## How the tenant is bound

`App\Http\Middleware\ResolveMasjidTenant` (alias `tenant`, on the admin route
group only) binds `TenantContext` to a MasjidAdmin's masjid. The masjid is
resolved from the authenticated user (`users.masjid_id` if present, else the
masjid the admin owns via `masjids.user_id` -> `User::masjid()`).

## Testing is not optional

Because there is no DB-level backstop, **every new tenant-scoped model MUST ship
a cross-tenant Feature test** (seed masjids A and B, assert A cannot read/update/
delete B's rows and that create stamps the bound tenant). Mirror
`tests/Feature/TenantIsolationTest.php`. Run: `php artisan test --filter=TenantIsolation`.

## Do NOT retrofit blindly

Existing pre-CRM models (Announcement, Event, Service, …) are scoped manually by
controllers today. Do not add `BelongsToMasjid` to them without a dedicated task
+ tests — a global scope silently changes every existing query and can break live
public endpoints.
