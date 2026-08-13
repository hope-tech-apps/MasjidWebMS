---
paths:
  - "app/Models/Group.php"
  - "app/Models/GroupMembership.php"
  - "app/Models/GroupPost.php"
  - "app/Models/GroupPostAttachment.php"
  - "app/Http/Controllers/AdminDashboard/GroupsController.php"
  - "app/Http/Controllers/AdminDashboard/GroupMembershipsController.php"
  - "app/Http/Controllers/AdminDashboard/GroupPostsController.php"
  - "app/Http/Controllers/AdminDashboard/GroupConsentController.php"
  - "app/Http/Controllers/AdminDashboard/GroupThreadsController.php"
  - "app/Http/Requests/Admin/Groups/**"
  - "app/Models/GroupThread.php"
  - "app/Models/GroupMessage.php"
  - "app/Models/GroupThreadRead.php"
  - "app/Support/GroupAudience.php"
  - "app/Support/GroupPostAttachments.php"
  - "app/Console/Commands/PurgeGroupFeed.php"
  - "config/groups.php"
  - "database/migrations/*_create_groups_table.php"
  - "database/migrations/*_create_group_memberships_table.php"
  - "database/migrations/*_create_group_posts_table.php"
  - "database/migrations/*_create_group_post_attachments_table.php"
  - "database/migrations/*_add_guardian_consent_to_group_memberships_table.php"
  - "database/migrations/*_create_group_threads_table.php"
  - "database/migrations/*_create_group_messages_table.php"
  - "database/migrations/*_create_group_thread_reads_table.php"
  - "app/Models/BehaviorSkill.php"
  - "app/Models/BehaviorAward.php"
  - "app/Http/Controllers/AdminDashboard/BehaviorSkillsController.php"
  - "app/Http/Controllers/AdminDashboard/BehaviorAwardsController.php"
  - "database/migrations/*_create_behavior_skills_table.php"
  - "database/migrations/*_create_behavior_awards_table.php"
  - "app/Models/HifzEntry.php"
  - "app/Support/HifzProgress.php"
  - "app/Support/QuranIndex.php"
  - "app/Http/Controllers/AdminDashboard/HifzEntriesController.php"
  - "database/migrations/*_create_hifz_entries_table.php"
---
# Groups — the org → group → member primitive

`groups` + `group_memberships` are the **second scoping level of the core**
(`DECISIONS.md`, 2026-08-10). One primitive, three verticals: a School's
classroom, a Masjid's ḥalaqa or weekend-school circle, a Community org's
volunteer team. A group belongs to exactly ONE organization.

Everything layered on top later — the group feed, messaging threads, private
group media, behavior points, ḥifẓ tracking, the parent/teacher app — hangs off
these two tables. None of it exists yet, and none of it should be designed into
them retroactively.

## Groups reference people, they never duplicate them

A membership points at an existing `Contact` (the CRM congregant record). Do not
add name/email/phone columns to `group_memberships`, and do not create a
parallel "student" or "parent" table. If a person needs to exist, they are a
Contact first.

## Roles are PHP constants, kinds are PHP constants

`GroupMembership::ROLES` (`leader`, `member`, `guardian`) and `Group::KINDS`
(`general`, `class`, `halaqa`, `team`) are the authority — the columns are plain
strings. Same reasoning as `Masjid::ORG_TYPES`: adding a role or a kind must
never mean `ALTER TABLE … MODIFY` on a live table, which
`.claude/rules/migrations.md` records aborting the SQLite test run for three
days. Validate at the request boundary with `Rule::in(...)`.

These names are **structural, not admin-facing**. A leader is called "Teacher"
in a school and "Ustādh" in a ḥalaqa; that is presentation and belongs to the
terminology pack, never to the constant.

## Guardianship is an explicit edge, not a role label

`role = guardian` alone is ambiguous the moment a group holds two children of
the same parent — it says an adult is *a* guardian here without saying *of
whom*, so no permission check ("may this adult see this child's record?") can be
answered from the row. Therefore:

- a guardian row also carries `guardian_of_contact_id`, naming the ward;
- **one row = one (guardian, ward, group) edge** — a parent with two children in
  one classroom holds two rows;
- the invariant holds in BOTH directions: a `guardian` row MUST carry a ward
  (`required_if`), every other role MUST NOT (`prohibited_unless`);
