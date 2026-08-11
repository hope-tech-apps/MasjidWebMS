---
paths:
  - "app/Http/Controllers/**"
  - "app/Support/FormAttachments.php"
  - "app/Models/FormResponseAttachment.php"
  - "config/forms.php"
  - "config/flyer.php"
---
# Private uploads

Some files this platform accepts are **not content**. A janazah photo being worked
on, a résumé on a Schools careers form, a document attached to an admissions
request — these must not be readable by anyone who guesses a URL. There are now two
implementations of the same arrangement (`FlyerCutoutController`, form attachments
via `FormResponsesController::downloadAttachment`), and any third one must match it.

## The arrangement

1. **Write to a disk with no `url`.** `local` is `storage/app/private`. Never
   `public`, which is symlinked into the web root — a file there is world-readable
   the moment it lands, whatever the filename.
2. **Randomise the stored name; keep the original in the database.** The
   respondent's filename is data: it is shown to admins and used as the download
   name, and it never touches the filesystem. This is also what makes traversal and
   collision impossible without sanitising anything.
3. **Scope the directory by tenant** (`<root>/<masjid_id>/…`), so one masjid's
   uploads are never interleaved with another's on disk.
4. **Serve through an authenticated endpoint that re-resolves the whole ownership
   chain.** For a form attachment that is masjid → form → response → attachment,
   each link `findOrFail`'d through its parent, so another tenant's id anywhere in
   the path is a 404 — not a filter, a miss. Put the route in the same middleware
   group as the rest of the resource (`auth:sanctum` + `admin` + `tenant`).
5. **Keep `findOrFail` OUT of the controller's `try/catch`.** The app's JSON
   renderer turns `ModelNotFoundException` into a clean 404; catching it reports a
   missing file as a 500. Same reasoning as `.claude/rules/tenant-scoping.md`.
6. **Answer with `Cache-Control: private`** and an attachment disposition, so no
   proxy holds one masjid's document and serves it to the next admin who asks.
7. **Never emit a public URL for one.** No accessor, no serializer field. The only
   link the admin payload carries points back at the authenticated endpoint, which
   the SPA fetches with the bearer token (see `downloadAttachment` in
   `FormResponsesView.vue` — a plain `<a href>` would 401).

## Validation belongs at the request boundary

Allowed types and the size ceiling live in config (`config('forms.attachments')`),
are enforced by a `BaseFormRequest` subclass, and are matched with `mimetypes:`
against the type **sniffed from the bytes** — never `mimes:` on a client-supplied
extension and never the `Content-Type` header. A tenant editing its own form schema
must not be able to widen what this server accepts, so the ceiling is never read
from the schema.

## Deleting must reach the disk

A DB-level `ON DELETE CASCADE` fires no model events. If the parent row's deletion
is what removes the child rows, the bytes are orphaned forever. Delete attachments
through the model in the parent's **`deleting`** hook (before the cascade can beat
you to it), and remove the file in the attachment's own `deleting` hook.

## Not medialibrary

`spatie/laravel-medialibrary` is the app's mechanism for PUBLIC images (masjid
logos, section images, gallery). It defaults to the `public` disk, and its `media`
table carries no `masjid_id`, so a private, tenant-scoped file would be relying on
configuration rather than on a column. Private uploads use a dedicated table plus
`Storage` instead — see `form_response_attachments`.
