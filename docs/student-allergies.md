# Student allergies

`student_allergies` is a per-school grant in the school capability group. It defaults to false for every organisation type and is hidden while off. The new routes return 404 while off, including for a SuperAdmin. Existing roster, student-details, teacher roster and attendance SQL/payloads are pinned to `52daab37`; no health query runs while off. Switching off hides the record and retains it.

## Switch on and use

1. Apply the migration and release the PHP/SPA code through the usual approved release process.
2. As a SuperAdmin, use the existing capability writer: `PATCH /api/admin/masjids/{school}/capabilities/student_allergies` with `{"enabled":true}`. Production switch changes require the owner's approval. The grant is deliberately absent from off catalogues and provisioning defaults.
3. Open a class's Roster. Choose **Copy allergies from a form**, select the school's enrolment form and enter the child field name, usually `allergies`. Preview first, then choose **Copy now**. The preview counts children read, children with a nonempty allergy, matched, already set, unmatched and ambiguous before showing unresolved children. Matching counts include empty answers. Extra counts explain office precedence, empty answers, superseded answers, invalid answers and prospective/actual writes.
4. Resolve unmatched or ambiguous children by hand: open the correct child's Student details and enter **Allergies or special needs**. The source and last-change date appear below the field. An empty answer clears the flag. Office edits, including clears, always win over later copies.
5. The class's **Export roster CSV** includes an Allergies column. This is a new, class-only export: the roster previously had no CSV action. Existing form-response and school-records exports are unchanged.

No automatic copy runs when a new response arrives or an existing answer changes. Run another explicit preview/copy to process those answers. In particular, a later form answer of “none” clears an earlier copied flag on that explicit copy, while an office edit remains authoritative.

## Command

```sh
php artisan students:copy-allergies --masjid=<school-id> --form=<form-id> --field=allergies --dry-run
php artisan students:copy-allergies --masjid=<school-id> --form=<form-id> --field=allergies
```

Both callers use `App\Support\StudentAllergies::copy`. The command refuses an off/non-school organisation or a form belonging elsewhere. Dry run performs no writes. Command output contains counts and unresolved response IDs/zero-based entry indexes, without children's names, guardian details or health text, so captured console output does not become a health log. The office result names unresolved children and their response/entry references.

## Matching and precedence

There is **no stored link from a form response or its child entry to a contact** in the pre-feature schema. `group_memberships.source_registration_id` names an offering registration, not a `form_responses` row. Offering registrants can name contacts, but there is no bridge from these form responses to those registrants. The website mapper writes form answers; sync uses `(form_id, external_ref)` to identify the external submission, not a student. CSV roster import records a `contacts.import_batch` for created contacts; it stores neither a form-response ID nor a child index.

For each non-cancelled response of the chosen tenant/form:

- A repeatable child section is read from `data[section.id]`, with `firstName` and `lastName`. Flat student forms use top-level `studentFirstName` and `studentLastName`. Incompatible selected fields/forms are refused.
- Candidate contacts must belong to this school and have a role-member membership in a class. First and last names are compared separately, lowercased with Unicode support, trimmed and with whitespace collapsed. Punctuation/diacritics are preserved.
- The response's `respondent_email`, trimmed/lowercased, must equal an email on one of the student's recorded guardian contacts. Only if the response email is absent is phone used, comparing digits after removing formatting. A present but nonmatching email never falls back to phone. These comparisons happen in PHP, independent of database collation.
- Exactly one contact matches: copy the answer unless an office record exists. Zero or multiple contacts: report unmatched/ambiguous and write nothing. Identically named siblings/twins are never guessed.
- If multiple responses identify the same student, the newest response ID wins, including empty answers; later duplicate entries in the same response and older responses are reported as superseded. Unusable answers are listed rather than truncated. Re-running unchanged input leaves the note and its timestamp unchanged. Preview recomputes identity and precedence; Copy recomputes again rather than trusting preview IDs.

Empty, `none`, `no`, `n/a`, `na`, `nil`, `nothing`, `no allergies`, `none known`, `-`, `--` and `.` mean empty, case-insensitively and with surrounding whitespace/punctuation. Other answers are preserved verbatim apart from whitespace trimming, with a 500-character limit. A new empty form answer creates no row; an empty office edit retains an office row to prevent future copying from restoring the flag.

## Disclosure

The office reads the note only on the class roster, the student's allergy field read/save, and the class CSV. Teachers read it only in their assigned-class roster/list and attendance register, including the response to saving the register. Teacher payloads carry only the note, without its source, response ID or editor. The badge says **Allergy**, has the note in its accessible name/title, and shows the words on focus, keyboard activation or tap. Teacher access is read-only.