- the ward must already hold a **participant** membership (`leader`/`member`) in
  that same group, else the edge grants access to a child nobody put there;
- removing a participant removes the guardian edges pointing at them, in
  `GroupMembership::booted()`'s `deleting` hook — so it holds for every caller,
  not just the controller.

The DB unique index dedupes guardian edges exactly (the ward is never null
there) but **cannot** dedupe `leader`/`member` rows, because MySQL and SQLite
both treat NULLs in a unique index as distinct. Duplicate participant membership
is therefore rejected in `GroupMembershipsController` before insert — that check
is the guarantee, not the index. Do not "simplify" it away.

### PROVENANCE — a guardian edge records ON WHOSE AUTHORITY it exists

A guardian edge is the single fact the parent portal reads to decide whose
child's behaviour, ḥifẓ and safeguarding records a credential opens. It is an
**authorization grant**, and until 2026-08-13 the table recorded no grantor: "the
office established this relationship" and "an anonymous POST to
`/api/v1/offerings/{slug}/register` asserted it" were the same row, so every read
path trusted both equally.

`group_memberships.provenance` is that missing fact
(`GroupMembership::PROVENANCES`, PHP constants for the same reason `role` is):

- **`confirmed`** — an authenticated staff act stands behind it,
  `confirmed_by_user_id` + `confirmed_at` name who and when. Grants what a
  membership always granted.
- **`self_asserted`** — a public form's claim, made with no session, no token and
  no proof of control of any address. It is a **roster fact and not a grant**: it
  lists a person, it counts towards capacity, a teacher keeps behaviour and ḥifẓ
  records about the child it enrols — and it opens **nothing** for its holder.
  `source_registration_id` says which signup asserted it, so the office can judge
  a claim instead of merely seeing one.

Three things make that true, and there is no fourth:

1. `GroupAudience::membershipsFor()` filters to `confirmed()`. ONE clause, in the
   one method every standing question already resolves through, so `mayReceive`,
   `standingIn`, the thread/award/ḥifẓ decisions, the listing queries and
   `Family\GroupsController` honour it by construction rather than by eight
   implementations that agree today.
2. `FamilyAccessService`'s eligibility condition — the SOLE remaining condition —
   counts confirmed edges only. Before this, the panel built to stop a registrar
   handing a nine-year-old a login advertised an anonymously-forged edge as
   `"eligible": true`.
3. `RegistrationService::writeRosterMemberships()` is the ONE unauthenticated
   writer and sets `self_asserted` **explicitly and unconditionally** — never
   from the ambient principal. A free registration confirms in-request behind an
   anonymous POST, a priced one from a Stripe webhook, and an admin may re-drive
   either; the list came from a public form in all three, so reading who pressed
   go would make provenance a fact about the trigger rather than about who
   vouched for the child.

**A MERGE MOVES ROWS AND NEVER LAUNDERS AUTHORITY.** This paragraph used to say
`RosterMergeService::carry()` is "where that claim finally gets its authenticated
act". That was wrong, and it was the third door: measured, an anonymous POST
wrote a duplicate child plus an edge over it, a registrar merged the two
identical rows exactly as the office should, `carry()` re-pointed the edge onto
the REAL child, and the stranger's portal opened her behaviour record, her ḥifẓ
and the thread "Safeguarding: incident on 3 Sept". The registrar authenticated a
**de-duplication**; nothing on the screen, in the request or in the response
named a guardianship. So:

- a `self_asserted` row that moves STAYS `self_asserted`;
- a `confirmed` guardian edge re-pointed at a **different ward** drops back to
  `self_asserted`, because a confirmation names one specific person and
  re-pointing changes the person (this shuts the same door one authenticated act
  further along: confirm over the phantom, then merge the phantom into the real
  child);
- and the opposite failure is closed too — when the survivor holds the same edge
  as an unconfirmed claim and the absorbed row was confirmed, the confirmation is
  CARRIED, so an ordinary de-duplication cannot quietly end a parent's sign-in;
- `ContactsController::merge` reports all of it, because `carry()`'s return value
  used to be discarded.

