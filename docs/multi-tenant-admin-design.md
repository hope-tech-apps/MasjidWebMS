# Multi-Tenant Admin Access — Design

_2 approaches + adversarial critique, supervisor rubric. 2026-08-11._

## Verification pass (I read the rule and the disputed files myself)

Both critiques hold up; three corrections to the record:

- **`ResolveMasjidTenant.php:62`** — the `MasjidAdmin` branch is **first**, `SuperAdmin` is the `elseif`. The pivot proposal's "SuperAdmin branch kept verbatim and first" is factually wrong; reordering changes behaviour for a SuperAdmin who also holds a membership.
- **Ownership is genuinely non-deterministic.** `masjids.user_id` is `nullable()`, no unique index (`2025_02_06_092023_create_masjids_table.php:17-18`); `unique:masjids,user_id` exists only in `StoreMasjidRequest:26` and `ProvisionMasjidRequest:82` (validation-only, and `UpdateMasjidRequest` omits it). `User::masjid()` is a `hasOne` → arbitrary row. **Both** approaches are built on sand until this is fixed.
- **The queue leak is real and invisible.** `.env:53` `QUEUE_CONNECTION=database`, `.env.testing:18` `sync`. `TenantContext` is `$this->app->singleton(...)` (`AppServiceProvider:24`) and `forgetTenant()` has **zero** production callers outside `ImpactMetrics:802`.

Minor: 31 models use `BelongsToMasjid`, not ~50. 1019 test methods, 12 `*TenantIsolation*` files. `masjids.org_type` already exists; no `parent_masjid_id` anywhere.

## Score table

| Criterion | Weight | user-tenant-pivot | org-family |
|---|---|---|---|
| Fulfils requirement | 40% | **5** — unrelated tenants, consultant admins, per-tenant roles later, verticals | **2** — only *related* tenants; cannot express "admin at school, member at masjid"; downward access is all-or-nothing |
| Risk | 20% | **3** — one chokepoint, but teams flip cut from scope | **3** — smaller surface, but non-deterministic root + `restrictOnDelete` breaks `moveToTrash` |
| Testability | 15% | **4** — membership is a queryable fact; 12 isolation tests extend with a dual-membership actor | **4** — pure resolver, easy to unit test |
| Simplicity | 15% | **3** — one table + resolver; touches login, SPA, ~12 stores | **4** — one nullable column, no backfill |
| Reversibility | 10% | **4** — additive, `hasOne` untouched, `down()` truly drops | **4** — drop column; fail-closed hardening is behavioural |
| **Weighted** | | **4.05** | **3.00** |

**Picked: `masjid_user` pivot.** org-family loses on the 40% criterion and doesn't even avoid the pivot's prerequisite — it needs ownership uniqueness fixed first too, then buys a weaker model for the same price.

---

# Design: multi-tenant admin access via `masjid_user`

## Schema

```
masjid_user: id, masjid_id FK→masjids, user_id FK→users cascade,
             role varchar(64), is_default tinyint(1) default 0,
             timestamps
  unique (masjid_id, user_id)
  unique (user_id, default_key)   -- generated: is_default ? user_id : NULL
  index  (user_id)
```

The second unique is a MySQL 8 stored generated column — it makes "exactly one default per user" a DB fact, closing the nondeterministic-binding hole. `masjids.user_id` stays as the ownership/billing pointer; the pivot becomes the sole *authorization* source.

## Migration path for existing admins

Slice 0 first: add a real `unique index` on `masjids.user_id` (partial on `deleted_at IS NULL`), after a reconciliation command reports duplicates. Until ownership is deterministic, nothing downstream is safe.

Then backfill in-migration: `INSERT … SELECT id, user_id, 'masjid-admin', 1 FROM masjids WHERE user_id IS NOT NULL AND deleted_at IS NULL`. Every existing admin lands with exactly one default membership, so the resolver below reproduces today's behaviour exactly. SuperAdmins get no rows. `down()` drops the table — genuinely reversible, because the teams flip is out of scope.

## Binding, fail-closed

`TenantContext` becomes `scoped()`, not `singleton()`, and the queue worker resets it in a `JobProcessing` listener. This is a **pre-existing** leak that switching would weaponise.

`ResolveMasjidTenant` keeps the `MasjidAdmin`-first / `SuperAdmin`-`elseif` order **unchanged**. The MasjidAdmin branch becomes:

