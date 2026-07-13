# MasjidWebMS — working memory

Laravel 11 + PHP 8.2 backend (Vue admin SPA) for the Burlington Masjid system.
Multi-tenant by `masjid_id` over one MySQL DB (utf8mb4_bin) on DigitalOcean
droplet 480119186. Auth = Laravel Sanctum. **MySQL has NO row-level security** —
tenant isolation is app-layer only.

## Detailed state lives in

- `STATE.md` — 60-second snapshot of where the project is.
- `PLAN.md` / `LOG.md` / `NOTES.md` — plan, changelog, scratch notes.
- `.claude/rules/` — path-scoped conventions (see `tenant-scoping.md`,
  `stripe-payments.md`, and `testing.md` in the coordination root).

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
