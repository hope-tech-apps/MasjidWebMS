---
paths:
  - "config/verticals.php"
  - "app/Models/Masjid.php"
  - "app/Http/Controllers/AdminDashboard/**"
  - "app/Http/Requests/Admin/**"
  - "app/Http/Controllers/Api/**"
  - "resources/vue-app/core/types/data/Vertical.ts"
  - "resources/vue-app/stores/masjidStore.ts"
  - "resources/vue-app/core/constants/dashboardAsideMenuItems.ts"
  - "resources/vue-app/views/dashboard/**"
---
# Manara verticals (org_type)

Manara runs three verticals on ONE core: **Masjids · Schools · Community**
(`DECISIONS.md`, 2026-08-10). A vertical is CONFIGURATION, never a fork.

## The discriminator

`masjids.org_type` — `masjid` | `school` | `community`, default `masjid`.

- **`Masjid::ORG_TYPES` is the authority on the allowed set, not a DB enum.**
  Adding a vertical must never require `ALTER TABLE … MODIFY` on a live table —
  see `.claude/rules/migrations.md` for what that did to the SQLite test run.
- Read it through `Masjid::orgType()`, never `$masjid->org_type` directly.
  An unrecognized or missing value **degrades to masjid**: unknown input must
  never silently grant a different vertical's behaviour.
- The table is still named `masjids` and the column `masjid_id`. Renaming the
  tenant root to `organizations` is deliberate, tracked tech debt (PLAN T-002),
  not an oversight — do not start that rename as a side effect of other work.

## Feature bundles are defaults, not authorization

`config/verticals.php` `feature_keys` are the set seeded onto a tenant **at
provisioning time**. They are NOT a runtime permission check. The
`mobile_app_features` pivot's `is_available` remains the single source of truth
for what a tenant actually has, and per-tenant gates (`crm_enabled`,
`assistant_enabled`) are unchanged.

Every key listed in a bundle MUST exist in `mobile_app_features.key`, or
provisioning silently enables nothing for it. `OrgTypeTest` asserts this.

`OnboardingController@provision` seeds from `$masjid->defaultFeatureKeys()`, and
an explicit wizard selection (`feature_keys_provided` + `feature_keys`) still
overrides it — a school admin may deliberately switch a worship module on. It
writes a `masjid_mobile_app_features` row for EVERY catalog feature, just with
`is_available = false` for the ones outside the bundle, so a later toggle needs
no repair script.

**The masjid bundle must stay equal to the full seeded catalog.** Provisioning a
masjid used to force every feature on; it now goes through the bundle, so the
day the two diverge, existing-tenant behaviour silently changes. Add a new
`mobile_app_features` key ⇒ add it to the masjid bundle in the same change.
`ProvisionOrgTypeTest` pins the set.

## Accepting a vertical at the request boundary

Anything that creates a tenant validates `org_type` with
`Rule::in(Masjid::ORG_TYPES)` and treats an **absent or empty** value as
`masjid`, normalized in `prepareForValidation()` — not defaulted downstream in
the controller — so validation and persistence can never disagree. The request
must extend `BaseFormRequest`, or a rejection escapes as a raw
`ValidationException` and this app's JSON renderer turns it into a 500 instead
of the legacy `{status:'failed'}` 422.

## Islamic worship modules are masjid-only

`adhkar`, `hadith`, `qibla`, `quran`, `tasbih` belong to the masjid bundle
alone. A school or community tenant must never load them. Everything else
(`about_us`, `announcements`, `contact_us`, `donate`, `gallery`, `services`) is
org-generic and shared.

When you add a masjid-specific capability, gate it on `isMasjid()` or a feature
key — never assume the tenant is a masjid.

## Terminology

Admin-facing labels come from the vertical's `terminology` pack via
`$masjid->term('members')` — "Congregants" for a masjid, "Families" for a
school. Do not hardcode "Masjid" or "Congregants" in new admin UI or API
payloads. `term()` falls back to a humanized key, so a missing entry degrades to
something readable rather than a blank label.

The Vue SPA gets the pack in the masjid payload as a `vertical` block
(`org_type`, `label`, `plural`, `terminology`). It is attached per-request with
`->append(Masjid::ADMIN_APPENDS)` in the ADMIN controllers
(`MasjidsController@index/@show`, `OnboardingController@provision`) and is
deliberately NOT in the model's `$appends`: that would widen the public/mobile
API payloads too, and those have no vertical awareness yet. Add the append to
any new admin endpoint that serializes a masjid; do not add it to `routes/api.php`
controllers without a decision to expose verticals publicly.

### In the SPA (T-003)

`useMasjidStore().term(key)` is the Vue counterpart of PHP's `Masjid::term()`
and the ONLY way admin UI should name a tenant concept. It degrades the same
way — a key the pack does not carry humanizes rather than blanking — and adds
one more fallback the backend does not need: **a payload with no `vertical`
block at all falls back wholesale to `MASJID_TERMINOLOGY`** in
`core/types/data/Vertical.ts`. Every tenant that exists today is a masjid, so a
stale or non-admin payload must never blank a label. That constant MIRRORS the
`masjid` pack in `config/verticals.php` — change one, change the other.

`TerminologyKey` is a union of the keys the config ships, so adding a key in PHP
and using it in Vue is a compile error until both sides agree. Keep it that way;
do not widen it to `string`.

Sidebar labels are data, not markup: an `AsideMenuItem` opts into the vocabulary
with `title_term` (+ optional `title_suffix`), and `DashboardAside.vue` resolves
it. `title` stays as the authored default for items that are vertical-neutral.

Terms are plural nouns ("Congregants", "Families"). There is no singular form in
the pack, so leave singular strings ("Add Member") hardcoded until one exists
rather than inventing one by trimming an "s".

## Adding a vertical

1. Add the constant to `Masjid::ORG_TYPES`.
2. Add its block to `config/verticals.php` (label, plural, feature_keys,
   terminology) — `OrgTypeTest` fails if any part is missing.
3. No migration is needed. That is the point.
