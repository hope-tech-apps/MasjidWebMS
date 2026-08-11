# The Al-Razi demo school

`php artisan demo:seed-school` stands up **Al-Razi Islamic School** — a
complete, entirely fictional tenant on the Schools vertical, with enough data
that every school feature demos convincingly, and one command to take it all
away again.

Everything in it is invented. The names are made up, every address is at
`al-razi-demo.invalid` (RFC 2606 reserves `.invalid`, so nothing there can
receive mail), and every phone number is in the NANP's reserved 555-01xx
fictional block.

---

## Seed it

```bash
php artisan demo:seed-school
```

Optional flags:

| Flag | What it does |
| --- | --- |
| `--admin-password=…` | Sets the principal's password so you can actually sign in. Without it the account gets a random password that is discarded. |
| `--city=<id>` | Attaches the tenant to a specific `cities` row. Defaults to the lowest-numbered city. |
| `--fresh` | Removes the demo tenant first, then seeds it from scratch. |
| `--rollback` | Removes the demo tenant and stops. |
| `--force` | Required to run at all when `APP_ENV=production`. |

**Prerequisite:** the `countries` / `cities` tables must have rows. Geography is
shared reference data, so this seeder borrows it and never creates any — if the
tables are empty, run `php artisan db:seed --class=CountriesCitiesSeeder` first.
The command says so if it applies.

**Re-running is safe.** The seeder creates only what is missing and never
updates anything, so a classroom you renamed, a consent you withdrew or a post
you edited during a demo all survive the next run. Objects with no natural key
(feed posts, threads, awards, recitations) are seeded per group and only when
that group holds none of them, so a group you emptied gets its content back and
a group you edited does not get a duplicate set.

---

## What exists afterwards

**The tenant** — `Al-Razi Islamic School`, `org_type = school`, provisioned
through `OnboardingController@provision`, the same endpoint the Super-Admin
wizard calls. That is what gives it:

