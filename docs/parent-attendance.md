# Parent attendance and same-day absence emails

Attendance is one tap from the family class page, beside Marks. A parent sees each child's current term totals and recorded class/day marks, newest first. The endpoint applies `FamilyController::subject`, the exact Marks guard, to the initial membership and every additional class belonging to that child. A class without that guardian's standing stays out. Notes, markers, correction history and timestamps are never fetched by the shared reader or included in the family response. No register means no mark, and an empty history says "No attendance has been recorded yet."

`AttendanceReader` is the seam for future period attendance. Both this view and the email read recorded daily cells through it. Totals count class/day cells, with present, late, absent and excused separate. When `school_calendar_terms` is enabled, the current dated term determines the inclusive bounds. Otherwise the current configured school year is used. Without a containing configured year, the existing August-to-July school-year convention applies. The list includes recorded history beyond the current totals window. Dates use the portal's selected language and Gregorian calendar; the layout wraps at narrow widths and uses logical alignment for RTL.

## Office controls and log

The new grant is `attendance_absence_emails`, false by default for every organisation type. Disabled schools see no grant in ordinary catalogues, capabilities, raw overrides or capability history. No migration enables it. The SuperAdmin capability endpoint is the existing way to grant it; the office can only change the clock time after it is enabled.

Open a class in the office and choose Attendance. The "Absence emails" section uses the existing Day control for its chosen day's list: guardian email, child first name, class(es), sent time and status. The Send time control is immediately above it and names the school's timezone. The log is also returned with the school register overview, for future whole-school presentation.

Recon found no pre-existing attendance-settings record. The time therefore reuses the existing per-school mail/settings table: `masjid_points_settings.attendance_absence_send_time`, null meaning **10:00**. Only this field is changed by the office write; points/report settings are preserved. `attendance_absence_last_date` is internal, written only by the scheduler, and prevents taking a second snapshot even on a day with no absences. No `masjids` column was added.

- Family GET: `/api/family/masjids/{school}/groups/{class}/members/{membership}/attendance`.
- Office time PUT: `/api/admin/masjids/{school}/attendance/absence-emails/settings`, body `send_time: "HH:mm"`; disabled means 404.
- Office GET: `/api/admin/masjids/{school}/attendance/registers?date=YYYY-MM-DD`; `data.absence_emails` is present only when enabled.

## Scheduling and delivery

`attendance:absence-emails` runs every five minutes with `withoutOverlapping(4)`. The first eligible run after the school-local clock reaches the chosen time atomically claims the school/date. It requires a containing school year, a calendar meeting day and no closure. The existing `SchoolCalendarReaders` / `SchoolDateAuthority` reads legacy calendar weekdays when dated terms are off and configured meeting weekdays when on. A calendar-free school sends nothing.

The run snapshots absent marks as they stand then. Present, late, excused and unmarked students never qualify. Each confirmed, current guardian edge is taken through `GroupMembership::confirmed()`; no feed/media consent or portal login is required for this notice about their own child. The recipient is `Contact.email`, the office's address on file. Missing/invalid addresses are skipped; `login_email` is not a fallback. Each guardian gets one email listing only their own eligible children and classes where their guardianship is confirmed.

Durable batch rows (`attendance_absence_mails`) have a unique guardian/date key. Child rows (`attendance_absence_mail_children`) have a unique guardian/student/date key and freeze class IDs/names, first name and date. Both tables have primary keys and `BelongsToMasjid`. Claims are written before queue handoff, and delivery atomically commits `queued` to `sending` before the network call. A rerun or competing worker cannot repeat that call. A later register, a changed send time or a school toggle does not cause a second snapshot that day.

