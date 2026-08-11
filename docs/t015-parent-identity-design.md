# T-015 Parent/Guardian Identity — Design

_3 approaches, adversarial security + minors'-data critics, supervisor rubric, lead-reviewer verification. 2026-08-11._

## Lead-reviewer verdict

RATIFIED — both documents.

**Rubric honesty (verified, not taken on trust).** Parent supervisor's three load-bearing claims are true: `config/auth.php` defines only `web`/`api`; `SanctumServiceProvider:24-27` merges `provider => null` and `Guard.php:151` returns `true` on null; `GroupAudience` carries `?User` at exactly the 13 cited lines; `standingIn()` sets `feed = true` outright for participants (`GroupAudience.php:517-523`). Admin supervisor's corrections hold too: `ResolveMasjidTenant` is MasjidAdmin-first/SuperAdmin-`elseif`; `masjids.user_id` is nullable with no unique index; `TenantContext` is `singleton` (`AppServiceProvider:24`). Both corrected their own winner's critic rather than parroting — the parent doc rightly discarded "merge transplants guardian edges" (merge moves donations/cards, then `forceDelete()`s; memberships cascade *away*) and "expiration null" (it's 480).

**Findings ledger:** every critique bullet in both documents maps to an addressed item or a reasoned deferral. None dropped.

**Rules:** no violation of the three files. Three amendments owed, not breaches — groups.md's "writing a message requires `manage contacts`" (T-015f), "written by `GroupConsentController`" (T-015h), "one `last_read_at` per (thread, user)" (T-015f) must be amended in the same commits.

**Q4.** *Admin endpoint:* none, conditional on T-015a preceding T-015c. `routes/admin.php` has exactly one `auth:sanctum` group and it always carries `admin`. Sanctum's session-first branch skips `hasValidProvider`, but `sanctum.guard=['web']` → provider `users`, so it can never yield a Contact. Inverse is open: a staff SPA *session* satisfies `auth:family`, so §5's staff-exclusion rests on `family.active`, not the provider. `tokenCan` appears nowhere in `app/` — abilities are not yet a layer.

*Non-ward child:* one unnamed path — a login-enabled contact holding a leader/member row gets `feed=true` and `mayReceive` true for every disclosure, the exact mechanism §7 uses to refuse students. Pin it.

*Tenant:* none in-design; T-015d's queued invites run where `TenantContext` is a never-forgotten singleton. Admin S1 must precede T-015d.

Also: 30-day family tokens are unachievable — `createGuard` passes global `config('sanctum.expiration')`; per-token `expires_at` can only shorten.

---

I read the three rules files plus the load-bearing code (`config/auth.php`, `vendor/laravel/sanctum/src/{SanctumServiceProvider,Guard}.php`, `ResolveMasjidTenant`, `UserAdminMiddleware`, `GroupAudience`, `ContactsController::merge`, `Contact`, and the `group_memberships` / `group_thread_reads` / `group_messages` / `users` migrations). Three critic claims I verified directly, because the decision turns on them:

- **`auth.guards.sanctum` genuinely does not exist** in `config/auth.php` (only `web` + `api`); `SanctumServiceProvider:24-27` merges `provider => null`, and `Guard::hasValidProvider()` (Guard.php:145-149) returns `true` when the provider is null. **Any** `HasApiTokens` model is admissible on every `auth:sanctum` route today. This is the gate all three designs stand on, and none of them has it yet.
- **`GroupAudience` has 13 `?User` typehints**, not one seam (lines 73, 111, 162, 203, 281, 300, 309, 332, 367, 393, 410, 439, 486). Every proposal understated this.
- **`standingIn()` sets `feed = true` outright for any participant** (GroupAudience.php:519-525). A student login would read the entire group feed — every classmate's photograph — plus participant threads about themselves, i.e. teacher↔guardian safeguarding conversations. Verified, and decisive for the student question.

Also verified: `users.masjid_id` was **dropped** (2025_02_06 migration), `users.type` is a real MySQL `enum`, `users.phone` is NOT NULL, `group_thread_reads.user_id` is NOT NULL with `unique(thread, user_id)`, and `ContactsController::merge` `forceDelete()`s the source — which DB-cascades `group_memberships` (and therefore `behavior_awards`) with no model events.

