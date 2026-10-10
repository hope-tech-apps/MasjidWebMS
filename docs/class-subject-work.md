# Class subject work: notes, pieces and marks

Steps 2 and 3 provide subject notes, pieces and marks in the teacher and office screens. Step 4 adds optional family sharing behind a separate school grant. Report-card summaries remain a later step.

Steps 2 and 3 provide subject notes, pieces and marks in the teacher and office screens. Report-card references and subject-line limits are provided separately by the dependent step 5 grant below. Family sharing is a separate build.

## The switch

`class_subject_work` is a per-school grant in the `school` group. Its default is false for every organisation type. It uses the existing `listed_when_off` and `catalogue_when_off` visibility rules: the grant stays out of the capability map, raw serialised overrides, catalogue and switch history while off. Studio does not offer it at creation.

`SchoolSettings::classSubjectWork($school)` is true only when **both** `class_subjects` and `class_subject_work` are true. The audited capability writers refuse an explicit work enable when the intended class-subjects value is false. `ClassSubjectMode::workEnabled()` reuses the organisation row already memoised for the HTTP request; it makes no additional switch query on the existing teacher bootstrap, lesson list or gradebook list. With work off those responses and SQL lists match parent `b504a492` literally, pinned in `SubjectWorkOffTest` and its new fixture.

Every new route has `capability:class_subject_work`. Its refusal is 404 even for a SuperAdmin, and it checks the dependency as well. The shared class bootstrap adds `class_subject_work_enabled: true` only while both grants are on; OFF omits it. No existing route is gated differently.

### Enable or disable one school

Prepare and review the change first; production execution requires the owner's approval for that specific action.

1. If class subjects are not yet initialised, review `php artisan class-subjects:initialize --masjid=<school_id> --dry-run`, then run `php artisan class-subjects:initialize --masjid=<school_id> --enable` after its report is clean and the activation is approved. Do not bypass its readiness checks.
2. A SuperAdmin enables work through the existing audited endpoint: `PATCH /api/admin/masjids/{school_id}/capabilities/class_subject_work` with `{"enabled": true}`. The bulk capability writer enforces the same dependency, including mixed calendar changes.
3. To stop offering work for this school, PATCH that endpoint with `{"enabled": false}`. Notes, pieces and marks remain stored.
4. To disable class subjects themselves, review `php artisan class-subjects:disable --masjid=<school_id> --dry-run` and its access warnings, then use that command under the existing approval protocol. Its output counts the subject notes, pieces and marks that stay stored. Class-subject work becomes ineffective while class subjects are off; its stored grant is retained, so reactivating class subjects restores work if the grant is still on. Switch work off separately when that is not desired.

The initializer has no new saved-text mapping to do: all three new tables already name permanent class subject IDs. Reactivating never remaps or deletes their rows.

## Tables

Three additive CREATE TABLE migrations use an `id` primary key, named indexes and foreign keys shorter than 64 characters. The three subject-work CREATE TABLE migrations have not run on a real system and include the Build C guide identity. The live class-subjects table gains its list through a separate nullable JSON migration, with MySQL `ALGORITHM=INSTANT` and no default.

| Table | Meaning and constraints |
|---|---|
| `subject_pieces` | A snapshot, or an own piece. Source is `guide`, `plan` or `own`. Guide identity is unique on `(class_subject_id, guide_subject, grade_label, week_no)`; plan identity on `(class_subject_id, lesson_plan_id)`. Guide grade spelling is copied, and subsequent reads resolve grade aliases with `GradeLevel::key()`. A removed plan sets `lesson_plan_id` NULL and leaves its piece and marks. Retiring the creator sets its user ID NULL. Microsecond timestamps preserve marking order. |
| `subject_piece_marks` | Unique `(subject_piece_id, group_membership_id)`. Level is nullable 1..4, comment nullable. No row means unmarked. Saving both empty deletes that student's row. Piece and membership hard deletion cascade; retiring the marker sets its user ID NULL. |
| `subject_notes` | Body about one class membership, or a whole-class update when membership is NULL. Plain body replacement and deletion, no history. Membership hard deletion cascades so a child's note cannot become a whole-class update. Withdrawing a student does not delete notes. Retiring the author sets its user ID NULL. |

