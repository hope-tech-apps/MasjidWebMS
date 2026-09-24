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
for what a tenant's MOBILE APP has, and per-tenant gates (`crm_enabled`,
`assistant_enabled`) are unchanged.

That pivot governs the app's drawer for every INSTALLED build. What an
organisation has is `config/capabilities.php` — opt-in grants and modules a
SuperAdmin switches per organisation (`.claude/rules/auth-permissions.md`).

**Since 2026-09-17 a module is no longer admin-only.** `GET
/mobile/masjids/{id}/menu` derives the R1 apps' side menu and tab bar from the
switches, and five modules (`quran`, `hadith`, `adhkar`, `qibla`, `tasbih`,
`surface => 'app'`) decide nothing BUT an app menu row. So the two switches are
still separate today — "Announcements" in the legacy drawer and the
Announcements module are different keys in different places — and they stop
being separate at S2b, when `app-features:cutover-plan`'s resolutions and the
cutover migration move each legacy row onto its module. Until that migration
lands, do not write one from the other: the pivot is what installed builds read
and the switches are what `/menu` reads, and they are allowed to disagree.

Modules default per org type (`Masjid::MODULE_DEFAULTS`). The masjid screens
(Splash, Services, Donation link, Giving, Properties & Rent) are off for a
school or community organisation until a SuperAdmin switches one on, which the
payload reports in `modules_on`. The Details screen's prayer tabs follow the
`prayer_times` module through the admin API; what the APP shows stays
client-side by org_type. A school with Giving on gets non-masjid receipt
wording (`Letterhead::religiousOrg`), and the Donations menu item reads the
`giving` term ("Giving" for a school).

Every key listed in a bundle MUST exist in `mobile_app_features.key`, or
provisioning silently enables nothing for it. `OrgTypeTest` asserts this.
Match a bundle key to the catalogue with `MobileAppFeature::normaliseKey()`
(the wizard: `core/helpers/featureKey.ts`), never by the raw key: production's
Qur'an row is keyed `qur’an` (U+2019) while the bundle says `quran`, and the
exact match gave every new masjid Qur'an off. `QuranFeatureKeySpellingTest`
seeds the production spelling.

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

### GO-LIVE: `crm_enabled` is NOT part of provisioning — no longer true since 2026-08-26, but check it the moment a tenant exists

`masjids.crm_enabled` defaults **false** at the column
(`2026_07_12_000011_add_crm_enabled_to_masjids_table`). Since 2026-08-26
`OnboardingController@provision` writes it **true** unless the request sends
`crm_enabled` false. Three of five production tenants had been created dark
through the wizard before that. Its other writers are
`MasjidsController::setCrmAccess` (**SuperAdmin only**, and recorded in
`masjid_capability_changes`) and `App\Support\DemoSchoolSeeder`. So a tenant
created any other way (the legacy `POST /api/admin/masjids`), or provisioned
with the CRM deliberately off, is switched off until a SuperAdmin flips this one
boolean — with its classrooms, guardians, offerings and fee plans all
configured.

What the flag being false actually looks like, on both sides at once:

  - **Families see a program that does not exist.** `PublicTenant::crmEnabled()`
    gates every public registration endpoint, so `GET /api/v1/offerings/{slug}`,
    `POST .../quote`, `POST .../register` and `POST /api/v1/registrations/
    {uuid}/checkout` all answer **404 "This offering is not available."** — the
    same words a slug that never existed gets, deliberately, so the refusal
    carries no hint about what is wrong. An `offering` page SECTION inlines as
    null and the organisation's own website renders nothing where the program
    should be (`OfferingPublicPayload::forId`).
  - **Staff get 403 from the screens that would explain it.** The `crm` route
    group in `routes/admin.php` (`EnsureCrmEnabled`) covers contacts, groups,
    offerings, fee plans and registrations, so the admin who built the program
    cannot open it either.

Neither refusal names the flag, and neither is reachable by the person who can
fix it: the toggle is SuperAdmin-only. **Check it the moment a tenant is
provisioned, not the morning registration opens** — the failure mode is a
correctly-built school that is simply invisible, which reads as a bug in the
registration feature rather than as one unset boolean.

The gate itself is right where it is and should not be relaxed to make this
easier; `App\Support\PublicTenant::crmEnabled()` carries that argument in full.

## Accepting a vertical at the request boundary

