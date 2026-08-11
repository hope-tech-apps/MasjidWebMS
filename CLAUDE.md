# MasjidWebMS — working memory

Laravel 11 + PHP 8.2 backend (Vue admin SPA), branded **Manara**. Multi-tenant
by `masjid_id` over one managed MySQL DB (utf8mb4_bin). Auth = Laravel Sanctum.
**MySQL has NO row-level security** — tenant isolation is app-layer only.

**Production is DigitalOcean droplet 586894889 (`masjid-backend-24-04`,
159.65.239.51, reserved IP 164.90.253.138).** Droplet 480119186 is STALE and
serves no traffic despite older docs naming it; both share the same database, so
a mistake is invisible from the data. Verify with
`getent hosts masjid.hopetechapps.com`. See `NOTES.md`.

Manara is a **three-vertical platform on one core** — Masjids · Schools ·
Community — keyed by `masjids.org_type`. Verticals are configuration, not forks;
see `.claude/rules/verticals.md` and `DECISIONS.md` (2026-08-10).

## Detailed state lives in

- `STATE.md` — 60-second snapshot of where the project is.
- `PLAN.md` / `LOG.md` / `NOTES.md` — plan, changelog, scratch notes.
- `.claude/rules/` — path-scoped conventions (`tenant-scoping.md`,
  `stripe-payments.md`, `migrations.md`, `auth-permissions.md`, `verticals.md`,
  `private-uploads.md`, `groups.md`, `section-types.md`).

## Status

- **Manara verticals — `org_type` foundation DONE** (T-001). `masjids.org_type`
  (`masjid`|`school`|`community`, default `masjid`, indexed) + `config/verticals.php`
  (per-vertical default feature bundle + terminology pack) + `Masjid::ORG_TYPES`,
  `orgType()`, `isMasjid()/isSchool()/isCommunity()`, `term()`,
  `defaultFeatureKeys()`, `ofOrgType()` scope. Behaviour-neutral: existing
  tenants all read as masjids and keep the worship modules
  (adhkar/hadith/qibla/quran/tasbih), which are masjid-bundle-only. Proven by
  `tests/Feature/OrgTypeTest.php` (9/9). Convention: `.claude/rules/verticals.md`.
- **Manara verticals — provisioning + admin payload DONE** (T-002, uncommitted).
  A School/Community tenant is now actually creatable: `ProvisionMasjidRequest`
  takes an optional `org_type` (absent ⇒ `masjid`, normalized in
  `prepareForValidation`; invalid ⇒ the legacy `{status:'failed'}` 422),
  `OnboardingController@provision` persists it and seeds the feature toggles
  from `$masjid->defaultFeatureKeys()` instead of "everything on" — an explicit
  `feature_keys_provided` selection still wins. The admin payload
  (`MasjidsController@index/@show`, the provision echo) appends
  `Masjid::ADMIN_APPENDS` = a `vertical` block (`org_type`, `label`, `plural`,
  `terminology`); the public/mobile API is untouched. Behaviour-neutral: the
  masjid bundle IS the full seeded catalog. Proven by
  `tests/Feature/ProvisionOrgTypeTest.php` (8/8). Next: T-003 (Vue reads the
  labels).
- **Manara verticals — terminology in the admin SPA DONE** (T-003, uncommitted).
  `core/types/data/Vertical.ts` types the `vertical` block (`OrgType`,
  `TerminologyKey`, `Terminology`) and carries `MASJID_TERMINOLOGY`, a mirror of
  the PHP masjid pack; `Masjid.ts` gains an optional `vertical`. `masjidStore`
  exposes `vertical`/`orgType`/`organizationLabel`/`terminology` + **`term(key)`**,
  the Vue counterpart of `Masjid::term()` — unknown key ⇒ humanized, absent
  `vertical` block ⇒ the masjid pack, so a stale payload can't blank a label.
  Sidebar items opt in via `title_term` (+ `title_suffix`) resolved in
  `DashboardAside.vue`; converted so far: "Mosque Details" → `{organization}
  Details`, "Member Directory" → `{members} Directory`, plus the headers of
  `MosqueDetailsView`/`MosqueDetailsTabsView` and `ContactsView`. A masjid admin
  now reads "Masjid Details" / "Congregants Directory" (was "Mosque Details" /
  "Member Directory") — the deliberate cost of the pack; a school reads "School
  Details" / "Families Directory". Router `meta.pageTitle` strings are still
  hardcoded (static, resolved before the masjid loads). Vue build green
  (`artifacts/vue_build_t003_20260810-213021.log`); PHP suite 366/366 on the
  droplet copy. Convention: `.claude/rules/verticals.md` ("In the SPA").