---

# Score table

Risk scored as *risk-managed* (higher = lower residual risk), per rubric direction.

| Criterion | Weight | contact-authenticatable | portal-user-table | unified-users-typed |
|---|---|---|---|---|
| Correctly fulfils requirement | 40% | **8** | 9 | 6 |
| Security/privacy (lower risk = higher) | 20% | **8** | 7 | 3 |
| Testability | 15% | **9** | 7 | 6 |
| Simplicity / maintainability | 15% | **9** | 5 | 6 |
| Reversibility | 10% | **8** | 5 | 4 |
| **Weighted total** | 100% | **8.30** | 7.30 | 5.20 |

**Winner: `contact-authenticatable`.**

Why not the other two, briefly. `portal-user-table` scores highest on requirement — it is the only one that natively handles one human at two tenants — but it buys that with a **globally-unique-email identity table**, which is a cross-tenant correlation and enumeration channel inside a product whose entire premise is that tenant A cannot learn anything about tenant B's families. Paying that in v1, for children's photographs, to serve a case we can add later as a purely additive link table, is the wrong trade; and it is the least reversible of the three. `unified-users-typed` fails on requirement before security: `users.email` is unique and `type` is a scalar, so **a teacher whose own child attends the school cannot exist** — routine in a masjid weekend school. Its security barrier is one non-strict `in_array` on a string column, in the same table, same guard and same token pool as SuperAdmin; `tokenCan('guardian')` is provably not a second brace because `AuthController:52` mints `['*']`. It also requires a live `ALTER TABLE users MODIFY` enum and resurrects `users.masjid_id`, which would become authoritative *ahead of* the ownership relation for every staff row — a direct breach of auth-permissions.md's "strictly additive, never changes how an existing admin logs in".

**Grafted in:** from `portal`, the per-principal-per-tenant uniqueness discipline, parent-facing consent withdrawal, the `self`-link consent objection, and separate token expiry per realm. From `unified`, its one real win — guardians writing messages need principal columns on `group_messages` / `group_thread_reads`, which the winner must actually pay a migration for rather than hand-wave.

---

# Critic findings ledger — all addressed or explicitly deferred

**Addressed in the design below:** sanctum provider unpinned (§4, T-015a) · `ResolveMasjidTenant` fail-open for non-staff (§5) · `UserAdminMiddleware` non-strict `in_array` (§4) · revocation checked only in `identitiesFor` (§5) · token expiry shared between realms (§2) · magic-link hardening: per-contact+per-IP throttle, hashed constant-time compare, attempt lockout, `consumed_at` in the mint transaction (§3) · "ward's first name" demoted to typo-guard, not a factor (§3) · enumeration — identical 202/410 responses (§3, §8) · contact merge destroying/transplanting logins and consent (§7, T-015g) · soft-deleted/merged contact must not resolve (§4) · attachments must re-resolve, never signed/cached (§7) · guardian at two tenants = two tokens (§2) · shared family email → per-contact `login_email`, custody = remove the edge (§3) · staff-login regression sweep (T-015a) · `group_thread_reads` NULL-distinct unique trap (§2) · `Permission::count() === 8` pinned (§8) · 13 `?User` signatures, not one (§4, T-015b) · student self-login reading the whole feed (§6) · parent cannot withdraw own consent (§8, T-015h) · no read log (§9, T-015i) · parents have no second factor (§3) · invite replay/expiry semantics (§8) · token rotation on credential change (§3) · `auth:family` rebinding the default guard (§4).

**Explicitly deferred, with reason:** *(full list at the end of the doc.)*

---

# T-015 — Parent/Guardian identity

## 1. Decision

A `Contact` becomes authenticatable behind its **own Sanctum guard**. A contact already *is* the person the classroom names — `group_memberships.contact_id`, `guardian_of_contact_id`, `behavior_awards.group_membership_id` all point at contacts, and `GroupAudience` reasons in contact ids. Giving a contact a login adds an *authentication* fact and changes no *authorization* fact: `identitiesFor()` stops guessing a contact from an admin's email and starts returning the contact who actually logged in. Credentials are passwordless; 200 families cannot be issued passwords and a school office cannot run a reset desk.

