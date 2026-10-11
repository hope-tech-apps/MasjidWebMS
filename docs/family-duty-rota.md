# Family duty rota

The `family_duty_rota` school grant defaults to false for every organisation type. It needs `school_calendar`, has no SuperAdmin bypass, and stays out of menus and capability lists while off. Ship and migrate with the grant off. No production activation or deployment is part of this build.

## Set up Practice School

1. A SuperAdmin enables `school_calendar` using the existing capability endpoint. Enter the school's time zone and school year, meeting weekdays and closures in School Calendar. Multiple meeting weekdays require the existing `school_calendar_terms` grant.
2. Enable `family_duty_rota` with `PATCH /api/admin/masjids/{school}/capabilities/family_duty_rota`, body `{"enabled":true}`. Use the existing audited `CapabilityWriter`; do not write overrides directly. Turning off the calendar requires turning off the rota first.
3. Open School > Duty rota. Create Cleaning, choose its year, capacity (default 3, 1–20), turns owed (default 2), reminder lead (default 3, 0–14), optional reminder line and active flag. The form source is optional. Choose a school meeting days checkbox field from that form; repeatable fields are excluded.
4. A source field looks like `{"name":"cleaningDays","type":"checkboxGroup","optionsSource":"school_meeting_days","minSelections":2,"maxSelections":2}` inside a schema section's `fields`. Existing form validation still controls registration submissions. The rota builds from stored responses, including dates no longer offered by the registration form.
5. Press Build from the form to preview registration/pick counts, then Confirm build. The preview rolls its writes back. A rebuild counts picks already held as placed; several registrations may name the same family's single assignment.
6. Review By day and Needs a day before sending. Email all families who need a day or Email this family opens a confirmation. The result counts queued emails and families skipped for no email. No-email rows say “No email: call them”. A second confirmed send issues a new link and voids the old one. Copy claim link preserves the current link; Reissue and copy link deliberately replaces it.
7. Move/Remove name the family and day. Move shows days with room unless the office deliberately allows over capacity. Add a family can use an existing family or a typed name plus email/phone. Export CSV contains day, family, phone, email and source, with spreadsheet-formula protection.

## Calendar, identity and history

Open days are derived from `SchoolDateAuthority`, never stored as rota-day rows. All writes use the rota row mutex, then fresh locking family/assignment reads. Source responses are processed globally by submitted time and id, then each pick's stored order. Email identity is trimmed/lowercased; phone digits are the fallback. With neither, a response is a separate uncontactable family rather than merging unrelated people. Copies of name, email, phone, earliest submitted time and registration count live on family rows. The office/public reads do not join responses.

Past days and school-local today accept every picked family even beyond capacity or owed turns. Future picks respect capacity and owed turns. Rebuilds never move or remove a held assignment or undo an office move or family give-back. Previously full picks can be placed on a later rebuild when room opens. Free dates in a partial claim are saved; filled or closed dates are named in the refusal and the fresh choices are returned. Families keep their successful choices and only re-pick missing ones. Give-back is allowed through the day before the assignment.

A closure releases assignments on that date when the rota is next read, built, claimed or swept. The copied family record names the closed date; the day vanishes from choices and the family owes the released turn. This reconciliation is under the same mutex and sends no email. Removing the closure does not automatically resurrect a released assignment.

Cancelling/deleting the last live source registration releases only that family's future form assignments. Past assignments, claims and office assignments stay. That family is marked withdrawn and no longer owes additional turns. A later live registration restores their obligation; a rebuild still respects intentional releases.

Retention decisions:

- A source form cannot be deleted, even while the grant is off. The office receives a validation refusal; copied family/assignment/mail history remains. Deactivate it instead.
- A referenced school year cannot be deleted. Its dates/meeting weekdays cannot be changed while a rota references it; closures remain editable.
- Year/source can be edited before families exist. After families exist, create a new rota to use another year or source; name/capacity/owed turns/reminder settings/active remain editable. Existing over-capacity assignments are retained when capacity is reduced.
- Switching the grant off retains all rows, tokens, assignments and mail history; routes return 404 and queued deliveries stop. Hidden form flags stop source submissions from queuing build jobs while off. Re-enabling resumes building; the next rota read reconciles closures entered during the pause.
- Releasing an assignment retains its row and reason. A deliberate claim/office re-add on the same date reactivates that row; its once-only reminder stamp remains. Closure notices remain on the family row. There is no rota/family delete endpoint.

## Private links and mail

Links use `/duty/{school}/{rota}#token=…`. The SPA removes the fragment immediately and sends the token only in POST bodies. Token hashes and an issuance nonce are stored; the nonce alone is not a bearer credential. HMAC with `APP_KEY` and a separate derivation domain regenerates the current token for reminder mail without changing an office-issued link. Token validation compares the stored HMAC hash, school/rota/family, active status and year-end expiry in school-local time. Reissue rotates the nonce and hash. Private replies and the landing have `no-store`; the landing uses `no-referrer`. The three public POST endpoints share a 30-per-minute IP limiter. Invalid, expired or void tokens receive the same neutral sentence: “This private link is unavailable. Ask the school office for a new link.” No public payload exposes another family's name or contact.

Mail goes only to the copied family email. The organisation is the sender name, the verified configured address is the sender address, and the organisation email is reply-to. Both plain text and escaped HTML carry the following English text. Dates use the existing school date helper, for example “Sunday, October 18, 2026”. Braced words below are replaced with stored values; optional lines are omitted when empty.