- route names a masjid → `memberships()->where('masjid_id', $routeId)->first()`; null → `abort(403)` (same message, same code).
- no route masjid, exactly one membership → bind it (byte-identical to today).
- no route masjid, multiple memberships → **unbound**, and only on an allowlist of genuinely non-tenant admin routes (`/user`, `/masjids`, `search`); anything else → 403. This deliberately avoids default-binding, which would silently mis-scope `EnsureCrmEnabled:38` and turn `ImpactMetrics::withTenant`'s guard into a 500.
- `users.type === 'User'` currently falls through to **unbound = unfiltered**. Close it: any authenticated non-SuperAdmin without a verified membership is 403.

`TenantContext::set(int)` becomes internal; the only public entry is `setFromMembership(MasjidUser)`, so no future controller can bind an unverified id. `ValidatesEmbedContent:106` is inverted to tenant-first (it currently reads the **route param first**).

## SPA

`/api/admin/user` returns `memberships[]`; `AuthController:54,84` stops force-logging-out a user whose `masjid` hasOne is null (that would lock out pivot-only admins), and `MasjidAdminsController:24`'s `doesntHave('masjid')` moves to the pivot. `dashboardMasjidId` becomes a switcher selection; the `localStorage` value is re-validated against `memberships` on rehydrate (`main.ts:32`) and cleared on logout. On switch: bump a request epoch, drop in-flight responses, `$reset()` every `stores/masjid/*`. **Every response echoes the server-resolved tenant**, and the chrome renders that — not the store.

**Since 2026-10-13: one login, a role at each organisation (the SPA's half).** A login can be lunch staff at one organisation and a teacher at another. The server answers sign-in and each area's `/user` in ONE area's voice (`type`, `masjid`, `memberships[]` are that area's), so everything here that routes or picks an API prefix by them is right unedited: `router.ts`, both `beforeEnter`s, `SignIn.vue`, `jummahLunchStore.base()`, `rehydrateOrganisation()`. Such a login's payload also carries `areas[]`; the key is absent for every other login, and "has `areas`" is the SPA's whole test. What was added, all of it inert without `areas`:

