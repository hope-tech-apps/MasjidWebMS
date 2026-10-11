# Office class attendance

The class page's Attendance tab lets school office users take and correct one day's register, with Present, Absent, Late, Excused and an optional note. Opening a register never fills missing marks automatically. Mark all present is an explicit choice. The office sees the current roster, the existing marks, each mark's author and last update in the school timezone. Corrections overwrite the existing cell and attribute it to the signed-in office user. The school overview labels a class taken when at least one mark exists, matching the teacher register; its author is the latest updated mark, with id as the tie-breaker. It is not a claim that every student was marked.

Previously, the office class page had no Attendance tab or write route. The separate Attendance screen remains read only: today's class register counts and away list, a date-window grid with class/student totals, and per-student history. The conditional Reports panel also shows published report-card attendance snapshots. Its two school attendance GET routes remain unchanged: `attendance` and `attendance/members/{membership_id}`.

New admin routes under `/api/admin/masjids/{masjid_id}`:

- `GET groups/{group_id}/attendance`: register, names-only student projection, last marker/time and calendar refusal.
- `PUT groups/{group_id}/attendance`: same marks validation and atomic writer as the teacher; office-local future bound.
- `GET attendance/registers?date=YYYY-MM-DD`: all school classes and their latest marker/time, in one request and two data queries. Tests also pin the complete authenticated HTTP request at ten queries for both two and twelve classes.

All three inherit `auth:sanctum`, `admin`, `tenant`, `crm` and reuse `permission:manage contacts`, the class roster write permission. No new permission, capability, model, table or migration. The register resolves a class through the tenant scope; each submitted membership must be a current participant of that class. The overview filters both its class and marks queries to the bound school. Teacher/family credentials cannot enter the admin realm.

`AttendanceRegister` wraps the existing teacher read and save bodies, which the calendar source contract freezes. A dispatch prefix and explicit frozen entry methods let both realms call those bodies without changing their SQL or projections. The teacher's allergy controller still decorates only once. Office reads reuse `StudentAllergies::decorateTeacherRoster` and the same `AllergyBadge`; no health field is added to another reader or model.

Date behavior differs from the brief's description of the existing teacher. At the base commit, the teacher permits a non-meeting or out-of-year day and validates future dates on the app clock. Those rules remain unchanged. The office uses `SchoolCalendarReaders` to default to school-local today and reject future, closed, non-meeting and out-of-year dates when a calendar exists. Future and closure refusals reuse the teacher's exact sentences; non-meeting/out-of-year refusals use its calendar hint with a refusal ending. With no school calendar, the existing permissive past-date behavior remains. A closure arriving after validation is rechecked under the existing school-year lock by the shared frozen writer.

The existing attendance rows retain their schema and semantics. Report card publication, the family report snapshot and exports continue reading those rows independently of who marked them. The current weekly points command/email does not read attendance; it remains unchanged. Published report cards remain frozen after publication. The new integration test writes as office, reads as teacher, publishes the actual report card, and reads its attendance as a family bearer token.

The date input shares `useCommittedDate` with the timetable: 400ms typing pause, Enter or blur. Save is disabled while the typed date differs from the loaded date. Stale reads cannot overwrite a newer loaded register. Times use `formatLastOpened` with `en-US` and school timezone; date labels use `formatSchoolDay`. Phone mark buttons wrap to two columns, and rows/notes permit shrinking.

Verification and limits are recorded in `artifacts/office-attendance-report.md`. Existing fixtures were not rewritten. The old office navigation assertions now include Attendance; the location byte comparison adds exactly the new navigation line to its in-memory expected value while retaining the existing fixture and all other bytes/requests.
