# School timetable

Step 1 gives the office period sets, optional locations, a class's week and computed clashes. Step 2 adds the teacher portal's read-only **My schedule**. Families have no timetable routes or screens. No courses, credits, rotating days, automatic placement, printing or exports are included.

## Switch and roles

`school_timetable` is a school grant, default false for every organisation, hidden from payloads, catalogue, history and navigation while off. Its routes return 404 even to a SuperAdmin while off. The audited `CapabilityWriter` refuses enabling it outside a school or without `school_calendar`. A combined write may enable both. Dated terms and class subjects are optional. The calendar cannot be switched off while the timetable remains on.

The office roles are the calendar's roles: MasjidAdmin with membership in the addressed school and SuperAdmin. The calendar has no separate view/edit permission; neither does the timetable. The admin and tenant middleware fence every route before the timetable gate. Teacher and family tokens cannot enter this realm.

## Reference data

A named period set belongs to one school year. Its rows have stable IDs, names, ordered positions, school-local start/end clocks and `teaching` or `block` kinds. Clocks never undergo timezone conversion. Rows must end after they start and cannot overlap within a set. Blocks may carry activities or whole-class meetings; subjects require teaching rows.

Each meeting weekday uses one school set; a class may override it. Meeting weekdays come from `SchoolDateAuthority`, including the calendar's single-weekday fallback while dated terms are off. A set change carries existing meetings to the new set's rows by position for the entire year; it refuses missing positions or subject meetings mapped to blocks. Times and row order also apply to the whole year. The screen says “This changes the times for the whole year.”

Locations belong to the school, with a case-insensitive unique name, optional positive capacity and active flag. Inactive locations remain readable but cannot be newly assigned. `timetable_class_rooms` holds usual locations, keeping `groups` unchanged. A meeting's NULL room resolves through this table. Usual location changes are undated and affect every meeting resolving that room, including past views.

## Dated meetings

A meeting names one class, weekday and period row, with exactly one of a class subject, a named activity of at most 60 characters, or the whole class where class subjects are off. Subject choices must belong to the class and be visible. Teachers are live Teacher staff accounts with a teacher membership at this school, independent of their class assignments. Defaults offered come from subject assignments, otherwise the class's teachers. Subject and whole-class meetings require at least one teacher; activities may have none.

Creating requires `effective_from`, offered as school-local today or the year's first day, whichever is later. Optional `effective_until` must be within the year and on or after the start. Changing later closes the previous row the day before and creates another. A change on or before its start edits that row from the chosen date. Removing a not-yet-started row from on or before its start hard-deletes it; all other removals retain the row and end it the day before the removal date. Removing from before an already-started row's start leaves an empty range, retaining its identity. Nothing started is hard-deleted by the timetable API.

Two rows for the same class, weekday and period cannot overlap in dates, even with clash confirmation. Copying a day uses another weekday of the same set, defaults to the same teachers/room, and opens the copied meetings from the chosen date through the year. Copy is atomic, including validation and warning failures.

## Clashes and reads

A first placement/change with clashes returns 409 `{status: "clashes", clashes: [...], clash_fingerprint: "..."}`. Each clash has `kind`, `id`, `name`, weekday, intersecting dates, and both meeting descriptions with class, label, teachers, room and clock times. The 409 includes `clash_fingerprint`, an HMAC of the normalized submission and canonical clash list. The screen resends the exact reviewed body with `confirm_clashes: true` and that fingerprint. If the body or clashes change, including when clashes disappear, a fresh 409 replaces the list and nothing is saved. Day copies use one fingerprint for the complete request/list and roll back all tentative rows on a warning or refusal. Confirmation never bypasses validation or duplicate refusal.