## 2. Schema

`contacts` +=  `login_email` (nullable), `login_enabled_at`, `login_revoked_at`, `last_login_at`; unique `(masjid_id, login_email)` — **per tenant, never global**. No password column. `Contact` gains `HasApiTokens` (`personal_access_tokens` is already polymorphic).

`contact_login_codes`: `masjid_id, contact_id, token_hash (sha256 binary), channel, expires_at (10 min), consumed_at, attempts, requested_ip`. `BelongsToMasjid`.

`contact_access_events`: `masjid_id, contact_id, group_id, subject_type, subject_id, disclosure, ip, created_at` — so "did a parent see this" is answerable.

`group_thread_reads`: replace `user_id` with a NOT-NULL `(reader_type, reader_id)` pair, unique `(group_thread_id, reader_type, reader_id)`; backfill `reader_type = 'user'`. This sidesteps the NULL-distinct unique trap rather than guarding around it. `group_messages` += nullable `author_contact_id`; a model `saving` guard asserts exactly one of `author_user_id` / `author_contact_id`.

`config/auth.php`: providers `contacts => Contact::class`; guards `sanctum => provider users` (**the pin**) and `family => sanctum/contacts`. Family tokens are minted with an explicit 30-day `expiresAt` and ability `['family']`; staff keep the 8h config value — one env var must not govern both realms.

Middleware: `family.active` (401 unless `login_enabled_at` set, `login_revoked_at` null, contact not trashed), `family.tenant` (binds `TenantContext` to `$contact->masjid_id` and **aborts 403 if it cannot bind**, never falls through unbound). Routes: `routes/family.php` at `/api/family/masjids/{masjid_id}/…`, stack `['auth:family','family.active','family.tenant','crm','throttle:family']`. No `admin`, no `super`, no `permission:` in that tree.

## 3. Onboarding and revocation — 200 families

The office already imports a roster. Admin picks a group → "Invite guardians" → one queued invite per guardian edge whose contact has a deliverable `login_email`. The parent taps the link, confirms their ward's first name (**a typo-guard, not a second factor — every classmate knows it**), and the device receives a token. No password, no account creation, no admin ever holding a credential.

Hardening, all mandatory: codes stored only as `sha256`, compared with `hash_equals`; 10-minute TTL; `consumed_at` written inside the same transaction that mints the token; 5-attempt lockout per code; throttles keyed on `contact_id` **and** requester IP; request-code returns an identical `202` for known and unknown addresses; replayed or expired codes return an identical `410`. Delivery goes only to a `login_email` an admin explicitly set on that contact — never to an unverified imported phone, because a recycled number is otherwise a credential for children's photographs.

Second factor: possession of the mailbox *is* the factor here, so parents are not weaker than staff by accident — but a parent's mailbox is genuinely the whole account, so any change to `login_email` **rotates all that contact's tokens**. Full parent TOTP is deferred (§10).

Revocation is layered. The *mechanism* is the roster: deleting the guardian edge drops `standingIn()` to `in_group = false` in the same transaction and every surface goes dark on the next request. `login_revoked_at` + `tokens()->delete()` is the hard cut, checked in `family.active` on **every** family request — not only inside `GroupAudience`, so an endpoint that never calls it still fails closed. A custody order is executed by removing the guardian edge, not by revoking a shared login; this is why `login_email` is per-contact and never per-household.

## 4. The `identitiesFor()` seam

Thirteen `GroupAudience` signatures widen from `?User` to `?Authenticatable`; only the body of `identitiesFor()` changes:

```php
if ($principal instanceof Contact) {
    return ($principal->login_revoked_at === null
        && ! $principal->trashed()
        && (int) $principal->masjid_id === $this->tenant->get())
        ? [(int) $principal->id] : [];
}
// existing User email-bridge, unchanged
```

The liveness checks are not decoration — `Contact` soft-deletes and merge force-deletes, so `contact_id && masjid_id` alone would resolve a merged or deleted child's parent. `standingIn`, `mayReceive`, `consentCovers`, `readableThreadsQuery`, `readableAwardsQuery`, `readableHifzQuery`, `constrainToOwnStudents` are untouched, so every disclosure rule in groups.md still holds by construction and its existing tests still prove it.

