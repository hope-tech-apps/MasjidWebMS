# S2a review — the derived `/features` contract

Read-only adversarial review, 2026-09-16. Six attack lenses against the promise that
already-installed apps keep working through the cutover; each lens independently
re-checked by a verifier instructed to refute by default and to **re-read HEAD first**
(the previous review of this branch produced two must-fix findings against a commit the
author had already fixed). Then a completeness critic. Thirteen agents, nothing written:
no edit to the author's worktree, no deploy, no suite, no staging, no device.

**78 findings survived verification: 6 must-fix, 52 should-fix, 20 nits.**

---

> ## Read this before quoting anything below
>
> **The build that is actually on people's phones is not in this repository, and was not
> on the machine this review ran on.** No commit in the iOS repo carries build 44, the
> version live on the App Store; every statement here about what the installed app does was
> read out of build 43 or 45. One commit in that two-week window — `d8d8ee9`, the NAFIS
> white-label — changes the answer, because it replaced a hard-coded
> `/mobile/masjids/1/features` with the per-target id.
>
> So the client-side conclusions are **leads for the device-evidence plan, not settled
> facts**. Treat them as the strongest guesses available from source that may not be the
> source that shipped. Bracket build 44's upload date from App Store Connect against those
> commit dates and tag the commit, and this caveat goes away.
>
> A second limit, smaller but the same kind: there is no PHP on the machine this ran on, so
> **nothing here was executed.** Every finding is source reading plus read-only production
> data. The attacks are reasoned, not fired.

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

### 3. `R1` — empty is served as a success, and this half IS shipped code

The never-5xx chain only advances when a step *throws*. `fromPivot()` returning `[]` does
not throw. So for any organisation with no pivot rows — org 17 today, and **every newly
provisioned organisation after S2b deletes the provisioning writes** — a single transient
failure in the derived step yields `{"status":"success","data":[]}`, HTTP 200, cached for
ten minutes. A blank drawer that looks healthy to every layer above it.

Fix: treat an empty pivot result as a miss and fall through.

**Shipped versus specified, because the two halves of this finding are not the same kind
of thing.** The *serving* half is live code: `Mobile/MasjidMobileAppFeaturesController::index`
on `main` has **no try/catch at all** — one `Cache::remember`, a `findOrFail`, and an
organisation with no feature rows serialises to `[]` with HTTP 200. Org 17 is doing exactly
that on production today. It is benign today, because `[]` is the honest answer for an
organisation nobody has configured; the controller simply **cannot distinguish "nothing is
configured" from "the load failed"**, and after S2b deletes the provisioning writes, every
newly provisioned organisation lands in the state where those two look identical.

The *fallback chain* half is specification: `app-features:drill` does not exist, and
`features.lastgood` has no writer and no reader anywhere in the repo — only a docblock and
a test that populates the key itself.

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

**This is a specification defect, not a code defect.** The chain it describes is not
implemented; anyone going to look for it will not find it. Fix it in §7.4 before
`LegacyFeatures` is written against the current wording, which is the cheapest moment it
will ever be fixable.

---

## The should-fix worth reading first

- **`R4` / `MB-1`, with the cause found afterwards** — the repo's catalogue spells feature
  1's key ASCII `quran`; production serves `qur’an`. **The divergence is a migration that
  silently missed.** `2025_12_09_203251_add_key_to_mobile_app_features_table` backfills
  `key` by matching on `name`, and its table is keyed `'Qur\'an'` — an ASCII apostrophe.
  Production's name is `Qur’an` (U+2019), so that row never matched, fell through to the
  "generate one from the name" branch, and got `qur’an`. Every other row matched and is
  ASCII. Nothing has broken because
  `MasjidMobileAppFeaturesController::normaliseKey` strips non-alphanumerics before the
  icon lookup, so `qur’an` and `quran` both resolve — one normaliser is the only thing
  standing between the two records. Anything that compares `$feature->key === 'quran'`
  passes in a test seeded from the repo's intent and fails on production. **The derived
  path must emit the STORED key, `qur’an`, not the repo's spelling**, or the payload
  changes byte-for-byte on the one feature the Play build matches by name.
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