- the school **feature bundle** (no worship modules — a school tenant never
  loads Qur'an/adhkār/qibla/tasbīḥ/hadith);
- the school **terminology** pack, so the admin UI says "Families" and
  "Classrooms";
- the three seeded school **forms** — Admissions Interest, Careers Application,
  Withdrawal Request;
- `crm_enabled = true`, without which no group screen loads. Set once, at
  creation; a later run never touches it.

**People** — 4 staff, 21 guardians and 20 students, all as `contacts`. Each
staff member is *also* a `users` row sharing the same address, because that
match is how the app decides which person a logged-in caller is.

**Classrooms** — three groups: `Grade 3 — Boys` (8 students), `Grade 5 — Girls`
(8), `Ḥifẓ Ḥalaqa` (8, drawn partly from the other two). Each has a teacher as
a `leader` membership and proper guardian **edges** — a guardian row that names
its ward, so "may this adult see this child's record?" is answerable from the
row rather than guessed from a role label. A parent with children in two
classrooms holds two edges, which is the case edges exist for.

**Consent** — recorded on most guardian edges (`feed` on some, `media` on
others) and **deliberately absent on exactly one**: Ibrahim Bello, Harun's
father in Grade 3. Absence of a record means no consent, so that is the edge to
open when you want to show the gate. The same father *has* consented for his
other son in the ḥalaqa, which demonstrates that consent is per
(guardian, ward, group) and never leaks sideways.

**The class story** — 8 feed posts across the three groups. Three of them carry
a photograph, written to the private disk through the same code the upload
endpoint uses (randomised filename, tenant-scoped directory, no public URL), so
the consent-gated download endpoint can actually serve them back.

**Messaging** — three threads: two participant-scoped conversations about one
student each, and one group-wide announcement discussion. Message authors are
`users`, because contacts cannot authenticate anywhere in this application yet —
so these read as the school's side of a conversation whose audience is the
guardian.

**Behaviour** — four skills (Participation, Kindness, Qur'an Recitation,
Disruption) and 34 awards scattered across most of the roster over the last
three weeks. Not every child has the same total, because a real term does not
look like that.

**Ḥifẓ** — 41 recitation entries across the ḥalaqa's eight students, spanning
sabak, sabqi and manzil, with each student at a different point in juz 30. The
manzil entries deliberately sit *earlier* in the muṣḥaf than the sabak ones, so
the progress summary has to prove it only advances on sabak.

**Money — local rows only.** One free offering (*Parent Night — Fall Term*,
3 registrations) and one paid one (*After-School Qur'an Club*, CAD 120.00 for
the term, 2 registrations — one confirmed, one still awaiting payment). Both go
through `RegistrationService`, the same service the public endpoint uses, so
the seat counter and the fee snapshots are real.

> **Nothing is created at Stripe.** No Checkout Session, Customer, Price or
> Subscription exists for any of it, and no `stripe_*` column is written. The
> confirmed paid seat was confirmed through the same seam a webhook would use.

**Public site** — two pages built from the school section types: *Our School*
(`staff_directory`) and *Admissions* (`programs` + `admissions_tuition`). Every
money value on the tuition table is display text; nothing charges from it.

---

## A five-minute demo path

1. **The vertical (30s).** Open the tenant in the admin. Point at the sidebar
   saying *Families* and *Classrooms*, and at Content → the three admissions
   forms that were there the moment the school existed. Nobody configured this;
   it came from `org_type = school`.
2. **A classroom (60s).** Open *Grade 3 — Boys*. Show the roster: a teacher, the
   students, and each student's guardians named as edges. Open Maryam Karim in
   *Grade 5* and show the same parents appearing there too — one family, two
   classrooms, four edges.
3. **The class story and the consent gate (90s).** Open the Grade 3 feed. The
   top post carries a photo. Now open **Harun Bello's** guardian edge: no
   consent record. Explain that his father's view of that same feed carries no
   attachment list at all — not a broken image, no list, because a filename is
   itself a disclosure about a child. Then open the same father's edge in the
   ḥalaqa, where he *has* consented, to show the two do not bleed.
4. **Behaviour (45s).** Open a student's behaviour summary. Then say the part
   that sells it: **there is no leaderboard, and there is no paywall.** A
   child's record goes to their teachers, to them, and to their own guardians —
   never to another parent, never as a class ranking. That is enforced as a
   query constraint, not a setting.
5. **Ḥifẓ (60s).** Open the ḥalaqa and a student's progress. Show that their
   position is a **surah and āyah**, never a percentage, and that their manzil
   over an earlier surah did not move them backwards. This is the flagship
   differentiator — no ClassDojo-style product tracks memorisation at all.
6. **Money and the public site (45s).** Show the two offerings, one free and one
   paid with a confirmed and an outstanding seat. Then open *Admissions* in the
   page builder — tuition table, programs, staff directory — and note it is the
   same builder every masjid tenant already uses.

---

## Remove it

```bash
php artisan demo:seed-school --rollback
```

This deletes the marker tenant and everything underneath it, plus the four staff
accounts at the marker domain. Nothing else is touched — a real tenant sitting
in the same database, with its own contacts, classrooms and private images,
comes through completely untouched. That claim is pinned by
`tests/Feature/DemoSchoolSeederTest::rollback_removes_the_demo_tenant_and_leaves_a_real_one_completely_untouched`,
which seeds exactly such a tenant alongside and asserts every row and every byte
of it survives.

### How the scoping works

- **The marker is an email domain.** The tenant is `office@al-razi-demo.invalid`,
  and `masjids.email` is unique, so it names exactly one row or none. Staff
  accounts are found by the same domain, because `users` has no `masjid_id`
  column in this schema. An address was chosen over a name prefix because
  nobody edits an address to make a screenshot look better, and over a metadata
  flag because a demo fixture is not a reason to migrate the tenant root.
- **The tenant is the scope.** Every table the seeder writes to except `users`
  carries `masjid_id`, so "what the demo created" is a set the database defines,
  not a list the rollback has to keep in sync. The rollback runs with the tenant
  *bound*, so a forgotten `where` could not reach another organization even by
  accident.
- **Bytes go through the model.** Feed posts are purged through `GroupPost` so
  each attachment's own hook removes its file. A raw cascade fires no model
  events and would leave every classroom photo on the private disk forever.
- **Production refuses.** With `APP_ENV=production` the command exits with an
  error unless `--force` is also passed — and it is not called by
  `DatabaseSeeder`, by provisioning, by the scheduler, or by anything else that
  runs on its own. A test asserts nothing in `app/`, `database/` or `routes/`
  references it.