- **Forms accept file uploads — DONE** (T-004, uncommitted). A `file` question
  (`FormSchema::FIELD_TYPES`) whose upload arrives in its own top-level `files`
  bag keyed by field name — NOT nested in `data`, because a multipart body cannot
  carry both shapes under one key. `SubmitFormResponseRequest` (BaseFormRequest)
  enforces the type/size ceiling at the boundary from `config/forms.php`
  (`mimetypes:` against the SNIFFED type + `max:` KB, both env-overridable),
  rejecting with the legacy `{status:'failed'}` 422; required/optional stays with
  the schema, so a missing résumé reports under its own field name. Files land on
  the PRIVATE `local` disk under `form-attachments/{masjid}/{form}/{random}.{ext}`
  and are recorded in `form_response_attachments` (denormalised `masjid_id`); the
  respondent's filename goes into `data` so the admin table and CSV still have a
  cell. The ONLY way back out is
  `GET .../responses/{id}/attachments/{id}` → `FormResponsesController::
  downloadAttachment`, in the existing forms middleware group (auth:sanctum +
  admin + tenant, no `permission:` gate — the whole forms surface has none), which
  re-resolves masjid → form → response → attachment so another tenant is a 404.
  File fields are refused inside a repeatable section (`ValidFormSchema`).
  Behaviour-neutral: a form with no file field sends no `files`, writes no rows,
  touches no disk. SPA: `file` in the builder palette + an Attachments block in the
  responses detail modal that blob-fetches with the bearer token. Convention:
  `.claude/rules/private-uploads.md`. Proven by `tests/Feature/FormAttachmentTest.php`
  (15 tests); full suite 381/381, 1106 assertions on the droplet copy.
- **Groups primitive — FOUNDATION DONE** (T-005, uncommitted). The second
  scoping level of the core, org → group → member, shared by classrooms
  (Schools), ḥalaqāt / weekend school (Masjids) and volunteer teams (Community).
  `groups` (name, per-masjid-unique `slug`, string `kind` from `Group::KINDS`,
  description, `is_active`, `starts_on`/`ends_on`, soft-deletes) +
  `group_memberships` (denormalised `masjid_id`, `group_id`, `contact_id`,
  string `role` from `GroupMembership::ROLES` = leader|member|guardian,
  `guardian_of_contact_id`, `joined_at`). Both `BelongsToMasjid`; kinds and roles
  are PHP constants, never DB enums. **Guardianship is an explicit edge**: a
  guardian row NAMES its ward, one row per (guardian, ward, group), required on
  guardian rows and prohibited on every other role; the ward must already hold a
  participant membership in that group; removing a participant removes the
  guardian edges over them (`GroupMembership::booted()` `deleting` hook).
  Admin CRUD at `/api/admin/masjids/{id}/groups` +
  `.../groups/{id}/members`, inside the existing `crm` group and gated by the
  CONTACTS permissions (no new permission — `RolePermissionBridgeTest` pins the
  set at 8). Naming is vertical-aware: `meta.group_label` comes from
  `$masjid->term('groups')` ("Halaqat"/"Classrooms"/"Teams"), nothing hardcodes
  "Classroom". Groups reference `Contact`, never duplicate a person. Purely
  additive — two new tables, no existing column, model, route or payload
  touched. Proven by `tests/Feature/GroupTenantIsolationTest.php` (13) +
  `tests/Feature/GroupCrudTest.php` (33); full suite 427/427, 1179 assertions on
  the droplet copy. Convention + the minors'-data obligations for every
  follow-on slice: `.claude/rules/groups.md`. **OUT of this slice on purpose:**
  group feed, messaging, group media, behavior points, ḥifẓ tracking, mobile app
  (T-013/T-014/T-015).
