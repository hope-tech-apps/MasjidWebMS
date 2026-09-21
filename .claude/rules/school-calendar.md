---
paths:
  - "app/Models/SchoolYear.php"
  - "app/Models/SchoolClosure.php"
  - "app/Support/SchoolCalendar.php"
  - "app/Support/SchoolCalendarPayload.php"
  - "app/Support/FormOptionSources.php"
  - "app/Support/FormSchema.php"
  - "app/Rules/ValidFormSchema.php"
  - "app/Http/Controllers/AdminDashboard/SchoolCalendarController.php"
  - "app/Http/Controllers/Teacher/SchoolCalendarController.php"
  - "app/Http/Controllers/Teacher/AttendanceController.php"
  - "app/Http/Controllers/Family/SchoolCalendarController.php"
  - "app/Http/Requests/Admin/SchoolCalendar/**"
  - "app/Http/Requests/Teacher/SaveAttendanceRequest.php"
---
# School calendar

A school year is a date range (`school_years`) and its no-school days
(`school_closures`). DECISIONS.md 2026-09-14.

## The shape

- **The meeting weekday is `first_day`'s and is never stored.** Meeting days
  repeat every 7 days to `last_day`. A stored weekday could disagree with the
  first day; meeting on another weekday means a different first day.
- **One authority: `App\Support\SchoolCalendar`.** The register, the admin
  screen, the teacher and family reads and the form field all ask it. Do not
  compute meeting days anywhere else.
- **Days are `Y-m-d` strings.** The `date` cast stores `Y-m-d 00:00:00` on
  SQLite, so query with `whereDate()` and compare `toDateString()`. Never
  BETWEEN on the raw column (LessonPlanController::index).
- **Today is the school's today** (`masjids.timezone` via
  `FormStaffCodesController::timezoneFor`, where an unset `UTC` reads as
  America/New_York). A UTC today closes Sunday at 8pm Eastern on Saturday.
- **Queries name the organisation AND keep the scope.** The public form paths
  are unbound, where the scope filters nothing. The scope stays on, so a caller
  bound to a different tenant gets an empty calendar.
- `curriculum_weeks.week_no` is still not mapped to dates.

## The register

- **Only closures are enforced.** A closed day serves `students: []`,
  `taken: false` and a `school_day` block, and its save is a 422. A day off the
  weekday, or outside every year, is still allowed. **An organisation with no
  school year behaves exactly as before** (Al-Razi); keep it that way.
- **A closure over existing marks is refused with the count.** It is counted
  inside `storeClosure`'s transaction under `lockForUpdate` on the
  `school_years` row. `AttendanceController::save` takes the same lock and
  re-checks before writing (`SchoolCalendar::closureFor(..., lock: true)`). Do
  not move either check outside its transaction: marks hidden under a closure
  would vanish from the register while still counting on the report card.

## Nothing is stranded

- Editing a year so a closure falls outside it, or off its weekday, is refused
  and the dates are named.
- **Deleting a year** is refused while it has closures, register marks inside
  its dates, or form answers naming its meeting days. The counts are named.
  Never add a "force" path that cascades.

## The capability

`school_calendar` defaults **off for every org type, schools included**. A
SuperAdmin switches it on per organisation and always passes the gate. Only the
admin writes and reads are gated. The teacher and family GETs are not: a gate
decides what is offered, never what is readable, and a school with no calendar
reads `years: []`.

## Calendar-sourced choices (`optionsSource`)

- A select/radio/checkboxGroup may carry `optionsSource: 'school_meeting_days'`
  and **stores no options**. It holds a reference, never a copy. It is refused
  in a repeatable section, on other types, beside typed options, and with an
  unknown source.
- **OFFER** set: open meeting days strictly after today. Used by the public
  schema (bindForm, OfferingPublicPayload) and by submit validation
  (FormSchema), so the page and both doors agree.
- **LABEL** set: every meeting day, closed ones with detail `No school — reason`,
  plus any stored ISO date formatted. Used by FormInsights and FormNotifier.
  The roster breakdown cannot reach a sourced field. It reads only the
  repeatable section's fields, where a source is refused, and a flat form's
  `columns()` carry no options.
- **An empty offer set refuses every answer** (`NONE_OPEN`), and a required
  question is not passed. Never restore the old "no options ⇒ no in-list check"
  shortcut for a sourced field.

## How many to pick (`minSelections` / `maxSelections`)

- Only on checkboxGroup. Whole numbers of at least 1, min ≤ max, and never more
  than a typed list has.
- Counted only when an answer was given; `required` decides blank.
- On a sourced field with fewer open days than `minSelections`, an answer, or a
  required blank, is refused with `NOT_ENOUGH_OPEN`.

## The lesson-plan week (2026-09-21)

`GET .../lesson-plans` serves `meeting_weekdays` = `SchoolCalendar::meetingWeekdays()`
(each year's first-day weekday, 0 = Sunday), or `null` when the organisation has no
year. The teacher's week grid and "Copy to the rest of this week" use those days, and
fall back to Monday–Friday on `null`, so Al-Razi is unchanged. BISS's "Sundays only"
needed nothing else: its year starts on a Sunday and its off-Sundays are closures.

## Not built (say so rather than imply it)

No per-day capacity, no alert when a chosen day later closes, no public
calendar section type, no two-weekday schools or make-up days, and no
per-Sunday roster. Roster row cells and the responses CSV show a sourced answer
as its ISO date. Only insights and emails carry the written-out label.
