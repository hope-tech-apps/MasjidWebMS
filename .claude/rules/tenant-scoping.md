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
  scope adds no constraint. Unbound means **"this route is not about one
  masjid"** — it does NOT mean "the caller is a SuperAdmin":
  - **SuperAdmin on a masjid-scoped route** (`/masjids/{id}/...`) — **BOUND to
    the masjid the route names.** A SuperAdmin may act on any masjid, but only
    one at a time. `/masjids/5/donations/export` returns masjid 5 and nothing
    else; pinned by `tests/Feature/SuperAdminExportScopeTest.php`.
  - **SuperAdmin on a route with no `{masjid_id}`** (the masjid list, the
    portal) — unbound, which is what keeps genuinely cross-masjid views working.
  - **Public mobile API** (`routes/api.php`, unauthenticated) — never runs the
    tenant middleware, passes `masjid_id` explicitly in the URL.

  > This bullet previously said SuperAdmins are *always* left unbound. That was
  > true of an early version of `ResolveMasjidTenant` and has not been true
  > since; it caused a security review to report a cross-tenant export leak that
  > does not exist. If you are about to "fix" such a leak, run the test above
  > first — the guardrail already holds.
- **Bypass explicitly** with `Model::withoutMasjidScope()` or
  `TenantContext::runWithout()` for super/system/reporting code. Never remove the
  trait to "make a query work".

## Admin CRM controllers

Admin CRM controllers (e.g. `ContactsController`) **keep the `{masjid_id}` route
param by convention but rely on the tenant guardrail for isolation — they never
hand-filter by `masjid_id`.** Concretely, for a `BelongsToMasjid` model:

- `index` queries the model directly (`Contact::query()->…->paginate()`); the
  bound `TenantContext` scopes it. Do NOT add `->where('masjid_id', $masjid_id)`
  and do NOT `Masjid::findOrFail($masjid_id)` to "scope" the list.
- `store` passes only validated fields to `create()` — never set `masjid_id`
  from the route or request body; the `creating` hook stamps it.
- `show`/`update`/`destroy` use the scoped `findOrFail()`, so another masjid's id
  resolves to a **404** (the row is invisible to the bound tenant). Keep that
  `findOrFail` OUTSIDE any broad `try/catch` so it surfaces as 404, not 500.
- Targeting a *different* masjid in the route (`/masjids/{other}/…`) is a **403**
  from `ResolveMasjidTenant`, which confines a MasjidAdmin to their own masjid.

This differs from the older pre-CRM controllers (Announcement/Event/Service),
which still hand-scope via `->where('masjid_id', …)` because their models don't
use the trait. New CRM controllers must NOT copy that hand-filtering.

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