**Pick subject:** `Pick your {lowercase duty name} day`

```text
{Hello family name, or Hello, when the name is unsafe for an email}

{school name}: please pick {owed} more {day/days} for {duty name}.
Days you hold: {dates separated by semicolons, or None}.
Your picks that were full: {dates separated by semicolons}.
Pick your days: {private link}
You can choose fewer days and come back. This private link works through the last day of the school year.

Reply to this email if you need help.
```

The full-picks line is omitted when there are no such picks. Only an explicit confirmed office action sends this mail; building never sends it.

**Reminder subject:** `Reminder: {duty name} on {date}`

```text
{Hello family name, or Hello, when the name is unsafe for an email}

Your {duty name} day at {school name} is {date}.
{editable reminder line}
Give the day back or choose another: {private link}
You can give it back until the day before.

Reply to this email if you need help.
```

The editable line is omitted when empty, for example “Cleaning starts at 1:30 PM after class.”

**Confirmation subject:** `Your {duty name} days`

```text
{Hello family name, or Hello, when the name is unsafe for an email}

Your {duty name} days at {school name} have been updated.
Days you hold: {dates separated by semicolons, or None}.

Reply to this email if you need help.
```

Successful claim/give-back changes send one confirmation with the resulting held dates; a wholly refused or replayed action sends none.

## Reminder schedule and delivery record

Command: `php artisan duties:remind`.

```php
Schedule::command('duties:remind')->hourly()->withoutOverlapping(50);
```

The existing cron runs `schedule:run` each minute. The sweep uses `PointsReportSchedule`'s school-local email hour, default **3:00 PM**, ignoring its weekday. Before that hour it waits. On/after that hour it queues assignments between today and today plus the rota's lead, inclusive. It catches a missed day in that window and a late assignment at the next hourly run, never sends for a past date, and honours closures and active status. With all grants off it runs one narrow school projection, no per-school query, and prints nothing.

Each send has an office-visible `duty_rota_mails` row with kind, family, queued/sent/failed timestamps and status. A unique reminder dedupe key and `reminder_queued_at` stamp are written under the rota lock in the queueing transaction; `reminder_sent_at` records actual job success. The job claims `queued → sending` atomically; a repeated execution cannot send twice. Jobs have one attempt. The Needs a day list shows the latest pick email’s queued/sent/failed status and time; `link_sent_at` records successful delivery. A crash after the claim may lose a notice rather than repeat it; delivery is not guaranteed by an at-most-once design. The job records relay failures, and off/inactive/closed/past pending reminders are marked failed without sending. Office pick resends are separate recorded actions.

The actual database queue payload contains encrypted mail content, including the private URL; a failed-job record cannot expose a plaintext reusable link. Queue workers must be restarted on deployment as for existing jobs. Automatic source builds are queued after the response transaction commits and require the existing worker too.

## Schema

All four tables have an auto-increment primary key, `masjid_id`, explicit short FK/index names and timestamps. They have no membership FK.

| Table | Columns beyond id/masjid_id/timestamps |
|---|---|
| `duty_rotas` | `school_year_id`, `name`, `capacity`, `turns_owed`, nullable `source_form_id`/`source_field`, `reminder_lead`, nullable `reminder_line`, `active` |
| `duty_rota_families` | `rota_id`, `identity_hash`, copied `name`/`email`/`phone`/`submitted_at`, `registration_count`, `withdrawn`, JSON `unplaced_picks`/`notices`, hidden `token_hash`/`issuance_nonce`/`token_expires_at`, `link_sent_at` |
| `duty_rota_assignments` | `rota_id`, `family_id`, `duty_on`, `source`, nullable `released_at`/`release_reason`, `reminder_queued_at`, `reminder_sent_at` |
| `duty_rota_mails` | `rota_id`, `family_id`, nullable `assignment_id`, `kind`, unique `dedupe_key`, `status`, `queued_at`, nullable `sent_at`/`failed_at` |

Hidden `forms.has_duty_rota_records`, `forms.duty_rota_enabled` and `school_years.has_duty_rota_records` let unused OFF paths retain their SQL and payload shape. PII and credentials are covered by staging scrub's table-drop policy, child tables first.

## Routes and fences

| Prefix | Routes | Fence |
|---|---|---|
| `/api/admin/masjids/{school}/duty-rotas` | `GET/POST /`, `GET/PUT /{rota}`, `POST /{rota}/preview`, `/build`, `/email`, `/assignments`, `/families/{family}/link`; `GET /{rota}/export` | Existing staff Sanctum/admin/tenant realm, school/calendar/rota grant, scoped rota/family/assignment; same office authority as School Calendar. Teacher/family realms refused. Build/email require explicit confirmation. |
| `/api/v1/schools/{school}/duty-rotas/{rota}` | `POST /page`, `/claim`, `/give-back` | Grant, active rota, private family token under the mutex; explicit tenant scope; shared throttle. A staff/family login does not substitute for the private token. |
| `/duty/{school}/{rota}` | `GET` | Grant and throttle before serving the SPA; fragment redemption by POST only. |

OFF regression pins are the new `tests/fixtures/duty-rota-off-52daab37.json`, captured from unchanged HEAD before implementation, including literal submit/list SQL and payload and existing points/Bucks command output. Existing fixtures were not changed. Mounted tests and source constraints do not prove actual 390/320 browser geometry or RTL layout. Real browser, real mail relay, real MySQL contention, CI/staging/production and deployed worker behaviour remain to be checked separately.
