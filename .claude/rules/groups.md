---
paths:
  - "app/Models/Group.php"
  - "app/Models/GroupMembership.php"
  - "app/Http/Controllers/AdminDashboard/GroupsController.php"
  - "app/Http/Controllers/AdminDashboard/GroupMembershipsController.php"
  - "app/Http/Requests/Admin/Groups/**"
  - "database/migrations/*_create_groups_table.php"
  - "database/migrations/*_create_group_memberships_table.php"
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
**additive** (no destructive migration), and they are obligations, not options:

1. **Private media.** Group media goes to its own table with its own
   `masjid_id`, bytes on the PRIVATE disk under a randomised name, served only
   through an authenticated endpoint that re-resolves the whole ownership chain.
   Follow `.claude/rules/private-uploads.md` exactly — the public `gallery`
   model and `spatie/laravel-medialibrary` are for public images and must NOT be
   used for anything a group produces.
2. **Guardian consent.** A guardian edge records a *relationship*, NOT consent.
   Before any slice publishes a child's photo, name, or progress to anyone, it
   must record consent against the guardian edge (a nullable
   `consent_granted_at` + `consent_scope`, or its own table) and check it at the
   point of disclosure. Absence of a record means no consent.
3. **Retention.** `groups` soft-deletes and memberships are retained with it, on
   purpose: a mis-click must not destroy a roster. That makes retention a
   *policy* decision that still has to be built — a nullable `retained_until`
   plus a purge that reaches the disk (a DB cascade fires no model events, so it
   orphans bytes forever; see `.claude/rules/private-uploads.md`).
4. **Least disclosure by default.** A new group-scoped read is visible to the
   group's leaders and to a contact's own guardians — never to the whole tenant
   because they happen to be a Contact.

## Tenant isolation

Both models use `BelongsToMasjid`; `group_memberships.masjid_id` is
denormalised so membership queries scope without joining through `groups`. The
controllers keep the `{masjid_id}` route parameter by convention and **never**
hand-filter by it — cross-tenant ids are 404 misses, and a cross-tenant route is
a 403. Proven by `tests/Feature/GroupTenantIsolationTest.php` (model layer) and
`tests/Feature/GroupCrudTest.php` (HTTP). See `.claude/rules/tenant-scoping.md`.
