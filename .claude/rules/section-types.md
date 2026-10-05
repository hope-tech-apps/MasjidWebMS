---
paths:
  - "app/Enums/SectionType.php"
  - "app/Http/Controllers/AdminDashboard/SectionsController.php"
  - "app/Http/Controllers/AdminDashboard/PageSectionsController.php"
  - "app/Http/Requests/Admin/Sections/**"
  - "app/Http/Requests/Admin/PageSections/**"
  - "app/Http/Requests/Admin/Pages/**"
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
| 1 | `app/Enums/SectionType.php` | the `case`, `label()`, `description()`, `usesExternalData()`, `requiresModule()`, `requiresGrant()`, `defaultContent()` |
| 2 | `SectionsController::getImageFieldsForSectionType` | image fields, if any |
| 3 | `PageSectionsController::getImageFieldsForSectionType` | **the same map again** — both controllers own a copy |
| 4 | `core/types/data/masjid-related/PageSection.ts` | the `SectionType` union, the content type, the `SectionContent` union |
| 5 | `components/sections/editors/<Name>SectionEditor.vue` | the editor |
| 6 | `SectionFormModal.vue` | the import **and** the `editorMap` entry |
| 7 | a Feature test | round-trip + the existing types still unchanged |

Validation needs no change: every request allowlists with
`new Enum(SectionType::class)`, so the enum IS the allowlist.

**Uploads are images unless one rule says otherwise.** The four section requests
take their per-file rules from `Concerns\ValidatesVideoSection::sectionUploadRules()`:
every uploaded file gets the image rule, except a `video` section's `video_url`,
which takes an MP4 (by its bytes) of up to 25 MB. A new type whose upload is not
an image adds its field THERE, by type AND field, never by loosening the image
rule: an MP4 in a field an `<img>` draws is a blank box on a live page
(`VideoSectionTypeTest` pins that it is still refused). Every rule checks the
bytes (`mimes`/`mimetypes`) AND the name (`extensions`): the media library keeps
the uploaded file name on the public disk, and the web server serves it by its
extension, so matching bytes named `.html` would be a page on this app's origin.

**The pair is for every upload whose client file name can reach the public disk,
not for sections only.** On 2026-10-05 every such upload carried it (found by
reading every upload under `app/`, not by a test). Two kinds of test watch it,
and a new upload needs both.

`UploadFileNameCoverageTest` reads the PHP under `app/` and fails when an upload
rule written in one of the ordinary ways has no `extensions:` beside it, unless
its `NAME_NEVER_PUBLIC` list says why the name cannot reach a public address (a
name this application chooses, a private disk behind a signed-in download, a
file that is never stored) and the facts it gives for that still hold. The
ordinary ways are a rule string or a rule list with `image`, `file`, `mimes:`,
`mimetypes:` or `dimensions:` in it, in any capitals, and the framework's own
fluent rules (`Rule::file()`, `File::image()`, `new ImageFile`,
`Rule::dimensions()` and their like, under an import alias too). "Beside" is
narrow: in the same string literal with its list written out, or as a whole
element of the same rule list. A pin in one branch of a ternary, or one whose
list comes from a variable, is reported as no pin.

**A green coverage test does not mean every door is closed.** It reads rules,
and only rules written those ways. Each of these passes it with a door open (its
own table, `whatTheScanCannotSee`, holds one of each that can be written as a
snippet, which is all but the rule outside `app/`):

- a rule with no file word in it at all (only `max:`, say);
- the one word `image` or `file` standing alone as a rule where the scan does
  not expect rules: in a helper with no `rules` in its name, a constant, a
  property, or a validator made another way (`app('validator')->make(...)`);
  and, wherever it is written, in an arm of a `match` or among another call's
  arguments, alone or as one branch of a ternary there
  (`Rule::when($new, 'image')`). The scan takes the bare word for a rule only in
  a list beside a presence word or a rule with a colon, or in a method with
  `rules` in its name, an array assigned to a variable with `rules` in its name
  or a validate call. Even there it reads the word only as a list element or as
  the whole of a value: what a field is given, what is assigned or handed back
  (`return`, an arrow function), or one branch of a ternary or the right-hand
  side of `??` in one of those, with or without brackets round it. A method with
  `rules` in its name is not read through and through;
- a rule that is not one piece of text: joined from two literals or from a
  constant, read from `config()`, made by `sprintf()`, or changed in a later
  statement;