- **Where a page load lands** (`core/helpers/staffAreas.ts`, `authStore.fetchAuthUser`). The area that answered is kept in `localStorage` under `MASJID_APP_STAFF_AREA`, written only while an answer carries `areas`, removed when one does not and on sign-out. Without it the three `/user` routes are asked in the order they always were. With it, the area in the address bar is asked first, else the stored one. One more rule covers a login made a teacher while signed in (a live token, no stored area): when the first answer carries `areas` and the address names another of its areas, that area's `/user` is asked and its answer kept; otherwise she would reload her lunch board into "401 Unauthorized".
- **When the area in the address has gone** (`main.ts`, `startInAreaThatAnswered` in the helper, so that a test executes the move). If an area was stored when the page began to load and a different area answered, boot navigates to the answering area's home with `await router.replace()` BEFORE `app.use(router)`. Rewriting the address bar would not work: `createWebHistory()` reads the address when `router.ts` is imported.
- **The switch.** `TeacherSchoolPicker.vue`, the props-driven copy of the administrators' switcher, lists the places of the OTHER area under their role ("Friday lunch · Burlington Masjid") and is mounted in both shells; the lunch shell passes it no schools. There is no switch endpoint, so `switchArea()` in each layout asks the other area's `/user` FIRST (`authStore.askStaffArea`): refused, the person stays where they are and reads the server's own words; answered, it runs every organisation switch's steps in their order (new request epoch, empty the stores, forget the echo, save the organisation) and loads the other shell from nothing. The layouts import nothing new, because two existing tests mount them over fixed import maps.
- **A role taken away under an open shell** (`core/tenancy/staffAreaGuardCore.ts`, wired in `teacherSchoolGuard.ts`). The token is also the other area's session, so it stays valid and the shell's requests are refused: 401 at the gate or 403 at the organisation. Outside `APP_DEBUG` every 403 reads "Request failed.", for a class as for an organisation, so the guard never reads the sentence. It asks the shell's own `/user`: if the area still has the login at the organisation the tab is in, the refusal was about something else and nothing happens; if not, one reload (the school guard's stamp stops a second), and boot starts in the area that answers. The teacher client asks the guard before pushing sign-in on a 401; `main.ts` puts the same guard on the admin client, which the lunch board rides.
- **Signing out of an area that has just gone** (`authStore.signOutThroughOtherArea`, `areaToSignOutThrough`). Each shell signs out through its own area's `/logout`, which sits behind that area's gate. When the role behind the open shell has just been removed (or the server's switch turned off) the gate answers 401, nothing is deleted, and the token is still the session of the other area. The shell hands that refusal to the store BEFORE it clears the browser, and the store posts the other area's `/logout`, which ends every session. A dead session (`Unauthenticated.`) is not tried, so a login with one kind of role signs out with the one request it always made. The area guard takes a refused sign-out without asking or reloading: a reload would cancel the request that ends the session.
- **The lunch-staff form** shows the door's sentences under the field they are about. They arrive as validation does, with no top-level `message`, and were shown as "Request failed with status code 422".

Tests: `staff-areas`, `staff-area-switch`, `staff-area-guard`, `staff-area-sign-out`, `lunch-staff-door-words` under `resources/vue-app/tests/`.

## Roles

`pivot.role` ships **advisory only**. Authorization stays on the global `users.type` bridge, so `Permission::count() === 8` is untouched and `RolePermissionBridgeTest:102` passes unmodified. To prevent the escalation the critic found, memberships are constrained **homogeneous**: `pivot.role` must equal the user's global bridged role, enforced by a check + test. Per-tenant roles (Spatie `teams => true`) are **explicitly deferred to a separate initiative** — that flip ALTERs `model_has_roles`' PK and is not reversible alongside this work.

**Since 2026-10-13, for two kinds only (one login, a role at each organisation).** The paragraph above still holds for administrators. For a `Teacher` or `LunchStaff` login the row's role is no longer advisory and memberships are no longer homogeneous: a `teacher` row makes its login a teacher at that organisation and a `lunch-staff` row lunch staff there, whichever of the two `users.type` says (`MasjidUser::kindFor`). The gates admit the other scoped kind through such a row while `tenancy.roles_per_organisation` is on (`App\Support\StaffArea`), the resolver binds only a row of the kind being acted as, and `/user` and sign-in answer in the voice of the area with an `areas[]` list for a login holding both. It is still not per-tenant roles: an administrator's rows are never re-read, `Permission::count() === 8`, both scoped roles hold no permission, and teams stay off, which is exactly why an administrator's address stays refused at both doors. Rollout and rollback: `docs/multi-org-admin-rollout.md`.

**Explicitly deferred, unfixed:** `ToolRegistry:821-848` raw `DB::table` bypass, absence of `app/Policies`, `MobileCache::flushMasjid` keying off the route param, and jobs carrying an unasserted `masjid_id` — all pre-existing, all filed separately.

## Invariants tests must pin

1. No membership + no route masjid → **403, never unbound**.
2. Route masjid without a pivot row → 403 (both roles).
3. Two `is_default` rows → DB rejects.
4. `TenantContext` is null at start *and* end of every job under `queue:work` (not `sync`).
5. Request issued immediately after a switch cannot return tenant A's rows.
6. Role×permission matrix byte-identical pre/post; `Permission::count() === 8`.
7. All 12 `*TenantIsolationTest` files pass with the actor holding memberships in **both** A and B.
8. `masjid_id` in body or header is ignored while bound.
9. `masjids.user_id` is unique among non-deleted rows.

## Slices

- **S0 — ownership determinism** (**M**): reconcile duplicates, unique index, `unique:masjids,user_id` added to `UpdateMasjidRequest`.
- **S1 — request-scoped context + queue reset** (**S**): pure bug fix, shippable alone.
- **S2 — pivot table + backfill + `User::memberships()`** (**S**): no behaviour change.
- **S3 — resolver + fail-closed middleware + `setFromMembership`** (**M**): the riskiest slice; ship behind the "exactly one membership" path so production behaviour is unchanged until S5.
- **S4 — API surface: `memberships[]`, login path, echoed tenant** (**M**).
- **S5 — SPA switcher, epoch, store resets** (**M**).

Total **L**. S0–S1 are independently valuable and should merge regardless.

## Before or after parent-identity: **BEFORE**

Parent-identity is definitionally a membership graph — one human, many org affiliations across the three verticals. If it ships on top of `User::masjid()` `hasOne`, the single-tenant assumption gets encoded into the identity layer, where undoing it means migrating identities *and* tenancy simultaneously. Landing S0–S3 first gives parent-identity a verified membership table to attach to instead of one to invent. The ordering is not negotiable in one respect: **S0 must precede both**, because parent-identity built on a non-unique `masjids.user_id` inherits the same non-determinism the org-family critique correctly called sand.
