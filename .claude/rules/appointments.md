---
paths:
  - "app/Models/AppointmentRequest.php"
  - "app/Models/AppointmentRequestNote.php"
  - "app/Http/Controllers/Api/V1/AppointmentRequestsController.php"
  - "app/Http/Controllers/AdminDashboard/AppointmentRequestsController.php"
  - "app/Http/Requests/Api/V1/AppointmentRequests/**"
  - "app/Http/Requests/Admin/AppointmentRequests/**"
  - "database/migrations/*_create_appointment_requests_table.php"
  - "database/migrations/*_create_appointment_request_notes_table.php"
---
# Appointment requests (Community vertical, T-021)

`appointment_requests` + `appointment_request_notes` replace the free clinic's
plaintext-Gmail intake. A visitor submits on the public site; staff triage the
queue in the admin dashboard. These rows describe a person's health contact,
which drives every rule below.

## Sensitive fields are encrypted at rest — and NEVER logged

- `appointment_requests.date_of_birth`, `appointment_requests.reason` and
  `appointment_request_notes.body` are `encrypted` casts (TEXT columns holding
  ciphertext). `AppointmentRequestEncryptionTest` reads the raw columns below
  the casts and fails if anyone removes one — nothing else would notice.
- Consequences of the ciphertext: never query/filter/index on these columns,
  and never move a value between rows without going through the model.
- **NO PHI IN LOGS.** No request payload on either the public or the admin
  path may reach `Log::*`. Errors report through `Errors::publicMessage`,
  which logs exception metadata only. A debugging `Log::info($request->all())`
  here is a data breach, not a log line.
- The deliberate boundary: `applicant_name` / `phone` / `email` are NOT
  encrypted — staff find and call people by them. The encryption test pins
  this too, so "encrypt everything" cannot silently break the queue.

## Statuses are PHP constants

`AppointmentRequest::STATUSES` (`new`/`contacted`/`scheduled`/`closed`) is the
authority — the column is a plain string, same reasoning as `Masjid::ORG_TYPES`
(.claude/rules/migrations.md). Validate with `Rule::in(...)` at the boundary.
Any status may be set in any order on purpose: triage is a label for staff,
not a state machine — a closed request reopens when the patient calls back.

## The two surfaces

- **Public** `POST /api/v1/appointment-requests` follows the form-submission
  idiom exactly: tenant from the `masjid-id` header (checked to exist —
  `/api/v1` never binds a tenant, so the controller stamps `masjid_id`),
  `website` honeypot, named throttle `appointment-request`, rejections as the
  legacy `{status:'failed'}` 422 via a `BaseFormRequest`. The success payload
  returns only the id — never echo the PII back.
- **Admin** lives inside the `crm` route group and reuses the CONTACTS
  permissions (`view contacts` / `manage contacts`), the same precedent as
  groups: minting `view/manage appointments` would change the seeded set that
  `RolePermissionBridgeTest` pins (`Permission::count() === 8`). Notes surface
  ONLY on the admin show endpoint; no public payload carries one.

## Tenancy and deletion

Both models use `BelongsToMasjid` (`appointment_request_notes.masjid_id` is
denormalised like `group_memberships`); controllers never hand-filter —
cross-tenant ids are 404 misses, a cross-tenant route is a 403. Proven by
`AppointmentRequestTenantIsolationTest` + `AppointmentRequestCrudTest`.
Deleting a request removes its notes in `AppointmentRequest::booted()`'s
`deleting` hook — the FK cascade is only a backstop, because a DB cascade
fires no model events (.claude/rules/private-uploads.md).

## OUT of this slice on purpose

Slot-based scheduling/calendars (why `preferred_window` is free text), SMS
reminders, Vue screens, file uploads. Design follow-ons as additive slices.