Anything that creates a tenant validates `org_type` with
`Rule::in(Masjid::ORG_TYPES)` and treats an **absent or empty** value as
`masjid`, normalized in `prepareForValidation()` — not defaulted downstream in
the controller — so validation and persistence can never disagree. The request
must extend `BaseFormRequest`, or a rejection escapes as a raw
`ValidationException` and this app's JSON renderer turns it into a 500 instead
of the legacy `{status:'failed'}` 422.

## Choosing the vertical: the onboarding wizard

`OnboardingWizardView.vue` asks for the vertical as the FIRST thing on its
Identity step, and everything it shows about that choice — the label, the
default feature bundle, the terminology pack — is fetched from
`GET /api/admin/onboarding/options` (`verticals` + `default_org_type`), which
serves `config/verticals.php` verbatim. **Do not retype any of it in the SPA.**
A fourth vertical must appear in the wizard with no Vue change at all;
`OnboardingVerticalPickerTest` fails if a pack's labels or a worship feature key
turn up as literals in that file.

`default_org_type` is `Masjid::ORG_TYPE_MASJID` — the same constant
`ProvisionMasjidRequest::prepareForValidation()` merges for an absent value — so
the wizard's pre-selection and the request's fallback cannot disagree. The test
proves it by provisioning without an `org_type` and comparing, rather than
asserting the same literal twice.

**The trap:** the wizard always posts `feature_keys_provided`, so its checkbox
state OVERRIDES the vertical bundle the controller would otherwise seed. The
checkboxes therefore have to carry the bundle themselves
(`applyVerticalFeatureDefaults()`, re-run whenever `org_type` changes) — before
that, picking "School" still provisioned Qur'an, Adhkar and Qibla, because the
step pre-checked the whole catalog. If you touch either side of that, keep
"what the operator saw" and "what got seeded" the same thing.

Creating an organisation does NOT publish it — see
`.claude/rules/directory-listing.md`.

## Islamic worship modules are masjid-only

`adhkar`, `hadith`, `qibla`, `quran`, `tasbih` belong to the masjid bundle
alone. A school or community tenant must never load them. Everything else
(`about_us`, `announcements`, `contact_us`, `donate`, `gallery`, `services`) is
org-generic and shared.

The same five are now also MODULES with the same rule
(`Masjid::MODULE_DEFAULTS`: masjid true, school and community false), so a
school reaches one only if a SuperAdmin deliberately switches it on — the same
deliberate act the wizard's checkboxes already allow. Keep the two in step: a
key added to the masjid bundle and not to the module defaults, or the reverse,
means the legacy drawer and `/menu` disagree about the same organisation.

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

## Vertical form templates (T-011)

`config/form_templates.php` seeds starter FORMS at provisioning time, exactly
as `feature_keys` seeds toggles: templates are data keyed by org_type, applied
by `App\Support\FormTemplates::applyTo()` inside
`OnboardingController@provision`. Schools get Admissions Interest / Careers
Application / Withdrawal Request; masjid and community list NONE — their
provisioning path must stay byte-identical (`FormTemplateTest` pins it, along
with every template validating against `ValidFormSchema`). A seeded form is an
ordinary `forms` row, indistinguishable from an admin-built one. Re-apply to an
existing tenant with `php artisan form:apply-templates {masjid}` — idempotent
and never destructive: any slug the tenant already holds (edited or
soft-deleted) is skipped and reported, never overwritten or resurrected.

## The page-builder palette is NOT per-vertical

A vertical is a feature bundle + a terminology pack. It is **not** a filtered
section-type list. `PageSectionsController@sectionTypes` maps
`SectionType::cases()` with no filter, and `config/verticals.php` carries no
`section_types` key — every tenant is offered every type, including the school
types added in T-010 (`staff_directory`, `programs`, `admissions_tuition`) and
the community types added in T-020 (`services_eligibility`,
`providers_directory`, `impact_stats`).

Do not add gating as a side effect of shipping a vertical feature. A masjid with
a weekend school has a tuition table and a teaching staff, and one running a
food pantry has services with eligibility rules and numbers for its funders. If per-vertical
offering is ever genuinely wanted, `.claude/rules/section-types.md` names the
one place to do it and why validation must stay ungated.

## Adding a vertical

1. Add the constant to `Masjid::ORG_TYPES`.
2. Add its block to `config/verticals.php` (label, plural, feature_keys,
   terminology) — `OrgTypeTest` fails if any part is missing.
3. No migration is needed. That is the point.
