---
paths:
  - "app/Http/Controllers/Mobile/MasjidsController.php"
  - "app/Http/Controllers/AdminDashboard/MasjidsController.php"
  - "app/Http/Controllers/AdminDashboard/OnboardingController.php"
  - "app/Models/Masjid.php"
  - "database/migrations/*_add_listed_at_to_masjids_table.php"
  - "resources/vue-app/views/dashboard/super/masjid/MasjidDetailsView.vue"
  - "resources/vue-app/views/dashboard/super/OnboardingWizardView.vue"
---
# The public directory listing (`masjids.listed_at`)

`GET /api/mobile/masjids` is the organisation picker every mobile app opens
with. **Creating an organisation must not put it there.**

## The incident this encodes

`Mobile\MasjidsController@index` returned `Masjid::with('logo')->get()` — every
row, no published/active gate, cached `TTL_DAY`. So:

- a tenant created for a pilot, a demo or a half-finished migration appeared in
  front of real congregations within a day, and
- the `test2` seed tenant (masjid 2 — no owner, no prayer settings, two live
  Stripe charges) was being offered to real users for months.

The gate is `masjids.listed_at`: NULL = not in the directory, a timestamp =
listed, since then. New organisations are born NULL.

## Reading and writing it

- Query it through `Masjid::listed()`, never `whereNotNull('listed_at')` inline.
- `listed_at` is deliberately **not** in `Masjid::$fillable`. Publishing is a
  SuperAdmin decision made through exactly one endpoint,
  `PATCH /api/admin/masjids/{id}/directory-listing`
  (`MasjidsController::setDirectoryListing`) — never a side effect of some other
  write path's mass assignment.
- Anything that changes listing state MUST
  `MobileCache::flushGlobal(MobileCache::MASJIDS_LIST)`. The directory is cached
  for a day, so without the flush the decision does not reach the apps until the
  entry expires.

## It gates the directory, NOT the organisation

`GET /mobile/masjids/{id}` is deliberately **ungated**. Unlisting is a
discoverability decision, not a revocation: an app that already holds the id
keeps working, so an operator can pull an organisation out of the picker without
breaking the people already using it.

This is **not an authorization boundary** and must never be used as one. Tenant
data is scoped by `BelongsToMasjid` + `TenantContext` (see
`.claude/rules/tenant-scoping.md`); `listed_at` only answers "should strangers be
able to find this".

## Why not `masjid_app_publishing`, and never `crm_enabled`

`masjid_app_publishing` is 1:1 with a masjid and is created during onboarding,
which makes it look like the natural home. It is not:

- **It does not exist for the tenants that matter.** On production (2026-08-12)
  exactly ONE of four tenants has a row — masjid 13. Masjids 1, 2 and 5 predate
  the table. A gate anchored there has to answer "no row", and both answers are
  wrong: *unlisted* hides Burlington Masjid and its 221 app users; *listed* is a
  fail-OPEN default, the same shape as the two cross-tenant holes found live on
  2026-08-11.
- **It answers a different question.** Every column there is an account mode or
  an encrypted publishing credential, all `$hidden`. `enabled_platforms` says
  which binaries get built — a web-only organisation still belongs in the
  directory, and one with an iOS build configured may still not be ready.

`crm_enabled` is the member-directory + money gate. Do not conscript it.

## The backfill policy

The column defaults to NULL, so the migration backfills existing rows **by
policy, never by id**:

> an organisation real people are already using, or that has already published
> content to them, stays listed.

Three `EXISTS` clauses (`mobile_app_users`, `announcements`, `services`), OR-ed,
so the predicate errs toward LISTING — hiding a live organisation is the
expensive mistake. Verified against production before it shipped: it selects
exactly masjids 1, 5 and 13, and drops `test2`, which has none of the three.

If you add a similar "was this already live?" backfill, **dry-run the predicate
against production first** and put the counts in the migration docblock.
`MasjidDirectoryListingBackfillTest` seeds a stand-in for those production rows,
takes the schema back to before the column existed, and migrates forward over
it — copy that shape rather than asserting on a freshly migrated empty table.
