# S2a review — the derived `/features` contract

Read-only adversarial review, 2026-09-16. Six attack lenses against the promise that
already-installed apps keep working through the cutover; each lens independently
re-checked by a verifier instructed to refute by default and to **re-read HEAD first**
(the previous review of this branch produced two must-fix findings against a commit the
author had already fixed). Then a completeness critic. Thirteen agents, nothing written:
no edit to the author's worktree, no deploy, no suite, no staging, no device.

**78 findings survived verification: 6 must-fix, 52 should-fix, 20 nits.**

Raw material: `s2a-review.json`, `s2a-surviving.json`.

---

## The six must-fix

### 1. `BI-1` — the golden capture did not exist. **Fixed: `e0392c6`.**

Five lenses found this independently. §7.1 and §7.2 both require a committed capture of
what production serves today; the byte-identity promise is unfalsifiable without one. The
only copy was in a per-session macOS temp directory that is collected when the session
ends.

I committed all eighteen bodies to `tests/fixtures/features-production-2026-09/`. Two
things surfaced while doing it:

- **Org 17 was missing from the capture** — the one organisation whose row *set* changes
  (`[]` today, eleven rows after), so the body most needed to catch the one guaranteed
  change was the one nobody had.
- **The bytes escape twice over**: Qur'an's apostrophe is `’` in both `name` and
  `key`, and every slash is escaped (`image\/svg+xml`, `https:\/\/…`). Verified with
  `od -c`. A generator that decodes and re-encodes, or that sets
  `JSON_UNESCAPED_SLASHES`, yields a payload that parses equal and differs on the wire —
  and the Play build routes by NAME.

### 2. `N5-06` / `M1` / `cw-3` / `OB-7` / `BI-4` — the plan is blind in the only direction production actually differs

The strongest signal in the review: five lenses, separately, on one line.
`AppFeaturesCutoverPlan.php:259` is `if ($pivotValue !== false) { continue; }`, so every
finding class sits behind pivot-OFF. **All thirteen differing pairs on production run the
other way.** The command therefore prints zero blocking findings while the migration is
about to write thirteen ON-ward overrides across five organisations, under a caption
reading "None: nothing is switched off."

At BISS that means four explicit SuperAdmin `false` decisions from 2026-09-13 are
overwritten, including `contact_requests`, which **re-opens a public contact form**.

Fix: a fifth class — pivot ON while the switch is OFF — blocking when an explicit
override is being reversed (a human decided that), a notice when it is only a type
default.

### 3. `R1` — the fallback returns empty as a success

The never-5xx chain only advances when a step *throws*. `fromPivot()` returning `[]` does
not throw. So for any organisation with no pivot rows — org 17 today, and **every newly
provisioned organisation after S2b deletes the provisioning writes** — a single transient
failure in the derived step yields `{"status":"success","data":[]}`, HTTP 200, cached for
ten minutes. A blank drawer that looks healthy to every layer above it.

Fix: treat an empty pivot result as a miss and fall through.

### 4. `C-1` (critic) — the build on the phones is not in the repo

Every claim in this review about "the Burlington iPhone on v2.5 build 44" was read out of
build 43 or 45; no commit in the iOS repo carries build 44. One fork in that two-week
window changes the answer — `d8d8ee9`, the NAFIS white-label, which replaced a hard-coded
`/mobile/masjids/1/features` with the per-target id.

This is not a defect in the branch. It is a limit on how much comfort the review can give,
and it should be said before anyone quotes it as assurance. Fix: bracket build 44's upload
date from App Store Connect against those commit dates and tag the commit, so future
client claims are anchored to something.

### 5. `C-2` (critic) — no automated gate sees the composite event

Each gate is individually blind to part of it: the contract test compares bytes but does
not apply the migration's writes; the migration test and `verify-cutover` compare database
values, which cannot see row order, the U+2019, `is_available` arriving as a boolean, or
the row count. What remains is a human running `curl` at 1am — against a baseline that,
until `e0392c6`, did not exist.

Fix: one test per captured org that loads the fixture, asserts the pre-marker body
byte-identical, runs the migration in-test, and asserts the post-marker body byte-identical
to the same fixture.

### 6. `N5-04` — the last step of the never-fail chain can itself fail

The innermost fallback's `Cache::get` is not inside a `try`.

---

## The should-fix worth reading first

- **`R4` / `MB-1`** — the repo's catalogue spells feature 1's key ASCII `quran`; production
  serves `qur’an`. Two records of the same catalogue disagreeing on the one byte the Play
  build matches on.
- **`BI-5` / `cw-1` / `OB-2` / `M2`** — `show_in_app` and `hide_everywhere` write the
  identical thing, so an owner's "keep it" silently becomes "delete it".
- **`cw-2` / `SEQ-1`** — the resolutions live in `config/`, which the migration reads
  through `config()` — unreadable on a config-cached box, so resolutions typed on
  production would be silently absent.
- **`BI-2` / `SEQ-7`** — nothing pins the wire order 1..11. `Masjid::features()` has no
  `orderBy`, so today's order is incidental, and registry order (10, 9, 6, 1…) is the more
  visible of the two orderings the branch offers. Play vc13 lays tiles out by position.
- **`cw-4`** — after the write, the switches panel badges eleven machine-written overrides
  as "Set by a SuperAdmin".
- **`OB-8`** — the new `/features` counter serialises every app launch on one database row
  while holding a php-fpm worker, on a one-vCPU box.
- **`R6` / `R7`** — S2b deletes the SuperAdmin screen, but the SPA ships out of band, so it
  renders an empty table rather than a 404, and three sidebar levers still point at it.
- **`SEQ-M1`** — `migrate:rollback` un-derives `/features` but leaves every capability
  override the cutover wrote, so BISS stays reversed after a rollback.

## What this review could not do

No PHP on this machine: nothing was executed. Every finding is source reading plus
read-only production data, and the attacks are reasoned rather than fired. Combined with
`C-1` — the shipped iOS binary's source is not identifiable in the repo — the client-side
conclusions are the softest part of the review and should be treated as leads for the
device evidence plan rather than as settled facts.
