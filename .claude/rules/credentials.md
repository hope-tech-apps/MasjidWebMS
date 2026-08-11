---
paths:
  - "app/Models/ContactCredential.php"
  - "app/Support/CredentialDocuments.php"
  - "app/Http/Controllers/AdminDashboard/ContactCredentialsController.php"
  - "app/Http/Requests/Admin/Credentials/**"
  - "config/credentials.php"
  - "database/migrations/*_create_contact_credentials_table.php"
---
# Volunteer credentials (T-023, Community vertical)

`contact_credentials` tracks what a Community org's volunteer providers are
licensed to do — medical licenses, background checks, BLS cards (pilot: a free
clinic). A credential belongs to an existing **Contact**; people exist once, as
Contacts, and everything else references them (same rule as groups).

## The non-negotiables

- **`kind` is a PHP constant set (`ContactCredential::KINDS`), never a DB
  enum** — `label` carries the free text for `kind = other`, so a new
  credential type never means `ALTER TABLE` (.claude/rules/migrations.md).
- **`identifier` (the license number) uses the `encrypted` cast.** The column
  is TEXT because ciphertext is long. A DB dump must not read a license number;
  pinned by `ContactCredentialTenantIsolationTest`.
- **Status (valid / expiring / expired) is DERIVED — accessor + scopes, never a
  column.** A stored status goes stale at midnight with nothing responsible for
  refreshing it. The expiring window is `config('credentials.expiring_within_days')`.
- **The scanned document is the THIRD implementation of
  .claude/rules/private-uploads.md** (after FlyerCutout and form attachments)
  and must keep matching it: private disk, random name, tenant-scoped
  directory, config-driven allowlist/ceiling (`config/credentials.php`),
  download only via `downloadDocument` which re-resolves
  masjid -> contact -> credential (foreign id anywhere = 404), bytes deleted in
  the MODEL layer (the credential's `deleting` hook; `Contact::booted()` covers
  the force-delete path the merge flow uses).
- **Authorization reuses the CONTACTS permissions** inside the `crm` group —
  minting `view/manage credentials` would break the pinned
  `Permission::count() === 8` (same call as .claude/rules/groups.md); splitting
  them out is a deliberate later task.

Scope OUT (deliberately not built here): expiry notifications/reminders,
background-check API integrations, Vue screens.