Teacher and resolved-room clashes compare overlapping clocks on the same weekday across sets, plus effective date ranges that contain that weekday. Touching boundaries are allowed. `GroupMembership::joiningDate` reuses the model's existing `joined_at` date cast, which the office attendance clip and roster moves already read. The protected legacy attendance method stays verbatim; timetable fallback dates use `membershipStart`. Student clashes use participant Contact identities on both rosters, intersecting each membership's joining date (the office's `joined_at`, then move-in date, then creation date) and leaving date (exclusive). Guardian edges do not count. The Clashes panel computes outstanding clashes as of the chosen date, including clashes introduced by whole-year setup changes.

`TimetableReader::meetings(school, year, date, lens, id, weekday)` is the shared server reader for `teacher`, `class`, `room` and `school`; it returns the week as of the supplied date, with optional weekday filtering. It executes exactly two queries regardless of class/meeting/teacher counts. `clashes` adds one bulk roster query, for exactly three. The teacher schedule also uses this bulk reader through `teacherVersions`, then resolves each displayed date against its own school year and effective range. The class week renders independent weekday columns with their own times, rather than a forced common row scale.

## Routes

Base `/api/admin/masjids/{masjid_id}/timetable`:

| Method | Suffix | Purpose |
|---|---|---|
| GET | `/` | School-local today and available years |
| GET | `/years/{year_id}/setup` | Bulk setup, classes, subjects and teacher choices |
| GET | `/years/{year_id}/week?group_id=…&as_of=…` | Class week (date defaults to today) |
| GET | `/years/{year_id}/clashes?as_of=…` | Computed clash list |
| POST / PUT / DELETE | `/years/{year_id}/sets[/{set_id}]` | Create, edit complete ordered rows, remove set |
| PUT | `/years/{year_id}/days` | Submitted school/class weekday mappings; NULL class mapping restores school's set |
| POST / PUT / DELETE | `/years/{year_id}/rooms[/{room_id}]` | School room reference data |
| PUT | `/years/{year_id}/classes/{group_id}/room` | Set/clear usual location |
| POST / PUT / DELETE | `/years/{year_id}/meetings[/{meeting_id}]` | Place/change/end dated meeting |
| POST | `/years/{year_id}/copy-day` | Atomic copy to same-set weekdays |

Every addressed year, class, set, period, subject, room and meeting is tenant-scoped; set/period/meeting IDs also resolve within the addressed year, subjects within the class. Submitted teachers resolve through this school's staff membership. Year writers lock the organisation then year and recheck the switch. The class Location writer takes the organisation lock and needs no year. GET readers do not write.

## Retention and scrub

Sets/period rows with any meetings cannot be deleted, and period removal names the number held. Deleting or archiving a class, deleting a class subject's actual row, deleting or changing the access of a referenced teacher account in school or global account operations, and removing its school membership are refused to preserve references, including historical rows. Hiding a subject through the existing office Remove action ends its meetings from school-local today, preserving earlier rows. Restoring that subject does not restore its timetable automatically. Rooms with explicit or usual-room meetings are kept. A school year with period sets is kept; year date/weekday edits cannot strand its timetable.

The hidden `has_timetable_records` booleans on `masjids` and `users` are retention hints, independent of grants. Their migration backfills existing rows; timetable row/link writers maintain them and deletions refresh them. A request-local positive never skips a durable marking write: an earlier nested transaction may have rolled back. The school hint reuses the existing request organisation memo; the account hint travels on the account already loaded by global routes. Missing hints before migration read as false, without schema probes. An unused OFF school and unreferenced global accounts preserve the base SQL list and payloads. Retained records are protected after switch-off. The flags are not PII-shaped; the staging scrub coverage gate confirms no new classification is required. Existing raw database snapshot comparisons assert the new flags are zero, then compare every legacy column against the unchanged fixtures; these internal columns do not enter public payloads.

There are eight additive tenant-scoped tables, all with `id` primary keys and short named indexes/FKs. No new `groups` column and no membership foreign key. The staging scrub's `name` token matches set/period/room names, room `name_key` and `activity_name`; all are anonymised conservatively, since typed names can identify people.

## Verification

`SchoolTimetableTest` builds full-time and weekend acceptance worlds, dated change and whole-class cases, rule/security/copy/removal tests and fixed query-count pins at two and twelve classes. `SchoolTimetableTenantIsolationTest` proves all eight model scopes and tenant stamps. `SchoolTimetableOffTest` compares literal SQL/payloads captured at 30cef6d4 and proves the switch decision adds no query. Existing OFF fixtures are unchanged. Literal global archive/delete SQL and payloads are pinned before and after migration; teacher removal SQL is pinned at unused OFF schools. The class page's rendered bytes and requests are pinned against 30cef6d4. Mounted tests edit a location, weekday mapping and class location, assert each weekday column independently, cover zero locations and replace stale clash lists. The fake phone-width test was removed: the mounted renderer has no CSS layout. Real browser geometry, touch and RTL remain separate verification work.

## Class names and optional Location

Slots name the class in every meeting/read/clash payload, including whole-class meetings. The class week and its editors name the class; meeting cells show a location only when one exists. Setup says locations are optional. No location is required before placing a class's timetable.

The class header offers **Location (optional)** only with the timetable on. Pick an existing suggestion or type a new name and save once; the school-scoped location creation and usual-location assignment are one organisation-locked transaction. Names match case-insensitively. Clearing the field unassigns it without deleting the location. No school year or visit to Timetable setup is needed. `GET` and `PUT /api/admin/masjids/{masjid_id}/groups/{group_id}/location` use the same office/tenant/timetable fences as the timetable routes and resolve an active class in that school. They disclose names/ids only; teacher and family tokens are refused, other-school ids miss, and OFF answers 404. The legacy group payload is unchanged.

## Dated range and retained-data rules

Removal only shortens a range; an earlier existing end stays. A change after the selected row's end refuses: “This meeting has already ended before that date. Choose the meeting live on that date.” It never silently operates on another row. A read between an old and new version has one row per slot.

Class archive/deletion, actual subject deletion, referenced account archive/deletion or access removal, referenced school membership removal, location deletion and year deletion use the same worded retention refusal after switch-off. Subject hiding while ON ends meetings from today; a hide while OFF is reconciled when switching back on. New timetable endpoints still answer 404 while OFF. Teacher removal with timetable ON and class subjects OFF takes the organisation mutex as its first transaction statement, ahead of the account lock and reference checks; an unused OFF school's statement list stays as on the base.

Both live capability writers reconcile retained ongoing/future rows while holding the organisation mutex before switching on. Hidden/missing subjects, archived/inactive/nonclass classes, teachers no longer live in the school, weekdays no longer met and missing/mismatched period-set mappings each **end the affected meetings from school-local today**, keeping earlier history. Existing earlier ends are never extended. The single-switch response adds `data.timetable_ended_meetings`; the bulk response includes that count in `meta`. The switch panel reports the count and that earlier rows were kept. There is no automatic reopening on later restore. Clearing an invalid override or restoring a weekday does not recreate ended meetings.

## Teacher: My schedule (step 2)

`GET /api/teacher/masjids/{masjid_id}/schedule?week=YYYY-MM-DD` supplies the complete page in one read. It is inside `auth:sanctum`, the Teacher-only realm, `EchoResolvedTenant` and `tenant`, then `capability:school_timetable`. OFF answers 404. No new switch or migration. An optional date picks its containing Monday-to-Sunday week; omitted means this week. Previous/next/this-week controls each perform one read. The response is `no-store`. Submitted teacher IDs never select a lens: the authenticated teacher ID is the only one used.

Today remains the school's current day while the week picker moves. SchoolDateAuthority supplies the calendar's local today, resolved timezone, year bounds, weekdays and named closures. Each displayed date uses only its containing year; a week may cross years. With dated terms OFF, use the calendar's legacy first-day weekday. A closure suppresses that day's meetings. The page says the school does not meet today, names a closure, says the year has not started/has ended, or says nothing is placed for the teacher yet. With no calendar years, the last state applies.

Only meetings explicitly naming this teacher in this school appear, including co-taught slots and duties on blocks. Leading a class alone grants no slot. Today's meetings are in start-clock order, with class, subject/activity, other teachers' names, and a location only when set. Whole-class slots name the class once. The grid has independent day columns sorted by clock time across period sets, rather than a shared period-ID scale. Empty periods and unassigned school blocks are absent. Columns include days with this teacher's live meetings, today when inside the selected week, and calendar closure days on meeting weekdays. Start/end changes falling inside the shown week carry translated date notes.

Slot links use `/teacher/classes/{group_id}?subject={id}` only when class subjects are ON, the subject is visible and the teacher's saved class-subject assignment/provenance permits it. Otherwise they use the class URL. Existing class/subject authorization still applies after navigation; being placed on a slot does not broaden roster or subject authority. A visiting teacher without a class assignment can read their schedule but the existing class gate refuses opening that class.

The calendar uses `masjids.timezone` through `FormStaffCodesController::timezoneFor`: unset, UTC or invalid zones retain the existing America/New_York fallback. The API returns that resolved zone. Start/end clocks remain school-local wall times. The page converts the device's current instant with Intl in that zone and compares HH:mm only on the returned today's date. It marks all currently overlapping meetings and the first future meeting as next, with the end boundary exclusive; the marker clock refreshes every 30 seconds without another request. Dates used in the picker and date labels use UTC calendar arithmetic, avoiding DST and device-zone date shifts. Reload/this-week refreshes today; no automatic midnight refetch is added.

The selected school's `/school` header and the legacy `/teacher/user` default-school object add `school_timetable_enabled: true` only while ON, using each already-loaded organisation row. OFF adds no key or query. The menu uses the selected-school header; school switching keeps the existing abort/reset/full-reload protocol. Literal base 15011d6c home/header/class-list SQL and payloads are in `tests/fixtures/teacher-schedule-off-15011d6c.json`; existing OFF fixtures remain unchanged. Page SQL is fixed at 11 queries with a fresh authenticated user (including existing tenant resolution and membership-seen handling), pinned at one/twelve classes, zero/many meetings and current/future/out-of-year week selections. Reusing a test user with cached relations omits one legacy ownership lookup; the pin resets those relations to model a fresh request.

The pre-existing teacher portal is English-only. To meet this step's explicit Arabic requirement, the new page and its menu label reuse the existing family language layer/picker metadata: English, Arabic, Spanish, Urdu, Pashto and Dari, including Gregorian/Western-digit Intl settings and RTL direction. The schedule includes its own selector; it shares that mechanism's persisted language preference. Only interface words are translated, never class/subject/activity/co-teacher/location names or closure reasons. New strings carry the existing MACHINE-DRAFTED marker pending fluent review. Urdu reuses the existing self-hosted Nastaliq stylesheet.

The phone layout contains week scrolling inside its own box and allows timetable-enabled header controls to wrap. Mounted tests cover wording, clocks, per-column times, optional locations, picker requests, Arabic and the menu's OFF requests. A real browser geometry check at 320/390 and touch/RTL inspection remains unverified: the local headless Chrome probe aborted with SIGABRT before loading a page.
