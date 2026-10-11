# Student allergies

`student_allergies` is a per-school grant in the school capability group. It defaults to false for every organisation type and is hidden while off. The new routes return 404 while off, including for a SuperAdmin. Existing roster, student-details, teacher roster and attendance SQL/payloads are pinned to `52daab37`; no health query runs while off. Switching off hides the record and retains it.

## Switch on and use

1. Apply the migration and release the PHP/SPA code through the usual approved release process.
2. As a SuperAdmin, use the existing capability writer: `PATCH /api/admin/masjids/{school}/capabilities/student_allergies` with `{"enabled":true}`. Production switch changes require the owner's approval. The grant is deliberately absent from off catalogues and provisioning defaults.
3. Open a class's Roster. Choose **Copy allergies from a form**, select the school's enrolment form and enter the child field name, usually `allergies`. Preview first, then choose **Copy now**. The preview counts children read, children with a nonempty allergy, matched, already set, unmatched and ambiguous before showing unresolved children. Blank, missing and null answers have their own **left unchanged: blank answer** count and do not enter matching or supersede real answers. Extra counts explain office precedence, explicit none answers, superseded answers, invalid answers, overlong answers and prospective/actual writes.
4. Resolve unmatched or ambiguous children by hand: open the correct child's Student details and enter **Allergies or special needs**. The source and last-change date appear below the field. An empty office answer clears the flag. The field retains the full input, shows a live Unicode character count and refuses saving more than 500 characters with the actual count in words. Office edits, including clears, always win over later copies.
5. The class's **Export roster CSV** includes an Allergies column. This is a new, class-only export: the roster previously had no CSV action. Existing form-response and school-records exports are unchanged.

No automatic copy runs when a new response arrives or an existing answer changes. Run another explicit preview/copy to process those answers. In particular, a later form answer of “none” clears an earlier copied flag on that explicit copy, while an office edit remains authoritative.

## Command

```sh
php artisan students:copy-allergies --masjid=<school-id> --form=<form-id> --field=allergies --dry-run
php artisan students:copy-allergies --masjid=<school-id> --form=<form-id> --field=allergies
```

Both callers use `App\Support\StudentAllergies::copy`. The command refuses an off/non-school organisation or a form belonging elsewhere. Dry run performs no writes. Command output contains counts and unresolved response IDs/zero-based entry indexes, without children's names, guardian details or health text, so captured console output does not become a health log. The office result names unresolved children and their response/entry references.

## Matching and precedence

Registrations can carry `form_response_id`; their registrants carry `contact_id`. Copy uses this stored bridge before name-and-guardian matching. Responses, registrations, registrants and contacts are fenced to the chosen school. Cancelled responses and registrations are excluded. Only contacts with a member row in a class can receive a note.

The link applies when the response contains exactly one child entry and the linked contacts identify exactly one child, even without respondent email/phone. With multiple entries or linked contacts, first and last names must identify exactly one linked contact and exactly one response entry after the same name normalisation. Two linked children with the same name are reported ambiguous. A link that cannot identify the child unambiguously does not guess; the existing exact name plus guardian path remains the fallback.

For each non-cancelled response of the chosen tenant/form:

- Choose a repeatable child section only when its fields include `firstName`, `lastName` and the selected allergy field. Read entries from `data[section.id]`. Otherwise, flat student forms use top-level `studentFirstName`, `studentLastName` and the selected field from non-repeatable sections. A repeatable emergency-contact section does not divert the flat path. Tests use both `database/forms/biss-2026.json` and `database/forms/alrazi-website-registration.json`. Incompatible selected fields/forms are refused.
- Candidate contacts must belong to this school and have a role-member membership in a class. First and last names are compared separately, lowercased with Unicode support, trimmed and with whitespace collapsed. Punctuation/diacritics are preserved.
- The response's `respondent_email`, trimmed/lowercased, must equal an email on one of the student's recorded guardian contacts. Only if the response email is absent is phone used, comparing digits after removing formatting. A present but nonmatching email never falls back to phone. These comparisons happen in PHP, independent of database collation.
- Exactly one contact matches: copy the answer unless an office record exists. Zero or multiple contacts: report unmatched/ambiguous and write nothing. Identically named siblings/twins are never guessed.
- Missing, null and whitespace-only answers change nothing and never supersede an earlier real answer. Otherwise, the newest response ID wins, including an explicit none-list answer; later duplicate entries in the same response and older responses are reported as superseded. Explicit none clears only a form-sourced note, never an office record. Invalid answers are listed. Re-running unchanged input leaves the note and its timestamp unchanged. Preview recomputes identity and precedence; Copy recomputes again rather than trusting preview IDs.