**The office's door.** `POST …/groups/{id}/members/confirm` with **no body** means
"every pending claim in this group" — a school with 200 camp signups gets one
click on the roster screen, where the ward names and the signup that asserted
them are already in front of the person deciding. `membership_ids` narrows it.
There is deliberately no tenant-wide confirm (one click over rosters its clicker
has never read) and no bulk reject (`destroy()` already exists, already cascades
the guardian edges, and a bulk delete over children's roster rows beside a bulk
confirm is one mis-click from destroying a term's ḥifẓ history). Typing in an
entry a pending claim already holds CONFIRMS it rather than answering 422 —
otherwise the duplicate refusal fires at the office's own remedy.

**What was reverted to get here.** An earlier round made
`Api\V1\OfferingRegistrationsController` resolve a REGISTRANT only to a contact
it created in that same request, never to a pre-existing one. It is gone. It
enumerated doors (the `payer` field is the same writer one field away and was
never guarded) and it caused three defects: one anonymous POST forked the
directory on a teacher's address and permanently 403'd her out of the classroom
she teaches (`GroupAudience` resolves a staff caller by `LOWER(email)` and
requires exactly one contact); a returning child became N people on one roster,
walking past the duplicate-participant refusal below because the writer created a
new PERSON; and the prescribed reconciliation was the merge verb, which is door
three. One resolver, find-or-create on `(masjid, LOWER(email))`, is what exists
now — with a NAME clause on registrants so two siblings on one household mailbox
stay two people, and with the rule that a contact this endpoint CREATES never
carries an address another contact already holds, which is what makes a staff
identity unambiguous whatever name a caller pairs with it. It is still not an
existence oracle: the endpoint answers the same 200 with the same body and writes
a contact either way.

## Naming a group is the tenant's vocabulary

The admin-facing word for a group comes from the terminology pack —
`$masjid->term('groups')` → "Halaqat" / "Classrooms" / "Teams" — and is served
as `meta.group_label` on the groups endpoints. **Never hardcode "Classroom".**
See `.claude/rules/verticals.md`. Unbound (no tenant on the request) degrades to
the neutral "Groups": the absence of a tenant is not a reason to speak another
vertical's language.

## Authorization reuses the CONTACTS permissions

The group endpoints are gated by `permission:view contacts` /
`permission:manage contacts`, inside the existing `crm` group (so
`masjids.crm_enabled` still governs the whole surface). A group is a structure
*over* the member directory and carries no data of its own beyond names and
roles. Minting `view groups` / `manage groups` would also change the seeded
permission set that `RolesAndPermissionsSeeder` and `RolePermissionBridgeTest`
pin (`assertSame(8, Permission::count())`), which the additive Groups slice must
not do. Splitting them out is a deliberate later step — do it as its own task,
with the seeder, a re-run migration, and that test updated together.

## Minors' data — what every FOLLOW-ON slice must honour

These rosters hold children. The schema was shaped so the next slices are
**additive** (no destructive migration), and they are obligations, not options.
T-005b (the group feed) discharged the first three for the feed surface; a new
group-scoped surface — messaging, points, ḥifẓ — must discharge them again, and
should reuse the machinery below rather than re-decide it.

1. **Private media.** ✅ *Built (T-005b).* Group media goes to its own table with
   its own `masjid_id`, bytes on the PRIVATE disk under a randomised name,
   served only through an authenticated endpoint that re-resolves the whole
   ownership chain. Follow `.claude/rules/private-uploads.md` exactly — the
   public `gallery` model and `spatie/laravel-medialibrary` are for public
   images and must NOT be used for anything a group produces. The
   implementation is `group_post_attachments` + `App\Support\GroupPostAttachments`
   + `GroupPostsController::downloadAttachment`; a fourth private-file feature
   copies that, it does not invent a fourth arrangement.
2. **Guardian consent.** ✅ *Built (T-005b).* A guardian edge records a
   *relationship*, NOT consent. Before any slice publishes a child's photo,
   name, or progress to anyone, it must record consent against the guardian edge
   and check it at the point of disclosure. Absence of a record means no
   consent. See "Consent" below.
3. **Retention.** ✅ *Built for the feed (T-005b).* `groups` soft-deletes and
   memberships are retained with it, on purpose: a mis-click must not destroy a
   roster. **And since 2026-08-13 the soft delete is REFUSED outright while the
   group is a live offering's `group_id`** (`GroupsController::destroy`, 422,
   naming the programs). `offerings.group_id` is nullable with `nullOnDelete()`,
   so the FK looks like it handles this — it does not, because a soft delete
   fires no FK and leaves the pointer dangling at a row that no longer resolves.
   Measured before the guard: a family's paid registration webhook 500'd three
   times, booked nothing, and the reaper cancelled her seat 46 minutes later
   (.claude/rules/registration-billing-data.md, T-006c/T-006g). The
   non-destructive paths the refusal points at are detaching the offering,
   re-pointing it at the replacement classroom, or `is_active = false` on the
   group. Soft-deleted offerings do not block. `group_posts.retained_until` + `groups:purge-feed` is the pattern —
   a nullable window plus a purge that reaches the disk THROUGH THE MODEL,
   because a DB cascade fires no model events and orphans bytes forever (see
   `.claude/rules/private-uploads.md`). `groups` and `group_memberships`
   themselves still have no retention policy; that remains to be decided.
