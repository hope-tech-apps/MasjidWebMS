# Attendance by period

For a school with Attendance by period enabled, each timetabled lesson on an open school day has a register. Either of its teachers can take it; class teachers and the office can correct it. In My schedule choose **Period register** or **Class Attendance**. A timetable-only teacher gets an attendance-only class view without other class tools. A joint lesson names each class's register separately. On class Attendance choose the period, mark all present and then the exceptions, and save. Notes are for staff only. The saved marker and time are shown. If someone changes the register while the page is open, reload and review the unsaved choices before saving again.

The office's class Attendance tab also shows the period registers. A child's school-wide Attendance record shows the day result and the individual period marks. Parents see the day mark followed by the named periods and their marks; they never receive notes or staff attribution.

Only periods taken for that child contribute to the day. No periods taken means no day mark. Present or late in any period makes the day present, except that late in the first scheduled period makes the day late. With no present or late period, all taken periods excused makes the day excused; otherwise it is absent. A child who leaves after attending remains present for the day. Untaken periods never become absences.

The school timetable must be enabled before Attendance by period can be enabled. The new grant is off and hidden for every school that has not requested it. The enable date is the school's own calendar date; earlier day registers remain in use and still count. Turning it off resumes day registers and keeps period marks read-only. The day screen offers the ordinary register and retained-period buttons. Earlier saved period results continue to count until a day register is taken for the same pupil/date. A partial day save keeps other pupils’ saved period results. Turning it back on starts periods on the new enable date again.

No register exists on a closure. A taken register prevents that day becoming a closure. Removing a timetable meeting preserves its class, period, subject and mark snapshots; admitted staff can correct its already-saved pupil cells while period attendance is on, and its marks still count. A removed meeting cannot gain new pupil cells. Switching period attendance off makes the retained register read-only. Timetable edits do not relabel saved cells. A child who leaves the roster keeps their saved cells readable and correctable; an untaken period cannot acquire a new cell for a departed child.

## Reader contract

`App\Services\Schools\AttendanceReader::dayQuery` / `routeQuery` are SELECT-only. The projection has the day-table shape so existing model casts, date filters, grouping, pagination and CSV streaming continue to work. Derived day IDs are negative period-row IDs, preventing collisions with ordinary positive day IDs; they are read-only projection IDs, never writable register IDs. The only status derivation lives in `routeQuery`. Register persistence/revision queries continue to read their actual stored cells.

Routed day readers:

- `AttendanceReader::marks` and `family`: family display and totals; `AttendanceAbsenceEmails::queue` consumes `marks` for absence selection.
- `AttendanceRegister::overview`: office school-register overview.
- `AttendanceLogController::marksIn`: school-wide board, class totals, today band, child history, roster cells and configured-calendar variants. Child history additionally receives safe period lines.
- `Teacher\AttendanceController::forMember`: teacher day history and summary; active date registers dispatch through AttendanceRegister to PeriodAttendance rather than reading day cells.
- `ReportCardService::attendanceCounts`: published attendance snapshots, including late in present as before.
- `SchoolRecordsExportController`: attendance manifest counts and streamed attendance CSV.
- `RosterMove`: last marked old-class day, first-day register status and missed registers on return.
- `SchoolCalendarConfigurationController` and the legacy calendar's period-aware dispatch/guard: marked-day closure protection, marked weekdays and year retention. These storage guards explicitly include superseded day cells so switching modes cannot permit closing a previously marked day.

`AttendanceController` frozen day reads/writes, `OfficeAttendanceController` day-cell attribution/existence, `AttendanceRegister` stored-cell revision queries and `AcademicRecordsHeld` retention counts deliberately inspect storage. They do not derive a day's mark from periods. Schools that have never enabled periods keep the original reader/writer bodies and existing frozen query fixtures.

## Storage and privacy

`period_attendance_records` has an auto-increment primary key and unique `(masjid_id, session_date, meeting_id, student_contact_id)`. The row also carries group membership, group ID/class title, period ID/title/clocks, optional subject ID/lesson title, teacher ID snapshot, first scheduled meeting ID/clock, status, note, marker and timestamps. Editable timetable identifiers have no foreign key. Tenant/contact/roster/class links have the same cascade lifetime as a register; marker deletion sets the marker to null. The model uses BelongsToMasjid. Roster removal protection includes period marks and member account deletion classifies the student ID as retained office data.

Staging policy: note is staff free text about a child and is scrubbed with the same free_text policy as day attendance. Class title is reviewed kept like groups.name. Period and lesson titles are anonymised labels like the editable timetable sources. All keys, dates, clocks, status, teacher ID list and marker timestamps are structural/audit data kept against scrubbed contact and user identities. The private switch windows and resumed-day dates contain no person or staff content and are never serialized publicly.

The teacher register uses the same component as the office period register and the same AttendanceRegister class lock, revision hash and conflict sentence. Teacher transport and meeting authorization remain distinct. Day and period saves lock the school before the class, preserving one lock order with switch/calendar writers. Every save rechecks the school switch; periods also recheck the dated meeting or retained snapshot and the school year's closure lock before writing.

## Local verification

Evidence and final totals are in `artifacts/period-attendance-report.md`. The guides session still needs its local demo walk and guide pages. Real-browser geometry/accessibility, fluent-reader translations, live mail/queues, MySQL locks/DDL and CI/staging/production are not established by SQLite and mounted SPA tests.

Contact merges preserve non-overlapping period cells by moving their student reference before the source contact is force-deleted. Two pupil records with a cell for the same school/date/meeting cannot merge silently: the office receives a plain refusal and both records remain. The earlier first-scheduled clock still governs the merged pupil's day result, even if that meeting has since been removed.