The server alone decides whether an answer means none: `none`, `no`, `n/a`, `na`, `nil`, `nothing`, `no allergies`, `none known`, and punctuation-only answers such as `-`, `--` and `.` are empty, case-insensitively after removing surrounding whitespace and ASCII punctuation. ASCII symbols (`$`, `+`, `^`, backtick, `|`, `~`, etc.), Unicode symbols/emoji, digits, other letters and non-ASCII punctuation are never stripped. Thus `🥜`, `none🥜`, `+none`, `none1` and `—none—` stay flagged. Other answers are preserved apart from whitespace trimming. The server normalises stored notes at disclosure too; the Vue badge does not repeat the none list.

Office input is limited to 500 Unicode code points. Form input is stored intact up to 8,000 code points. The worst-width test stores 32,000 plaintext bytes as 57,088 encrypted bytes, below the TEXT limit of 65,535 bytes. Above 8,000, copy reports **too long: enter by hand** and stores **See enrolment form: answer too long** as a form-sourced note for the matched child, retaining the response ID and guaranteeing a visible flag without truncating the answer. The complete answer remains in the form response. Office precedence still applies. A new explicit none answer creates no row; an empty office edit retains an office row to prevent future copying from restoring the flag.

## Disclosure

The office reads the note only on the class roster, the student's allergy field read/save, and the class CSV. Teachers read it only in their assigned-class roster/list and attendance register, including the response to saving the register. Teacher payloads carry only the note, without its source, response ID or editor. The badge says **Allergy**, has the note in its accessible name/title, and shows the words on focus, keyboard activation or tap. Teacher access is read-only. Register badges use the attendance response map, so turning the switch on or adding a note appears on the next attendance fetch without reloading the class roster.

“Assigned” is the existing `group_staff` relationship: `Group::ledBy` filters lists, and `teacher.leads`/`GroupAudience::isLeaderOf` fences individual class reads. A teacher restricted to some subjects still sees the whole class's health flags. A timetable meeting's teacher link alone grants nothing. Tenant membership still fences two-school teachers, and only current student memberships disclose a note to a teacher. A move/withdrawal retains the contact's note; former teachers lose the note when the student is no longer current in their class.

An unreadable encrypted note is caught per student and produces `unavailable: true`. Office and teacher rosters/registers display **Allergy note unavailable: ask the office**, as does the office field and roster CSV. Other students still render, and register marks still save. A warning log contains only school, contact and health-record IDs; it contains no note, ciphertext or exception text.

Family/guardian reads, child mode, device/member/mobile APIs, public pages, other exports, notifications and emails never load this table. The Contact model has no health relation or append. `TeacherController::student` and `FamilyController::student` are unchanged. A note is attached only at the named read boundaries; teacher attendance history, gradebook, subject work, resources, report cards, points, class store and avatar answers retain their prior projections.

Reviewed serializers: Contact's array/JSON serialization; office ContactsController and roster mutation/consent/withdrawal/move answers; FamilyController, FamilyAuthController, MeController, GroupsController, StudentSessionController, family awards/grades/letters/hifz/report cards/resources/threads/class-store projections; MemberAuthController, MemberAccountController, MemberPurchases/MemberRecurringGiving and device registration/update/masjid projections; public offering registration/page responses; SchoolRecordsExportController datasets; form-response list/roster/export and notification/mail contact readers. None gained a health relation or health reader.

## Storage and lifecycle

`student_allergies`: `id` primary key; `masjid_id`; `contact_id`; nullable `allergies` TEXT; `source` (`form` or `office`); nullable `form_response_id`; nullable `changed_by_user_id`; `created_at`; `updated_at`. Unique `(masjid_id, contact_id)` uses the short index name `student_allergy_school_contact_uq`. The model is tenant-scoped. Health text is encrypted with the application's key; the plaintext limit is enforced before storage, and write SQL bindings contain ciphertext. Provenance IDs survive retirement of a response or staff account. Console copies have a null editor ID and source `form`.