Every model uses `BelongsToMasjid`. Organisation, subject and membership IDs are derived or resolved on the server. `shared_with_family` defaults to false on notes and marks, is excluded from writable fields and responses, while sharing is OFF, and any teacher write sending true (including string `"true"`, or a mark-row flag) is refused with 422. The sharing-ON contract is below.

Staging scrub anonymises pieces' `title` and `detail`, marks' `comment` and notes' `body`. It conservatively scrubs every title, including copied source titles, because own titles can name children.

## Fences and routes

Teacher base: `/api/teacher/masjids/{masjid_id}/groups/{group_id}/subjects/{subject_id}`.

| Method | Suffix | Action |
|---|---|---|
| GET | `/work` | Complete subject page data |
| GET | `/notes` | Notes newest first, with student/Whole class, author and timestamps |
| POST | `/notes` | Create a note; optional `group_membership_id`, required `body` |
| PUT | `/notes/{note_id}` | Replace `body` only |
| DELETE | `/notes/{note_id}` | Delete a note |
| POST | `/pieces` | Create an own piece: required `title`, optional `detail` |
| PUT | `/pieces/{piece_id}` | Edit an own piece in place |
| DELETE | `/pieces/{piece_id}` | Delete an own piece, requiring `mark_count` |
| PUT | `/marks` | One atomic save of the submitted students' marks for one piece |

All teacher routes retain auth, staff token, tenant and `teacher.leads` middleware. `SubjectWorkController::context()` resolves the subject through its class and uses the **one** `SubjectFence::allowsWork()` ID predicate. A foreign organisation/class/child/piece/note/plan ID is 404; a visible subject outside a teacher's limit is 403; a hidden subject is 404 for teachers. Both school grants must be on.

The office has only GET `/work` and GET `/notes` at the same base under `/api/admin`, behind admin, tenant, CRM, `permission:view contacts` and the work capability. The work payload is shared with teachers, with office-only advice when a subject follows no curriculum. Office reads include hidden subjects when addressed directly; teacher reads do not. No office writes were added.

Writes recheck capability, current class, visibility, assignment and students under existing organisation/class/subject primary-key locks, in that order. Current roster membership is required for new notes and every mark save. Existing withdrawn notes remain readable and their bodies can still be corrected; withdrawn marks remain stored but cannot be edited. Deletion confirmation counts all marks, including departed students. A mismatched count returns 409 with `mark_count` and requires confirmation again.

## Copy, never point

Curriculum and linked lesson plans are listed live without making pieces. A first meaningful level or comment creates a piece and copies its words. Empty first saves create nothing. Later guide imports, plan edits, subject renames and plan deletions do not change copied words. Guide snapshots use focus as title, assessment note as detail, and copy grade label, entry number, quarter and standard code. A plan title is its ISO date followed by objective, or title, or activities in that order.

The source's required title must fit the specified 255-character snapshot column. Overlong titles, grade labels or standard codes are refused with a readable 422 rather than silently truncating words or relying on SQLite's permissive VARCHAR handling.

Save `/marks` with `source` and one of:

- `guide`: `guide_subject`, `grade_label` and `week_no`, or an existing `piece_id`. A single followed subject can still supply the name for an older caller; several choices require an explicit name. The server checks following only before the first mark; an existing piece can be updated after unfollowing.
- `plan`: linked `lesson_plan_id`, or an existing `piece_id` (including a piece whose plan was deleted).
- `own`: existing `piece_id`.

