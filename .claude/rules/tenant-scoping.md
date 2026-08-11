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
group only) binds `TenantContext` to a MasjidAdmin's masjid. Since S3 the masjid
is decided by `App\Support\TenantResolver` from the `masjid_user` pivot, and the
binding is made with `TenantContext::setFromMembership()` — see the next two
sections. The pre-S3 rule (`users.masjid_id` if present, else the masjid the
admin owns via `masjids.user_id` -> `User::masjid()`) is still exactly what a
production request resolves to, because the resolver's gate is shut.

## The resolver FAILS CLOSED — an unbound context is not a safe default

`App\Support\TenantResolver` answers one question: which masjid may this
authenticated admin act on for this request? Its verdict is one of three
(`App\Support\TenantResolution`), and the third is the one that gets lost:

- **bind** this verified `masjid_user` row;
- **denied** — 403, `ResolveMasjidTenant::FORBIDDEN_MESSAGE`;
- **unbound** — *this route is not about one masjid*, which is a narrow,
  deliberate verdict and NOT a synonym for "could not decide".

Collapsing the last two is how cross-tenant reads happen: there is no row-level
security here, so an unbound context adds **no filter**. None of these bind, and
each is a comment in the code saying why: no membership at all; a membership
whose masjid is soft-deleted; a `{masjid_id}` the user holds no membership for
(holding one *elsewhere* does not soften it); several memberships with nothing in
the route to choose between them; a principal that is neither admin type. A
`masjid_id` in the body, the query string or a header is never read — the
resolver takes the ROUTE parameter, and `ResolveMasjidTenant::routeMasjidId()`
is the only place it is obtained.