- the array form of a rule (`['mimes', 'jpg', 'png']`);
- a custom rule object or a closure;
- a pinned rule the request switches off (`exclude_if:` in front of it);
- a rule outside `app/`;
- an upload read with no rule at all, and a file that does not arrive as an
  upload (written from text the client sent, or fetched from an address);
- a pinned upload whose stored name comes from another input;
- whether a pinned list is a sensible one (`extensions:jpg,html` counts as
  pinned).

What each such rule carries:

- `extensions:` listing, **in lower case**, what a real file of that field can
  be called: the rule's own `mimes:` list, or `jpeg,jpg,png,gif,bmp,webp` beside
  a bare `image`. An icon's list is shorter than its `mimes:`, which names `ico`
  (and `icns` on the service edit form): `image` beside it refuses those bytes
  first, so no real icon file can use those names, and the list is `png,webp`
  (`png,gif,webp` on that form).
  `extensions` lower-cases the client's name and not its own list, so
  `IMG_0001.JPG` passes `extensions:jpg` and nothing passes `extensions:JPG`.
- `bail` first, so a file that is not an image is told that once and is not also
  told to rename it (a PDF renamed `.jpg` is still refused).
- a sentence of the field's own for the name: "The …'s file name must end in ….
  Rename the file and upload it again."

An upload this application names itself (the lunch flyer is `<uuid>.<ext>`)
takes that extension from the sniffed type (`$file->extension()`), never from
the client's name.

