---
paths:
  - "app/Enums/SectionType.php"
  - "app/Http/Controllers/AdminDashboard/SectionsController.php"
  - "app/Http/Controllers/AdminDashboard/PageSectionsController.php"
  - "app/Http/Requests/Admin/Sections/**"
  - "app/Http/Requests/Admin/PageSections/**"
  - "app/Support/SectionContentBinder.php"
  - "app/Http/Resources/Api/V1/PageSectionResource.php"
  - "resources/vue-app/components/sections/editors/**"
  - "resources/vue-app/components/modals/SectionFormModal.vue"
  - "resources/vue-app/core/types/data/masjid-related/PageSection.ts"
---
# Page-builder section types

A section type is **three things and nothing more**:

1. a case on `App\Enums\SectionType` (the string stored in `sections.section_type`),
2. the `content` JSON shape its `defaultContent()` returns,
3. a Vue editor keyed off that string in `components/sections/editors/`.

There is no per-type table, no per-type controller, no registry class and no
migration. **Adding a type is a code change to a fixed set of files.** If a
change needs a fourth thing, stop and re-read this file — a parallel system is
the failure mode this rule exists to prevent.

## The seven places one type has to land

| # | File | What |
|---|---|---|
| 1 | `app/Enums/SectionType.php` | the `case`, `label()`, `description()`, `usesExternalData()`, `defaultContent()` |
| 2 | `SectionsController::getImageFieldsForSectionType` | image fields, if any |
| 3 | `PageSectionsController::getImageFieldsForSectionType` | **the same map again** — both controllers own a copy |
| 4 | `core/types/data/masjid-related/PageSection.ts` | the `SectionType` union, the content type, the `SectionContent` union |
| 5 | `components/sections/editors/<Name>SectionEditor.vue` | the editor |
| 6 | `SectionFormModal.vue` | the import **and** the `editorMap` entry |
| 7 | a Feature test | round-trip + the existing types still unchanged |

Validation needs no change: every request allowlists with
`new Enum(SectionType::class)`, so the enum IS the allowlist.

`label()`, `description()`, `usesExternalData()` and `defaultContent()` are
**exhaustive `match` with no default arm, on purpose.** Adding a case without
classifying it is a fatal error at the first call, not a silent wrong default.
Keep them that way.

## Traps that cost real time

- **`editorMap` is typed `Record<SectionType, …>`.** Add to the union in
  `PageSection.ts` and forget `SectionFormModal.vue` and it is a type error —
  but `npm run build` is `vite build` with **no typecheck** (and this repo has
  no `vue-tsc`), so it builds anyway and the admin gets the type in the dropdown
  with an empty editor pane. That shipped twice already (`events`, `form`).

  Since 2026-08-12 this is **enforced mechanically** rather than remembered:

  ```bash
  php artisan test --filter=every_section_type_is_wired_into_the_spa
  ```

  `OfferingSectionTypeTest` lints the SPA sources against `SectionType::cases()`
  — every case must appear in the `SectionType` union in `PageSection.ts` and as
  an `editorMap` key in `SectionFormModal.vue`, and every component the map names
  must be imported and exist on disk. It is **lexical, and a floor rather than a
  ceiling**: it proves the case is named and the file exists, not that the editor
  is correct. Same reasoning as `TenantScopingCoverageTest` — a rule a human has
  to remember is documentation, not a control.
- **Uploads only reach ONE array level.** `handleArrayImageUploads` turns
  `items.*.image_url` into `items_(\d+)_image_url` and reads `$matches[1]`, so a
  pattern with two wildcards (`departments.*.members.*.photo_url`) silently
  drops every file. **Design the content shape flat enough to upload**: one list
  of objects, grouped by a label field the renderer buckets on, not a nested
  tree. `staff_directory.members[].department` is the reference shape.
- **Pending uploads are keyed by position.** The FormData key is literally
  `members.0.photo_url` (PHP turns the dots into underscores). Any editor that
  lets an admin reorder or delete an item MUST re-key the queued files, or the
  photo lands on the wrong row — see the `remap*Files` helper in
  `CarouselSectionEditor` / `StaffDirectorySectionEditor`. On a staff page that
  is someone's face published under another person's name.