Include `marks: [{"group_membership_id": ..., "level": 1..4 or null, "comment": "..." or null}]`. Omitted students are unchanged; only explicit empty rows clear marks. A duplicate membership ID is refused. Wrong-grade guide marks are 422. Blank comments are empty; a comment alone is a meaningful mark. An empty marks array is a no-op for an existing piece. Submitted source must match an addressed saved piece.

A unique key and Laravel `createOrFirst()` recover a competing first insert inside a savepoint. If InnoDB's repeatable-read view cannot see that winner, a current read of the proven unique tuple recovers it. There is no speculative lock on a missing guide/plan range. The organisation/class mutex also serialises normal API writers against visibility/roster changes and own-piece deletion.

## Subject page data

`data` includes `subject`, `levels` from the report-card authority `PerformanceLevel::key()`, current `students`, `curriculum`, `lesson_plans`, `own_pieces` and `notes`. Entries/pieces have `piece_id`, copied or live `title` and `detail`, `marks` and `mark_count`; guide entries also include their own `week_no`, grade, quarter and standard code. Unmarked students have no mark row.

Each curriculum block corresponds to one current grade key, contains only that grade's students and orders by the office's followed subject list, then entry number. Its heading uses the first followed guide column's grade label, falling back to the roster only when there are no live guide rows. Opening uses the piece most recently meaningfully marked for that grade, even if its mark rows were later cleared; otherwise the first entry in that order. No calendar arithmetic or school-year lookup occurs. `GET /work?grade_label=1st&week_no=4&guide_subject=Science` selects a particular entry. Omitting the subject is accepted only when the number identifies exactly one entry for that grade. Blocks include `opening_guide_subject` and `selected_guide_subject` alongside their existing number fields. Saved entries removed by reimport or by changing the following list remain listed and reachable by `piece_id`. Retained guide subjects come after the current choices, in first-piece order, then entry number. Marked plan pieces remain listed after plan deletion. Lessons are ordered newest date first; own pieces newest creation first; note edits do not reorder original creation dates.

Read queries are bulk operations, independent of roster and guide size. The cold teacher HTTP page count is pinned at 18 for 1 and 30 current students, 1 and 40 guide entries, with a saved piece, and with two followed guide subjects and 80 entries. The SQL list is recorded in `artifacts/subject-work-query-count.json`. This includes the existing authentication/class/subject fences as well as the page reads; user relation caches can reduce the count on later requests in the same test process.

Local evidence and limitations are in `artifacts/subject-work-server-report.md`. MySQL grammar compilation is checked without connecting; actual MySQL contention and production operation require separate verification.

## Subject screens (Build B)

When bootstrap has both `class_subjects_enabled: true` and `class_subject_work_enabled: true`, teacher and office subject pages render the four work blocks below the existing tool. Work OFF keeps the existing no-tool sentence and sends no new bootstrap/page requests. `resources/vue-app/views/teacher/subject/` contains the shared components; office uses `readonly` and the admin GET routes only.

Teachers choose the server's numbered curriculum entries for each grade, open linked plans or own pieces, and save one entry's levels/comments together. Pressing a selected level clears it. Drafts remain until saved or explicitly discarded when changing entry or class line. Note/piece forms preserve text on failed saves. Piece deletion includes the server count and asks again after a 409 count change. Family sharing is offered only when the separate sharing grant is effective.

The work payload serves `wording_changed` and `marked_against_date` for marked guide entries, comparing copied words against the current guide. Removed or unfollowed entries keep their copied words and date.

Local evidence and limitations: `artifacts/subject-work-screens-report.md`. Mounted tests do not measure actual phone overflow or touch-target geometry.


## Multiple curriculum subjects (Build C)

A class subject follows an ordered list of the school's curriculum subjects. `ClassSubject::followedGuideSubjects()` reads `guide_subjects` when non-null; NULL falls back to the existing `guide_subject` or an empty list. The office list API exposes the raw nullable list, and every list writer keeps the single field equal to its first name or NULL. The list is hidden in existing teacher serialization so the work-OFF teacher payloads and SQL remain unchanged. Initializing a class or adding a subject keeps the existing single-match choice and leaves the list NULL.

