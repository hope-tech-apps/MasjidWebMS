# What production served on 2026-09-16, byte for byte

Eighteen files: `GET /api/mobile/masjids/{id}/features` and `GET /api/v1/settings`
(with `masjid-id: {id}`) for all nine organisations, captured from
`masjid.hopetechapps.com` at 00:48 UTC on 2026-09-16, before any part of the S2
cutover ran. Org 17's `/features` was captured later the same night, at 03:1x UTC;
its body is `{"status":"success","data":[]}` and nothing about it had changed.

These are **the golden capture** the server plan's §7.1 and §7.2 require: S2a
promises an installed app sees the same bytes after the cutover as before, and
that promise is unfalsifiable without a record of what "before" was.

## Why they are here rather than regenerated

**The record is perishable in a way code is not.** The Mobile App Features
screen is live until S2b, and every SuperAdmin switch changes the derived side.
The moment anybody edits either, the ability to record the pre-cutover truth is
gone — and a capture taken afterwards would freeze the WRONG bytes as the thing
the cutover is checked against.

Until this commit the only copy lived in a per-session macOS temp directory
(`/private/tmp/claude-501/<session-uuid>/…`), which is collected when the session
ends. A reviewer found that before the directory was, which is the only reason
these files still exist.

## Read them raw. Do not round-trip them.

Production's JSON encoder escapes two things that a naive decode-and-re-encode
silently loses:

- **The apostrophe in Qur'an is `’`**, in both `name` and `key`, not a
  literal `'` and not an ASCII `'`. The Play build vc13 routes features BY NAME,
  so a normalised apostrophe is a feature that build can no longer find.
- **Every forward slash is escaped**: `image\/svg+xml`, `https:\/\/masjid…`. PHP
  escapes slashes unless told not to; `JSON_UNESCAPED_SLASHES` on the derived
  side would produce a payload that is equal when parsed and different on the
  wire.

So a comparison must be made on these bytes, or on a parse of both sides — never
on a re-serialisation of one side against the bytes of the other.

## What varies between organisations, and what does not

Across all eight non-empty bodies, `id`, `name`, `key`, `created_at`,
`updated_at`, `icon.id` and `icon.original_url` are **identical** — the icon
belongs to the feature, not to the tenant. The only per-organisation variation is
`pivot.masjid_id` and `pivot.is_available`. (File sizes differ by exactly 11
bytes between one- and two-digit org ids: one byte per row.)

That makes the golden cheap to reason about: one shared catalogue block, plus
nine `(masjid_id, is_available[11])` tuples, org 17's being the empty list.

## The rows, as captured

| Org | | `is_available` = 1 for legacy ids |
|---|---|---|
| 1 | Burlington Masjid | 1–11 (all) |
| 2 | test2 | 1–11 (all) |
| 5 | NAFIS Apex Mosque | 1–11 (all) |
| 13 | Muslim Education Center | 2–11 (Qur'an off) |
| 14 | Al-Razi School | 6, 7, 8, 9, 10, 11 |
| 16 | Intellicor International Academy | 6, 7, 8, 9, 10, 11 |
| 17 | ZZ QA Sandbox | *no rows at all — serves `[]`* |
| 18 | Burlington Islamic Sunday School | 6, 7, 8, 9, 10, 11 |
| 19 | MAS Youth Charlotte | 6, 7, 8, 9, 10, 11 |

Org 17 is the only organisation whose row SET changes at the cutover: `[]` today,
eleven rows afterwards. It is also the one that was missing from the capture
until a reviewer noticed — the single body most needed to catch the single
guaranteed change. It is unlisted and no installed app reads it, which is why
that change has no victim.