`PublicUploadFileNameTest` (a page's title background, the flyer) and
`PublicUploadFileNameDoorsTest` (the thirteen admin uploads found beside them:
announcements, the gallery, logos, avatars, services, About, the donation link,
a push, the publish composer) send real bytes through the real route, because
`UploadedFile::fake()` reports a type from its argument or its name and so
cannot show bytes named as something else. For each door they prove two things:
image bytes under a page-like name (`x.html`, `x.HTML`, `x.jpg.html`, `x.htm`,
`x.xhtml`, `x.svg`, no extension at all) are refused and nothing is stored; and
every kind of file an office may upload there is accepted, under a lower-case
and an upper-case name. The kinds are written in the test, door by door, and are
not read from the rule, so a list that loses `webp` turns that door red. They do
not prove that a list is not too wide, beyond those page-like names. A door
proves a rule only where that rule is the only guard: the composer's picture is
also held by the announcement's rule when the feed is ticked, so the composer is
five doors, the feed with push and then each other channel alone (push, the
board, email, a text message). **A new upload to the public disk needs its own
row there**, whatever the coverage test says, and every file a controller reads
needs a rule.

`label()`, `description()`, `usesExternalData()`, `requiresModule()`, `requiresGrant()` and
`defaultContent()` are **exhaustive `match` with no default arm, on purpose.** Adding a case without
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

(One exception: a type that `requiresGrant()`, below.) `PageSectionsController@sectionTypes` maps `SectionType::cases()` with **no
org-type filter**. Every tenant is offered every type regardless of `org_type`, and
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

## A type under a GRANT is not offered without it (`shop`)

`SectionType::requiresGrant()` names a grant (`config/capabilities.php`, `kind => grant`), or null. Only `shop` has one,
and it is the one exception to "the palette is global" above. A grant is the opposite of a module: a module is on until a
SuperAdmin switches it off, so its type stays offered and says so; a grant is off until given, so its type is not offered.

- **Offered:** `PageSectionsController@sectionTypes`, the one place the palette is filtered, leaves the type out unless
  `Masjid::hasCapability()` (fails closed) is true for the organisation in the URL. The list is re-indexed, so the payload is
  still a JSON list.
- **Created:** `Concerns\ValidatesShopSection::validateGrantedSectionType()` is a closure rule on `section_type` in all four
  requests. Creating one, or changing a section to one, without the grant is a 422 in the catalogue's own words ("Online shop is
  not switched on for this organisation, so it cannot have a Shop section.").
- **From the organisation, never the viewer, SuperAdmin included.** The public shop API answers the dark 404 for an
  organisation without the grant whoever built the section.
- **Never gated: reading, editing as the same type, deleting, the public payload.** A section already of the type survives the
  grant being switched off; the renderer draws nothing because the shop API 404s. Changing one away and back is refused (the way
  back is a change to it). Do not add a gate to those, and do not hide the type from `index`/`show`.
- **Content** is four keys, checked strictly by `validateShopContent()` (heading 120 characters, category 60, `max_items` an
  integer 1 to 24, `show_view_all` a boolean) and an unknown key is refused. The section stores NO product, price or size: the
  renderer fetches them (`usesExternalData()` is true), so there is nothing to bind and nothing to go stale.
- **Not in `withoutRenderer()`**: its one sentence is about an unrendered sign-up form and would be false here. Until MEC's
  renderer ships, a shop section draws nothing; ship the renderer before a page carries one.
- `ShopSectionTypeTest` pins all of it, including that every other type's `requiresGrant()` is null.
- The editor has no i18n: no page-builder editor does (the only locale files are the family portal's).

## A type that shows a switchable module says so, from the ORGANISATION

Some types draw data a SuperAdmin can switch off for one organisation
(`config/capabilities.php`, `kind => module`; DECISIONS 2026-09-16):
`announcements_list` → announcements, `events` → events, `gallery` → gallery,
`about_us` and `mission_vision` → about_us (the binder draws both from
`MasjidAbout`), `contact_form` → contact_requests, `offering` → programs, and
(switches wave 2) `prayer_times` → prayer_times, `donation` → donation_link,
`services_list` → services. `moduleOffNote()` says "switched off", so it is only
ever served for a module the organisation's type is offered: a school was never
offered Services or Donation link, and nobody switched them off.

- `SectionType::requiresModule()` records it: exhaustive, **no default arm**. A
  new case must be classified, even as `null`. `moduleOffNote()` holds the one
  sentence per module.
- `PageSectionsController@sectionTypes` serves `module_off_note` only when the
  ORGANISATION in the URL is offered that module and has it off
  (`Masjid::moduleOfferedByDefault` and `Masjid::moduleIsOff`, the `modules_off`
  rule), **never from the viewer**: a SuperAdmin building BISS's page reads what BISS's own
  admin would. `SectionFormModal` prints it the way it prints `renderer_note`.
  Do not write the sentence into a component.
- **The palette is still not filtered**, for the reasons above. A section
  authored before a switch-off keeps loading, saving and serving.
- The SuperAdmin switch panel's `in_use` counts come from the same method, so
  the count and the note cannot disagree. Per TYPE only: a generic section bound
  to About Us through `settings.bind` is not seen. `SectionTypeModuleTest` pins
  the map and the org-not-viewer rule.

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
  `FormResponse`: it takes no seat, and it moves money only when the form's own
  settings turn on its single one-time payment (DECISIONS 2026-09-11: festival
  registration, with per-staff cash codes). It never has a place, a waitlist or
  installments. An `offering` registration reserves a place and opens a Stripe
  Checkout Session. An admin who reached for the wrong one would believe
  sign-ups (or places) were being collected when nothing had been.

## A type whose renderer has not shipped says so, in ONE string

The renderers live in the Nuxt site repo and ship separately, so the backend
half of a type can land while the half that DRAWS it does not. `offering` is in
exactly that state: the public read, the presenter, the section and the binder
all exist; the component that draws a registration block does not.

`SectionType::withoutRenderer()` is the one list that records it, and everything
else is derived:

- `hasRenderer()` / `rendererNote()` read it;
- `description()` **appends `SectionType::RENDERER_PENDING_NOTE`** to any such
  type's description, so a surface that prints only the description still tells
  the truth;
- `PageSectionsController@sectionTypes` serves `has_renderer` + `renderer_note`,
  and `SectionFormModal` and the type's own editor print that string verbatim.

**Do not write the caveat into a component.** Before 2026-08-12 there were three
surfaces and three stories: this enum's description promised "its fee plans, the
places left, and the registration form", `OfferingSectionEditor` previewed the
states the published block would show, and the only surface saying the block is
not drawn was a note on the Programs screen an admin need never open. An admin
who believed the first two published a section that renders nothing and — as
that note warns — reasonably published the intake FORM as a substitute, which
takes no seat and, at the time, could move no money.

The type is **not** gated out of the palette. The data is genuinely served, a
tenant may lay its page out ahead of the renderer, and per-type gating is the
mechanism the section above says not to invent. What is forbidden is promising a
rendering. **When the renderer ships, delete the case from `withoutRenderer()`**
— every string flips with it — and delete the hand-written note in
`OfferingsView.vue` in the same commit. `SectionTypeRendererTruthTest` fails if
a type without a renderer has no note, if a description does not carry it, or if
the payload omits the pair.

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