In Class subjects, edit a subject and tick **Follows the curriculum for**. The checklist is in name order and hints at the guide's own grade labels with entries for the class's current roster grades. Zero choices explicitly follows nothing. Several class subjects may follow the same guide subject. The checklist and manager API are available independently of `class_subject_work`, under the same office authorization as all manager writes.

The manager save accepts `guide_subjects: ["Science", "Joint studies"]`. Each name must exist in this school's guide. Duplicates, non-lists and invalid names are 422. If a request also includes `guide_subject`, it must equal the first list item (or NULL for an empty list). An older update supplying only the single field replaces the list with that one choice or an empty list; omitted fields preserve the saved choice. Office list metadata retains `guide_subjects` (the ordered distinct names) and adds `guide_subject_grades` (names to relevant guide grade labels).

Guide pieces record `guide_subject`. Same-number entries in separate guide subjects stay separate. No curriculum row is merged, split or rewritten. A grade block containing several guide subjects shows each name in its chooser and heading after the entry number and focus. One guide subject keeps the existing wording. Saved work stays listed after unfollowing; new marks on an unfollowed guide are refused. If a subject follows nothing, the office sees “This subject follows no curriculum. Choose one under Class subjects.” Teachers receive no such advice; retained marked entries, when present, still appear.

`class-subjects:initialize --dry-run` and `class-subjects:audit` report curriculum coverage per class: visible subjects following nothing where the guide serves the class's current grades, and guide subjects serving those grades with no visible follower. The initializer diagnoses its proposed list before initialization and the actual list afterwards. Hidden subjects do not count as followers. Both sections contain counts and subject names only and make no writes.

Local Build C evidence and unverified browser/MySQL checks: `artifacts/build-c-report.md`.


## Review fixes (Build D)

Marks saves submit only students whose level or comment changed from the loaded/last-saved values. An unchanged editor disables Save. Every submitted row carries `updated_at`: the exact UTC ISO timestamp served on its mark, or NULL if no mark existed. A missing version is 422. The server compares every submitted version under the existing organisation/class write mutex before writing marks. Any mismatch returns 409 with `students: [{group_membership_id, name}]` and rejects the entire request, including otherwise valid rows. Successful PUT responses include `data.piece_id` and `data.marks: [{group_membership_id, updated_at}]` for submitted rows; cleared rows return NULL. These versions reach the parent immediately with the accepted values, before the optional wording read. Mark counts include untouched and departed marks. The wording read updates wording only, and stale read responses cannot replace a later save or draft.

Mark timestamps currently store whole seconds. A saved row receives the later of the current whole second, its previous version plus one second, and the piece's retained watermark plus one whole second, so rapid saves still produce distinct versions without a schema change. The timestamp serves as a version and can briefly lead wall-clock time during rapid edits. The piece timestamp retains the latest version across mark clearing/recreation and its own wording corrections. No historical rows or deletion tombstones are retained.

409 marks show “Someone else changed this mark. Reload to see it.” beside each affected student. Save stays disabled until Reload resolves the conflict. Reload explicitly replaces this piece editor's draft with the latest saved marks; typing remains visible until that read succeeds, and failed reads retain it. Other entry/grade/note/piece drafts remain untouched.

Deleting the note or own piece currently being corrected names that correction in the deletion confirmation. Cancellation and failed deletion retain the correction; successful deletion closes that form and drops its draft. The existing mark-count re-confirmation remains in place.

Validation messages for mark levels/comments, note body/student and own-piece title/detail sit beside their controls, with `aria-invalid` and message IDs referenced by `aria-describedby`. Mark request indices map through the submitted rows to membership IDs. Both this application's `data` validation envelope and Laravel's `errors` envelope are supported. Errors with no matching field retain a general alert. Subject controls have component-scoped 44px minimum phone targets; shared performance help owns its summary target, including Grades and Reports while work is off. Copied wording comparisons trim title, detail and standard code and treat NULL as empty text.