## 5. Why a parent token cannot reach admin

Four layers, the first structural and in vendor code:

1. **Provider mismatch.** Once `auth.guards.sanctum.provider = 'users'`, `Guard::hasValidProvider()` compares the tokenable against `App\Models\User`; a `Contact` token on any `auth:sanctum` route resolves to *null* — unauthenticated, before a line of our middleware runs. Today that check is a no-op, which is why T-015a ships alone and first.
2. **Actor class.** `UserAdminMiddleware` is rewritten to `Auth::user() instanceof User && in_array(..., ADMIN_TYPES, true)`. The current non-strict `in_array` with a null needle holds only by PHP 8 accident, and `auth:family` rebinds the default guard, so `Auth::user()` there *is* the Contact.
3. **Abilities.** Family tokens carry `['family']`; staff tokens are changed to `['staff']` (today they are `['*']`, which would let a staff token walk into the family surface — the reverse direction must be pinned too).
4. **Route separation.** Family routes never enter the admin group; `permission:` is never applied to a Contact, and `Permission::count() === 8` stays pinned.

## 6. Tenant isolation

`family.tenant` binds `TenantContext` from the **token's tokenable**, never from the URL or a header, and 403s if the route names a different masjid or if binding is impossible. This matters more than it looks: `ResolveMasjidTenant` branches only on `MasjidAdmin`/`SuperAdmin`, so a non-staff principal falls through **unbound**, and tenant-scoping.md defines unbound as *no filter*. A family route that reached a `BelongsToMasjid` model unbound would read every tenant. `family.tenant` therefore aborts rather than no-ops, `identitiesFor()` re-checks the bound tenant independently, and `EnsureCrmEnabled`'s route-param fallback is not treated as a substitute for binding.

## 7. Students

**No student logins in T-015.** Not deferred for effort — deferred because the code would grant the wrong thing today. `standingIn()` sets `feed = true` outright for any participant, so a student token would read the whole group feed and every attachment, i.e. photographs of every classmate, with nobody's consent — while that student's own parent needs an explicit `media` grant. It would also read participant threads about the student, which are exactly where a teacher and a guardian discuss a safeguarding concern. A student login is therefore not an opt-in flag on this design; it is a **different standing computation** (own-record-only, no group feed, no participant threads) plus a per-child guardian-granted enablement, and it belongs to its own task with its own tests.

## 8. Consent interaction

Nothing about consent moves. The guardian edge still carries `consent_granted_at` / `consent_scope`; `media` still covers `feed`; consent still gates broadcasts (feed, group threads) and still does **not** gate a parent's view of their own ward's participant threads, awards and ḥifẓ. Two consequences must be built, not assumed:

- **Consent revoked mid-session** is safe only because every read re-enters `GroupAudience`, including `GroupPostsController::downloadAttachment`. The parent app therefore gets **no signed, no long-lived, no CDN-cached attachment URLs** — every byte re-resolves the ownership chain on every request. Already-delivered bytes and the app's on-device cache cannot be recalled; that is stated in the parent-facing consent copy rather than pretended away.
- **Ward removed from roster**: `group_memberships.deleting` removes the guardian edges pointing at them, `behavior_awards.group_membership_id` cascades and ḥifẓ entries go with the roster row — so the parent's surface empties on the next request, which is the correct least-disclosure outcome.
- **Parents can withdraw their own consent.** `GroupConsentController` is `manage contacts`, so today withdrawal means emailing the school — which is not withdrawal. T-015h adds a family-guard read of that parent's own consent state and a withdrawal write, the one exception to "family routes are read-only", writing through the same `GroupMembership` columns so there is still exactly one consent record.

**Merge is the sharpest hazard.** `ContactsController::merge` force-deletes the source, and the DB cascade takes `group_memberships` and `behavior_awards` with it firing no model events. So: merge **refuses** (422) when either side has `login_enabled_at`, and refuses when either side holds group memberships, until a task exists that migrates edges deliberately. Orphaned tokens fail closed (a null tokenable is unauthenticated), but they are deleted explicitly anyway.

## 9. Invariants the tests MUST pin