- **Group feed + private group media — DONE** (T-005b, uncommitted). The "class
  story": `group_posts` (author is a `users` row — a Contact cannot authenticate
  anywhere here, so attributing a post to one would record an unverified claim;
  `nullOnDelete` so retiring a teacher's login keeps the history) +
  `group_post_attachments`, both `BelongsToMasjid`, plus two nullable consent
  columns added additively to `group_memberships`. Admin CRUD +
  soft-delete at `.../groups/{id}/posts`, consent at
  `.../groups/{id}/members/{id}/consent`, inside the `crm` group under the
  existing CONTACTS permissions (still no new permission — the set stays at 8).
  **Disclosure ≠ administration:** writing is `manage contacts`; READING
  additionally requires being in the group, decided by `App\Support\GroupAudience`
  (the caller's person = the tenant Contact with their login email, exactly one
  match or no identity). **Consent** lives on the guardian EDGE
  (`consent_granted_at` + `consent_scope` from `GroupMembership::CONSENT_SCOPES`
  = feed|media, a hierarchy) and is checked at the point of disclosure — a
  guardian with no record gets neither feed nor image; with `feed` gets the words
  and no attachment list at all (`media_withheld`, because a filename is itself a
  disclosure). Images go to the PRIVATE disk under
  `group-media/{masjid}/{group}/{random}.{ext}` via `App\Support\GroupPostAttachments`
  (`config/groups.php`: jpeg/png/webp, 8MB, 8 per post), readable only through
  the chain-resolving `.../posts/{id}/attachments/{id}` endpoint. **Retention:**
  `group_posts.retained_until` (stamped on create from config, default 365d) +
  `php artisan groups:purge-feed` — force-deletes THROUGH the model so the bytes
  go; soft delete deliberately keeps them; `Group`'s force-delete hook covers the
  DB cascade that fires no events. Purely additive: two new tables, two nullable
  columns, no existing route/payload/behaviour touched. Proven by
  `tests/Feature/GroupFeedTenantIsolationTest.php` (23) +
  `tests/Feature/GroupFeedTest.php` (30); full suite **497/497, 1403 assertions**
  on the droplet copy. Conventions: `.claude/rules/groups.md` ("Disclosure is not
  administration", "Consent") + `.claude/rules/private-uploads.md` (third
  implementation; soft-delete vs force-delete). **OUT on purpose:** messaging
  threads (T-005c), behavior points, ḥifẓ, mobile app, admin Vue screens.
- **School section types — DONE** (T-010, uncommitted). Three page-builder types
  so a School tenant can publish the pages alrazischool.org hardcodes today:
  `staff_directory`, `programs`, `admissions_tuition`. Added on the EXISTING
  mechanism only — a `SectionType` case (+ `label`/`description`/
  `usesExternalData`/`defaultContent`, all still exhaustive `match` with no
  default arm), a `content` JSON shape, a Vue editor in the `editorMap`. **No
  migration, no new table, no registry**: section types are data, and
  `new Enum(SectionType::class)` is already the allowlist every request uses.
  **The palette stays GLOBAL** — `sectionTypes()` maps `SectionType::cases()`
  with no filter and `config/verticals.php` has no `section_types` key, so these
  are offered to masjid and community tenants too; gating would have been a new
  mechanism, and a masjid with a weekend school legitimately wants a tuition
  table. Shape decisions that are load-bearing: `members[]` is FLAT with a
  `department` label (the upload pipeline matches one array index, so a nested
  departments→members tree would silently drop every photo); money is DISPLAY
  TEXT (`$8,000` / `Included` / `Contact us` in one column, nothing charges from
  a section); `show_contact` defaults FALSE; and a staff directory is editorial
  content, never a query over `contacts`. Purely additive — the twenty existing
  types are pinned value-for-value and default-content byte-for-byte. Proven by
  `tests/Feature/SchoolSectionTypesTest.php` (17); suite **444/444, 1302
  assertions** on the droplet copy. Vue build green
  (`artifacts/vue_build_t010_20260810-223941.log`). Convention:
  `.claude/rules/section-types.md` + `resources/vue-app/components/sections/
  editors/CLAUDE.md`. **Not visible to the public yet:** the Nuxt renderer
  (separate repo) needs one component per new `section_type`.