Evidence, rewritten ON tests and verification limits: `artifacts/build-d-report.md`.


## Family sharing (step 4)

`class_subject_sharing` is a new grant in the school group, false by default for every organisation type and hidden while ineffective. `SchoolSettings::classSubjectSharing()` requires all three grants: `class_subjects`, `class_subject_work`, and `class_subject_sharing`. The audited single and bulk capability writers refuse explicit sharing enables without both dependencies. Disabling a dependency preserves the stored sharing grant and records; re-enabling the dependency restores effective sharing. Disable sharing separately when that is not desired.

Enable using the existing audited PATCH `/api/admin/masjids/{school_id}/capabilities/class_subject_sharing` with `{"enabled":true}`, after the two dependencies are on. The ordinary capability map, raw overrides, catalogue, Studio and switch history hide it while off. Production execution remains a separate owner-approved action.

The reader reuses the complete organisation row already held by `ClassSubjectMode` for the request. Sharing OFF keeps the existing work projections, mark-save SQL, teacher/office screens and family/child payloads. `ClassSubjectSharingOffTest` pins literal SQL and JSON from 8b6d8f09 with work ON/sharing OFF and both OFF, using `tests/fixtures/subject-sharing-off-8b6d8f09.json`; existing OFF tests and fixtures are unchanged. Its middleware probe also proves the sharing decision adds no query to teacher, family or child requests.

Sharing ON: every mark row and note has its own **Share with the family** choice. New records start unticked. Beside the choice: **This student's family can read this.**, or **Every family in this class can read this.** for a whole-class note. No share-all control exists. Mark saves send only changed students, including a sharing-only change, with the same `updated_at` conflict check. The flag travels in that atomic save. A level or comment edit without a flag preserves existing sharing; an empty mark is deleted, and its next draft starts unticked. Note body corrections likewise preserve sharing when the flag is omitted. Browser string booleans are validated and normalized; invalid values are refused. Staff work reads serve the boolean only when sharing is effective; office views show **Shared with the family**.

### Family routes and disclosure

All new routes are GETs below `/api/family/masjids/{masjid_id}/groups/{group_id}/members/{membership_id}/subjects`, inside the existing parent-token stack, with `capability:class_subject_sharing`:

| Suffix | Response |
|---|---|
| `/` | Visible subjects with shared marks and notes for this child |
| `/{subject_id}/marks/{mark_id}` | One already-shared mark, or 404 |
| `/{subject_id}/notes/{note_id}` | One already-shared note, or 404 |

The organisation is bound from the authenticated guardian's credential; a foreign organisation URL is 403, and a foreign group/child/subject/item ID is 404. Child membership resolution stays inside the named class. The per-child read reuses `GroupAudience::mayReceiveThread()` with a resolved participant target. Bulk reads reuse the same participant contact target set as conversations and the existing child-record query fence. Whole-class notes use exactly `GroupAudience::mayReceive(..., DISCLOSURE_FEED)`, including consent, provenance and leaving-date rules. Hidden subjects and unshared records never enter the shared projection; item lookup happens only inside that projection.

The family class-detail payload adds `children[].subjects` only when sharing is effective. Its class-list and student-mode payloads remain unchanged. Under each child the portal shows each shared subject's name, copied piece title, localized report-card level, teacher comment, dates and notes. No roster or staff author details are added. Three bulk record reads plus fixed audience checks cost the same number of queries regardless of child or item count. Cold authenticated class detail is pinned at **20 queries** for 1/3 children and 1/30 subjects with shared marks and notes; each measurement includes the token's last-used update.