- **The binder has a `default` arm.** `SectionContentBinder::bind()` returns
  stored content untouched for any type it does not name, so a new type is
  inert there. Only add an arm when the content genuinely belongs to a
  dedicated model (see DECISIONS 2026-06-25 / 2026-06-26).
- **`button_page_id` is free.** `Section::getContentAttribute` resolves a
  top-level `button_page_id` (and one inside `content.items[]`) into
  `button_page_url` at read time. Use that name for an internal link and the
  link survives a page rename; invent another name and it rots.

## The palette is GLOBAL, not per-vertical

`PageSectionsController@sectionTypes` maps `SectionType::cases()` with **no
filter**. Every tenant is offered every type regardless of `org_type`, and
`config/verticals.php` — whose header comment claims a vertical is partly
"which page-builder section types are offered" — carries no `section_types`
key. The comment describes the intent; the code does not implement it.

The school types (`staff_directory`, `programs`, `admissions_tuition`) and the
community types (`services_eligibility`, `providers_directory`, `impact_stats`)
are therefore visible to every tenant whatever its `org_type`. **That is
deliberate.** A masjid with a weekend school has a tuition table and a teaching
staff, and a masjid running a food pantry has services with eligibility rules
and numbers for its funders; gating the palette would invent a mechanism to keep
a real tenant away from a section it wants.

If per-vertical offering is ever actually wanted:

- filter in **ONE** place — `sectionTypes()` — keyed off `config/verticals.php`,
- keep **validation ungated** (`new Enum(SectionType::class)` unchanged), so a
  tenant that switches `org_type`, or a section authored before the gate, never
  stops loading. The palette decides what is *offered*; it must never decide
  what is *readable*.

## A section that CHARGES holds a reference, never a figure

`offering` (T-006g) is the first type whose referenced row decides what somebody
is charged, and it is the reason the next section reads the way it does.

- Its content is `offering_id` plus page wording (`title`, `intro`,
  `show_fee_plans`, `button_text`, `background_color`) — **no amount, no
  currency, no capacity, no window**. All of those live on the `Offering` and its
  `FeePlan`s, and `SectionContentBinder::bindOffering` inlines them under
  `content.offering` at serve time through `App\Support\OfferingPublicPayload`,
  the same presenter `GET /api/v1/offerings/{slug}` uses.
- **Never copy a price into section JSON.** Fee plans are immutable and are
  deactivated-and-replaced rather than edited, so a copied figure goes stale the
  moment a price changes — and the page would then advertise one number while
  Stripe charged another. `OfferingSectionTypeTest` asserts the shape carries no
  `amount`/`currency`/`capacity` key at all.
- `form` and `offering` must not converge. A `form` submission writes a
  `FormResponse`: it takes no seat and moves no money. An `offering`
  registration reserves a place and opens a Stripe Checkout Session. An admin who
  reached for the wrong one would believe sign-ups were being collected when
  nothing had been.

## Money in section content is display text

`admissions_tuition.tiers[].amount`, `stats.value` and
`impact_stats.stats[].value` are strings. A real tuition table mixes `$8,000`,
`Included` and `Contact us` in one column; a real impact line reads `$6.3M` or
`6,000+`, where the rounding and the plus sign are part of the claim. Nothing in
this app charges or computes from a section — the Stripe path is donations only.
A string says "this is what the page says"; a decimal implies a machine reads
it. Do not "fix" these into numbers without a billing engine behind them
(PLAN T-006).

## Public sections are editorial, never a CRM view

`staff_directory` holds names typed into the section. It is **not** a query over
`contacts` or `group_memberships` — those are private records about families and
congregants, and a public page must never become a window onto them. Publishing
a person is an editorial act with a human behind it. Any future "pull the roster
in automatically" idea needs an explicit per-person publish flag on the record
itself, not a section that reads the table. Same reasoning as
`.claude/rules/groups.md` on minors' data.