“Assigned” is the existing `group_staff` relationship: `Group::ledBy` filters lists, and `teacher.leads`/`GroupAudience::isLeaderOf` fences individual class reads. A teacher restricted to some subjects still sees the whole class's health flags. A timetable meeting's teacher link alone grants nothing. Tenant membership still fences two-school teachers, and only current student memberships disclose a note to a teacher. A move/withdrawal retains the contact's note; former teachers lose the note when the student is no longer current in their class.

Family/guardian reads, child mode, device/member/mobile APIs, public pages, other exports, notifications and emails never load this table. The Contact model has no health relation or append. `TeacherController::student` and `FamilyController::student` are unchanged. A note is attached only at the named read boundaries; teacher attendance history, gradebook, subject work, resources, report cards, points, class store and avatar answers retain their prior projections.

Reviewed serializers: Contact's array/JSON serialization; office ContactsController and roster mutation/consent/withdrawal/move answers; FamilyController, FamilyAuthController, MeController, GroupsController, StudentSessionController, family awards/grades/letters/hifz/report cards/resources/threads/class-store projections; MemberAuthController, MemberAccountController, MemberPurchases/MemberRecurringGiving and device registration/update/masjid projections; public offering registration/page responses; SchoolRecordsExportController datasets; form-response list/roster/export and notification/mail contact readers. None gained a health relation or health reader.

## Storage and lifecycle

`student_allergies`: `id` primary key; `masjid_id`; `contact_id`; nullable `allergies` TEXT; `source` (`form` or `office`); nullable `form_response_id`; nullable `changed_by_user_id`; `created_at`; `updated_at`. Unique `(masjid_id, contact_id)` uses the short index name `student_allergy_school_contact_uq`. The model is tenant-scoped. Health text is encrypted with the application's key; the plaintext limit is enforced before storage, and write SQL bindings contain ciphertext. Provenance IDs survive retirement of a response or staff account. Console copies have a null editor ID and source `form`.

Soft contact deletion retains the note. Permanent contact deletion cascades it. A merge permanently deletes the absorbed contact's note and leaves the survivor's own note unchanged; it never transfers health information onto a different contact. If the absorbed note is needed, resolve it on the intended surviving student's record before merging. This intentionally differs from moving roster/financial records: a contact merge is not sufficient evidence to assign a child's health note to another contact.

Deleting an app login follows the existing account-deletion rules. A roster row, even withdrawn, already preserves the contact and its note. If all records that keep the contact have been removed and the contact is erased, the health row follows its FK cascade; it does not add an erasure-blocking SQL query while off. `MemberAccountDeletion::CASCADE_RECORDS` classifies that decision. A health row survives removal of its roster row until the contact itself is erased. No membership-retention hook is needed because the key is the contact.

Staging scrub drops all health rows via `config/staging_scrub.php`.

## Verification

`tests/Feature/StudentAllergiesTest.php` pins normalization, copy/preview/precedence/idempotence, edit/clear, role/tenant/class fences, limited and two-school teacher access, timetable-only refusal, lifecycle, encryption and literal OFF SQL/payload. The flagged-student walk exercises 23 family GET routes, one child-mode GET and five member GET routes, successful device/public responses and all existing school-records export datasets, asserting absence of the note and health keys.

At both 3 and 30 students, measured request SQL counts are office roster 10 → 11, teacher class roster 12 → 13 and register 11 → 12. Removing the one health SELECT gives the exact OFF SQL list. The student-details OFF fixture covers its actual separate birth-date read; the details panel itself uses the office roster with no request.

Mounted tests compile the real badge, field and copy components, and the exact office/teacher/register name templates extracted from their SFCs. They exercise keyboard/tap note visibility, absence for empty/none, source/date display, save/clear and preview/results. Responsive rules use logical sizes/alignment, keep the badge on the first line and let the name wrap at 390/320. The guard includes every touched SFC and rejects bare nested templates.

Local tests and a production bundle build do not verify real-browser geometry, assistive technology, MySQL, CI, staging or production; those remain separate checks.

Final local gates, run sequentially:

- Requested Pest filter: **2874 passed (35060 assertions)**, **Duration: 189.61s**.
- `npm run -s test:spa`: **tests 1955**, **pass 1954**, **fail 0**, **skipped 1**, **duration_ms 171226.799333**.
- Exactly one `npm run build`: **1489 modules transformed**, **built in 9.69s**, exit 0. Inspected emitted office/teacher code, accessible badge and logical/responsive styles; removed `public/build`. Existing chunk-size warning remains.

Existing fixtures were not changed. No CAPTURE variable, formatter, commit, CHANGELOG edit, deployment or production switch change. The route privacy walk includes unseeded detail routes returning 4xx; core family/child-mode and device/public reads explicitly assert successful responses. Notifications/mail were inspected in code, rather than delivered.
