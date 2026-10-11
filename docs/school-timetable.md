# School timetable

Step 1 gives the office period sets, rooms, a class's week and computed clashes. Teachers and families have no timetable routes or screens in this step. No courses, credits, rotating days, automatic placement, printing or exports are included.

## Switch and roles

`school_timetable` is a school grant, default false for every organisation, hidden from payloads, catalogue, history and navigation while off. Its routes return 404 even to a SuperAdmin while off. The audited `CapabilityWriter` refuses enabling it outside a school or without `school_calendar`. A combined write may enable both. Dated terms and class subjects are optional. The calendar cannot be switched off while the timetable remains on.

The office roles are the calendar's roles: MasjidAdmin with membership in the addressed school and SuperAdmin. The calendar has no separate view/edit permission; neither does the timetable. The admin and tenant middleware fence every route before the timetable gate. Teacher and family tokens cannot enter this realm.

## Reference data

A named period set belongs to one school year. Its rows have stable IDs, names, ordered positions, school-local start/end clocks and `teaching` or `block` kinds. Clocks never undergo timezone conversion. Rows must end after they start and cannot overlap within a set. Blocks may carry activities or whole-class meetings; subjects require teaching rows.

Each meeting weekday uses one school set; a class may override it. Meeting weekdays come from `SchoolDateAuthority`, including the calendar's single-weekday fallback while dated terms are off. A set change carries existing meetings to the new set's rows by position for the entire year; it refuses missing positions or subject meetings mapped to blocks. Times and row order also apply to the whole year. The screen says “This changes the times for the whole year.”

Rooms belong to the school, with a case-insensitive unique name, optional positive capacity and active flag. Inactive rooms remain readable but cannot be newly assigned. `timetable_class_rooms` holds usual rooms, keeping `groups` unchanged. A meeting's NULL room resolves through this table. Usual room changes are undated and affect every meeting resolving that room, including past views.

## Dated meetings

A meeting names one class, weekday and period row, with exactly one of a class subject, a named activity of at most 60 characters, or the whole class where class subjects are off. Subject choices must belong to the class and be visible. Teachers are live Teacher staff accounts with a teacher membership at this school, independent of their class assignments. Defaults offered come from subject assignments, otherwise the class's teachers. Subject and whole-class meetings require at least one teacher; activities may have none.

Creating requires `effective_from`, offered as school-local today or the year's first day, whichever is later. Optional `effective_until` must be within the year and on or after the start. Changing later closes the previous row the day before and creates another. A change on or before its start edits that row from the chosen date. Removing a not-yet-started row from on or before its start hard-deletes it; all other removals retain the row and end it the day before the removal date. Removing from before an already-started row's start leaves an empty range, retaining its identity. Nothing started is hard-deleted by the timetable API.

Two rows for the same class, weekday and period cannot overlap in dates, even with clash confirmation. Copying a day uses another weekday of the same set, defaults to the same teachers/room, and opens the copied meetings from the chosen date through the year. Copy is atomic, including validation and warning failures.

## Clashes and reads

A first placement/change with clashes returns 409 `{status: "clashes", clashes: [...]}`. Each clash has `kind`, `id`, `name`, weekday, intersecting dates, and both meeting descriptions with class, label, teachers, room and clock times. The same draft with `confirm_clashes: true` saves. Confirmation never bypasses validation or duplicate refusal.

Teacher and resolved-room clashes compare overlapping clocks on the same weekday across sets, plus effective date ranges that contain that weekday. Touching boundaries are allowed. Student clashes use participant Contact identities on both rosters, intersecting each membership's arrival date (move-in date, otherwise creation date) and leaving date (exclusive). Guardian edges do not count. The Clashes panel computes outstanding clashes as of the chosen date, including clashes introduced by whole-year setup changes.

`TimetableReader::meetings(school, year, date, lens, id, weekday)` is the shared server reader for `teacher`, `class`, `room` and `school`; it returns the week as of the supplied date, with optional weekday filtering. It executes exactly two queries regardless of class/meeting/teacher counts. `clashes` adds one bulk roster query, for exactly three. No teacher endpoints are introduced. The class week renders independent weekday columns with their own times, rather than a forced common row scale.

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
| PUT | `/years/{year_id}/classes/{group_id}/room` | Set/clear usual room |
| POST / PUT / DELETE | `/years/{year_id}/meetings[/{meeting_id}]` | Place/change/end dated meeting |
| POST | `/years/{year_id}/copy-day` | Atomic copy to same-set weekdays |

Every addressed year, class, set, period, subject, room and meeting is tenant-scoped; set/period/meeting IDs also resolve within the addressed year, subjects within the class. Submitted teachers resolve through this school's staff membership. All writers lock the organisation then year and recheck the switch. GET readers do not write.

## Retention and scrub

Sets/period rows with any meetings cannot be deleted, and period removal names the number held. Deleting or archiving a class, deleting a class subject's actual row, deleting or changing the access of a referenced teacher account in enabled-school or global account operations, and removing its school membership are refused to preserve references, including historical rows. Hiding a subject through the existing office Remove action ends its meetings from school-local today, preserving earlier rows. Restoring that subject does not restore its timetable automatically. Rooms with explicit or usual-room meetings are kept. A school year with period sets is kept; year date/weekday edits cannot strand its timetable.

There are eight additive tenant-scoped tables, all with `id` primary keys and short named indexes/FKs. No new `groups` column and no membership foreign key. The staging scrub's `name` token matches set/period/room names, room `name_key` and `activity_name`; all are anonymised conservatively, since typed names can identify people.

## Verification

`SchoolTimetableTest` builds full-time and weekend acceptance worlds, dated change and whole-class cases, rule/security/copy/removal tests and fixed query-count pins at two and twelve classes. `SchoolTimetableTenantIsolationTest` proves all eight model scopes and tenant stamps. `SchoolTimetableOffTest` compares literal SQL/payloads captured at 30cef6d4 and proves the switch decision adds no query. Existing OFF fixtures are unchanged. Mounted tests cover setup, independent weekday times, as-of reads, clash lists and confirmation. Real browser geometry, touch and RTL remain separate verification work.
