---
paths:
  - "app/Enums/SectionType.php"
  - "app/Http/Controllers/AdminDashboard/SectionsController.php"
  - "app/Http/Controllers/AdminDashboard/PageSectionsController.php"
  - "app/Http/Requests/Admin/Sections/**"
  - "app/Http/Requests/Admin/PageSections/**"
  - "app/Http/Requests/Admin/Pages/**"
  - "app/Support/SectionContentBinder.php"
  - "app/Support/PageDocuments.php"
  - "app/Http/Controllers/AdminDashboard/PageDocumentsController.php"
  - "app/Http/Requests/Admin/Pages/StorePageDocumentRequest.php"
  - "resources/vue-app/components/form/SectionDocumentUpload.vue"
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

**The web server is the second lock, and it has a list of its own.** Since
2026-10-05 nginx answers a file under `/storage` inline only when its ending is
on a short list (pictures, `pdf`, video, audio; `svg` sandboxed) and answers
everything else as a download (`deploy/nginx/manara-storage.conf`, installed by
hand on each server, `deploy/README.md`). So a new upload whose file must OPEN
in the browser needs its ending on that list too, on every server:
`NginxStorageAllowlistTest` fails when a rule's `extensions:` names an ending
the repository's copy does not. The `extensions:` rule is still required: the
server's list is for the name nobody thought of, not a reason to skip the rule.

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

**A PDF is not a section upload, and takes none of the seven places.** An office
attaches a document (a curriculum, a calendar, a schedule) by uploading it ON ITS
OWN: `POST {masjid}/pages/documents` (`PageDocumentsController`,
`StorePageDocumentRequest`, `App\Support\PageDocuments`), in the group that
guards saving a page and outside the purge groups. It stores one PDF on the
ORGANISATION (media collection `page_documents`, read only through
`Masjid::pageDocuments()`) and answers `{url, name, size}`; the page tool writes
`url` into a link field a section already has (`links[].url`,
`programs[].link_url`, `button_link`), where the website already draws a link.
So: no `SectionType` case, no entry in either upload map, no change to
`sectionUploadRules()`, no renderer component. A PDF sent WITH a section's save
is still a 422 (`PageDocumentUploadTest` pins it on both routes).

- **What is let in:** `mimetypes:application/pdf` (the bytes) AND `%PDF-` as the
  first five bytes AND `extensions:pdf` AND 25 MB. The second is not a belt: the
  type sniffer calls a web page with a PDF after it `application/pdf` (seen on
  PHP 8.3). The type a browser declares is never read.
- **What is written:** a name the SERVER makes, `Str::slug` of the client's name
  plus `.pdf` (`PageDocuments::storedName`). The client's name never reaches the
  disk, so the rule above about `extensions` is a refusal a person can act on
  here, not the guard.
- **What is answered:** the media row's URL, from the public disk's configured
  `url`, never from the request (`.claude/rules/generated-urls.md`). It must be
  absolute, because the website is another host: a disk with no absolute `url`
  refuses the upload and keeps nothing.