Soft contact deletion retains the note. Permanent contact deletion cascades it. A merge reconciles health records inside the merge transaction, even with the switch off:

- Survivor has no note: move the absorbed record, keeping source and response provenance, with the merging user's editor ID.
- Both notes normalise to the same text: keep the survivor's record untouched.
- Different notes: store `survivor text / absorbed text` on the survivor as an office note with the merging user's editor ID if the combined note fits within 500 characters. Otherwise refuse the whole merge and show the service refusal in the office merge dialog: **Both records have allergy notes too long to combine. Shorten one, then merge.**
- An unreadable note refuses the merge in words so it cannot cascade away unread.

The migration is `2026_10_11_000000_create_student_allergies_table.php`. A hidden `masjids.has_student_allergy_records` retention hint follows the timetable's mechanism: merge reads the school row already loaded by tenant middleware, independent of the switch. Writers mark it, and individual health-row deletion/contact cascades refresh it. An unused school adds no query: the starting commit and fixed merge each emitted the identical 40-query SQL list in the local comparison. The hint is hidden from model serialization and public-directory columns. Bulk SQL maintenance that bypasses model events must refresh the hint with `StudentAllergies::refreshRetention` in the same operation.

Deleting an app login follows the existing account-deletion rules. A roster row, even withdrawn, already preserves the contact and its note. If all records that keep the contact have been removed and the contact is erased, the health row follows its FK cascade; the hint refresh follows the physical cascade when records were held; unused schools keep the existing erasure path. `MemberAccountDeletion::CASCADE_RECORDS` classifies that decision. A health row survives removal of its roster row until the contact itself is erased. No membership-retention hook is needed because the key is the contact.

Staging scrub drops all health rows via `config/staging_scrub.php`.

## Verification

`tests/Feature/StudentAllergiesTest.php` pins normalization, copy/preview/precedence/idempotence, edit/clear, role/tenant/class fences, limited and two-school teacher access, timetable-only refusal, lifecycle, encryption and literal OFF SQL/payload. Review scenarios were made red before their fixes, including flat forms, blank supersession, unreadable ciphertext, linked registrations, emoji, merge loss and overlong form input. `resources/vue-app/tests/student-allergies-mounted.test.ts` covers unreadable badges, full overlong input/count/refusal and attendance without the roster health key.

The privacy walk seeds real child-scoped stories, conversations/messages, attachments, resources, a published report card, shared subject marks/notes, a paid purchase and a succeeded gift/issued receipt. Every walked GET must return 200, and any unseeded route parameter fails. It inspects JSON, streamed/binary downloads, generated report-card/receipt PDF bytes and decoded PDF Flate streams, and all school-records CSV datasets for the note text, `student_allergy` and case-insensitive `allerg`. The separate authorised office roster CSV deliberately contains the note. The current inventory is recorded in `/private/tmp/student-allergies-privacy-walk.json`. Notifications/mail are inspected in code, not delivered.

Required local gates: the owner's Pest filter, `npm run -s test:spa`, then exactly one `npm run build`, inspecting emitted assets and removing `public/build`. Review-fix totals, run one at a time:

- Requested Pest filter: **2896 passed (35328 assertions)**, **Duration: 202.14s**.
- `npm run -s test:spa`: **tests 1964**, **pass 1963**, **fail 0**, **skipped 1**, **duration_ms 178681.397583**.
- Exactly one `npm run build`: **1489 modules transformed**, **built in 10.26s**, exit 0. The emitted unavailable warning, full office input/count, merge refusal and attendance map were inspected; `public/build` was removed. Existing Browserslist/chunk-size warnings remain.

Existing fixtures remain unchanged. No CAPTURE variable, formatter, commit, CHANGELOG edit, deployment or production switch change.

Not verified by local gates: actual browser paste/layout at 390/320 or RTL, assistive technology, physical printing, MySQL concurrency/runtime, CI, staging or production.