1. A family token on **every** route under `api/admin`, enumerated from `Route::getRoutes()` rather than the file → 401.
2. A staff token on every route in `routes/family.php` → 401 (proves abilities are scoped, not `*`).
3. A family request whose tenant cannot be bound → 403, **never** data; assert `TenantContext` is never unbound inside the family tree.
4. `identitiesFor()` returns `[]` for a revoked, soft-deleted, merged, or cross-tenant contact.
5. `login_revoked_at` set, or the guardian edge deleted, or consent withdrawn → the surface darkens on the **next** request, including attachment download.
6. Guardian A requesting guardian B's ward: 403 at the endpoint **and** zero rows from `readableAwardsQuery` / `readableHifzQuery` / `readableThreadsQuery`.
7. A guardian with no consent record: `mayReceive(feed)` false, attachment list absent with `media_withheld`, **and** their own ward's participant thread / awards / ḥifẓ still readable.
8. Codes: reuse → 410, expired → 410, 6th attempt → lockout, unknown address → byte-identical 202, hashed comparison only.
9. Merge involving a login-enabled or group-member contact → 422.
10. Staff login, 2FA challenge and tenant binding are byte-identical before and after the guard pin; `Permission::count() === 8`.
11. `FamilyAuthTenantIsolationTest` mirroring `GroupTenantIsolationTest`, at model and HTTP layer.

## 10. Slices

| Slice | Scope | Size |
|---|---|---|
| **T-015a** | Pin `auth.guards.sanctum.provider = users`; `UserAdminMiddleware` → `instanceof User` + strict compare; mint `['staff']` abilities in `AuthController`. No new tables. Ships alone with the full staff regression sweep (invariant 10). | **S** |
| **T-015b** | Widen the 13 `GroupAudience` signatures to `?Authenticatable`. Pure refactor; existing group tests are the proof. | **S** |
| **T-015c** | `contacts` auth columns; `contacts` provider + `family` guard; `family.active` + `family.tenant`; `routes/family.php` with `GET /me` only. Invariants 3, 4, 5(revoked). | **M** |
| **T-015d** | `contact_login_codes` + OTP/magic-link service, delivery, throttles, lockout. Invariant 8. | **M** |
| **T-015e** | `identitiesFor()` Contact branch + family read endpoints (feed, threads, awards, ḥifẓ) mounted on existing `GroupAudience`. Invariants 1, 2, 6, 7. | **M** |
| **T-015f** | Dual-principal `group_thread_reads` + `group_messages.author_contact_id`; parents can reply. | **M** |
| **T-015g** | Merge refusal, token rotation on `login_email` change, cascade-hazard tests. Invariant 9. | **S** |
| **T-015h** | Parent-facing consent view + self-withdrawal. | **M** |
| **T-015i** | `contact_access_events` + admin "who read what". | **M** |
| **T-015j** | iOS: token to `Keychain.system`, login screen, `AppEnvironment.Status.login`, header injection in `RequestInterceptor.adapt`, 401 → reset. | **M** |

Each is independently shippable; a–d leave the product externally unchanged.

## 11. Explicitly deferred

- **Student logins** — the current `standingIn()` would disclose the whole class feed (§7). Needs its own standing rule, not a flag.
- **One identity across two tenants** — served later by an additive link table over per-tenant contact logins; a global identity table is a cross-tenant enumeration channel we will not open for children's data.
- **Parent TOTP** — the 2FA endpoints sit inside `['auth:sanctum','admin','tenant']` and moving them breaches "strictly additive"; parent 2FA gets its own routes when parent writes grow beyond messages and consent.
- **Academic-record export on family exit** — real obligation, but it is a data-portability feature, not an auth one.
- **Staff `LoginRequest` `exists:users,email` enumeration oracle** — pre-existing; the family realm must not copy it, but fixing the staff one changes a live login response shape and needs its own task.
- **Recall of already-delivered bytes and app caches** — technically impossible; disclosed in consent copy instead.
- **`view groups` / `manage groups` permission split** — groups.md requires seeder + migration + `RolePermissionBridgeTest` changed together.
- **Classroom tab UI, vertical-aware Home, school build target** — T-016; T-015 ships the identity, not the screens.
- **Merge that migrates group edges deliberately** — merge refuses for now rather than guessing.