Withdrawal and class moves preserve the old membership and confirmed guardian edges. Report cards remain readable there through `FamilyController::subject()`, so shared marks and child notes do too. A real roster-move test verifies both published report cards and shared work stay under the old class. Class-wide notes follow the current Class Story entitlement and stop when that entitlement ends.

No subject disclosure is stored in a family store, localStorage, service worker or server response cache. New routes and the sharing-ON class detail use `private, no-store`. The Subjects projection is cleared and re-read when the parent returns to the child tab or foregrounds the page; failed revalidation keeps the old disclosure cleared. The class list does not carry subject work. An already-open screen learns a remote change on its next read/revalidation; this feature adds no push channel.

### Translation and localization

Shared note bodies and mark comments are offered on the existing family class **Translate** button, through `useContentTranslation` and `/translations`. Existing availability checks, per-item/request limits, batching, notices, throttles and tenant/source-hash `content_translations` caching apply unchanged. Copied piece titles and subject names are never sent. Translation keys include the child, item kind and ID; mark versions use the saved date and notes use a SHA-256 of the disclosed body so same-second corrections cannot keep an earlier translation. Cached translations do not cache subject entitlement or cause an item absent from the latest payload to render.

The only new family locale key is `subject_work_heading`, in all six languages. Spanish, Urdu, Pashto and Dari entries carry the existing MACHINE-DRAFTED warning and require human review. Existing report-card level keys and date locales are reused. The Subjects layout wraps text, uses logical alignment and keeps source text `dir="auto"`; actual 320/390px browser geometry remains unverified locally.

Evidence, route references, all six strings and printed gate totals: `artifacts/subject-sharing-report.md`. No schema migration or free-text column was added.

## Report-card references and subject-line limits (step 5)

`class_subject_report_summary` is a school grant, default false and hidden while ineffective. `SchoolSettings::classSubjectReportSummary()` requires both class subjects and subject work. Both audited capability writers refuse enabling it without the intended dependent grants. The request decision reuses the existing organisation memo. Switching work off makes this grant ineffective without deleting its stored value or any marks.

Teachers can still read the whole report card. Staff references appear only beside academic subjects whose frozen report-card name matches a visible class subject's current name through `SubjectKey::for()` and the initializer's two alias pairs (Arabic/Arabic Language and ELA/English Language Arts). Curriculum links and previous names do not match. The reference groups this child's non-null levels by 4/3/2/1 in one SQL read. It never changes report levels or comments. Family serialization and PDF data do not use it.

A card with `school_term_id` and dated terms enabled uses inclusive term dates. Plan pieces use the live plan's session date, or the first ten characters of their copied ISO-date title after plan removal. Guide and own pieces use the mark's `updated_at` day in the school's calendar time zone: local start-day midnight inclusive to midnight after the end day exclusive, converted to UTC. Other cards count all retained levels, including withdrawn students' marks. A missing linked term falls back to the year-to-date heading and all marks.

A limited teacher can save only matching visible subjects allowed by `SubjectFence`'s immutable ID predicate. A matching hidden subject requires unlimited authority. Unmatched subjects, learning behaviours and the overall comment remain class-wide. One closed submitted line refuses the entire request with its subject sentence, including comments and preparation's template writes. ON saving runs inside preparation's existing transaction; OFF retains the original separate preparation/save transactions and SQL. Publishing and unpublishing remain class-wide. The teacher screen already submitted changed rows only; closed controls and keyboard editing are disabled and closed rows are excluded from dirty saves.

The base worktree had no office report-card surface. Step 5 adds only a dark office read-only Reports line and GET `/api/admin/masjids/{school}/groups/{class}/report-cards` and `/members/{membership}/report-card`. These require the new effective grant, CRM and `view contacts`. They read existing cards only, including drafts and started withdrawn students' cards; they create no template rows and add no marking or publishing routes. Subject links stay in each viewer's own class view with `?subject=<id>`.

Evidence, the office scope assumption, literal OFF comparisons and verification limits: `artifacts/subject-report-summary-report.md`.