The jobs carry IDs and a deadline, not guardian addresses or child data. They use the existing configured school mailer, sender address on the existing `manara.hopetechapps.com` sending domain and school display name (domain documented in `docs/mail-sending-domain.md`, live configuration unchecked), and Reply-To is the school's email. They share the duty-rota guard for resolved log/array/failover transports and existing provider timeouts. The queued worker rechecks the switch, open school day, same local date, current confirmed guardian edges, enrollment and unchanged recipient address. If any is no longer valid it skips the batch. A synchronous queue configuration falls back to the database queue so pacing is retained.

No existing school-mail per-minute limiter was found. This stream adds a shared ceiling of **30 emails/minute**, configurable with `ATTENDANCE_ABSENCE_EMAILS_PER_MINUTE`, as a conservative starting value rather than a measured provider allowance. Laravel's queue rate middleware releases excess jobs before their delivery claim; the release window ends at that school's midnight. Workers and a working scheduler/database queue are required.

Statuses are `queued`, `sending`, `sent`, `failed` and `skipped`. Sent means transport acceptance, not inbox delivery. A failed handoff/send records the exception class only, never exception messages, and logs a warning. A killed send attempt becomes failed after ten minutes; it is never reclaimed. Older queued rows become failed on a later eligible sweep. A crash can lose an email, including between the school snapshot claim and queue handoff, but cannot produce a duplicate. There is no automatic retry or office resend action in this item. The school/day claim is monotonic: a clock/date going backwards cannot re-open a prior claimed date.

The staging scrubber drops child logs before batch logs, including guardian email addresses and child names. Removing a member login keeps this office delivery history and its contacts; explicit contact or school erasure cascades these rows. The account-deletion classification includes both new tables, with a strict missing-table guard for the deployment window. There is no separate timed retention policy in this item.

## Exact English email

Subject:

```text
{School name}: Attendance for {Month D, YYYY}
```

Plain-text body (the first line is the school name):

```text
{School name}

{Child first name} was marked absent from {class(es)} today, {Month D, YYYY}.

To reach the office, call {school phone} or email {school email}.
```

For siblings, one child sentence is added on the next line for each eligible child. Multiple classes are joined with a comma. If only one office contact is available, only that contact appears. If neither is recorded, the final sentence is "Please contact the school office." The HTML version contains the same words in escaped paragraphs.

Email is English because the portal language lives only in the browser; the server cannot determine a guardian's language. This choice is recorded in `DECISIONS.md`. All seven portal word tables include the new chrome, while existing report-card attendance keys keep their original wording. French, Urdu, Pashto, Dari and Spanish additions retain the files' machine-draft warnings. New Arabic and Spanish text still require fluent-reader verification.

## Reused paths

| Existing source | Reuse |
| --- | --- |
| `app/Services/Schools/AttendanceRegister.php`, `app/Models/AttendanceRecord.php` | Same daily cells written by teacher and office registers; no duplicate register. |
| `app/Http/Controllers/Family/FamilyController.php`, `GradesController.php` | Exact `subject` guard used by Marks for every disclosed class. |
| `app/Models/GroupMembership.php` | Confirmed provenance scope and current enrollment/guardian scopes. |
| `app/Support/SchoolCalendarReaders.php`, `SchoolDateAuthority.php`, `SchoolCalendar.php` | Meeting days, closures, dated school years/terms and school-local dates/timezone. |
| `app/Models/MasjidPointsSetting.php`, `app/Support/PointsReportSchedule.php` | Existing per-school mail timing/settings storage and valid HH:mm reader. |
| `app/Jobs/SendDutyRotaMail.php`, `app/Mail/DutyRotaMail.php` | Queued school mail path, configured sender, school name, Reply-To and shared extracted `SchoolMailTransport` guard. |
| `resources/vue-app/core/types/data/masjid-related/SchoolCalendar.ts`, `views/family/familyI18n.ts` | Existing language registry, date formatting, Gregorian/RTL choices and locale persistence. |

Local evidence and remaining unverified work are recorded in `STATE.md`; gate totals are copied into the build report after completion. Real email delivery and a real phone are not verified by these tests.