4. **Least disclosure by default.** A new group-scoped read is visible to the
   group's leaders and to a contact's own guardians — never to the whole tenant
   because they happen to be a Contact. See "Disclosure" below.

T-005c (messaging), T-013 (behaviour points) and T-014 (ḥifẓ) each discharged 3
and 4 again for their own surface, reusing this machinery rather than
re-deciding it. None of them carries bytes, so obligation 1 does not arise; see
their sections below for what each one *did* have to decide. T-014 is the one
that answers obligation 3 with a **different** policy — bounded by the roster
rather than by a clock — and says why.

## Disclosure is not administration

`App\Support\GroupAudience` is the ONLY place that answers "may this caller
receive this disclosure about this group?", so the feed listing, one post and an
image download cannot drift apart. Any new group-scoped read goes through it.

The split it enforces, and the reason a `permission:` middleware alone was not
enough:

- **Writing** a group-scoped record is `permission:manage contacts` — the
  accountable roster administrator, same gate as the roster endpoints.
- **Reading** additionally requires being IN the group. `view contacts` is held
  by every masjid admin, so gating a child's photograph on it would publish the
  class story to the whole tenant, which obligation 4 forbids.

An admin who is not on the roster can therefore publish to a group and NOT read
it back. That asymmetry is deliberate; do not "fix" it by adding a staff bypass.

**Which person is the caller.** For a STAFF caller, resolved by matching their
login email to a Contact of the **bound tenant**, case-insensitively, and only
when it resolves to EXACTLY ONE contact: an ambiguity about identity resolves to
no identity. This is an identity *bridge*, not an escalation (an admin who wanted
in could add themselves to the roster, which they may already do), and it lives
in `GroupAudience::identitiesFor()` and nothing else.