- **The branch order in the middleware is MasjidAdmin-`if` / SuperAdmin-`elseif`.
  Do not swap it.** A SuperAdmin is bound from the ROUTE (they hold no
  memberships — S2's backfill gave them none on purpose); moving them onto the
  membership branch would 403 them out of every masjid they are not a member of.
  A design proposal described the SuperAdmin branch as "first"; it never was.
- **`TenantContext::set(int)` is `@internal`.** It stays public for exactly two
  callers with no membership to offer: the SuperAdmin route-derived branch, and
  system/reporting code that resolved the masjid itself (`ImpactMetrics::
  withTenant`). Request code binding on behalf of an admin uses
  `setFromMembership(MasjidUser)`, so the binding carries provenance
  (`TenantContext::membership()`) and no controller can bind an id it merely
  received. For the same reason, code that needs "the current masjid" reads the
  bound tenant FIRST and the route parameter only as a fallback — see
  `ValidatesEmbedContent::embedMasjid()`.

## The one-membership gate: `tenancy.multi_membership` ships FALSE

S3 shipped the multi-tenant resolver behind `config/tenancy.php` so production
behaviour is unchanged until S5. **While the flag is false a user's grants are
the single organisation they own**, and any further `masjid_user` row is inert:
it cannot be bound and naming its masjid in the URL is the same 403 it always
was. Read `TenantResolver::soleOwnedMembership()` — it is the gate, it is the
only place the flag is consulted, and S5 lifts exactly that method.

- **Do not flip the flag to "make multi-tenant work".** Turning it true also
  drops the ownership fallback (`membershipFromOwnership()`) that keeps admins
  working where the S2 backfill never ran or where a masjid was provisioned
  after it — nothing writes membership rows yet, that is S4. It also needs the
  SPA switcher, the request epoch and the store resets (S5), or a user gains a
  second tenant they can hold and never leave.
- The fallback is not a hole: it binds `masjids.user_id`, which S0 made unique
  among live rows in the database and which is precisely the authority the
  middleware ran on before S3. It is built as an UNSAVED `MasjidUser` — never
  persist it; a read path must not backfill authorization rows, and the
  one-default-per-user index would make that a request-time constraint error.
- `tests/Feature/TenantResolverTest.php` pins both halves: the identical fixture
  is refused with the gate shut and binds with it open, so the gate — not a
  half-built resolver — is demonstrably what holds the path closed.

## `masjids.user_id` is UNIQUE among live rows — keep it that way

`User::masjid()` is a `hasOne`. If a user owned two masjids it would not error,
it would return **one arbitrary row**, and the middleware above would bind the
tenant to whichever one the database felt like returning. Since S0 that is a
database fact, not a convention: a unique index over non-deleted, owned rows
(`add_owner_uniqueness_to_masjids_table`; see .claude/rules/migrations.md for how
the same predicate is spelled on each driver).

- Every write path that sets `masjids.user_id` must carry
  `unique:masjids,user_id` — `StoreMasjidRequest`, `ProvisionMasjidRequest` and
  `UpdateMasjidRequest` all do. On update it must be
  `Rule::unique(...)->ignore($this->route('masjid_id'))->whereNull('deleted_at')`,
  or the ordinary save that re-submits a masjid's own owner starts 422ing.
- Two states stay legal and must not be "fixed": a **trashed** masjid does not
  pin its former owner, and a masjid may have **no owner at all**.
- `php artisan masjids:reconcile-owners` reports duplicate owners; `--fix`
  detaches all but each owner's first-created masjid. Run it before migrating
  any environment whose data did not come from the admin API.

## `masjid_user` is the membership table — it must NOT use `BelongsToMasjid`

Since S2 there is a `masjid_user` pivot (`App\Models\MasjidUser`, reachable as
`User::memberships()` / `Masjid::memberships()`): one row per (user, masjid) with
a per-tenant `role` string and an `is_default` flag. It is the table the tenant
will eventually be derived FROM, so it is the one table carrying `masjid_id` that
must **not** get the global scope:

- Scoping it would make "which masjids may this user act on?" answerable only
  from inside a masjid the user is already bound to — a multi-tenant admin could
  never see or switch to their second membership.
- Its `creating` hook would stamp `masjid_id` from the bound tenant and silently
  write memberships into the wrong organisation.

Isolation for memberships is an authorization concern (the resolver + the API
surface), not a global-scope one.

- **At most one `is_default` row per user, enforced by the database** — a partial
  unique index on SQLite, the `default_key` STORED generated column on MySQL (see
  .claude/rules/migrations.md). It is what makes the single-tenant fallback
  deterministic; do not weaken it to an application check. Moving the default is
  an ordinary update as long as the old row is cleared in the same transaction.
- `pivot.role` mirrors the values of `User::TYPE_ROLE_MAP` and is **advisory**:
  authorization still runs on the `users.type` bridge, and spatie's `teams`
  feature stays off (flipping it ALTERs `model_has_roles`' primary key).
- `masjids.user_id` remains the ownership/billing pointer. Since S3 the resolver
  reads `masjid_user` — but while the one-membership gate below is shut,
  ownership is still what selects the single grant, so a membership row in any
  OTHER organisation grants nothing on its own.

## `TenantContext` is `scoped()`, and every queued job starts UNBOUND

It was a `singleton()`, which is fine for one request and wrong for `queue:work`
— one long-lived process, many jobs, one container. A job that bound masjid A
left it bound for the next job off the queue, which then read and wrote through
the global scope under someone else's tenant, silently.

- The binding is registered with `scoped()` in `AppServiceProvider`. The worker
  calls `forgetScopedInstances()` before reserving each job, so each job rebuilds
  it from nothing. **Do not change it back to `singleton()`.**
- `App\Listeners\ResetTenantContextBetweenJobs` clears it on `JobProcessing` for
  the worker paths that do not reset the container scope (`queue:work --once`,
  `queue:listen`). It **exempts the `sync` driver on purpose**: a sync job runs
  inside the dispatching request, under that request's tenant, and clearing it
  there would unbind the rest of the request. The suite runs `sync`.
- Anything under `app/Listeners` with a typed `handle(SomeEvent $e)` is **also
  auto-registered by Laravel's event discovery** (`Application::configure()`
  calls `withEvents()`), on top of any explicit `Event::listen`. Keep such
  listeners idempotent, and do not conclude a listener is unregistered just
  because you cannot find an `Event::listen` for it.
- A job that needs a tenant must bind it itself (`ImpactMetrics::withTenant()` is
  the pattern) rather than assuming the dispatcher's binding survived the queue.

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