## Org 17 is where everyone looks away

Three separate misses tonight, on the same organisation, by three people:

1. The acceptance criterion pinned it as "no change" when it is the **only** organisation
   whose row set is guaranteed to change — the rehearsal would have failed it with a
   misleading complaint about row order.
2. The fix for that turned the pin into a blanket waiver, until `expected_row_ids` was
   added to name a destination.
3. My own production capture — the one this review rests on — **did not include it**, and
   would have been committed without it.

The pattern is worth naming because it will recur: the sandbox is the organisation people
skip *because* it is a sandbox, and it is simultaneously the only one exercising the
interesting path. Everything in S2 that says "for every organisation" should be checked
against org 17 specifically, by someone who has been told why.

## What this review could not do

No PHP on this machine: nothing was executed. Every finding is source reading plus
read-only production data, and the attacks are reasoned rather than fired. Combined with
`C-1` — the shipped iOS binary's source is not identifiable in the repo — the client-side
conclusions are the softest part of the review and should be treated as leads for the
device evidence plan rather than as settled facts.

---

## Addendum: R1 composed with `features.lastgood`

Asked after the review closed, by the session that owns the rehearsal: *can a transient
`[]` poison the thirty-day last-good cache?* The review answers it, and the answer is
worse than either finding alone.

**First, what is actually shipped.** `lastgood` appears in exactly two places at HEAD — a
docblock sentence in `MobileCache.php` and a test pinning that `flushFamily` leaves it
alone. **Nothing writes it, nothing reads it.** The thirty-day cache, the four-step chain
and the `Cache::remember` closure are all §7.4 *specification*, not code. Three lenses
described the landmine as live; they were describing the plan.

**Second, the composite.** As specified, the closure writes lastgood from whatever
`rows()` returned, and the only thing that makes a payload "good" is that it did not
throw. `R1` is precisely a path that returns `[]` **without throwing**. So a single
transient failure on an organisation with no pivot rows would be recorded as the last
known-good answer — and the fallback built to survive a bad day becomes the mechanism
that extends it.

**Third, the correction the critic makes to all three lenses.** The put is *inside* the
closure, so it fires on every cache miss — at least every ten minutes under any traffic —
and only when the build succeeded. A wrong body is therefore overwritten by the next
successful build, and `down()` deleting the marker means the next miss rewrites lastgood
from the pivot. The "permanent floor that no flush can clear" framing is wrong for any
organisation with traffic, and an organisation without traffic never reads lastgood
either.

**So the fix is not a flush lever.** Two changes, each cheap, and together they close the
composite:

1. `R1`'s: treat an empty pivot result as a **miss**, not a success, so the chain falls
   through instead of serving a blank drawer with HTTP 200.
2. `SEQ-M2`(a), which the critic endorses as the only proposal that survives the above:
   until S3a, write `features.lastgood` **only** from `LegacyFeatures::fromPivot()`, never
   from the derived path. The pivot is the contract's own reference source and lives until
   S3a, so the cutover cannot poison the safety net it is supposed to fall back on.

**A caution about convergence, since this review leans on it twice.** Three lenses agreed
that `lastgood` was a thirty-day landmine no flush could clear, and all three were wrong:
the convergence meant something real was there, not that the diagnosis was right. Five
lenses agreed on the ON-direction blindness and were right. Agreement between independent
readers is a signal to look, not a verdict — the thing that separated the two cases was
somebody opening the file.

**And a client-side asymmetry worth pinning in the device plan** (from the rehearsal
session): the same transient `[]` is a **one-tab app on iPhone and a full bar on Android**.
iOS gates each tab individually, so an empty list collapses to Home alone; Android's
`visibleTabs` reads empty as "not configured yet" and returns the whole bar. One server
fault, visibly broken on one platform and invisible on the other — and the invisible one
is the more dangerous, because nobody reports it.