- **A failed upload leaves no row.** The media library saves the row and then
  copies the file, and takes its row back only for a write the disk refuses; a
  copy that THROWS (the file's directory cannot be made) would leave a row with
  no file. `PageDocuments::store()` runs in a transaction for that reason.
- **Thirty REQUESTS an hour for each signed-in user in each organisation**
  (`throttle:page-documents`, `AppServiceProvider`), because each upload is a
  public file no screen lists. Keyed by the user and the route's `masjid_id`
  read as a NUMBER, as the tenant gate reads it: the route does not pin its
  spelling, and a key on the text would give `7`, `07` and `7.0` thirty each.
  Laravel runs the limiter straight after `auth`, AHEAD of the tenant and
  capability gates and of the upload's own rule, so every signed-in request to
  that address is counted: stored, refused by the rule, or refused because the
  organisation is not theirs. The 429 carries a sentence (`message`), which the
  page tool shows, and it says "tried to upload" so that it is true of someone
  whose thirty were all refused.
- **It is public from the second the upload ends**, before any save. The editor
  says so. A document that must not be public does not go here
  (`.claude/rules/private-uploads.md`).
- **A document stays online while a saved section links to it.**
  `PageDocuments::forgetUnlinked()` runs after `PageSectionsController::update`,
  `SectionsController::update` and `SectionsController::destroy`, and DELETES the
  organisation's own page documents whose address that write removed from the
  section, unless another section of the organisation (on a page or only in the
  library, active or not) still carries it. It matches the PATH
  (`/storage/{id}/{name}.pdf`), so a change of host or scheme cannot make a live
  file look unlinked; it resolves by media id AND stored name through
  `Masjid::pageDocuments()`, so another organisation's document, a section image
  or a gallery photo can never match; it never fails the save; and it leaves a
  WARNING line by ids alone for each file it removes or cannot remove. **Any new
  route that writes or deletes a section must call it**, or documents unlinked
  there are simply left online. Five things it is careful about, each of which
  was a wrong deletion, a false record or a save held for seconds before it was
  written down here:
  - **Only one spelling STARTS a deletion, and every spelling stops one.** The
    address pattern takes an id with no leading zero (`/storage/03/x.pdf` is
    nobody's document, though read as a number it is document 3). "Still linked"
    is asked of the address as written, of the document's own path, of both
    percent-decoded (a viewer's link that carries the address encoded keeps the
    file), and of each of those in a spelling a browser resolves to the same
    file: a tab or a line break inside it, backslashes or JSON-escaped slashes
    for slashes, a doubled slash, `.` and `..` segments, the `..` stepping back
    over any segment but one holding `?` or `#` (`PageDocuments::resolved()`).
    A link that was only ever there encoded or in such a spelling never starts
    a deletion. Widen the KEEPING side freely; never what starts one. STILL NOT
    SEEN, and this is all of it (PD-17): any character of the address written
    as an HTML character reference (`&#x2F;`, `&#47;`, `&sol;`, `&#46;`); the
    address percent-encoded twice or more; the path or `.PDF` in another letter
    case; a link that does not hold the path at all (a redirect, a short link,
    an address relative to another).
  - **Both readers take ONE pass over a text, and stay that way.** A section's
    text has no size limit, and the cleanup reads every string of the section on
    every save, and every other section's when a save drops a document.
    `resolved()` once took one `..` step per pass over the whole text, and the
    out-of-date check read the whole text in front of every address: 40,000
    steps, or 5,000 addresses, held a save for eleven and for nineteen seconds
    after its content was written. `resolved()` cuts the text at its slashes
    once; the out-of-date check reads back from each address to the nearest
    character a host cannot hold, at most 2,048 characters, and past that it
    THROWS (the caller logs "were not checked" and deletes nothing). One cost
    is bounded instead: each address a save lets go of is looked for in all the
    new content and asked of the database, so a save that lets go of more than
    1,000 at once THROWS the same way (20,000 took six seconds). A bound that
    gives up silently is not the safe side here: it would stop keeping a file,
    or call an address "not ours" unread.
  - **A save from an out-of-date editor deletes nothing, and ONLY such a save.**
    If the content after the save brings in an address the section did not have
    before, that is written as OURS (on the public disk's host, on the host the
    request came in on, or with no `//host` in front of the path at all) and has
    NO media row ON THE PUBLIC DISK with that id and file name, the save is an
    old copy putting a deleted file's link back (a second tab): the document it
    would otherwise unlink is the CURRENT one. A host is compared as a browser
    takes it: any letter case, with or without the port its scheme uses anyway,
    with or without a closing dot. Only the public disk is asked, because the
    answer shows in what happens to the administrator's own document: asked of
    every disk, a save told them whether another organisation's PRIVATE file has
    a given number and name.
    One warning line names what was kept. The save is not refused, so the old
    link stays dead. Do not widen this: a document kept here has left the
    content, so no later save can reach it. The first version fired for any
    address of the shape that was not the organisation's own, and so kept, for
    good, a document that was replaced by another organisation's, by the
    organisation's own PDF in another collection, or by another site's address.
  - **"Deleted" is said only when the disk says the file is gone, and "kept"
    only when it says the file is there.** The media library deletes the row
    first, and a disk that will not let a file go raises nothing (the public
    disk does not throw). The disk is asked afterwards; a file it still holds is
    logged as NOT removed and still online, and a disk that cannot be asked at
    all gets a third line: the file could not be checked and may still be online.
    Nor is "the delete returned" always "the row is gone": a listener can cancel
    a delete by answering false, with nothing raised. Anything but a plain yes is
    logged as NOT deleted, like a delete that threw, and the disk is not asked.
  - **It runs whenever the content was written**, not only when the save
    succeeded: both `update` actions call `forgetUnlinkedBySave()` in a
    `finally` that starts after the section's row is updated, and it compares
    with what is STORED. The row is written before the images, in no
    transaction, so a failed image step answers 500 with the address already
    gone, and no later save would find the document.
- **Kept on purpose:** a document whose section was only taken off a page, or
  whose page was deleted (the section is still in the library), and a document
  that was uploaded and never saved. Nothing scheduled deletes one: a sweeper
  would delete public files by inference, and `media:verify` exists because that
  went wrong here once. An address pasted somewhere that is NOT a section (a menu
  link, an announcement) does not keep its file online.
- **`media:verify`:** the collection is counted like any other. If the platform
  holds five or more page documents and every one is taken offline between two
  runs, the vanish floor reports it until an operator accepts the baseline. That
  is the detector working.
- **A removed file's address is a 404**, not the admin screen: `routes/web.php`
  answers `/storage/{missing}` ahead of the SPA catch-all. Keep the parameter's
  name: a route spelt `storage/{path}` would be replaced, in that place, by the
  framework's signed route to a disk if `'serve'` were ever switched on for one.
  It is off on every disk (`config/filesystems.php`), and
  `NoSignedLocalDiskRoutesTest` keeps it off.
- **The control** is `components/form/SectionDocumentUpload.vue`, under the link
  field of Link Buttons, Programs & Curriculum and Call to Action only (the local
  rules are in `components/sections/editors/CLAUDE.md`). The address can be
  pasted into any other link field by hand.
- **`SectionFormModal` holds Save while a PDF uploads** and asks before closing
  (a section saved mid-upload is saved without the address), and says beside
  Save which saved documents the content no longer links: those are the files
  the save takes offline. A new section's Section Type and its Create New /
  Attach Existing choice are held too. What the screen may say about a file
  depends on whether the SAVED section links it; a file uploaded since is
  deleted by no save, and must never be told that clearing or replacing it
  takes it offline. Closing on a file the saved section does not hold asks
  once, with its name and its address: this form is the last screen that shows
  where it is.
- **A PDF uploaded in the modal stays on the screen when the form lets go of
  it.** The modal keeps the list of what was uploaded while it is open
  (`sectionUploadedDocuments`; the control adds each file as its upload ENDS,
  whether or not the control is still there), and its footer names each one the
  form no longer holds: the name, that it is online and in no saved section,
  the address, how to take it offline. One mechanism for every way an address
  leaves the form (Remove on its row, a new section's type changed, another PDF
  put in its place, the field cleared, Attach Existing), because none of those
  can say it themselves: a control is made anew when rows move and is gone with
  its row. The question on Cancel and the close button names these files too,
  once each, and Attach Section asks it before closing. Do not put a notice
  about a file the form no longer shows back into the control or an editor.
- **The modal's form is a deep copy of the section's content** (and of a new
  section's default content), and every editor that holds rows copies each row.
  The section the page list holds is what was SAVED, and Cancel does not reload
  the list: when Link Buttons wrote into the list's own link objects, an upload
  that was cancelled read as saved the next time, and a cleared address that
  was cancelled went out with the next save and deleted the document. This is
  the rule for every editor, with or without a PDF
  (`components/sections/editors/CLAUDE.md`, "Copy every ROW").
- **Limits that stay:** a stalled upload holds Save, the type and the mode, and
  the only way out is Cancel, then Close Anyway; the modal's list of uploads
  lives only as long as the modal, and once it is closed no screen lists a file
  that no saved section links; an address put in BY HAND and then replaced or
  taken out is not named afterwards (it was not uploaded here, and the page
  tool cannot know whose file it is); a size is shown in the unit of the limit
  (1024s), so a computer that counts in thousands shows a slightly larger
  number for the same file; anything that throws after the file is copied and
  before the row is committed leaves a file with no row; a save whose content
  holds an address after more than 2,048 characters of unbroken text, or that
  lets go of more than a thousand addresses at once, has its cleanup given up,
  out loud.
- `PageDocumentUploadTest` and `PageDocumentCleanupTest` pin all of it; the SPA's
  `section-document-file.test.ts`, `section-document-upload.test.ts`,
  `section-form-modal-documents.test.ts` and `section-editors-own-copy.test.ts`
  pin the control, the modal and the editors.

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