- **Stripe Connect onboarding landings — public pages** (written, NOT deployed).
  Stripe redirects an admin's browser to `return_url`/`refresh_url` with no
  Sanctum token, so those now hit `ConnectOnboardingLandingController` via
  `routes/web.php` (`connect.return`/`connect.refresh`), not the admin API. The
  authed JSON endpoint is now `GET .../connect/status`. Proven by
  `tests/Feature/ConnectOnboardingLandingTest.php` (8/8).
- **Live Stripe — LIVE and verified** (2026-08-11). Live keys on production
  586894889; Connect webhook `we_1U34AGCvsApMECsnKybrkQSX` created FROM
  production so its signing secret is in the serving `.env`. Proven with a real
  event: Stripe POST → HTTP 200, `evt_1U34Ai…` recorded in
  `stripe_webhook_events`. Burlington `acct_1U2y2o…` charges=1, payouts=0
  (held by Stripe's own `listed` review, `currently_due: none`).

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
- **Member directory — full admin Contact CRUD** (same branch, local only).
  `ContactsController` (index w/ `?search=` over first/last/email/phone,
  store, show, update, destroy) at `/api/admin/masjids/{masjid_id}/contacts`,
  plus `Store`/`UpdateContactRequest`. The controller keeps the `{masjid_id}`
  route param but does NOT hand-filter — the `tenant` middleware + the
  `BelongsToMasjid` trait enforce isolation (create ignores client `masjid_id`;
  `destroy` soft-deletes). Vue SPA: `ContactsView.vue` (table + search +
  create/edit/view modals + delete-confirm), `contactsStore.ts`, router entry
  `/masjid/contacts` + "Member Directory" sidebar link. Proven by
  `tests/Feature/ContactCrudTest.php` (not run locally — no PHP; run on CI/
  droplet with `php artisan test --filter=ContactCrud`). Vue build verified green.
- **CRM money-path scaffolded — funds/donations/receipts + Stripe Connect
  webhook** (same branch, local only). Stripe Connect STANDARD accounts + DIRECT
  charges + `application_fee_amount` (org is merchant of record; funds land in
  the org's balance). Adds `funds`, `donations`, `donation_receipts`,
  `stripe_webhook_events` tables + `stripe_account_id/charges_enabled/
  payouts_enabled` on `masjids`; `Fund`/`Donation`/`DonationReceipt`
  (BelongsToMasjid) + `StripeWebhookEvent` models; `StripeConnectService`,
  `DonationService` (donor-covers-fees gross-up), `ReceiptService` (gap-free
  serial per masjid); `StripeWebhookController` (signature-gated, dedup'd,
  idempotent — webhooks are the source of truth, never the redirect); admin
  connect/funds/donations + public donation-checkout endpoints. `stripe/
  stripe-php ^16.0` added to `composer.json` — **run `composer update` on the
  server** (no PHP/composer locally; `composer.lock`/vendor not yet updated).
  Stripe test keys not yet in `.env` (user adds `STRIPE_KEY`/`STRIPE_SECRET`/
  `STRIPE_WEBHOOK_SECRET`). Tests (Stripe mocked, not run — no PHP):
  `tests/Unit/DonorCoversFeesTest.php`, `tests/Feature/DonationFlowTest.php`.
  Convention: `.claude/rules/stripe-payments.md`. DEFERRED: refunds, disputes,
  recurring/dunning, payout reconciliation, receipt PDF rendering, admin Vue
  screens.
- **Granular permissions (Spatie) + admin 2FA — ADDITIVE, bridged** (same branch,
  local only). `spatie/laravel-permission ^6.9` layered ALONGSIDE `users.type`
  (NOT a replacement): `type`→role bridge (`User::TYPE_ROLE_MAP` +
  `syncRoleFromType()` kept in sync by `UserObserver`, backfilled by
  `RolesAndPermissionsSeeder`). Granular CRM permissions gate ONLY the new
  contacts/funds/donations/connect endpoints via the spatie `permission:`
  middleware (per-route, after `auth:sanctum`+`admin`+`tenant`). The `admin`/`super`
  middleware and all `type` checks are UNCHANGED. Admin TOTP 2FA
  (`pragmarx/google2fa` + `bacon/bacon-qr-code`, via `App\Services\TwoFactorService`
  — NOT Fortify): enroll/confirm/disable endpoints + nullable **encrypted**
  `two_factor_secret`/`two_factor_confirmed_at` on `users`. Login requires a code
  ONLY for confirmed-enrolled admins — everyone else logs in exactly as before
  (no lockout; `config('crm.require_admin_2fa')` default false, not enforced yet).
  **Run `composer update` on the server** (no PHP/composer locally; `composer.lock`
  /vendor not updated). Tests (not run — no PHP): `tests/Feature/RolePermissionBridgeTest.php`,
  `tests/Feature/TwoFactorTest.php`; `ContactCrudTest`/`DonationFlowTest` now seed
  roles in setUp. Convention: `.claude/rules/auth-permissions.md`.
- **SuperAdmin CRM feature gate — `crm_enabled` default off** (same branch, local
  only). Adds `masjids.crm_enabled` (boolean, **default false**; fillable + cast;
  auto-served in the admin masjid payload). New `crm` middleware
  (`EnsureCrmEnabled`) 403s the CRM route group (contacts/funds/donations/connect)
  unless the tenant masjid's `crm_enabled` is true — layered on top of the
  `permission:` gates, so a permissioned MasjidAdmin still gets 403 when off.
  SuperAdmin-only toggle `PATCH /api/admin/masjids/{id}/crm-access {enabled}` →
  `MasjidsController::setCrmAccess` (super enforced via `abort(403)`, not the
  `super` middleware's 401). **NOT gated:** 2FA, the toggle itself, any
  pre-existing endpoint. Vue SPA hides the "Member Directory" sidebar item +
  guards `/masjid/contacts` unless `masjidStore.masjid.crm_enabled`, and adds a
  SuperAdmin CRM switch on `MasjidDetailsView.vue`. Vue build green. Tests (not
  run — no PHP): `tests/Feature/CrmFeatureGateTest.php`; `ContactCrudTest`/
  `DonationFlowTest` now enable `crm_enabled` in setup. Convention:
  `.claude/rules/auth-permissions.md`.
- **CRM Phase 1 — Funds + Donations admin UI** (branch
  `feat/crm-phase1-funds-donations-ui`, off `feat/crm-phase0-tenancy`, local only —
  not pushed). Completes `FundsController` (adds `show`/`update`/`destroy`,
  mirroring `ContactsController`; `destroy` is a HARD delete wrapped in try/catch
  because funds are not soft-deleted and `donations.fund_id` is a non-cascading
  FK) + `UpdateFundRequest` (BaseFormRequest, same rules as StoreFundRequest);
  routes `GET/PUT/DELETE .../funds/{fund_id}` (`view donations` to read,
  `manage funds` to mutate). `DonationsController` adds `show` (donation +
  eager-loaded fund + receipt) and a `?fund_id=` filter on `index`; donations
  stay READ-ONLY (no store/update/destroy — Stripe webhooks own writes). Vue SPA:
  `FundsView.vue` + `fundsStore.ts` (flat list — funds index returns a plain
  array, not paginated; create/edit modal with type select + receiptable/active
  switches, delete-confirm) and `DonationsView.vue` + `donationsStore.ts`
  (paginated list, status + fund filters, detail modal showing intended/charged/
  net/fees, donor-covered-fees, Stripe ids, and the linked receipt; amounts
  formatted from integer cents via `Intl.NumberFormat`). Router entries
  `/masjid/funds` + `/masjid/donations` (both `requiresCrm`), two `requiresCrm`
  sidebar items, plus `SystemRoutes`/`BackendApiRoutes`/`Fund`/`Donation` types.
  Both screens are CRM-gated (`crm` middleware) + tenant-scoped (BelongsToMasjid,
  no hand-filtering) + permission-gated. Tests (not run — no PHP):
  `tests/Feature/FundCrudTest.php`, `tests/Feature/DonationReadTest.php`. Vue
  build green (`artifacts/vue_build_20260712_103122.log`).
- Older backend state (theming, content unification, V1 caching deploy hold)
  is tracked in `STATE.md`.

## Key tenancy rules (see `.claude/rules/tenant-scoping.md`)

- Every tenant-scoped CRM model MUST use `App\Models\Concerns\BelongsToMasjid`.
- `masjid_id` is server-derived from `TenantContext`, never client input.
- Unbound context = no filter (SuperAdmin + public mobile API preserved).
- No DB-level backstop, so a cross-tenant Feature test is mandatory per model.