> **Amended by T-015c, then by T-015e.** This paragraph used to open "A
> `Contact` cannot authenticate anywhere in this application — there is no
> congregant guard". That stopped being true when T-015c gave a contact its own
> `family` guard (`.claude/rules/auth-permissions.md`). T-015c's amendment then
> said an authenticated parent resolved to NO identity; **that is now also
> out of date, and this is the update it promised.**
>
> **A parent is now a caller.** `identitiesFor()` has a `Contact` branch: a
> contact resolves to its OWN id — never its ward's — subject to three liveness
> checks (`familyLoginIsActive()`, i.e. enabled + not revoked + not trashed, and
> `masjid_id` equal to the BOUND tenant, re-checked here independently of
> `family.tenant`). Anything that is neither a live `Contact` nor a `User` still
> resolves to `[]`.
>
> **Nothing else in this file changed, and that is the point.** `identitiesFor()`
> was the ONLY place T-015e touched in `GroupAudience`; `standingIn`,
> `mayReceive`, `mayReceiveThread`, `mayReceiveRecordAbout`,
> `readableThreadsQuery`, `readableAwardsQuery`, `readableHifzQuery` and
> `constrainToOwnStudents` are untouched, so every rule below holds for a parent
> BY CONSTRUCTION rather than by a second implementation that happens to agree.
> A guardian still gets the feed only where consent covers it, still reads
> participant threads / awards / ḥifẓ only about their own ward, and is still
> excluded at QUERY level from another family's rows.
>
> **Amended again, 2026-08-13: A FAMILY CREDENTIAL SPEAKS FOR WARDS ONLY.**
> `GroupAudience::membershipsFor()` is a second — and last — place that touches
> the principal, and it is a SCOPE question rather than an identity one: for a
> `Contact` principal it keeps the guardian edges and DROPS the holder's own
> `leader`/`member` rows. So through the parent portal a participant row buys
> no feed, no participant thread about the holder, no award, no ḥifẓ record, and
> no standing in that group at all (`/groups` omits it, `/groups/{id}` 403s).
> STAFF callers are untouched — a participant is still the person themselves and
> still holds their own group outright.
>
> This closes the hazard the paragraph above used to leave open ("enabling a
> login on a child's contact row is a student login"), and it replaces a
> different answer that did not survive contact with a real school: refusing a
> credential to ANY contact holding a participant edge, plus a
> `GroupMembership::created` hook that revoked one when a roster row arrived
> later. That pair refused a parent enrolled in the adult ḥalaqa and a teacher
> who is also a parent, and the hook destroyed working credentials as a side
> effect of an ordinary roster add — reachable, measured, by an anonymous POST
> to the public registration endpoint. Controlling what a credential READS is
> the property; controlling who may hold one was a proxy that over-refused and
> still needed patching from behind. **`FamilyAccessService::enable()` now asks
> only that the contact be somebody's guardian over a live ward** — a child's own
> row is nobody's guardian, so the registrar-and-the-nine-year-old case is still
> shut, by the condition that was always doing that work.
>
> A STUDENT login remains its own task and is still not built: this narrowing
> gives a family credential NOTHING from a participant row, not the narrower
> own-record slice a student login eventually should.
>
> `Http\Controllers\Family\GroupsController` asks the same question through the
> same call rather than querying `group_memberships` itself, which it used to —
> a second definition of standing living outside the class that owns it is the
> drift `GroupAudience` exists to prevent, and it disagreed the moment this rule
> changed.
>
> The parent-facing endpoints are `routes/family.php` +
> `app/Http/Controllers/Family/` — READ-ONLY, no `permission:`, no roster
> listing, and attachments served as bytes through an authenticated endpoint
> rather than as a signed URL (a signed URL would survive consent withdrawal).
> Pinned by `tests/Feature/FamilyPortalTest.php`, whose whole fixture is TWO
> families in ONE classroom, plus
> `tests/Feature/ContactLoginCodeTenantIsolationTest.php` for the cross-tenant
> half. `tests/Feature/FamilyAuthGuardTest.php` still pins the guard, the
> liveness checks and the staff bridge.
>
> Still NOT built, deliberately: a parent writing a message or moving a read
> bookmark (**T-015f** — `group_thread_reads.user_id` is NOT NULL and points at
> `users`, so no Contact can be written there today and no `unread` flag is
> served), and a parent withdrawing their own consent (**T-015h**).

## Consent

Recorded on the guardian edge as `consent_granted_at` + `consent_scope`
(nullable, added additively; every pre-existing row correctly reads as "no
consent"). Because the edge already says guardian-of-WHOM-in-WHICH-group,
consent cannot leak sideways to a parent's other children or their other groups:
a parent with two children in one classroom consents twice, because those are
two decisions.

- `GroupMembership::CONSENT_SCOPES` = `feed`, `media` — PHP constants, not a DB
  enum, for the same reason as `ROLES`.
- The scopes are a **hierarchy**: `media` covers `feed`. A photograph is a
  sharper disclosure than a note, so it takes its own explicit grant.
- Consent is meaningful ONLY on a guardian row. A leader/member IS the person,
  and nobody consents on their behalf — `consentCovers()` returns false on a
  participant row even if the columns are somehow populated.
- Written by `GroupConsentController`, checked by `GroupAudience`. Both halves
  are mandatory: a recorded consent nobody reads is paperwork, and a check with
  nothing to read is a guess.
- Withdrawal nulls both columns, returning the row to the never-consented state
  — "absence of a record means no consent" only works if that state is
  reachable.

Least disclosure is applied to the PAYLOAD, not just the download: a reader
without media consent gets no attachment list at all, because a filename and a
file size are themselves a disclosure about a child. The response says
`media_withheld` so "no photos this week" is not confused with "not allowed".

## Messaging threads (T-005c)

`group_threads` + `group_messages` + `group_thread_reads` are the leader ↔
members/guardians channel. What a follow-on slice must not re-decide:

- **The thread's `scope` is the disclosure shape.** `group` = the feed
  audience (the decision IS `GroupAudience::mayReceive(..., feed)` — one
  decision, not a parallel one); `participant` = the group's leaders plus the
  ONE member/guardian the thread concerns, named explicitly by
  `about_membership_id` — an edge to the member's participant membership,
  mirroring how a guardian row names its ward. All of it is decided in
  `GroupAudience::mayReceiveThread()` / `readableThreadsQuery()`, never inline
  in a controller.
- **Consent gates broadcasts, not conversations.** A guardian with no consent
  record still reads (and writes in) a participant thread about their own
  ward — requiring feed consent there would block a parent from talking to
  the teacher about their own child. Group-wide threads stay consent-gated
  exactly like the feed.
- **Writing a message requires being able to READ the thread** on top of
  `manage contacts` — the one place the feed's read/write asymmetry does NOT
  carry over, because speaking in a conversation is not publishing an
  announcement. Opening/closing/soft-deleting a thread is plain roster
  administration (`manage contacts`, no read gate).
- **Fail closed.** An unrecognized stored scope degrades to participant
  (leaders-only), and a participant thread whose target membership was removed
  from the roster (`about_membership_id` nulls on delete) is readable by
  leaders only — the record survives, the audience shrinks.
- **Text only, rows only.** Attachments are deliberately deferred; the feed
  owns media. That is why `GroupThread::purge()` may rely on the DB cascade
  (nothing on disk to orphan) where `GroupPost::purge()` must not. Retention
  is the same pattern (`retained_until` from `config('groups.messaging')`),
  swept by the SAME `groups:purge-feed` command.
- **Unread is a bookmark, not a receipt**: one `last_read_at` per
  (thread, user) in `group_thread_reads`, moved on view/write. It is never an
  authorization record.

Proven by `tests/Feature/GroupMessagingTest.php` +
`tests/Feature/GroupMessagingTenantIsolationTest.php`.

## Behaviour / recognition — the Classroom module (T-013)

`behavior_skills` (per-tenant vocabulary) + `behavior_awards` (one skill given
to ONE student) are the behaviour layer on top of the Groups primitive. They add
no new group system: the student is named by their `group_memberships` row, the
same explicit edge a guardian names its ward with.

**Two constraints below are design constraints, not preferences.** They come
from the two loudest, most-documented complaints about ClassDojo, the product
this module answers (`docs/recon-2026-08-11.md`, DECISIONS.md 2026-08-10), and
being the opposite on both is the differentiator. Do not "add an option" for
either.

### 1. Points are PRIVATE. There is no leaderboard.

A child's record is disclosed to the group's **leaders**, to the **student**,
and to **that student's own guardians**. Never to another guardian in the same
group; never to the whole tenant; never as a class-wide ranking.

- The decision is `GroupAudience::mayReceiveAwardsAbout()` and
  `GroupAudience::readableAwardsQuery()` — in `GroupAudience`, never in a
  controller, for the same reason the feed and threads are.
- It is enforced at **QUERY level**, exactly as `readableThreadsQuery()` does:
  a forbidden award is never fetched, so it cannot surface in a page, in a
  paginator total, or inside an aggregate. The endpoint 403 and the query
  constraint are both required — the 403 is honest to a parent who mistyped an
  id, and the constraint is what makes the honesty safe.
- Every aggregate is **per student**. There is deliberately no class-wide
  endpoint, no rank column, and no comparison payload. If a future slice wants
  a teacher's overview, it is a list of per-student rows a leader is already
  entitled to — not a ranking, and not something a guardian can reach.
- **Consent gates broadcasts, not a parent's view of their own child** — the
  same call T-005c made for participant threads. A guardian with no consent
  record still reads their own ward's awards; requiring feed consent there
  would lock a parent out of the record most obviously theirs.

Proven by `tests/Feature/BehaviorAwardsTest.php` (endpoint AND listing-query
halves) + `tests/Feature/BehaviorTenantIsolationTest.php`.

### 2. Nothing in this module is paywalled.

No `plan`, `tier` or `is_premium` column exists on either table, no controller
checks one, and there is no cap on how many skills a tenant may define or how
many awards it may give. Behaviour points, notes, the per-student summary and
the retention sweep are all base product. The `crm` middleware these routes sit
behind is the pre-existing per-tenant module toggle (`masjids.crm_enabled`) that
every group surface already uses — it is not a plan gate, and no new gate was
introduced. A future contributor adding one is changing the product's
positioning, not its configuration.

### What else this slice re-decided, and why

- **Point values are SNAPSHOTTED** onto the award (`skill_label`,
  `skill_polarity`, `points`), the same reasoning as the fee-plan snapshots on
  `registrations`: renaming or re-weighting a skill describes next term, it does
  not retroactively restate what a child was told in October.
  `behavior_awards.behavior_skill_id` is provenance only and nulls on delete;
  nothing on the read path re-reads the skill for a value.
- **`behavior_skills` does NOT soft-delete**, deliberately breaking with groups
  and posts. The mis-click guard exists to protect records ABOUT CHILDREN, and
  the vocabulary holds none — deleting a skill removes a drop-down entry and
  nothing else. Retiring one is `is_active = false`.
- **`behavior_awards.group_membership_id` CASCADES**, where
  `group_threads.about_membership_id` nulls. A thread is a conversation between
  adults that survives with a shrunken audience; an award's ENTIRE audience is
  derived from the membership row, so a dangling award would be unreadable data
  about a minor, retained forever. Least disclosure says it goes with them.
- **Revocation IS the soft delete.** `deleted_at` is the revocation clock, so
  one mechanism drops an award from every listing and every total; there is no
  parallel `is_revoked` flag a query could forget. `revoked_by_user_id` records
  who corrected the record.
- **Retention** joins the existing story (obligation 3): `retained_until` from
  `config('groups.behavior.retention_days')`, swept by the SAME
  `groups:purge-feed` command. Rows only — no bytes — so `purge()` is a plain
  `forceDelete()`, as it is for threads.
- **Permissions**: `view contacts` / `manage contacts`, minting nothing.
  `Permission::count() === 8` stays pinned.

## Ḥifẓ tracking — Qur'an memorization (T-014)

`hifz_entries` is one recitation heard from ONE student, on the same primitive:
a **ḥalaqa IS a group**, and the student is their `group_memberships` row — the
same explicit edge a guardian names its ward with and an award names its subject
with. This section lives here, and not in a `hifz.md` of its own, because the
only question that could be answered twice — *who may read a record about a
child* — is answered ONCE, by the same `GroupAudience` code the awards use.
Splitting the two across two documents is exactly the drift `GroupAudience`
exists to prevent.

### The domain, which is not negotiable

The classical daily cycle is the schema. `HifzEntry::KINDS`:

- **`sabak`** — the NEW lesson memorised today;
- **`sabqi`** — recent memorisation under active revision (the last juz or so);
- **`manzil`** — older, consolidated memorisation on a long rotation.

**ONLY SABAK ADVANCES A STUDENT.** A manzil entry over al-Baqarah does not mean a
child memorising juz 30 went backwards; it means they revised what they already
hold. Any future code that treats a revision entry as progress is wrong.

**PROGRESS IS A POSITION, NEVER A PERCENTAGE.** Everything is reported as surah +
ayah + juz. "62% memorised" is a number no ḥalaqa uses and no ijāza recognises;
do not add one, including as a convenience field.

The classical names are kept as-is rather than translated to "new/recent/old" —
they are what every ḥifẓ teacher already says. What a UI *labels* them is
presentation, exactly as with `GroupMembership::ROLES`.

### Position is DERIVED, and that is the load-bearing decision

There is no `current_surah` / `current_ayah` column anywhere. A student's
position IS their sabak history:

- **current** position = the end of the LATEST sabak entry (not the furthest —
  a child sent back over earlier material is at that lesson today, and reporting
  the high-water mark would tell a parent their child is somewhere they are not.
  Both are served, because they are different facts);
- **how much** is memorised = the union of every sabak range, merged;
- **juz completed** = per-juz COVERAGE, never position ÷ juz length — ḥifẓ is
  commonly memorised from juz 30 backwards, and a linear reading would report
  thirty completed juz for a beginner at an-Naba.

A denormalised column would need every writer guarded the way
`registrations.registration_count` had to be, and would still be wrong in the
case that matters most: striking a mis-recorded sabak must move the position
BACK. `App\Support\HifzProgress` is the one place the derivation lives; it is
handed an ALREADY audience-constrained query and never decides access itself.

### The range, and why there is no juz or page column

`(from_surah, from_ayah) .. (to_surah, to_ayah)` — a closed interval that may
cross surahs, because revision does ("revise juz 26" is 46:1 .. 51:30).

- **No `juz` column**: juz is a function of (surah, ayah), so storing it would be
  a second writer for one fact. `QuranIndex::juzFor()` derives it.
- **No `page` column**: a page number is meaningless without naming which muṣḥaf
  it refers to — Madani 15-line, Indo-Pak 16-line and regional printings
  paginate differently, so a bare integer is a number two teachers in one school
  read differently. Ayah-precise ranges are edition-independent and convert into
  any pagination later; a stored page never converts back.

`App\Support\QuranIndex` is a **PHP constant table, not a seeded DB table** — 114
ayah counts (Kufan/Ḥafṣ counting, checksum 6236) plus the 30 juz boundaries.
Reference data fixed for fourteen centuries that no tenant may edit belongs in
code, for the same reason `Masjid::ORG_TYPES` does; a seeded table would make
every validation a query and a half-seeded tenant would validate nothing. It
carries NO Qur'an text, no translation and no page map, and must not grow one.

### Privacy — the same rule, the same code

A ḥifẓ record reaches the ḥalaqa's **leaders**, the **student**, and **that
student's own guardians**. Never another guardian in the same ḥalaqa, never the
whole tenant, never a class-wide ranking of who has memorised most.

- `GroupAudience::mayReceiveHifzAbout()` / `readableHifzQuery()` both delegate to
  the private `mayReceiveRecordAbout()` / `constrainToOwnStudents()` that T-013's
  award methods now also use. One implementation, two named entry points — a
  future slice that genuinely needs a different rule has an obvious place to put
  it, and until then the two cannot disagree.
- Enforced at **QUERY level** as well as at the endpoint: a forbidden entry is
  never fetched, so it cannot surface in a page, a paginator total, or a
  memorisation aggregate. Every model routed through `constrainToOwnStudents()`
  MUST expose its subject as a `membership` relation.
- There is deliberately **no group-wide progress endpoint**. A leader's listing
  is per-student rows they are already entitled to; a "top memorisers" board is
  T-013's public shaming aimed at Qur'an.
- Consent gates broadcasts, not a parent's view of their own child — the same
  call T-005c and T-013 made.

### Retention — a DELIBERATE departure from every other group surface

**`hifz_entries` has no `retained_until` and is NOT in `groups:purge-feed`.** Do
not "finish the job" by adding it. The feed, the threads and the awards describe
a *moment*, so bounding them by default is right. A ḥifẓ record is an **academic
record**: it is the only evidence of what a student has memorised, a school that
loses last year's sabak entries cannot tell a new teacher where the child is, and
— decisively — the position is DERIVED, so a sweep that removed the newest sabak
would silently move a child backwards in the muṣḥaf. The record's lifetime is
bounded by the **roster** instead: `group_membership_id` cascades, so a student's
entries go with them, which is also what keeps obligation 4 satisfied (a dangling
entry would be unreadable data about a minor, kept forever).

**Correction is the soft delete**, as revocation is for an award: `deleted_at`
drops the entry from every listing, every total and every derivation at once, and
`corrected_by_user_id` records who did it. There is no update endpoint on
purpose — striking and re-recording leaves an audit trail where an in-place edit
would quietly rewrite what a teacher said they heard.

**Permissions**: `view contacts` / `manage contacts`, minting nothing.
`Permission::count() === 8` stays pinned. Nothing here is paywalled.

Proven by `tests/Feature/HifzTrackingTest.php` (endpoint AND listing-query
halves) + `tests/Feature/HifzTenantIsolationTest.php` +
`tests/Unit/QuranIndexTest.php`.

## Tenant isolation

Both models use `BelongsToMasjid`; `group_memberships.masjid_id` is
denormalised so membership queries scope without joining through `groups`. The
controllers keep the `{masjid_id}` route parameter by convention and **never**
hand-filter by it — cross-tenant ids are 404 misses, and a cross-tenant route is
a 403. Proven by `tests/Feature/GroupTenantIsolationTest.php` (model layer) and
`tests/Feature/GroupCrudTest.php` (HTTP). See `.claude/rules/tenant-scoping.md`.
