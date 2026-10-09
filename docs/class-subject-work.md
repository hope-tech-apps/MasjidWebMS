# Class subject work: notes, pieces and marks

This server build provides steps 2 and 3. It adds no screens, family sharing or report-card summaries.

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

Three additive CREATE TABLE migrations use an `id` primary key, named indexes and foreign keys shorter than 64 characters. Existing migrations are unchanged.

| Table | Meaning and constraints |
|---|---|
| `subject_pieces` | A snapshot, or an own piece. Source is `guide`, `plan` or `own`. Guide identity is unique on `(class_subject_id, grade_label, week_no)`; plan identity on `(class_subject_id, lesson_plan_id)`. Guide grade spelling is copied, and subsequent reads resolve grade aliases with `GradeLevel::key()`. A removed plan sets `lesson_plan_id` NULL and leaves its piece and marks. Retiring the creator sets its user ID NULL. Microsecond timestamps preserve marking order. |
| `subject_piece_marks` | Unique `(subject_piece_id, group_membership_id)`. Level is nullable 1..4, comment nullable. No row means unmarked. Saving both empty deletes that student's row. Piece and membership hard deletion cascade; retiring the marker sets its user ID NULL. |
| `subject_notes` | Body about one class membership, or a whole-class update when membership is NULL. Plain body replacement and deletion, no history. Membership hard deletion cascades so a child's note cannot become a whole-class update. Withdrawing a student does not delete notes. Retiring the author sets its user ID NULL. |

Every model uses `BelongsToMasjid`. Organisation, subject and membership IDs are derived or resolved on the server. `shared_with_family` defaults to false on notes and marks, is excluded from writable fields and responses, and any teacher write sending true (including string `"true"`, or a mark-row flag) is refused with 422. No sharing reader is implemented.

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

The office has only GET `/work` and GET `/notes` at the same base under `/api/admin`, behind admin, tenant, CRM, `permission:view contacts` and the work capability. The payload is shared with teachers. Office reads include hidden subjects when addressed directly; teacher reads do not. No office writes were added.

Writes recheck capability, current class, visibility, assignment and students under existing organisation/class/subject primary-key locks, in that order. Current roster membership is required for new notes and every mark save. Existing withdrawn notes remain readable and their bodies can still be corrected; withdrawn marks remain stored but cannot be edited. Deletion confirmation counts all marks, including departed students. A mismatched count returns 409 with `mark_count` and requires confirmation again.

## Copy, never point

Curriculum and linked lesson plans are listed live without making pieces. A first meaningful level or comment creates a piece and copies its words. Empty first saves create nothing. Later guide imports, plan edits, subject renames and plan deletions do not change copied words. Guide snapshots use focus as title, assessment note as detail, and copy grade label, entry number, quarter and standard code. A plan title is its ISO date followed by objective, or title, or activities in that order.

The source's required title must fit the specified 255-character snapshot column. Overlong titles, grade labels or standard codes are refused with a readable 422 rather than silently truncating words or relying on SQLite's permissive VARCHAR handling.

Save `/marks` with `source` and one of:

- `guide`: `grade_label` and `week_no`, or an existing `piece_id`.
- `plan`: linked `lesson_plan_id`, or an existing `piece_id` (including a piece whose plan was deleted).
- `own`: existing `piece_id`.

Include `marks: [{"group_membership_id": ..., "level": 1..4 or null, "comment": "..." or null}]`. Omitted students are unchanged; only explicit empty rows clear marks. A duplicate membership ID is refused. Wrong-grade guide marks are 422. Blank comments are empty; a comment alone is a meaningful mark. An empty marks array is a no-op for an existing piece. Submitted source must match an addressed saved piece.

A unique key and Laravel `createOrFirst()` recover a competing first insert inside a savepoint. If InnoDB's repeatable-read view cannot see that winner, a current read of the proven unique tuple recovers it. There is no speculative lock on a missing guide/plan range. The organisation/class mutex also serialises normal API writers against visibility/roster changes and own-piece deletion.

## Subject page data

`data` includes `subject`, `levels` from the report-card authority `PerformanceLevel::key()`, current `students`, `curriculum`, `lesson_plans`, `own_pieces` and `notes`. Entries/pieces have `piece_id`, copied or live `title` and `detail`, `marks` and `mark_count`; guide entries also include their own `week_no`, grade, quarter and standard code. Unmarked students have no mark row.

Each curriculum block corresponds to one current grade key, contains only that grade's students and is ordered by entry number. Opening uses the piece most recently meaningfully marked for that grade, even if its mark rows were later cleared; otherwise the lowest entry number. No calendar arithmetic or school-year lookup occurs. `GET /work?grade_label=1st&week_no=4` selects a particular entry. Saved entries removed by reimport remain reachable by `piece_id`. Marked plan pieces remain listed after plan deletion. Lessons are ordered newest date first; own pieces newest creation first; note edits do not reorder original creation dates.

Read queries are bulk operations, independent of roster and guide size. The cold teacher HTTP page count is pinned at 18 for 1 and 30 current students, 1 and 40 guide entries, and with a saved piece. The SQL list is recorded in `artifacts/subject-work-query-count.json`. This includes the existing authentication/class/subject fences as well as the page reads; user relation caches can reduce the count on later requests in the same test process.

Local evidence and limitations are in `artifacts/subject-work-server-report.md`. MySQL grammar compilation is checked without connecting; actual MySQL contention and production operation require separate verification.

## Subject screens (Build B)

When bootstrap has both `class_subjects_enabled: true` and `class_subject_work_enabled: true`, teacher and office subject pages render the four work blocks below the existing tool. Work OFF keeps the existing no-tool sentence and sends no new bootstrap/page requests. `resources/vue-app/views/teacher/subject/` contains the shared components; office uses `readonly` and the admin GET routes only.

Teachers choose the server's numbered curriculum entries for each grade, open linked plans or own pieces, and save one entry's levels/comments together. Pressing a selected level clears it. Drafts remain until saved or explicitly discarded when changing entry or class line. Note/piece forms preserve text on failed saves. Piece deletion includes the server count and asks again after a 409 count change. Family sharing is not offered.

The existing work payload preserves copied wording but lacks its copy date and a flag comparing it with current guide words. Build B can render optional `wording_changed` and `marked_against_date` fields if supplied; these are a pending read-contract addition, not fields currently served by Build A. The dated changed-wording notice therefore remains blocked on the contract clarification; no date is guessed.

Local evidence and limitations: `artifacts/subject-work-screens-report.md`. Mounted tests do not measure actual phone overflow or touch-target geometry.
