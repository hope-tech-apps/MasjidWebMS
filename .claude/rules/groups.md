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
   roster. `group_posts.retained_until` + `groups:purge-feed` is the pattern —
   a nullable window plus a purge that reaches the disk THROUGH THE MODEL,
   because a DB cascade fires no model events and orphans bytes forever (see
   `.claude/rules/private-uploads.md`). `groups` and `group_memberships`
   themselves still have no retention policy; that remains to be decided.
4. **Least disclosure by default.** A new group-scoped read is visible to the
   group's leaders and to a contact's own guardians — never to the whole tenant
   because they happen to be a Contact. See "Disclosure" below.

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

**Which person is the caller.** A `Contact` cannot authenticate anywhere in this
application — there is no congregant guard, and the parent/teacher app is T-015.
So the caller's person is resolved by matching their login email to a Contact of
the **bound tenant**, case-insensitively, and only when it resolves to EXACTLY
ONE contact: an ambiguity about identity resolves to no identity. This is an
identity *bridge*, not an escalation (an admin who wanted in could add themselves
to the roster, which they may already do), and it is the seam to replace on the
day contacts get their own login — `GroupAudience::identitiesFor()` and nothing
else.

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

## Tenant isolation

Both models use `BelongsToMasjid`; `group_memberships.masjid_id` is
denormalised so membership queries scope without joining through `groups`. The
controllers keep the `{masjid_id}` route parameter by convention and **never**
hand-filter by it — cross-tenant ids are 404 misses, and a cross-tenant route is
a 403. Proven by `tests/Feature/GroupTenantIsolationTest.php` (model layer) and
`tests/Feature/GroupCrudTest.php` (HTTP). See `.claude/rules/tenant-scoping.md`.
