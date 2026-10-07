---
paths:
  - "app/Models/Group.php"
  - "app/Models/GroupMembership.php"
  - "app/Models/GroupPost.php"
  - "app/Models/GroupPostAttachment.php"
  - "app/Models/GroupMessageAttachment.php"
  - "app/Http/Controllers/AdminDashboard/GroupsController.php"
  - "app/Http/Controllers/AdminDashboard/GroupMembershipsController.php"
  - "app/Http/Controllers/AdminDashboard/GroupPostsController.php"
  - "app/Http/Controllers/AdminDashboard/GroupConsentController.php"
  - "app/Http/Controllers/AdminDashboard/GroupThreadsController.php"
  - "app/Http/Requests/Admin/Groups/**"
  - "app/Models/GroupThread.php"
  - "app/Models/GroupMessage.php"
  - "app/Models/GroupThreadRead.php"
  - "app/Models/GroupMessageSchedule.php"
  - "app/Http/Controllers/AdminDashboard/GroupMessageSchedulesController.php"
  - "app/Http/Requests/Admin/Groups/*ScheduledMessageRequest.php"
  - "app/Http/Requests/Admin/Groups/ValidatesSendAt.php"
  - "app/Services/Groups/GroupThreadWriter.php"
  - "app/Services/Groups/GroupStoryPublisher.php"
  - "app/Services/Groups/ScheduledSendGate.php"
  - "app/Support/ScheduledTime.php"
  - "app/Console/Commands/PublishDueGroupItems.php"
  - "database/migrations/*_add_scheduling_to_group_posts_table.php"
  - "database/migrations/*_create_group_message_schedules_table.php"
  - "app/Support/GroupAudience.php"
  - "app/Support/GroupThreadUnread.php"
  - "app/Models/GroupResource.php"
  - "app/Models/GroupResourceRecipient.php"
  - "app/Http/Controllers/Teacher/ResourcesController.php"
  - "app/Http/Controllers/Family/ResourcesController.php"
  - "app/Support/GroupResourceFiles.php"
  - "database/migrations/*_create_group_resources_table.php"
  - "database/migrations/*_create_group_resource_recipients_table.php"
  - "app/Support/GroupPostAttachments.php"
  - "app/Support/GroupMessageAttachments.php"
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
  - "app/Support/PointsWeek.php"
  - "app/Support/SchoolPointsWeek.php"
  - "app/Http/Controllers/Teacher/PointsPeriodController.php"
  - "app/Console/Commands/SendWeeklyPointsReports.php"
  - "app/Mail/WeeklyPointsReportMail.php"
  - "app/Models/BehaviorWeek.php"
  - "app/Models/MasjidPointsSetting.php"
  - "app/Support/PointsReportSchedule.php"
  - "app/Services/Groups/GroupNotificationRecipientResolver.php"
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
- a `confirmed` guardian edge whose PAIR changes — either end — is retired and
  re-issued as a fresh `self_asserted` claim with a NEW id, because a
  confirmation names one specific adult over one specific ward and changing
  either end makes it a statement about somebody nobody was asked about (this
  shuts the same door one authenticated act further along: confirm over the
  phantom, then merge the phantom into the real child). The new id also means a
  Confirm list drawn before the merge names a row that no longer exists, so the
  click is an honest `{"confirmed":0,"skipped":1}`;
- **AN ABSENT VALUE IS NEVER AN IDENTITY MATCH**, and this is the one rule that
  answers both ends. See the section below — it is where the previous round put
  a regression.
- when a confirmed edge is DROPPED because the survivor already holds the same
  edge, the confirmation is **not** carried onto the survivor's row — the
  survivor may be a row nobody vouched for, and the same caller who authored it
  can author its claim over the ward and collect the confirmation. It is
  COUNTED instead (`unconfirmed`, `confirmed_guardian_edges_dropped`,
  `family_logins_left_without_a_ward`), so an ordinary de-duplication cannot
  quietly end a parent's sign-in *in silence*. Losing a click is recoverable;
  the other direction is a stranger reading a child's safeguarding record.
  (This bullet used to say the confirmation was CARRIED. It was, once; the code
  changed and this sentence did not.)
- `ContactsController::merge` reports all of it, because `carry()`'s return value
  used to be discarded.

### AN ABSENT VALUE IS NEVER AN IDENTITY MATCH

`App\Support\ContactIdentity` is the only place this application asks "are these
two rows the same person", and the answer is **false whenever either side has no
address — including when neither does.**

The previous round wrote the holder end as
`addressOf($source) !== addressOf($target)` with `addressOf()` returning `''` for
a contact with no email, and reasoned in that method's own docblock only about
`'' != 'real@x'`. So two address-less adults were the same person. Measured, from
a caller with no account and no token: an anonymous
`POST /api/v1/offerings/{slug}/register` plants a second address-less contact
under a real parent's name (`registrants.*.email` is `nullable`, and
`createContact()` nulls an address another contact already holds); the registrar
de-duplicates; and the confirmed guardian edge lands on the stranger's row
carrying `provenance: confirmed`, `confirmed_by_user_id` and `consent_scope:
media`, reported as `unconfirmed: 0`. Family-login then answered
`"eligible": true`, enable answered 200, and the stranger's own token read
`/groups`, the thread "Safeguarding: incident on 3 Sept", the award "Left the
classroom without permission" and the ḥifẓ sabak.

Three consequences, and none of them is optional:

- **An address-less row is the ONE shape an unauthenticated caller can plant.**
  Nulling the address is `createContact()`'s refusal. So the absent shape is
  precisely the shape that must never match.
- **The rule is enforced by a TYPE, not by a paragraph.** `ContactIdentity` hands
  back no string at all — no getter, no `__toString` — so there is nothing to
  compare with `===`, and the only comparison is `isTheSamePersonAs()`. A caller
  who ignores that and compares two instances gets "changed", which re-opens a
  row that did not need it: a lost click, not a laundered grant. The previous
  round had the correct rule written out in prose on the ward end and the
  opposite code twenty lines later, which is why prose is no longer where it
  lives.
- **The ward end still has NO address exemption, even with the fixed
  comparison**, and `ContactIdentity` is deliberately not called there. A ward is
  a child; most have none and the siblings who do share the household mailbox.
  Any change of ward retires and re-issues.

A phone number is shown on a roster (an operator can ring it) and is **not** an
identity: nothing mints a credential against one and nothing resolves a principal
by one, so it cannot answer "would a credential reach the same person".

And the merge SCREEN says it before the click. `ContactsView.vue` renders each
candidate's address — "no address on file" in red, never a blank, the same call
`GroupRosterTab.vue` made on the roster — and warns outright when the two records
share a displayed name and at least one has no address, because that is the shape
a stranger produces and the shape an operator has nothing to judge with.

**The office's door.** `POST …/groups/{id}/members/confirm` is still ONE click for
a school with 200 camp signups, and the click now has to say what it read. Three
fields, each added because binding to the previous set was not enough:

- `membership_ids` — **required**. An absent body used to mean "every pending
  claim at the moment the request LANDS", which is not the set the operator was
  shown: measured, a registration arriving while the dialog was open was
  confirmed by a click on the eight rows above it, and the ninth was a stranger's
  claim over a named child. This defeats INSERTION.
- `fingerprints` — **required, one per named id**, echoing the value
  `index()` served with that row (`App\Support\RosterClaimIdentity`). An id
  identifies a row and not what the row SAID: a merge re-points `contact_id` on a
  pending claim and the id does not move, and a merge that force-deletes an
  absorbed payer nulls `registrations.contact_id` without touching the membership
  row at all. A row that no longer matches its description is SKIPPED and
  reported. This defeats MUTATION.
- `contested_membership_ids` — the rows the operator decided **one at a time**. A
  pending guardian claim that shares its ward AND its displayed name with another
  row is refused a place in a sweep, because naming its id binds the agreement to
  a row the operator could not read apart from its twin. Measured: a stranger who
  knew a child's name and household address typed the MOTHER's name as payer with
  his own email, and the roster drew "Aisha Ahmed" twice with no address and no
  ward. Plurality is NOT the trigger — a mother and a father are two legible
  claims and sweep through in the one click; indistinguishability is.

And the screen has to carry what the request claims it carries. This rule and
that request's docblock both used to assert that "the ward names, the claimed
guardian and the signup that asserted them are in front of the person deciding".
Measured in `GroupRosterTab.vue`: `source_registration` 0 times, `confirmed_by` 0
times, no address on either table, and the ward column rendering `—` on every row
because `$snakeAttributes` serialises `guardianOf` as `guardian_of` and the
template read the camelCase name. `index()` now serves a `claim` block per row
(fingerprint, contested + rivals, and the asserting signup's payer **including an
explicit state for each way that evidence can be missing**), the roster tables
draw it, the confirm dialog enumerates guardian claims BY ADDRESS rather than by
count, and `RosterClaimIdentityTest` fails if any of that stops.

There is deliberately no tenant-wide confirm (one click over rosters its clicker
has never read) and no bulk reject (`destroy()` already exists, already cascades
the guardian edges, and a bulk delete over children's roster rows beside a bulk
confirm is one mis-click from destroying a term's ḥifẓ history). Typing in an
entry a pending claim already holds CONFIRMS it rather than answering 422 —
otherwise the duplicate refusal fires at the office's own remedy — and that
response now SAYS when the entry it confirmed has a same-named rival over the
same child, because it is the same grant reached by a different door. **And the
screen shows it.** The server computed that sentence and
`groupsStore.ts::addMembership` returned `res.data.data`, dropping `message`,
while `GroupRosterTab.vue` fired a hardcoded `{icon:'success', title:'Added'}` on
a 1600ms timer — measured, with the contested stranger row confirmed through this
door and the warning present in the body and invisible on screen. The store now
returns `AddMembershipResult` (`membership` + `message` +
`confirmedAnExistingClaim`) and anything the server chose to say is rendered as a
dialog the operator has to dismiss. A promise kept on the wire and broken on the
screen is not kept.

`store()`'s two responses also narrow the related people to the columns the
screen names (`GroupMembershipsController::PERSON_COLUMNS`, shared with
`index()` so the two cannot half-apply again). They used to serve every column of
`contacts` — `login_email`, `login_enabled_at`, `login_revoked_at`,
`last_login_at`, the CRM notes and all four SMS-consent fields. Same audience, so
never an escalation; and .claude/rules/credentials.md keeps those columns out of
request bodies, so a roster verb is not where they belong on the way out.

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

## Student details on the office roster (2026-10-04)

On the office roster a student's name is a button (so is the child's name in a
guardian row's "Guardian of" cell). It opens **Student details**
(`StudentDetailsPanel.vue`), which makes **no request**: it is drawn from the
roster listing the page already holds, by `core/helpers/studentDetails.ts`.

**Only a guardian entry that is `confirmed` AND has no `left_on` is offered as
someone to call.** The helper sorts every guardian entry naming the student into
exactly one list, and only the first carries a `tel:` / `mailto:` link:

| the entry | shown as |
|---|---|
| confirmed, still in the class | name, tap-to-call, email link, consent state |
| not confirmed (a registration-form claim) | name and address as PLAIN TEXT, with a warning |
| has a leaving date | name and "Left {day}" |
| the STUDENT's own row has left | names only, for every entry, and no link at all |

A form claim is somebody who was not signed in saying they are the child's
parent, so it must never be the number the office taps in a hurry. Anything that
is not exactly `confirmed` is read as a claim. A new list or a new link goes
through the helper, never into the template.

The panel is the OFFICE's. A teacher's screen never mounts it and no teacher
payload carries a parent's phone or email (owner, 2026-10-04: office only).

Because the panel is drawn from `GET …/groups/{group}/members`, that listing's
exact key set is pinned (`OfficeStudentDetailsPayloadTest`): a key added to the
row or to its narrowed person fails there and has to be classified.

"Open full record" is a link to the Member Directory with `?contact={id}`. The
id in an address is whatever somebody typed, so `ContactsView` goes through
`openLinkedRecord`: FETCH FIRST, open the dialog only with what the server
returned, say "That record could not be opened." otherwise, and take the id out
of the address either way. It does not reuse `viewContact`, which opens first.

The panel's Grade field is read-only while it saves, never `disabled`: a
disabled field drops keyboard focus behind the dialog and Escape stops closing
it (seen in a browser).

**What the other two roster features put in the panel.** The panel has five
named slots, each handed the student, and `GroupRosterTab.vue` fills them. The
panel itself still makes no request and holds none of their logic:

| slot | filled with | drawn when |
|---|---|---|
| `#moved-badges` | the roster row's own badge (`movedLabels`): "Moved to {Class} {date}" while the student is still there, else "Left {date}" and the line "Was moved to …. No longer there." | always (it replaces the plain "Left" badge) |
| `#moved-from` | "Moved from {Class} {date}" | the row was moved in |
| `#age` | "Age {n}" | a class, and an age is known |
| `#birth-date` | `StudentBirthDateForm.vue` (which asks the server, and draws nothing for a login that may not see the date) | a class; also for a student who has left, so a date can still be removed |
| `#move` | the Move button | a class, role `member`, no leaving date |

"Is this a class" is the server's `meta.teaches_students` for the panel's slots
and the roster's columns, never a kind compared in the browser. (Three other
places in the browser DO compare kind `class` and are listed on
`Group::teachesStudents()`: the Move dialog's class list, its `kind=class`
query, and the teacher's student sheet. Widening "what is a class" means
changing those too.) Move and "Left the class" from the panel shut it first:
one dialog at a time. A saved or removed date patches `age` on the row in place
from the server's answer; the roster is not re-read. The form is handed a
FUNCTION (`:after-change`), not listened to with an event, and the row is found
by its id: the panel can be closed while a save is on its way, which unmounts
the form, and Vue drops an emit from a component that is gone, so the date was
saved and the row went on showing a dash.

A slotted form CAN take the focused control away (the date form turns its Save
button off while it saves), which is the same fault as the disabled Grade
field. So while it is open the panel also listens on the document: with focus
outside the panel, Escape closes it and Tab comes back to its close button. A
key pressed inside the panel, or under a message box, is left alone.

**Joined is a calendar day.** `joined_at` is a date column served as midnight
UTC. The roster row and the panel read the day off the string
(`formatStoredDay`, `storedDayLabel`), as consent and leaving dates already
were: drawn as an instant it showed the day before to everyone west of UTC,
which a move made visible ("Moved from … 4 Oct" beside "Joined Oct 3").

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
two decisions. ONE act copies it to another class, and only one: moving the
student, which carries the same adult's consent for the same child as it was
recorded, marks the copy with the class it came from, and never widens what
the adult already holds in the class entered (the owner, 2026-10-05; "Moving a
student to another class", R8 and R10). Nothing else writes consent but the
office.

- `GroupMembership::CONSENT_SCOPES` = `feed`, `media` — PHP constants, not a DB
  enum, for the same reason as `ROLES`.
- The scopes are a **hierarchy**: `media` covers `feed`. A photograph is a
  sharper disclosure than a note, so it takes its own explicit grant.
- **Consent grants nothing on a row nobody has stood behind.**
  `GroupConsentController::update()` has always refused to WRITE consent onto a
  pending claim; every READ path served the state anyway, so the server refused
  the state it happily read. `GroupMembership::hasConsent()` now consults
  provenance, which puts the gate in front of `consentCovers()`, `show()`,
  `scopeConsented()` and `GroupAudience` at once rather than on one of them.
  A read gate is **not sufficient on its own**: measured, a row left in the old
  state reads as granting nothing, and then one ordinary Confirm click — a
  decision about a RELATIONSHIP, on a screen that says nothing about photographs
  — re-arms the stale `media` grant and the family feed answers 200 with
  `media_withheld: false`. So
  `…_clear_consent_on_unconfirmed_group_memberships` **erases both columns on
  every row whose provenance is not `confirmed`**. Deliberately destructive and
  deliberately wide: an unconfirmed row carrying consent is a state no writer may
  produce from any path, nothing records which merge produced which row, and
  re-asking a parent costs one conversation the office is already having when it
  confirms the claim. `show()` says so rather than rendering a blank —
  `withheld_pending_confirmation` plus the reason.
- Consent is meaningful ONLY on a guardian row. A leader/member IS the person,
  and nobody consents on their behalf — `consentCovers()` returns false on a
  participant row even if the columns are somehow populated.
- Written by `GroupConsentController`, checked by `GroupAudience`. Both halves
  are mandatory: a recorded consent nobody reads is paperwork, and a check with
  nothing to read is a guess.
- Withdrawal nulls both columns, returning the row to the never-consented state
  — "absence of a record means no consent" only works if that state is
  reachable. It writes those two keys and nothing else, so on an entry whose
  consent a move carried the marker stays, and the roster reads "withdrawn here
  after it was carried": that is the state a later move back is checked
  against. Its answer names where else the same adult's consent still stands.

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
- **Staff messages may carry photos (2026-09-16).** `group_message_attachments`
  is the feed's arrangement again — same private disk, allowlist, size and
  per-message count (`config('groups.media')`), written by
  `GroupMessageAttachments`, served only by each realm's
  `downloadAttachment`. Who may have them is ONE decision,
  `GroupAudience::mayReceiveThreadMedia()`: the thread's readers, plus MEDIA
  consent on a group-wide thread (a broadcast, like the feed). A participant
  thread's photos need no consent, for the reason above. A reader who may not
  have them gets no attachment list and `media_withheld: true`. Parents'
  replies stay text only.
- **Teardown goes through the model.** Photos put bytes under a thread, so the
  DB cascade is no longer enough: `GroupThread`'s force-delete hook removes the
  photos through the model first, and `Group::booted` purges threads the same
  way. A SOFT delete keeps them. Retention is the same pattern
  (`retained_until` from `config('groups.messaging')`), swept by the SAME
  `groups:purge-feed` command.
- **Unread is a bookmark, and since 2026-09-21 the bookmark is also the
  receipt.** One row per (thread, reader) in `group_thread_reads` —
  `last_read_at` plus `last_read_message_id`, the newest message the reader
  was SERVED (forward-only, `GroupThreadRead::advance()`). It moves on OPENING
  a thread (show) and on writing, never on the list. A receipt is derived —
  `GroupThreadRead::covers($message)` — and never stored per message. Old rows
  without the id answer by time. It is never an authorization record.
- **The unread COUNT a staff member sees (2026-10-01) is ONE query,
  `App\Support\GroupThreadUnread`, and it never writes.** A message is unread
  for staff user U when somebody else wrote it, its thread is live (a
  soft-deleted one never counts, a closed one does, a scheduled-unsent
  conversation is not a `group_messages` row at all), and it is newer than U's
  bookmark, by message id or, for an old row without one, by time. With NO
  bookmark only messages at or after `config('groups.messaging.unread_since')`
  count (a UTC literal in `config/groups.php`, not an env var; null = no
  floor). **Never seed or backfill a bookmark to make a count start at zero:**
  staff bookmarks are the read receipts families see, so a seeded row would say
  "seen by the teacher" about messages nobody opened. The number is delivered as
  `unread_count` per thread and `meta.unread_total` for the whole class on the
  thread list (both staff realms), `unread_messages` on the teacher class
  (`GET .../groups` computes it ONCE for all the teacher's classes, never per
  class; `GET .../groups/{id}` for one) and on the admin group show (0, not an
  error, for an office user who cannot read the class's threads). The staff
  `unread` boolean keeps its key and now means `unread_count > 0`; the family
  payload is unchanged. The SPAs show it as a pill on the Messages tab, an
  "N new" chip beside the class name (Messages is the seventh tab and a phone
  scrolls it away), "N new" on each conversation row and a count on each My
  Classes card.
- **Opening a long thread clears it only when its last page is served.** `show`
  moves the bookmark to the newest message on the page it SERVED, so a
  conversation longer than one page stalls the count at the unserved tail.
  `per_page` is therefore honoured up to `GroupThreadsController::
  MAX_MESSAGES_PER_PAGE` (200; the default stays 50 for the native apps) and
  both SPAs read every page (`core/helpers/threadUnread.ts` `openWholeThread`).
  A new screen that opens a thread must do the same, or its count stalls.
- **A reply advances the writer's bookmark only when nothing from anyone else
  sits between it and the reply.** Replying from a screen that was open while a
  parent wrote must not carry the bookmark past that message (it would never be
  unread, and the receipt would claim it was seen), so `storeMessage` asks
  `GroupThreadUnread::hasUnseenFromOthers` first and otherwise leaves the
  bookmark where it is. Opening a thread (`GroupThreadWriter::open`) is
  unchanged: a new thread has nothing between.
- **Who is shown whose receipts and reactions is ONE decision,
  `App\Support\GroupMessageSignals`.** Staff (office, teacher) see every
  name. A parent sees staff names and their own `mine`; another parent's
  reaction is COUNTED but never named, and another parent's reading is not
  shown at all — on a class-wide thread a name would reveal which families
  are in the room, and on a private one when the other guardian looked.
- **Reactions are the fixed four** (`GroupMessageReaction::REACTIONS`:
  ameen 🤲, thumbs_up 👍, hundred 💯, question ❓), one row per (message,
  reaction, person), added by PUT and removed by DELETE on
  `.../threads/{id}/messages/{id}/reactions/{key}` in all three realms. The
  gate is REPLYING's, in the same order: the realm's write gate, then
  `mayReceiveThread()`, then "not closed", with the message found THROUGH
  the thread. A tap notifies nobody; the AUTHOR hears once, in the hourly
  content-free digest (see "Class story engagement" below — this reverses the
  2026-09-21 "no notification" rule, 2026-09-29). A new realm that shows
  messages must serialize through `GroupMessageSignals`, not re-derive names.
- **A sent message can be edited by its author, and every edit leaves a trace (W7, 2026-10-01).**
  `PUT .../threads/{id}/messages/{id}` in admin and teacher (one method, `updateMessage`): only the staff AUTHOR
  (`author_user_id` = the caller, which refuses a parent's, a colleague's and an orphaned message), who can still read
  the thread, in an open conversation, body only, no time window. An unchanged body is a no-op. A real edit writes the
  old text to the append-only `group_message_edits` and stamps `group_messages.edited_at`, in one transaction under a
  row lock, inside `withoutTouching` so the thread's `updated_at` and ordering do not move. It sends NO notification
  and moves no read marker; reactions and "seen by" stay. The office reads the earlier versions at
  `GET .../messages/{id}/edits` (admin realm only, same read gate); payloads carry `edited_at` (staff and family) and
  `can_edit` (staff) and never an earlier text. Edit rows go with their message (DB cascade; the model's `deleting`
  hook). Before `migrate` an edit answers 503, never a 500. Proven by `tests/Feature/EditSentMessageTest.php`.

Proven by `tests/Feature/GroupMessagingTest.php` +
`tests/Feature/GroupMessagingTenantIsolationTest.php` +
`tests/Feature/GroupMessagePhotosTest.php` +
`tests/Feature/GroupMessageReactionsTest.php` +
`tests/Feature/GroupThreadUnreadTest.php`.

## Class story engagement — reactions, read receipts, the reaction digest (2026-09-29)

Three owner requests on the class story (T-002.1, T-002.3, T-002.2), built on the
messaging arrangement above. Proven by `GroupPostReactionsTest`,
`GroupPostReadsTest` and `ReactionDigestTest`.

**Reactions on a story post** (`group_post_reactions`, `GroupPostReaction`).

- The four keys and the NAMING RULE are one class, `App\Support\Reactions`, shared by
  message reactions and story reactions so the two cannot drift. A realm that draws
  reactions serializes through `GroupMessageSignals` (messages) or
  `GroupPostSignals` (stories), never re-derives names: staff see every name; a
  parent sees staff names, their own reaction as `mine`, and other families as a
  COUNT only.
- **A guardian's reaction is drawn only while that guardian is in the room.**
  `GroupPostSignals::forPosts()` counts and names a guardian's reaction only if their
  contact is in the CURRENT `GroupAudience::storyGuardianContacts()` of the post's
  class (the set `seenFor()` reads); a family that withdrew consent, left the class or
  lost its login is refused the reaction endpoints, so without this its taps would sit
  on the story with nobody able to take them back. Staff reactions are never asked.
  The row is kept, not deleted: a family that is re-admitted finds its reaction again.
- **The gate is the FEED READ gate** (`GroupAudience::DISCLOSURE_FEED`), after the
  realm's write gate (`permission:manage contacts` / `teacher.leads` / the family
  guard): a person may react only to what they may read. A guardian with no consent,
  withdrawn consent, or a family that has left the class is refused with nothing
  written; media consent is not asked. The post is found THROUGH the group, so a
  foreign, other-class or soft-deleted post is a 404. Routes: PUT and DELETE
  `.../posts/{id}/reactions/{key}` in admin, teacher and family (TeacherRealmTest +2
  verbs; the family write list +2).

**Read receipts — "Seen by 4 of 7 parents"** (`group_post_reads`, `GroupPostRead`),
OFF BY DEFAULT behind `groups.story_reads.enabled` (`GROUP_STORY_READS_ENABLED`).

- **A read is recorded ONLY by a client POST** (`POST .../posts/seen`, family realm),
  which the portal fires when the Story tab is actually showing the posts. NEVER
  from the `/posts` GET: the portal fetches it on page load whatever tab is open and
  it returns the 15 newest stories, so a GET-side write would mark them all "seen"
  when a parent only opened Grades. A test pins that no GET records anything.
- **The switch gates three things together and they must go live together:**
  recording, the parent-facing notice ("your school can see which parents have opened
  each class story, and when") and the staff "Seen by" line. The portal draws the notice
  from `meta.story_reads.enabled`, which is the same config value (the family payload
  and the staff payloads share ONE shape: an object with `enabled`), so no read is
  recorded before the notice is on screen. The portal reports a read only once the
  Story section has been DRAWN: `watchStoriesSeen` (`familyClassRun.ts`) waits for the
  load chain to finish without failing and for the next render, and a parent still
  looking at a spinner, or at the error alert, has had nothing recorded. **Do not switch it on until the ar / ur / ps / fa-AF (and
  es) `story_seen_notice` strings have had a human review**: they are machine-drafted
  (owner, 2026-09-29). While off, the staff payload OMITS the seen fields rather than
  showing "0 of 7" for a receipt nobody keeps.
- **The audience (the denominator) is `GroupAudience::storyGuardianContacts()`**:
  consented, still in the class (`current()`), with a live family login. A read row
  counts only while its reader is in that audience, so `seen_count` never exceeds
  `audience_count`. `meta.story_reads.unreachable_count` says how many consented,
  current parents hold no portal login (the footnote); a guardian with several
  children in the class is one parent in both numbers. The email fan-out for a story
  (`GroupNotificationRecipientResolver::feedGuardians`) reads the same method.
- **Nothing is recorded before the switch goes on**, so a story older than that with no
  read on it is `seen_tracked: false` with `seen_since` (a school-local `Y-m-d`), and its
  three seen fields are omitted: the staff line says "Not tracked before <date>", never
  "Seen by 0 of 7". The day is `groups.story_reads.since` (`GROUP_STORY_READS_SINCE`) if
  set, else the school's earliest recorded read (`GroupPostSignals::trackingSince`);
  until either exists no story is marked. A read that WAS recorded on an old story is
  shown as a read.
- **`seen_by`, `seen_count`, `audience_count`, `unreachable_count`, `seen_tracked` and
  `seen_since` exist ONLY in the staff serializers** (`AdminDashboard\GroupPostsController`, which the teacher realm
  also mounts). The family serializer never builds them; `GroupPostReadsTest` walks
  the family JSON on every surface (list, show, seen, react, class) for those keys and
  for another family's name.
- The write is `insertOrIgnore` on the (post, contact) unique key: idempotent, first
  time kept, `masjid_id` taken from the group resolved through the bound tenant. The
  family write list +1 (`POST .../posts/seen`); the request carries story ids only and
  ids that are not stories of this class are ignored silently (not an oracle).
- The portal side (`familyClassRun.ts::recordStoriesSeenFor`) is a stale-run-guarded
  helper: the school and class are read once when the run begins, the request is signed
  with that school's token by its URL, and a run that went stale sends nothing.

**The reaction digest** (`groups:notify-reactions`, hourly at :20, `withoutOverlapping(55)`: a killed run must not hold the mutex for the 24 h default).

- **A tap dispatches nothing** (`GroupMessageReactionsTest`, `GroupPostReactionsTest`).
  The sweep emails the AUTHOR of the story or message, once, "You have new reactions":
  no names, no emoji, no counts, no content (`GroupUpdateNudgeMail` kind `reaction`). No
  staff push: the staff app is parked.
- **Author only; never for the author's own reaction.** A reaction must have stood for
  `groups.reactions.settle_minutes` (default 10, an ESTIMATE: production had 0
  reactions) so a tap taken back is never announced and a burst is one email per author
  per class.
- **Consent is re-checked at SEND time on both ends**: the command skips a reactor who no
  longer may read the subject; the job (`GroupNotificationRecipientResolver::
  reactionRecipient`) re-checks that the recipient may still read it (a teacher taken off
  the class, an administrator who cannot read the story back, a guardian who left or lost
  their login gets nothing). Both run `GroupAudience` with the tenant bound to the
  group's organisation, because a job starts unbound and that reads as "no standing"
  (and restore whatever was bound before, in a `finally`). An ARCHIVED (soft-deleted)
  staff member is nobody, as a trashed guardian is: their `group_staff` rows survive the
  archive, so `principal()` must not resolve them, or the digest would mail them and count
  their taps. Digests are grouped per author AND per class, so a teacher of two classes gets
  two emails, each naming its own class.
- **A reaction on a story that is not out yet is not announced** (T-002.4): the reaction endpoints refuse
  an unpublished story, so such a row should not exist, and if one does the sweep leaves it UNCLAIMED (it is
  announced once, after the story is out) rather than telling the author before any family could read it.
- **At most once**: each row is CLAIMED by an UPDATE guarded by `notified_at IS NULL`
  before it is sent; skipped rows are claimed too. A crash between claim and send loses
  that digest rather than repeating it.
- **A reaction that predates the column is not news.** The migration that adds
  `notified_at` stamps every existing row `notified_at = created_at`, so the first
  sweep after the deploy does not announce every reaction ever made (`ReactionDigestTest`
  seeds one before `up()` and asserts the stamp and a silent first run).
- **`SendGroupNotificationJob` picks the sign-in address by realm**: a staff recipient
  (`NudgeRecipient::realm === 'staff'`) gets `/auth/sign-in`, a guardian the family portal.
  It used to build the family URL for everybody, so a teacher told "a parent replied" was
  sent to a page with no login for them.
- Account deletion: `group_post_reactions.contact_id` and `group_post_reads.contact_id` are
  OFFICE records in `MemberAccountDeletion::OFFICE_RECORDS` (only a guardian writes one,
  and their guardian edge already keeps the contact). Neither table holds free text, so the
  staging scrub needed no new entry (the coverage test passes unchanged).

**Editing a story after it is sent — "Edited", and nothing re-sent** (2026-10-01). Proven by
`EditSentClassStoryTest`.

- **Who** is unchanged: the class's teachers and the office (`manage contacts`) edit a story that is out; a
  scheduled one is the author's alone (below). **A story that is out is edited under the FEED READ gate**
  (`authorizeDisclosure(DISCLOSURE_FEED)` in `update()`, before anything is written), because the response hands the
  story back: an office administrator off the roster is 403 on `GET /posts` and is therefore 403 on this PUT, and the
  response is media-gated by `mayReceive(DISCLOSURE_MEDIA)`, not allowed by default. A story that is not out keeps the
  author-only rule with no roster standing. `destroy()` is not read-gated (it returns an id only).
- **`group_posts.edited_at`** is the one marker. It is stamped only by a REAL change (title, body, or a file added) to a
  story that is out, decided on the row under its lock; never by a scheduled story's edits, a move or "Send now", a
  no-op save or a `retained_until`-only change. `updated_at` is not usable (the sweep and retention bump it). It is not
  an audit trail: it does not say who or what. Staff payloads carry `edited_at` and `can_edit`; the family payload
  carries `edited_at` only.
- **`can_edit`** is true exactly when this caller's PUT would be allowed for a story that is out (realm write gate,
  tenant check, feed read gate); the SPA draws Edit from it alone. False for a story not yet out; absent in
  `metadataOnly()`.
- **No re-notification.** An edit dispatches no email and no push, and leaves read receipts (`seen` means opened at some
  time) and reactions as they are.
- **Translations** need no server step: the cache key is a hash of the text. The portal's in-page map is keyed by id, so
  its key includes `edited_at` (`postTranslationKey`).
- **Deploy window:** with the column missing, reads are null-safe and an edit that must stamp it is a 503 before any
  write, so the teacher's text survives. Attachments are not edited in the SPA form.

## Scheduled class stories and new conversations — "Send later" (T-002.4, 2026-09-29)

A teacher or the office writes a class story, or opens a NEW conversation, to go out later. Proven
by `ScheduledClassStoryTest` and `ScheduledGroupMessageTest`; each guard below was removed in turn
and a test went red (DECISIONS.md, school side quest W5).

**Stories: a story is out when its time has come AND the sweep has announced it.**
`group_posts.published_at` is when families may see it; every family read goes through
`GroupPost::scopePublished()` (`published_at <= now` AND `announced_at IS NOT NULL`, and not
`publish_failed_at`). The announcement is what makes the S15 author gate independent of the sweep's
timing: `announced_at` is stamped by an ordinary post's own write, and for a scheduled story ONLY by
`GroupStoryPublisher::announce()` after the gate passed, so a late, killed or absent sweep DELAYS a
story and can never let one out that the gate has not passed, nor leave one on screen that the gate
then pulls back. `GroupPost::isPublished()` / `isScheduled()` are the row-level twins of the scopes:
keep the three in step. A NULL `published_at` reads as out (a row from code that predates the
column); the model stamps the rest, so an ordinary post is out the moment it is written.

- **A family site that forgets `->published()` serves tomorrow's story today.** The sites are:
  `Family\GroupPostsController` `index`, `show`, `markSeen` (W2), `setReaction` (PUT and DELETE, W2),
  `downloadAttachment`, `playbackTicket`, and the stream `GroupMediaPlaybackController::post` (which
  filters unless `GroupAudience::mayReadUnpublished`). `ScheduledClassStoryTest` asks EVERY one of
  them for a future story, each after proving the door is open for an ordinary one. A new family
  read of a post joins that list or it is a leak. Staff reactions are refused on an unpublished story
  too, and the reaction digest leaves such a row UNCLAIMED (announced once, after it is out).
- **Who sees a story that is not out: `GroupAudience::mayReadUnpublished`**, deliberately narrower
  than reading the feed: a teacher of the class (`group_staff`) and the office (`manage contacts`),
  never a Contact and never an administrator who reads the class only as a consented parent. The
  staff feed (`index`) shows what families see; the Scheduled list is `GET .../posts?scheduled=1`.
  The office manages a scheduled story without roster standing (a story not out is not yet a
  disclosure); the feed gate is unchanged. `show` and the attachment routes use `postsFor()`.
- **`announced_at`** is the class-story email's claim AND what makes a due story visible: an UPDATE
  guarded by `announced_at IS NULL`, `publish_failed_at IS NULL` and `published_at <= now`, so at most
  once, whoever gets there first (sweep, "Send now"), and never for a story moved to a later time after
  the sweep listed it. The migration backfills it to `created_at` so the ten live stories are never
  re-announced; the model stamps it for a story that is already out when written. A story scheduled
  ahead dispatches NOTHING at creation. A story moved by `PUT` is decided on the row under
  `lockForUpdate`, so the sweep cannot announce a story that is being moved, and a story it announced
  a moment earlier is not pulled back (422).
- **S15: the author left the class.** The sweep asks the gate for every story that is due, and refuses
  one whose author may no longer send it (`publish_failed_at` + `publish_failure`). A refused story
  was never announced, so it was never visible and nothing is pulled back, however late the sweep runs
  (an outage, a killed run holding the `withoutOverlapping` mutex for its 5 minutes). Looking
  `groups.scheduling.lookahead_seconds` (120) ahead only lets the office read the refusal a little
  before the time. A failed story is excluded by the scope whatever the clock says. A new `send_at` or
  `send_now` asks the gate AGAIN at once (story and conversation): an author who may still send puts
  it back; one who may not gets a 422 saying to cancel it and write it again, and nothing changes.
- **The Scheduled lists are ONE page holding every pending item** (`?scheduled=1` and
  `scheduled-messages`): a month of daily stories is 30 rows, and an item the list does not show
  cannot be edited, sent now or cancelled. The paginator shape is kept, so no client changed.
- **A `retained_until` may not close before the day the story goes out** (422, on create and on edit,
  including moving a story past a window its author chose): the nightly purge deletes on that date
  alone and would delete the story and its photos unsent.
- **Edit / Send now / Cancel** are the existing PUT and DELETE. `send_at`/`send_now` on a story that has
  gone out is a 422; ONLY THE AUTHOR edits, moves or sends a scheduled story now (403 for anyone else,
  SuperAdmin included), the author and the office (`manage contacts`) cancel it, and a co-teacher only sees it
  (`authorizeScheduledWrite`; an already-published story is edited by the class's teachers and the office, under the
  feed read gate and with an "Edited" marker: see "Editing a story after it is sent" above).
  `retained_until` counts from the day it goes OUT; a window the system stamped follows a new time.
- The family payload gains `published_at` (the date it shows); the staff payload gains
  `published_at_local`, `status`, `publish_failure`, `can_change_schedule` and `meta.scheduling`.

**Conversations: the sweep WRITES.** A scheduled conversation is a row in `group_message_schedules`
and NOTHING ELSE until its time: never a `group_messages` row with a future time, because receipts
compare message ids (`GroupThreadRead::covers`) and unread compares them to a bookmark, so an early
row would be counted, listed, translated, reacted to and emailed the moment it was written. NEW
conversations only (S11), text only (S13): a `send_at`/`send_now` on a reply or on `POST /threads` is a
422, never a quiet send-now, and a photo on a schedule is refused, not dropped.

- **One send path.** `GroupThreadWriter::open()` is extracted from `GroupThreadsController::store`
  and is what the sweep calls; the `sent` stamp runs INSIDE its transaction, so a thread exists if
  and only if its schedule says `sent`.
- **Gates re-run at send time** (`ScheduledSendGate`), the tenant bound to the item's own school:
  the author still teaches the class (a teacher on `group_staff`; an administrator who still belongs
  to the school and holds `manage contacts`; not archived, not deleted), and for a conversation about
  one child, that child is still a participant who has not left. A refusal is `status=failed` with
  a fixed sentence naming no person; an unexpected error is `failed` too, logged by CLASS NAME only.
- **Claims.** `scheduled -> sending` is one UPDATE guarded by status and time. A claim older than
  `stale_claim_minutes` (10) wrote nothing (atomic with the thread) and is handed back. Edit, Send now
  and Cancel are each one guarded UPDATE, so a sweep that claims between the page load and the click
  wins cleanly and the click is told "no longer editable".
- **Who** (S14 as decided by the point, 2026-09-30): the author edits, sends now (PUT `send_now`, the
  sweep sends within a minute) and cancels; a co-teacher sees the Scheduled list with the words and is
  offered nothing that would be refused. The OFFICE (`manage contacts`) sees that an item waits, when,
  who wrote it, its kind and audience (`one_child` names no child) and may CANCEL it, but gets no
  subject, body or attachments (`content_hidden: true`) and may not edit, move or send it now (403).
  So a conversation about one child is no more visible before it is sent than after it, when roster
  standing governs. Seams: `GroupAudience::mayReadUnpublished` (the class's teachers; the author reads
  their own through the controller) and `mayCancelScheduled` (the class's teachers and the office).
  Both realms mount `GroupMessageSchedulesController` (teacher: `teacher.leads`; admin: `manage
  contacts`). An office administrator who also teaches the class reads it as a teacher and may cancel it, but unless
  she wrote it she may not edit, move or send it now (403); the story payload says so in `can_change_schedule`
  (false) and `can_cancel` (true), and the SPA draws Cancel from `can_cancel`.
- Retention: `retained_until` counts from `send_at`; `groups:purge-feed` deletes finished rows
  (sent, failed, cancelled), never a waiting or sending one.

**Time.** `send_at` is the SCHOOL's wall clock (`2026-10-05T10:00`, `masjids.timezone`, an unset UTC
reads as America/New_York), read by `App\Support\ScheduledTime`; a value with its own offset is
honoured; stored in the application zone. In the future and at most `groups.scheduling.max_days_ahead`
(30) days ahead. An hour that does not exist (spring forward) moves on and the response says what was
kept. The Vue field labels the zone the SERVER names, never the browser's.

**The sweep.** `groups:publish-due`, every minute, `withoutOverlapping(5)` (a killed run must not hold the
mutex for 24 hours), one info line per run on the `monitors` channel. Runs unbound and binds each item's own tenant, restoring
the previous binding. `--masjid=` narrows, `--dry-run` changes nothing. `groups:sweep-health` (every ten minutes, a command of its
own) counts stories and conversations (`scheduled` OR `sending`) more than ten minutes past their time and
logs an ERROR with the counts; it stops when the scheduler cron stops, so silence from both is the signal.
A `transient` database error hands a conversation back only while it is less than
`groups.scheduling.transient_retry_minutes` (120) past its time, judged on the DRIVER's text, never on the
exception message (which carries the teacher's words as bindings).

**Deploy.** Deploy AFTER HOURS: `bin/deploy` checks out new PHP before it migrates, and the family
reads select `published_at`, so the seconds between fail. Run the migrations up, down and up on staging
MySQL first (RECON-PLAN 3.1 rule 13).

## Class files — a handout is ADDRESSED (2026-09-24)

`group_resources` is a file a teacher keeps for a class, and
`group_resource_recipients` names the students one was addressed to. The
audiences are `GroupResource::VISIBILITIES` — PHP constants, plain string column,
for the reason `ROLES` is:

- **`staff`** — the class's leaders and the office. THE DEFAULT and the
  fail-closed direction. A parent is never told such a row exists.
- **`families`** — the whole class, gated by FEED consent exactly as the class
  story is.
- **`students`** — only the guardians of the children named in
  `group_resource_recipients`, feed consent still required.

What a follow-on slice must not re-decide:

- **`GroupAudience::readableResourcesQuery()` is the only place an audience
  becomes rows**, and `mayReceiveResource()` asks it rather than re-deriving. A
  controller that filters for itself is the drift this class exists to prevent —
  `Family\ResourcesController` used to apply `visibleToFamilies()` and could not
  be taught the third audience without learning guardianship a second time.
- **Enforced at QUERY level in the LISTING and in the DOWNLOAD.** A filename and
  a size are themselves a disclosure ("Progress reports Sept.pdf, 2.1 MB" says
  plenty), so a forbidden row is never FETCHED rather than merely hidden, and a
  guessed id is a 404 whose body names nothing.
- **A recipient is a MEMBERSHIP**, as an award's and a ḥifẓ entry's subject is,
  so it cannot name a child who is not on this roster and the guardian edges
  answer "whose child is this" once. `ResourcesController::resolveRecipients()`
  re-reads every id through `$group->memberships()->participants()->current()`
  and refuses the WHOLE request on any miss.
- **THE EMPTY SET IS THE EMPTY AUDIENCE.** `group_membership_id` CASCADES (as
  `behavior_awards` does, where `group_threads.about_membership_id` nulls),
  because a recipient row's entire meaning is the roster row it names. A
  `students` file whose last recipient has left the roster reaches STAFF ONLY.
  There is deliberately no "no recipients means everyone" branch; widening an
  audience as a side effect of a roster edit is the one direction this feature
  must never move in. A withdrawal (`left_on`) does the same thing by a different
  route: the guardian edge leaves with the child, so the family's standing ends
  and the recipient row is simply moot.
- **Two serializers, and the difference is the point.** `toAudienceArray()` is
  the FAMILY shape and must never learn who else a handout went to;
  `toStaffArray()` adds `recipient_membership_ids` + `recipient_count` and is
  what the teacher and the office read. Anything added to the first is published
  to parents.
- **A targeted file nudges the named child's CONSENTED guardians**
  (`GroupNotificationRecipientResolver::consentedWardGuardians()`), never the
  class: a class-wide nudge would tell every family that something had been filed
  for somebody. `wardGuardians()` is the wrong one here — it is not
  consent-gated, and a targeted file is.
- **CONSENT GATES THE WHOLE-CLASS FILE AND NOT THE ADDRESSED ONE** (owner's
  ruling, 2026-09-24). A `families` handout is classroom-wide content and keeps
  the class story's consent rule. A `students` file reaches the named child's
  guardian with NO consent record, because it is a record ABOUT that child —
  the same call T-005c, T-013 and T-014 all made. An earlier round of this
  feature gated both and is the thing that was wrong: the document is a report
  card, and the gate locked the parent out of it in silence.
- **Consent and departure are TWO questions, and `standingIn()` returns two
  flags.** `feed` (may receive a class-wide disclosure; consent, for a guardian)
  and `current` (holds a row that has not left; no consent clause). They moved
  together until a handout could be addressed to one child. The `families`
  branch asks `feed`, the `students` branch asks `current`, so leaving the class
  still ends a targeted file even though consent no longer gates it. A reader
  reaching for the wrong flag is how this drifts back.
- **The consent gate lives in `GroupAudience` and NOWHERE else.**
  `Family\ResourcesController` used to call
  `authorizeDisclosure(DISCLOSURE_FEED)` over the whole surface, which 403'd the
  listing before the audience query ran and hid a targeted file the audience
  would have served. It now 403s only on null (no standing in the group at all)
  and otherwise serves the constrained query — the shape
  `Family\BehaviorAwardsController::readable()` has always had. A second consent
  branch in a controller can only disagree with the first, and this one did.

Proven by `tests/Feature/GroupResourceAudienceTest.php` (two families in ONE
classroom — a one-family fixture cannot express a single property above) plus
the resource half of `TeacherLessonsGradebookResourcesTest` and
`AdminSchoolOfficeReadsTest`.

### Files under a lesson plan's Activities (T-004.1, 2026-09-28)

A lesson plan LISTS files from the class's Files; it owns no bytes. The join is
`lesson_plan_resources` (`lesson_plan_id`, `group_resource_id`, `position`; both
FKs cascade; `lesson_plans` is never altered). A teacher uploads through the
existing `POST .../resources` (staff-only by default) and the plan's own save
carries the list as `resource_ids`.

- **EVERY `resource_id` must belong to the plan's class AND school.**
  `LessonPlanController::resolveAttachments()` re-reads the ids through
  `group_id` and `masjid_id`, and refuses the WHOLE request (422, before the plan
  is written) on any miss. Another class's file and another school's file are
  tested on create and update. The refusal answers a foreign id and a nonexistent
  id identically, so it is not an existence oracle.
- **`resource_ids` is `sometimes|array|max:10`, the one exception to "every field
  is `nullable`, never `sometimes`".** Prose is re-sent whole; links are not. An
  absent key keeps the plan's files (an old tab, the by-day PUT), `[]` clears
  them, a list is exact and ordered. Cap: `groups.lessons.max_attachments`.
- **Staff information only.** `attachments` is in the teacher's and the office's
  plan payload (one `plan()` serializer for both) and in NO family payload; no
  family route mentions a lesson plan. `lesson_plan_count` is in
  `toStaffArray()` only, never `toAudienceArray()`, which parents read. Attaching
  a file does not change its `visibility`; a file reaches families only if it is
  separately shared from Files.
- **Deleting either side takes the link, never the other side.** Removing a file
  from Files removes it from every plan (the Files row says "In N lesson plans");
  removing a plan or detaching keeps the file.
- **"Copy to week" follows the Activities rule** (`copyRequest`): a day that keeps
  its own activities keeps its own files, a day that takes the source's
  activities takes the source's files. Never a mix.
- **Adds no teacher write verb.** `TeacherRealmTest`'s list is unedited;
  `LessonPlanAttachmentsTest::attaching_files_adds_no_teacher_write_verb` names
  the five lesson-plan writes it relies on.
- Proven by `tests/Feature/LessonPlanAttachmentsTest.php` and
  `resources/vue-app/tests/lesson-plans.test.ts`.

## A student's date of birth, and the age on a roster (2026-10-04)

The owner asked for ages on the class rosters and said yes to keeping an optional
date of birth for a student. The date is the only fact about a child's person
this product stores beyond a name, so where it may go is written down here and
pinned by `StudentBirthDateTest` and `StudentBirthDateLeakTest`.

**What is stored.** `contacts.date_of_birth`: TEXT, nullable, the ciphertext of a
'Y-m-d' day (the `encrypted` cast). It is on the CONTACT, not the roster row: a
child in two classes has one birthday. It is in `Contact::$hidden`, not in
`$fillable`, and there is no `age` accessor and nothing in `$appends`. **The age
is never stored**; it is whole years worked out on read, on the SCHOOL's clock
(`App\Support\StudentAge`, `SchoolCalendar::for($masjidId)->today()`).

**One writer, one reader, and nobody reads the property.**

- `Contact::recordDateOfBirth(?string $ymd, ?User $actor, string $through)` is
  the only writer. It accepts a real day written exactly 'Y-m-d' or null, saves,
  and writes one `warning` line: contact id, organisation id, acting user id, the
  verb (`set` / `changed` / `removed`) and the door (`roster` / `contact` /
  `merge`). NEVER the date. `warning` because production runs
  `LOG_LEVEL=warning`; an `info` line would not be kept.
- `Contact::dateOfBirthOrNull()` is the only reader. A value that cannot be
  decrypted (a database restored without its `APP_KEY`) reads as null and writes
  one ERROR line with the contact id and no value. So one bad row never breaks a
  roster, never truncates the streamed records export, never turns a merge into
  a 500, and is never silent. `holdsDateOfBirth()` answers "is anything there"
  from the raw column without decrypting.
- `$contact->date_of_birth` as a property is forbidden outside the model: it
  decrypts outside the reader and throws on an unreadable value. A source pin
  lists every file that may call the writer, the reader and `StudentAge`.

**Where the DATE appears: two places, both behind `manage contacts`.**

1. The office's birth-date routes (`GroupBirthDateController`):
   `GET` and `PUT …/groups/{group}/members/{membership}/birth-date` read and set
   it by roster row, for **a student in a class only**: role `member` in a group
   that `Group::teachesStudents()` (kind `class`). Anything else (a guardian
   entry, a legacy `leader` row, a member of a ḥalaqa, a team or a general
   group) is 422 "A date of birth is kept only for students in a class."
   `DELETE …/contacts/{contact}/birth-date` clears it, **by contact, for any
   contact (deleted ones included), and is never refused**: the date outlives
   the roster row that let the office type it (Remove, an import undo, an
   archived class, a class whose kind changed, a merge), and it must still be
   removable then. It answers the same whether or not a date was held. Remove on
   the roster says when a date is still held, in one of two ways:
   - **another class still lists the student** (a `member` row of theirs,
     current or marked as left, in a class that still exists): "Their date of
     birth is still on their record: they are still listed in {Class}, where it
     gives their age. It can be changed or removed from their details there."
     No `data.birth_date`, so NO clear is offered. This is the ordinary case
     after a move, whose own answer invites the office to remove the empty old
     entry; offering the clear there wiped the age of a current student.
   - **no class lists them any more**: "Their date of birth is still on their
     record." with `data.birth_date = { held, contact_id }`, and the office
     roster offers "Remove the date of birth" beside OK, because no roster is
     left to remove it from. A failed clear offers "Try again". **A contact
     deleted in the Member Directory is always in this second case**, whatever
     class rows remain: a deleted contact's row shows no age and its date form
     answers 404, so no roster can read or remove the date.
   So `data.birth_date` present means exactly "offer the clear". KNOWN GAP: once
   that offer is dismissed there is no screen that removes the date of a person
   who is in no class (the Member Directory has no date control); the route
   still works for any contact id, and adding the child to a class brings the
   form back.
2. The `contacts` file of the school records export, `Date of birth` column,
   including soft-deleted contacts like every column there.

"The office" in permission terms is every MasjidAdmin of the organisation and the
platform's SuperAdmins. The admin SPA holds no permission list, so the date form
(`StudentBirthDateForm.vue`) is drawn from the GET's answer and not drawn on a
403. "Not in the future" is judged on the school's today, as the age is.

**Where the AGE appears: two payloads, as `age: int|null`.**

- The office roster list (`GroupMembershipsController::index`), on every row;
  a number only for a student in a class. The screen draws it as an **Age**
  column between Grade and Joined ("—" when there is none), only on a class
  (`meta.teaches_students`), with one line above the table when a CURRENT
  student has none: "{n} students have no date of birth on file, so no age is
  shown for them. Tap a name to add it." A moved student's age is the same
  number in both classes: it is the contact's, not the roster row's.
- The teacher's class payload (`TeacherController::classPayload`), on each
  student; a number only when the group `teachesStudents()` AND the row's role is
  `member`. `classPayload` serves every kind of group a teacher leads and its
  roster includes a legacy `leader` row, and a contact can carry a date from a
  class onto one of those. `student()` itself is NOT changed, so the register,
  the gradebook, report cards, the class store and the avatar answers keep their
  shape.

Both go through `StudentAge::forRoster()`, which reads the dates in a query of
its own that names only the students, so the contacts a roster payload serialises
never hold the value.

**Nowhere else.** No other office answer, no family payload, no member (mobile)
payload, no public page carries the date or an age; the teacher never receives
the date. `StudentBirthDateLeakTest` walks every GET route of the teacher, family
and member realms out of the router, so a route added later is walked without
being listed.

**A teacher still sees nothing about a parent on the roster or the student
sheet.** The sheet a teacher opens by tapping a Roster row
(`TeacherStudentSheet.vue`) shows the avatar, the name, the grade and the age,
built from a four-key model, and says to contact the office in an emergency. The
dormant `guardianNames` hook on the row was deleted. A parent's NAME does reach a
teacher in five places, by the owner's decision of 2026-09-21 that staff see
every name: a message's author, beside a reaction on a message, "read by", beside
a reaction on a class story, and "seen by". **A test that pins "no parent name
reaches a teacher" must exclude the conversation AND class story endpoints**
(`threads`, `posts`, `scheduled-messages`), or it fails on correct behaviour.

**Deploy order.** `bin/deploy` serves new code BEFORE it migrates. Every read of
the column is behind `StudentAge::columnExists()` (`Schema::hasColumn`, as
`GroupThreadsController` does for its own column), so between checkout and
migrate the rosters answer with no ages rather than a 500. SQLite does not fail
on a missing double-quoted column, so the test for this asserts that no statement
NAMES the column, not that nothing threw.

**The other places a new contacts column has to be classified**, each done here:
`MemberAccountDeletion::OFFICE_COLUMNS` (a held date keeps the contact),
`config/staging_scrub.php` `encrypted_null` (nulled on staging, so staging shows
no ages), `ContactsController::merge` (carried onto the kept record when it has
none; the kept record's stays when the two differ, and the office is told, with
no date printed; an unreadable one is not carried, and the office is told), and
`artifacts/t040-pii-inventory.md`. A staff delete is a soft delete and keeps the
date, like every other column.

**Rolling back.** By code only. `migrate:rollback` deletes every date the office
typed. The `$hidden` line must be the last thing to go: without it every
whole-model contact answer would carry the ciphertext.

**Not built, on purpose:** an `age` accessor; a stored age; the date on the
Member Directory edit form (that form is filled from a list row, which cannot
carry a hidden column, so an untouched field would wipe the date); age in
`student()`; age on the teacher's Attendance row; anything for parents or the
native apps; a date-of-birth column in the roster CSV; proposing dates from
enrolment form answers.

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
  RANKING, no rank column, and no comparison payload. The one class-wide read is
  the teacher's overview, `GET .../awards/totals` (BISS, 2026-09-21): a list of
  per-student rows a leader is already entitled to, in roster order, leaders
  only, and not something a guardian can reach. (An earlier version of this
  bullet said "no class-wide endpoint" and was already false the day `totals`
  shipped.)
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
- **A negative skill always SUBTRACTS in every total** (B1, 2026-09-28, owner
  approved). The vocabulary and the award snapshot store a MAGNITUDE
  (`points = 1` for "Talking out of turn"); direction comes from
  `skill_polarity` at READ time through `BehaviorAward::signedPointsSql()`
  (`CASE WHEN negative THEN -ABS(points) ELSE points END`), in the staff
  summary, the family summary and the class totals. No stored row changes.
  Before this, every `SUM(points)` ADDED a correction while the picker showed
  "-1". Only negative-polarity rows move: every other row reads as stored, so a
  positive-polarity award typed with a negative override (a teacher docking a
  child) keeps netting what it always did, and an award row already stored signed
  is not double-negated. The award LOG shows the same signed figure
  (`signedAwardPoints`, `core/helpers/behaviorSkills.ts`) on the teacher, office
  and family screens. Pinned by `BehaviorSignTest` and `behavior-skills.test.ts`.
- **A picker (and a summary) reads POSITIVE FIRST** (T-003.1, 2026-09-28):
  positives, then negatives, then any unrecognised polarity, then by label.
  `BehaviorSkill::scopeInPickerOrder()` / `orderInPickerOrder()` is the ONE
  definition, used by the skills list and by both `by_skill` summaries (staff and
  family, ordered by the SNAPSHOT columns). `ORDER BY polarity` is alphabetical
  and put "negative" on top; do not reintroduce it. The award LOG stays
  newest-first. On the teacher's screen `core/helpers/behaviorSkills.ts` keeps a
  locally added skill in the same order and opens the picker on the first
  positive skill. Pinned by `BehaviorSkillOrderTest` and `behavior-skills.test.ts`.
- **The weekly view is a VIEW, and nothing is deleted (T-003.2, 2026-09-29;
  owner: "points need a reset option at the end of the week that teachers can opt
  into").** `groups.points_period` (`running` | `weekly`; null reads as `running`,
  `Group::pointsPeriod()`) decides only how a class's points are SHOWN. A teacher of
  the class sets it (`PUT .../points-period`, the realm's +1 write verb) or the office
  does through the group form; it belongs to the CLASS, not the teacher, because a
  family sees one figure for their child, and the screen and the response both say it
  applies to every teacher. Turning it on or off changes no `behavior_awards` row, so
  it needs no backup and switching it off gives the running total straight back
  (`PointsWeekTest` snapshots every row before and after).
  - **A week is Sunday 00:00 to Sunday 00:00 on the SCHOOL's clock**
    (`App\Support\PointsWeek`, start day named explicitly). Its two ends are built as
    local midnights and converted, so the week that holds a daylight-saving change is
    167 or 169 hours, not 168; `start + 7 x 24h` would drop a Saturday-night award into
    the wrong report.
  - **Awards are placed by INSTANT, never `whereDate`**
    (`BehaviorAward::scopeAwardedWithin`, half-open `[start, end)`). `DATE(awarded_at)`
    is the UTC date, so a Saturday-evening Eastern award is already Sunday in the column.
    The older `awardedBetween(from, to)` is left exactly as it was for its callers.
  - **`?week=`** (any day of a week names it, or the word `current` for the school's
    week in progress) narrows the SAME audience-constrained query, so a week can never
    include anything the caller could not already read. A value that is not a date is a
    422, never a silent fall back to this week under last week's label. It is on the
    staff and family listings and summaries; `totals` always carries the week beside the
    running figures (`week_points`, `week_awards`, and the class's).
  - **The figure a class leads with follows `points_period`; both are always served.**
    Nothing is summed in the browser (`core/helpers/pointsWeek.ts`).
  - **A family sees a week only where its switch is on (review F1, 2026-09-29).** The portal's "This
    week" line is for a class that opted in to `points_period = weekly`; the weekly REPORT page and every
    link to it are for a school holding `points_weekly_report`. The family class payload carries
    `weekly_report` (the school's answer) beside `points_period`, and the family awards endpoints agree:
    a `?week=` is served when the school has the report on (any week), or as `current` for a weekly class,
    and is a plain 404 otherwise, before the value is read as a date. No `?week=` is unchanged.
- **The Friday report is a notice and a link, off by default, once per class and week
  (T-003.3, 2026-09-29; owner B5).** `points:weekly-report` runs hourly and, for a school
  holding the `points_weekly_report` grant (OFF for every organisation until a SuperAdmin
  decides), emails each family "your child's weekly report is ready" and each class's teachers
  their summary. **Nothing about a child is in the email** (`WeeklyPointsReportMail`): the numbers
  live behind the portal's ward-edge gate, where consent and identity are checked, not in an inbox
  that forwards and previews on a lock screen.
  - **Recipients are the strictest of the four notifier shapes**
    (`GroupNotificationRecipientResolver::weeklyReportGuardians`): a CURRENT ward, and a
    confirmed, CURRENT, feed-consented guardian edge with a live family login. The resolver checks
    both `left_on` columns itself; the model hook that ends a guardian edge with the child is not
    relied on. Consent is required here although a parent may always READ their child's record,
    because this is a mail to an address the school holds. Teachers are `group_staff` logins only.
  - **The week is the one holding the SCHEDULED instant, up to that instant.** A later award shows in
    the portal and never makes a second email. The moment is the school's (Friday 15:00 unless
    `masjid_points_settings` says otherwise, SuperAdmin-only), never derived from the calendar; a
    week with a calendar closure is skipped.
  - **`behavior_weeks` is the atomic claim** (an insert that swallows ONLY the unique violation, then
    `UPDATE ... WHERE report_sent_at IS NULL`; never `insertOrIgnore`, which on MySQL turns any other
    failure into a warning and reads as "already sent"): at most once by design, and a run with nobody to tell claims nothing. It holds no child
    data, so it has no retention, erasure or RESTRICT (cascades to the school and class).
  - **The portal page** (`FamilyWeeklyReport.vue`) reads the existing `/awards` and `/awards/summary`
    with `?week=`, for the parent's own children only, printable, and says so when a read fails.
    Sign-in follows `?next=` only for that one path shape for the same school
    (`familyNextPath`), optionally with `?week=YYYY-MM-DD` (a real date): both emailed links NAME the
    week that was reported, so a link opened on the Sunday after still shows that week, not the new one.
  - **A send in which every email failed is given back** (`BehaviorWeek::release`), so the next run
    inside the 12-hour window retries; a partial send keeps its claim (a retry would double-send).

## Class store — Manara Bucks (T-003.4, W6, 2026-09-29; owner B6)

A week's POSITIVE points become Manara Bucks; each class's teachers run a store where students
spend them; the app keeps each balance. **Points stay the record.** Bucks are a separate,
APPEND-ONLY ledger (`prize_ledger_entries`) that a week of points is turned into, and a child's
balance is the SUM of their rows, never a stored figure that can drift from its history.
The whole feature is behind the `class_store` capability, a grant, **OFF for every
organisation** (Al-Razi included) until a SuperAdmin switches it on.

> **2026-10-05: THIS SECTION IS BEHIND THE CODE, on purpose, until the commit that lets a move carry
> a balance rewrites it.** The ledger has gained, DARK, what that commit needs, and NOTHING CALLS
> THE WRITER YET (`ClassStore::carryBalance` has no caller; `ClassStoreCarryTest` pins "none, or
> exactly one in `RosterMove`"). So a moved student's balance still stays on the old row, and the
> move still says so. What is already in the code and not yet in the paragraphs below:
> - two kinds, `transfer_out` and `transfer_in` (`PrizeLedgerEntry::TRANSFER_KINDS`; in `KINDS`,
>   in neither `MINTED_KINDS` nor `REVERSIBLE_KINDS`), a nullable DATE `counts_from` kept on both
>   rows of a pair, and the writer with its rule on two classes' dates (`carryRule`), each behind
>   `ClassStore::carryReady()`. The teacher's and the family's screens have words for both kinds
>   (the family's five translations are machine-drafted, like the rest of that block), and
>   `LedgerKindsTsMirrorTest` keeps the SPA's list equal to the server's;
> - **nothing is minted or adjusted on a roster row a student was MOVED away from** (a leaving
>   date and `moved_to_group_id`, read from the row under its lock, not from the ledger), and a
>   prize given from such a row can no longer be undone there (`moved_away`); a row put back by
>   hand earns again. Both act from now on, pair or no pair;
> - the sentence for a student who has left no longer says their Bucks "stay on their record";
> - expiry counts both transfer kinds, each with its sign, by `counts_from`, and the grace is never
>   more than seven days whatever is configured (`BucksExpiry::graceDays()`);
> - the office's reconciliation shows no figures for a class that holds any transfer row, exactly
>   as for a class that is too small, and its sentence names both reasons;
> - Remove names the ledger ("Manara Bucks history") and never counts its rows;
> - `ClassStore.php` holds FIVE `lockForUpdate(`, not four (the pin counts comments too);
> - `php artisan bucks:transfer-rows` prints how many transfer rows exist (exit 0 none, 3 some),
>   for a deploy guard that is not written yet.

- **OFF means untouched.** Every store route is behind `capability:class_store` (403), `bucks:mint`
  and `bucks:expire` skip a school without it, the mobile `/features` and `tv-config` are byte for
  byte the same on or off (test), and the teacher and family CLASS payloads gain a `class_store: true`
  key only when it is on (no key at all otherwise). The five capability files (`config/capabilities.php`,
  `Capability.ts`, `OrganisationModulesTest`, the Studio catalogue test, the three provision snapshots and
  `set-capability-responses.json`) differ only by that key.
- **The ledger is append-only in the application.** `PrizeLedgerEntry` throws on `updating` and
  `deleting`, and no route edits or deletes a row (a test scans the route list). A correction is a NEW
  `reversal` row that points at the old one. No SoftDeletes and no triggers, so erasure and retention
  still work. `dedupe_key` is a nullable UNIQUE string (NULLs are distinct on both engines; there are no
  partial indexes) and is how "once" is a database fact: `earned:{membership}:{week_start}`,
  `adjusted:{membership}:{week}:{n}`, `reversal:{entry}`, `redeemed:{membership}:{request_id}`,
  `expired:{membership}:{cutoff}:{n}` (`n` counts that cutoff's earlier write-offs). **The key is BYTE-EXACT on
  MySQL** (`utf8mb4_bin`, declared in the table's own CREATE): under the default `utf8mb4_unicode_ci` two request
  ids differing only in case were one key there and the second redemption a false replay, which SQLite never shows
  (`ClassStoreSchemaTest` compiles the MySQL statement and pins it). **Only a duplicate is swallowed** anywhere a
  store write races (`markPrizesConverted` inserts and catches `UniqueConstraintViolationException`, like the
  report's `claim()`; never `insertOrIgnore`, which is MySQL's INSERT IGNORE). **The W6 migrations after the
  ledger's own (100200, 100300, 100400) refuse to roll back while a ledger row exists**, as 100100 does. `week_start` is a plain `Y-m-d` string, NOT a `date` cast: the cast stores a
  timestamp on SQLite, and an exact match would silently miss there.
  `group_membership_id` is RESTRICT like every academic record, and `AcademicRecordsHeld` tells the office
  ("N Manara Bucks") before the database refuses.
- **What earns a buck (R1, R2).** `floor(P / points_per_buck)` per child per week, where `P` is the sum of the
  child's live awards that week whose skill is not negative AND whose points are above zero. A negative
  award never takes bucks away (it is not in `P`); a positive skill a teacher docked with a negative
  override does not mint either (`ABS(points)` would have). A revoked award is not live. Whole bucks per
  child and week: at one point a buck (the default) nothing is lost; at a dearer rate the remainder of a week
  is not carried over. Weeks are Sunday 00:00 to Sunday 00:00 on the SCHOOL's clock (`PointsWeek`, DST tested).
  A child who has left the class is not minted for.
- **When.** `bucks:mint` runs hourly (for a school with the grant): every closed week from `bucks_from` with
  no `earned` row mints; the LAST TWO closed weeks are re-read and a late change becomes an `adjusted` delta
  clamped so no balance goes below zero. `week_basis` records what the week's points came to after each
  row, so a clamped clawback is forgiven ONCE and never collected out of a later week's earnings. Older
  weeks are frozen. **A week keeps the rate it was minted at:** every `earned` and `adjusted` row also keeps
  `week_rate` (and `week_points`, what the basis was worked out from), a week is re-priced at ITS OWN rate, so a
  SuperAdmin changing `points_per_buck` moves nothing already minted (the first
  version compared today's rate with a basis worked out at yesterday's and clawed back a fifth of two weeks).
  The adjustment is decided again under the student's lock, so two overlapping runs cannot write one late
  change twice. **`bucks_from` is a DAY, not a week:** points awarded before its midnight on the school's clock
  never mint, even inside the same Sunday-to-Sunday week. **A class that has ended** (`groups.ends_on`) mints
  nothing for a week that opens after its last day. **Nothing retroactive by surprise:** the first time a school
  with the store on is seen, `masjid_points_settings.bucks_from` is set to the start of the week in progress; a
  SuperAdmin may move it earlier on purpose (only the last 60 closed weeks are minted in one run). **A pause is
  not history:** every sweep that finds the store on leaves `bucks_swept_at`; a sweep that finds it OFF for a
  school with that mark clears `bucks_from` and the mark, so switching the store back on counts from the week in
  progress and the weeks it was off are not paid in one hourly run (a start day chosen by a SuperAdmin after
  that is theirs, and a school that never ran keeps a start day set in advance). `behavior_weeks.prizes_converted_at`
  records a processed class-week (a saving of work; the dedupe key is what makes minting once-only) and never
  touches the Friday report's `report_sent_at` claim.
- **Every write locks the student first.** `App\Support\ClassStore` is the only writer of a person's ledger
  action: the student's roster row (and the prize row for a redemption) is locked with `lockForUpdate` inside one
  transaction, and because SQLite has no row locks the invariant is ALSO checked after the write and rolled
  back: **a balance never goes below zero, with or without the lock**, and a refused redemption writes
  nothing. A `request_id` is REQUIRED on a redemption and a cash-out (422 without one) and makes a double-tap or
  a retry a replay; **a replay answers with the first row only when it asks for the same thing**: the same id with
  another prize (or another cash-out amount) is a 409 `request_id_reused`, never the first row passed off as the
  second's. A child who has LEFT the class is refused with `student_left` and a sentence saying their Bucks stay on
  their record (what happens to them on a move or withdrawal is owner question W6-C1). **The prize must be this
  school's and either school-wide or this class's own** (`Prize::availableTo`, checked again in the service
  because a console caller runs unbound). Stock is optional (blank = unlimited), taken in the same
  transaction and given back by a reversal. The locks cannot be seen by a SQLite test, so
  `ClassStoreLedgerTest::every_ledger_write_takes_its_row_locks_and_takes_them_first` pins the source (four in
  `ClassStore`, one in the retention purge, each before the read it protects). The screen keeps ONE request id per
  write (student and prize, or student and amount) across a retry after a dropped response, and forgets it only
  on success or a definitive 4xx, so a lost response is a replay and not a second deduction. Reversal is once per entry, only of a redemption or a cash-out, and refused after
  an expiry (an expired balance stays ended).
- **Cash-out to paper is BUILT AND OFF** (`masjid_points_settings.paper_bucks_enabled`, default false, SuperAdmin
  only): the owner paused the physical Manara Bucks until the admin team decides. It is refused in `ClassStore`
  itself, not only in a controller, and the screen hides it. It writes a `cashed_out` row with the 20/10/5/1
  breakdown, and `GET .../bucks/handout` is the printable class hand-out for a day.
- **Expiry (R5).** `bucks:expire` writes an `expired` row per child and cutoff at the end of a class
  (`groups.ends_on`) and of each school-calendar year: what the child still holds that was minted before the
  cutoff, never below zero. **A cutoff waits `groups.bucks.expiry_grace_days` (default 7) before it acts**, because
  the dates come from office screens that accept any date, and **a write-off whose date later disappears (corrected,
  or moved later) is given back** by a `reversal` row pointing at the `expired` one (`reversal:{entry}`, once, under the
  student's lock); a date moved later is written off again when the new one is due, and a date that still exists but
  is not yet due is left alone. A reversal of a prize is refused only across an expiry that still stands. **It is re-runnable per cutoff:** the week that holds the cutoff is minted only
  after it closes, so bucks for a pre-cutoff week can arrive after the first write-off; every run works the sum
  out again and writes a further `expired:{m}:{cutoff}:{n}` for what is left (nothing left, nothing written).
  Tests drive the real `bucks:mint` then `bucks:expire` order across that week. **The retention purge removes a child's ledger as a SET**
  (`PrizeLedgerEntry::purgeDueSets`, inside `groups:purge-feed`): only when EVERY row is due, decided again inside a
  transaction that holds the roster row, so it can never leave a redemption without what paid for it. **And only a
  set worth nothing that belongs to nobody still here:** the rows must sum to ZERO and the child must no longer be
  enrolled (`left_on` set, or the class's `ends_on` before the purge date). A still-enrolled child's history and any
  balance that is not zero stay, however old: in a school with no calendar and no end date a balance never expires.
- **Privacy: exactly as private as an award.** Every balance is read through
  `GroupAudience::readablePrizeLedgerQuery` (the class's teachers, the student, that student's own guardians,
  nobody else; null, not an empty page, for a stranger), constrained at QUERY level so a forbidden row cannot
  surface in a page, a paginator total or a SUM. The endpoint 403 (`subject()` on the family side, the audience on
  the teacher side) and the query constraint are BOTH required, and each has its own test. Consent is not
  consulted (a parent reading their own child's record is not a broadcast). Roster order, **no rank, no class
  total, no prize wall**; the family payload is the narrow one (no teacher note, author, prize id or paper breakdown).
  **The office reads CLASS TOTALS ONLY** (`mayReceiveClassStoreTotals`, the reconciliation view): no child's name,
  roster id or per-student figure, because the office administers the store but does not stand in a class.
  `mayReceiveClassStoreTotals` checks the BOUND TENANT like every other decision (the group must be the bound
  school's; unbound grants nothing), never `users.type` alone. **A class with fewer current students than
  `groups.bucks.reconciliation_min_class_size` (default 5) shows no figures** (listed by name, `suppressed: true`)
  and is left out of the school totals, because in a one-student class the class total IS a child's balance and a
  total that included it would give it back by subtraction. An
  office-run school-wide store that lets an administrator read every child's balance is deliberately not built.
- **Who sets what.** The office keeps the school-wide prize list (`/api/admin/masjids/{id}/prizes`, create, edit,
  retire; never delete); a teacher keeps their class's own list; the ROUTE decides the shelf, never the body.
  **An edit locks the prize row and compares the stock** (`ClassStore::updatePrize`): a `stock` must come with
  `expected_stock`, the count the form was opened at, and a mismatch is a 409 `stock_changed` that writes nothing,
  so a stale screen never puts a given prize back on the shelf; the screens send the stock only when it was changed.
  **An absent, null or empty `is_active` means no change** (filter_var reads null and '' as false, which used to
  retire the prize). A
  SuperAdmin alone sets `points_per_buck`, `paper_bucks_enabled` and `bucks_from`
  (`PUT .../class-store-settings`, audited at WARNING). There is no settings screen yet: API only, like the
  Friday report's schedule.
- **Teacher write verbs: +5** (`TeacherRealmTest`): `POST prizes`, `PUT prizes/{id}`, `POST members/{id}/prizes/redeem`,
  `POST members/{id}/prizes/cash-out`, `POST prize-entries/{id}/reverse`. Family write verbs: 0 (a GET only).
  `TeacherMultiSchoolTest`'s route-list sweep is taught the five and runs with the store ON for both schools.
- **The records export carries the ledger** (`SchoolRecordsExportController`, dataset `bucks_ledger`, W6-C2): entry,
  class, membership, kind, amount, week, prize title, what it corrects and when; never the teacher's note.
- **The screens** (W6 point review): a refused prize keeps its message through the reload that follows it (the
  reload passes `keepMessage`); the teacher's history pages 25 at a time with "Show earlier", as the family's does,
  so an older prize can still be undone; the student list is real buttons (keyboard). The Arabic `bucks_*` lines are
  MACHINE-DRAFTED and marked so. Each of the three screens is MOUNTED in `npm run test:spa`
  (`tests/class-store-mounted.test.ts` through `tests/support/mountSfc.ts`: the project's own compiler and Vue's
  `createRenderer`, no DOM and no new dependency) and driven through a refusal and its busy guard.
- **T-040 PII inventory:** the ledger's `note` is scrubbed (`free_text`) in `config/staging_scrub.php`; nothing else
  on either table is personal data (a prize title is a shelf item). `prize_ledger_entries` has no `contact_id`, so
  `MemberAccountDeletion` classifies nothing new.

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
`corrected_by_user_id` records who did it. WHAT WAS HEARD is never rewritten
without a trace, on purpose — an edit that left nothing behind would quietly
rewrite what a teacher said they heard.

**A recorded line is corrected IN PLACE, with the old line kept as a struck copy**
(owner, 2026-10-07): `POST .../groups/{group_id}/hifz/{entry_id}/correct`
(teacher realm, `teacher.teaches:quran`). In one transaction the line as it stood
is written as a soft-deleted row carrying `corrected_by_user_id`, then the entry
takes the corrected kind, range, quality and (only when sent) day. It is NOT
"strike and record again": HifzProgress orders by `(recited_at, id)`, so a
re-recorded line with a new id can move a child's position backwards among lines
that share an instant (every backdated line of a day does). The request has no
`membership_id`: a correction never moves a recitation to another student.

**The note is the one field edited in place** (owner, 2026-10-07):
`PUT .../groups/{group_id}/hifz/{entry_id}` (teacher realm, `teacher.teaches:quran`)
takes `note` and nothing else. It is commentary, read by no derivation, so it
cannot move a child's position. Whoever may record and strike may reword it; each
change writes a WARNING line with the entry and the account, never the words. Do
not widen that endpoint to the portion, the kind, the quality or the day: those go
through `correct`, which keeps the struck copy.

**The day a recitation reads as** is decided in ONE place on the screens,
`resources/vue-app/core/helpers/hifzDay.ts`: a day chosen in a date box is stored
at midnight UTC (rows before 2026-10-07) or noon UTC (since), and formatting that
instant in the reader's zone showed the day BEFORE in the Americas. Any new list
of recitations reads the day through that helper, never `toLocaleDateString` on
`recited_at`.

**Permissions**: `view contacts` / `manage contacts`, minting nothing.
`Permission::count() === 8` stays pinned. Nothing here is paywalled.

Proven by `tests/Feature/HifzTrackingTest.php` (endpoint AND listing-query
halves) + `tests/Feature/HifzTenantIsolationTest.php` +
`tests/Unit/QuranIndexTest.php`.

## Letters tracker — English capitals and lower case (T-004.2)

`arabic_letter_progress` holds both alphabets (`alphabet` column). English is
tracked as **52 drills, not 26**: `a.upper` … `z.lower`, in two runs of tiles
(Capitals, then Lower case) with a count on each and "of 52" overall.

- **Drill ids are `x.upper` / `x.lower`, NEVER `A` / `a`.** Production's
  `drill_id` is `utf8mb4_unicode_ci`, case-insensitive, so `A` and `a` are one
  key under the `(group_membership_id, alphabet, drill_id)` unique index. SQLite
  (the suite) is case-sensitive and cannot show that, so
  `EnglishCurriculumTest::no_drill_id_is_a_bare_letter…` asserts it on the ids.
  `EnglishCurriculum::drillId()` is the one place an id is assembled.
- **`LetterCurriculum::sets()` / `set($drillId)`** describe the runs. Arabic
  returns `[]` / `null`: its four letter FORMS (`positionsFor`) are a different
  idea and never become sets or separate denominators. The tracker payload adds
  `sets`, `set_totals` (`[{id,label,mastered,total}]`, `[]` for Arabic) and a
  `set` key on every drill (null for Arabic). Clients draw runs through
  `core/helpers/letterRuns.ts`, never by branching on the alphabet id.
- **`classOverview()` filters its numerator by the stage's syllabus.** Before,
  it counted every mastered row in the class against the syllabus denominator,
  so an Arabic child's letter-GROUP drills (valid at any stage) inflated the
  count and only the `min()` clamp hid it at 100%.
- **The migration** `split_english_letters_by_case` copies every existing
  English mark to BOTH cases keeping status, note, `marked_by`, the original
  `mastered_at` and both timestamps (owner question B2: copy to both, answered
  2026-09-28), then removes the bare row. It reads inside its transaction (row
  lock on MySQL) and copies with `insertOrIgnore`, because `bin/deploy` runs the
  new code before `migrate --force` with no maintenance mode: a case written in
  that gap wins instead of aborting the deploy. Its `down()` refuses when the two
  cases differ on status, note, mastered date or who marked them, when one is
  missing, or when a bare row sits beside the pair. **It rewrites production
  rows: deploy after 18:00 ET, when teachers are not marking letters.** The
  sibling `create_lesson_plan_resources_table` `down()` refuses while any link
  exists, so back W1 out with `migrate:rollback --step=N`, never a bare rollback
  of the whole batch.
- **A stale tab** that still posts a bare letter for English gets a 422 with
  `code: stale_page` and a "reload the page" message, and nothing is written.
- **The records export** keeps the stored `Drill id` and appends a readable
  `Letter` column ("Capital A") after `Alphabet`, so positional readers keep
  their columns.

## Gradebook — subject, type, weight, standard and the subject fence (W3, 2026-09-29)

Work (`class_assignments`) carries a **subject**, a **type**, an optional **weight** and
**one standard**. Every one is a SNAPSHOT, never a foreign key, and existing rows stay
NULL (blank is not a default): renaming a subject, re-importing the pacing guide or
retiring a type changes no mark a family has read. `subject_key` is derived from
`subject` on every save (`App\Support\SubjectKey`) and is `hidden`.

- **One key for "the same subject".** `SubjectKey::for()` trims, collapses spaces,
  lower-cases and DROPS every apostrophe-like mark, so `Qur’an`, `Qur'an` and `Quran` are
  one subject. It never lengthens a name (a 64-character name is a key that fits a
  64-character column). `LessonPlan::subjectKeyFor` does NOT use it: that key carries a
  unique index with live rows, and changing it would let the by-day save miss the plan it
  means and add a duplicate. The lesson-plan fence compares the folded key instead.
- **The school's list** (`school_subjects`, the office's Subjects screen) decides what a
  class offers (`ClassSubjects`): the list, else the guide's subjects, else free text;
  limited to grades someone in the class is in (`GradeLevel` folds `Pre-K`/`Pre-Kindergarten`,
  `KG`/`Kindergarten`, `1st`/`Grade 1`); an unlabelled child hides nothing. It is
  reference data: the office edits it, a teacher never does.
- **A standard is only ever a row of the school's own guide.** The teacher picks it from
  `GET curriculum/standards` (the ONE matcher; there is no second one), and
  `GradebookController::refuseStandard` checks the (code, focus, week) really is a
  `curriculum_weeks` row of this school before it is stored. An uncoded weekly focus is a
  standard by its words. Where `short_lesson_plan` is on (BISS) the three standard keys are
  dropped before validation: not shown, not written. Qur'an, Arabic Language and Islamic
  Studies have standards ONLY where the school wrote them: Al-Razi's Quarter 1 plan of
  2026-09-07 (Pre-K to Grade 2, weeks 1-8) carries its own `PK.QUR.*`, `PK.AAL.*`,
  `PK.IS.*` codes (and K, 1, 2), imported verbatim by `curriculum:import` from
  `database/curriculum/al-razi-qai-split-2026-27-q1.json`. None may be invented anywhere
  else: weeks 9-36 and Grades 3-5 stay under the combined "Qur’an & Islamic Studies" column,
  and an Arabic search there answers nothing.
- **A guide row can carry the school's Objective and Learning Outcome** (`curriculum_weeks.
  objective` / `.learning_outcome`, nullable). Each school column lands in the field of the
  same name and nothing is joined, so a test can compare every stored string with the
  school's document byte for byte (`CurriculumSplitSourceFaithfulnessTest`). The prefill and
  standards payloads carry the two keys ONLY on rows that have them, so a July row's payload
  is unchanged; the picker's de-duplication key includes the objective, because the plan
  repeats a code and focus in two weeks with a different Objective each time. Subject names
  in that file are the catalogue's exact bytes (`Qur'an` with U+0027), not the combined
  column's U+2019, because `LessonPlan::subjectKeyFor` does not fold apostrophes.
- **`curriculum:import` is how the guide changes.** A file may `replaces` cells (deleted in
  the same transaction as the upserts). `--dry-run` prints the exact counts and a `PLAN`
  json line, `--expect=` refuses unless they match, an apply writes an inverse file and a
  snapshot (0600, `storage/app/private/curriculum-imports/`) before it changes anything,
  and `--verify` compares the database with the file byte for byte. It never writes a lesson
  plan or an assignment; the dry run counts the ones that copy a replaced cell. Re-running
  the base file after a file that replaces some of its cells re-creates them: re-run the
  replacing file.
  An apply REFUSES (exit 1, nothing written) while `plans_touching`, `assignments_touching`
  or `delete_absent` is above 0, with or without `--expect`, unless `--allow-references` is
  given, and the flag is passed only with `--expect` pinning `plans_touching`, `assignments_touching` and
  `delete_absent` (the dry run prints the line). The rollback restores content, not ids or timestamps, and the
  inverse file refuses too (`plans_touching` above 0) once teachers have planned on the split cells, so it needs the
  same dry run and `--allow-references`. `--verify` checks only the
  file's cells and the replaced keys, so pass `--verify --expect=after=<the dry run's
  after>` and compare the tenant total it prints.
- **Weights** (`class_grade_weights`, `PUT grade-weights`): all five types or none. No rows
  means the class is unweighted and every average is byte for byte what it was. A TYPE is
  one slot in the weighted average, worth its class weight however many pieces are in it;
  the pieces in a slot are pooled (points over points, as the by-type row prints them), and
  slots are renormalised over the types that have counted work, so a type nobody has been
  marked on drags nothing down and Test 40 / Homework 10 with one Test at 90% and any number
  of Homework at 100% is 92.0. A piece with its own weight is a slot of its own worth exactly
  that number (0 keeps it out of the figure), and is not in its type's by-type row. A piece
  with neither a type nor a weight of its own is left out and counted in `untyped_excluded`
  ("N pieces of work have no type"). Levels get a weighted mean LEVEL over the same slots,
  never a percentage; simple marks are never averaged. An override is refused (422) unless
  the class is weighted, and clearing the weights clears every override in the class in the
  same transaction. Only a teacher NOT limited to some subjects may set or clear
  them (403, nothing written, for a limited one: the weights move every subject's average, which
  parents read; review F5, 2026-09-29, superseding W3-3(e)); the office sets and clears them through
  its own route (`PUT admin/.../grade-weights`, `permission:manage contacts`, no subject fence), which runs the
  same `ClassGradeWeightsService` the teacher's route does, so a class of only limited teachers is not stuck
  (`AdminGradeWeightsTest`).
  `App\Support\GradeRecord` is the one copy of the arithmetic; the teacher's and the
  parent's endpoints both call it, and `weighting` / `by_subject` sit BESIDE the older
  summary keys, which are unchanged. The teacher's endpoint adds `data.fenced`.
- **The subject fence now covers grades and lesson plans** (`App\Support\SubjectFence`). A
  teacher whose `group_staff.subjects` lists some subjects is LIMITED: they list, set, edit,
  withdraw and mark only work whose subject maps to a staff subject they teach, read only
  those subjects' marks of a child (the summary, `by_subject`, `levels`, `simple` and the
  list are all filtered), must NAME a subject they teach when setting work, and cannot move
  work into another subject. `SubjectKey::staffKeys()` is the map: the combined
  "Qur'an & Islamic Studies" column belongs to BOTH `quran` and `islamic_studies`;
  Mathematics belongs to none, so a limited teacher does not teach it. Work with NO subject
  is invisible to a limited teacher (404, not 403: it is not any subject's to refuse), and so is
  ANOTHER subject's work or lesson plan (review F4, 2026-09-29): by id it answers the one plain
  404 that untagged work (or a plan id that names nothing) answers, status and body alike, so a
  refusal never names a subject and never confirms that something is there. The by-day lesson plan
  address counts and touches only the plans the teacher may touch: their save on a day holding
  only another subject's plan creates their own beside it, their delete never reaches the others,
  and a day with nothing of theirs is a 404 whether it is empty or not. Only a subject the
  teacher TYPES and does not teach is refused with a 403 in the words of `teacher.teaches:`
  ("You do not teach X in this class.", X being what they wrote).
  A lesson plan with no subject (the day's general plan) stays open to every teacher of the
  class, because fencing it would strand every plan BISS has. Only a signed-in TEACHER is
  limited: the office reads the same controllers through the admin realm and is never
  fenced, and the family endpoint has no fence at all.
- **Migrations.** `add_curriculum_fields_to_class_assignments_table` (one migration for all
  three items), `create_class_grade_weights_table`, `create_school_subjects_table`: additive,
  hand-named unique indexes under 64 characters, `down()` refuses while data exists. The
  seed `seed_school_subjects_for_alrazi_and_biss` is guarded by org id AND name, insert-only,
  logs one WARNING line, marks every row it writes (`school_subjects.seeded_by`, added by its own
  guarded migration `add_seeded_by_to_school_subjects_table`, after the table and before the seed: an applied
  migration is never edited), and its `down()`
  removes only marked rows that are still untouched (an office "Qur'an" it skipped is never its own). Deploy after
  hours (new code meets the old schema for a few seconds). Ship the seed only after the
  owner's yes (B3).
- **Proven by** `GradebookWeightingTest`, `GradebookCurriculumFieldsTest`,
  `GradebookSubjectsTest`, `SchoolSubjectsTest`, `SchoolSubjectsTenantIsolationTest`,
  `SeedSchoolSubjectsMigrationTest`, `GradebookSchemaTest`, `TeacherRoutesRegisteredOnceTest`, `TeacherSubjectAccessTest`
  (the fence), `FamilyGradesTest` (parity and privacy) and `tests/Unit/SubjectKeyTest.php`.

## The age a family gave (2026-10-05)

A registration form that asks "how old is your child?" gives a school an age for every student and a date
of birth for none, so the Age column would stay empty until the office had typed a date for each child. The
owner (2026-10-05): ages are to be there for every student. So a roster has a SECOND source for an age.

- **The value.** `contacts.age_given`, TEXT, `encrypted`, hidden, not fillable: `{age}@{Y-m-d}`, the whole
  years the family answered and the day they answered, as ONE value (an age without its day is wrong within a
  year). One reader, `Contact::ageGivenOrNull()`, and one writer, `Contact::recordAgeGiven()`, which logs the
  contact, the organisation, the actor and the verb at `warning` and never the value. `StudentAgeGivenTest`
  pins who calls them. An unreadable value reads as none, with one ERROR line.
- **The order (`StudentAge::shown()`).** A date of birth the office typed WINS, always. With no readable date,
  the age given is shown, plus the whole years since the day it was given, on the school's clock. It can be
  one short after a birthday (6 in September, a birthday in October, still shown as 6 until the next
  September). Nothing shows the stored number as it is.
- **The office is told which ages those are.** The office roster row carries `age_given: bool` beside `age`;
  the screen draws the word "given" beside the number, says what it means above the roster, and Student
  details reads "Age 6, as the family gave it at registration". The birth-date endpoints answer with what the
  roster shows (`age`, `age_given`), so removing a date puts the family's age back on the row without a
  re-read.
- **A teacher gets the number only.** The teacher realm's `age` is filled from either source and carries no
  flag, no day and no answer. Nothing else changed in who sees an age: the same rows, the same realms.
- **How it gets there.** Never from a request body. The ages are copied from a school's registration answers
  by an office-run script or command through the model's writer (`through: registration`), matched to a
  student only when exactly one current student has that name. A merge carries it onto a kept record that has
  none (`through: merge`), and never replaces the kept record's own.
- **Not built.** A registration does not write the age onto the student by itself: a student added after the
  copy shows a dash until it is run again or a date is typed. The office cannot edit or clear the age given
  (typing a date of birth supersedes it). The school records export does not carry it (it is in the form
  responses export, where the family wrote it).
- **The clear says nothing about it.** `DELETE …/contacts/{contact}/birth-date` takes any contact of the
  organisation, so it answers the same constant for everyone (`age: null, age_given: false`): an answer that
  carried the family's age would say that somebody who is not a student has one on file. What a student's row
  shows after a clear is read by ROSTER ROW (`GET …/members/{row}/birth-date`, which answers for a student in a
  class and nobody else); the date form does that read itself and tells the roster.
- **KNOWN GAP, next change.** Nothing removes an age given: the clear takes the date of birth only, Remove on
  the roster says nothing about it, and the writer's callers are the copy and a merge. It is to be cleared
  together with the date once no class lists the contact, and Remove is to speak when either is held. Until
  then, a family's request to delete what the school holds about a child who has left needs a one-off through
  `recordAgeGiven(null, null, …)`.
- **Deploy window.** Read only behind `StudentAge::givenColumnExists()`, asked separately from the date of
  birth's column, so between checkout and migrate a roster shows the ages dates of birth give and names no
  missing column.
- **Rolling back.** By code only, never `migrate:rollback`. And never to a commit from before this one as it
  stands: older code does not hide `age_given`, and the staff contact endpoints serialise the model whole, so
  the ciphertext would ride every contact they list. A revert keeps `age_given` in `Contact::$hidden` and its
  cast, the same standing rule as `date_of_birth`.
- **What a teacher sees changed in content, not shape.** A teacher's `age` is now filled for a student whose
  family gave one, with no mark that it may be one short.
- **Classified.** Staging scrub: `encrypted_null`. Account deletion: `OFFICE_COLUMNS` (the school holds
  something about this person).
- **Proven by** `StudentAgeGivenTest` (20; three guards were removed once each and a test went red: the date
  wins, the age grows, the value is hidden), the screen tests in `student-age.test.ts` and
  `student-details.test.ts`, and on MySQL `tests/Mysql/ContactDateOfBirthMysqlTest.php` (CI only).

## Moving a student to another class (2026-10-04; consent carried and a whole class, 2026-10-05)

`App\Support\RosterMove`, behind `GET` / `POST …/members/{id}/move` (`GroupMoveController`, both
`permission:manage contacts`). The office says who belongs in a class; there is no teacher or
family route. Design and the review that changed it: the roster features folder of 2026-10-04.
A whole class is the same move run once per student (`RosterClassMove`, below).

**A roster row NEVER changes class.** There is one path, "left and started": the old place gets a
leaving day and stays where it is with everything recorded on it, and a new place opens in the
new class (`left_and_started`), or the place the student held there before opens again
(`returned`). There is no re-point, even for a row that holds nothing. The reason is every other
writer: each office roster action and each record writer loads a roster row through its class and
then writes by primary key with no lock, so a row that changed class would be written to by
requests that loaded it under the old one. When the old place holds nothing the answer says so,
and Remove (which refuses if something landed meanwhile) takes it.

The rules. R1, R3 and R4 are as they shipped; R2 was reworded and R8 to R10 added when consent
began to travel (the owner, 2026-10-05: "Carry each parent's consent as it is", and for a single
move "yes it should carry it too"). R5 to R7 are about a class-store balance following the
student and are written here with the commit that first lets a move carry one; until then the
"Class store" paragraph below is the whole of it.

- **R1. Records never change class.** Ten of the eleven tables that point at a roster row carry
  their own `group_id` and the eleventh names its class through its file; a record whose class
  disagreed with its roster row could be opened from neither.
- **R2. A move never gives an adult more than they held.** A closed entry in the class being
  LEFT never travels. A confirmed entry in the class being ENTERED is re-opened only when the
  same adult holds a confirmed, current entry for the student in the class being left; otherwise
  the move is refused. An unconfirmed entry may be re-opened and stays unconfirmed. Consent is
  carried as it is onto an entry the move creates, from a confirmed, current entry, and not when
  the adult already stands in the class being entered for another child with less. Consent on an
  entry the class already holds is never written by a move; when it is in force again the screen
  names each guardian with the scope and the date, and a move is refused while it would bring
  back a consent the family withdrew where it had been carried.
- **R3. A move destroys nothing.** No row is deleted and no consent column is cleared, on any
  path. Every successful-move test ends with that invariant.
- **R4. Locks first.** One transaction: the student's CONTACT row by primary key (the mutex for
  every roster row about that child: an insert naming the contact, as the person or as a guardian
  entry's child, needs a shared lock on it), then the roster row by primary key, then the first
  ordinary read, then every roster row about the student in the two classes by primary key,
  ascending, then a SHARED lock on the two class rows, which are checked again (still a class,
  still running, not deleted). Never a range lock. A short lock wait is set for the move; a
  deadlock, a lock wait timeout and a unique violation are answered 409 "this roster changed",
  never a 500. The move is logged after the commit. Carrying consent added NO lock: its reads
  of other children's entries and of other classes are ordinary reads (why that is sound is
  under "never wider" below), and the two source pins that count lock expressions are unchanged.
- **R8. A consent travels only as it was recorded.** Same adult, same child, from a confirmed,
  current entry for which `hasConsent()` is true, onto an entry this move creates, both columns
  unchanged, marked with the class it came from, and not at all when it would widen what the
  adult already holds in the class entered. A blank stays blank: no consent is written for an
  adult who has none on record, on any path, and no gate (`hasConsent`, `mayReceive`,
  `standingIn`, the recipient resolver) was touched.
- **R9. A class is moved one student at a time, and the answer names every student.** One
  transaction per student; nothing is locked across two students; the request names the roster
  rows the office was shown.
- **R10. Nothing withdrawn comes back by a move.** An entry that a move or a "Put back" would
  re-open with consent is checked against the other side of every carry it took part in; when
  the family has since withdrawn or narrowed there, the act is refused with the remedy. Both
  directions: the copy withdrawn or reduced where it was carried, and the source withdrawn or
  reduced where it was first recorded. A move refuses on the server; "Put back" is not offered
  on the screen, and its verb stays ungated (below).

**The guardian rule, and its refusal.** `RosterMove::notVouched()` is one pure function with two
callers: the move (may the target's confirmed entries be open again?) and the roster list's
`moved_to_state` (may "Put back" re-open this class's?). It returns a REASON per entry,
`no_entry` or `only_unconfirmed`, each with its own sentence. One refusal names EVERY unvouched
guardian and carries the class to open, so it can always be cleared from the screen it appears
on: confirm or add the adult on this roster, or remove their entry in the other class.

**Who may confirm: two names.** `confirmedByStaff` (three callers: `GroupMembershipsController`
`store` and `confirm`, and `RosterImportService::apply`) creates a confirmation. `carriedFrom`
COPIES one, unchanged, for the SAME (adult, child) pair onto a new row in another class of the
same organisation. Its one caller is `RosterMove::carry()`, which stamps the new row
`selfAssertedFrom(null)` first, so a row that misses the copy is an unconfirmed claim with no
consent. It copies provenance and `source_registration_id`, and the confirmer and time only when
the old provenance is exactly `confirmed`; never a leaving date. It throws on a saved row, on
another person, role, child or organisation, on the same class, and on an old row that has left.
Both caller lists are counted by `RosterMoveTest`.

**Consent is carried as it is, through that one door.** `carriedFrom(self $old, bool $withConsent
= false)`: a caller that says nothing copies nothing. With the argument, and only inside the
confirmed branch, on a guardian entry, from a source for which `hasConsent()` is true (never
`consentColumnsAreSet()`, which is true for half a record), it copies `consent_scope` and
`consent_granted_at` unchanged (the day the family gave it, not the move day) and sets the
marker. The unconfirmed branch nulls both consent columns, so a claim cannot hold consent
whatever was put on the unsaved row. The student's own place never gets consent. A twin (an
entry the class entered already holds for that adult and child), open or closed, blank or not,
is re-used or re-opened with what it holds and nothing is written to it. The old entries keep
their consent (R3). `RosterMove::carry()` passes the argument only for the source entries
`decide()` put in the plan's `consentToCarry`.

**The marker.** `group_memberships.consent_carried_from_group_id` (`GroupMembership::
CONSENT_CARRIED_FROM`): the id of the CLASS the consent was copied from. Nullable, no foreign key
(a key into this table would be a twelfth and fail the schema walk), no index, not fillable. It
names the class and not the entry because Remove and a merge re-issue rows; while the source
entry exists it is the one row for that class, adult and child.

| Marker | Consent columns | Reads as |
|---|---|---|
| null | set | recorded by the office for this class |
| set | set, as much as the marked class holds | carried from that class, untouched since |
| set | set, LESS than the marked class holds | carried, and recorded here since for less (the family reduced it) |
| set | null | withdrawn here after it was carried |
| null | null | never asked, or withdrawn where it was recorded (the same state, by design) |

Its writers: `carriedFrom` with consent sets it. `GroupConsentController::update` CLEARS it, an
unchanged re-save included (the office is now asserting the consent for this class, and the
dialog says so), WITH ONE EXCEPTION: a record of LESS than the entry in the marked class holds
(the class story where that one holds photographs) KEEPS it, on a marked copy and on one that
had been withdrawn. That is a family reducing what was carried, and with the marker gone
nothing would stop a move back from bringing the wider consent into force. `destroy` keeps it:
a withdrawal writes its two keys and nothing else, is never refused and depends on no new
column. `unconfirm()` clears it together with a consent it clears, and leaves it alone on an
entry that was already blank (that state records a withdrawal). A merge re-issue loses it with
the old row. Leaving, returning and "Put back" do not touch it: rule R10 reads it on a closed
copy. Known limit: withdraw, record again for as much as the other class holds, withdraw again
leaves null and null, "never asked". The office roster row carries the column,
`consent_carried_from` (`{id, name, deleted_at}`) and `consent_less_than_carried_from`, a
boolean the server works out because it takes the other class's row; both consent verbs answer
the same three. The teacher and family payloads carry none of them (pinned), and the records
export has no column for it, so a carried consent reads there as dated before the entry's own
joining day.

"Less than the marked class holds" is a COMPARISON OF TWO ROWS, not a memory of an act
(`GroupMembership::holdsLessThanCarriedFrom`). An untouched copy whose source was recorded for
MORE afterwards reads the same and is refused the same. So nothing built on it says "reduced":
the roster's label is "Carried from {class}, which holds photograph consent", and the refusal
says what each class holds.

**Who carried a consent, and when, is not on the entry.** "When" is the copy's own first day
(`joined_at`) and `created_at`. "Who" is `moved_by_user_id` on the student's row until that
student's next move, and after that only the `roster.move` log line, which production keeps for
about two weeks. The column is the record; the line is history.

**Never wider than the adult's standing in the class entered.** An adult is admitted to a class's
broadcasts on ANY one current entry there that covers the disclosure, so a consent carried for
one child would open the class to an adult whose entry there for a brother or sister is blank,
and blank may be a withdrawal. So `decide()` reads the adult's other current, confirmed entries
in the class entered (for a child other than this one): with none, or when the widest among
them covers the source's scope, the consent is carried as it is; when the widest is narrower,
NOTHING is carried for that entry, the new entry is blank and unmarked, and the plan names the
guardian. As it is, or not at all: no narrowed copy is ever written. Unconfirmed entries are not
counted, nor entries that have left (one exception, in a whole-class move, below). A marked entry
the family has taken back since it was carried, blank (withdrawn) or holding less than the class
it came from (reduced), always caps, whatever its id (`RosterMove::takenBackSinceCarried`). That read
takes no lock, on purpose: a withdrawal on the other child's entry that lands between the read
and the commit gives the state "moved, then withdrew", which no lock can forbid, and the
withdrawal's own answer names what still stands.

**What the dialog showed is what happens.** The preview takes no lock, so the POST echoes what it
showed: `expected_path`, `expected_first_day`, `expected_joined_on`, and now `expected_consent`,
a fingerprint of the consent result (`m{photographs}f{story}s{not carried for a sibling}n{none on
record}e{left as it was}`). A difference under the locks is 409 "changed while you were looking"
and nothing is moved. `expected_bucks_rule` is accepted and handed on and is null until a move
carries a balance. A null expectation is not checked, WITH ONE EXCEPTION at the single verb
(`consent_must_be_echoed`, set by `GroupMoveController` and by nothing a request sends): a body
with no `expected_consent` may not CARRY a consent. The dialog always echoes, so such a body
comes from a page opened before a move carried consent, whose sentence was "Consent ... does not
move". It is answered 409 with its own sentence ("Manara has been updated since this page was
opened ... Reload this page ..."): "look again" would loop, because that page can never send the
echo. A move that carries nothing is not held up, and the service's other callers (a whole-class
run, which always echoes) are not held to it.

**Rule W: what R10 refuses.** Let E be a guardian entry naming the student, in the class that
would be entered (or, for "Put back", beside the old row), closed now, open afterwards, with
consent. `RosterMove::consentComingBack()` is one pure function with two callers, as `notVouched`
is: the move, and the roster list's `moved_to_state`. It answers, per entry:

- `copy_withdrawn`: another entry of the same adult and child, in any class, has its marker
  naming E's class and both consent columns blank. E is the source; its copy was withdrawn where
  it had been carried. REFUSED.
- `copy_narrowed`: the same, where the marked entry holds LESS than E (the class story where E
  holds photographs). The office recorded less on the copy and that record kept the marker.
  REFUSED.
- `source_withdrawn` / `source_narrowed`: E's own marker names a class, that class holds an entry
  for the same adult and child, and that entry now holds nothing, or the class story where E
  holds photographs. E is the copy. The source's columns are read as they are, left or not.
  REFUSED.
- `source_gone`: the marker names a class that no longer holds an entry for them. Nothing to
  compare with: the move goes ahead and the guardian is NAMED.

The refusal is the unvouched-guardian refusal's shape (409, one sentence per guardian, "Nothing
was moved.", the class to open and now the roster row the remedy is about, `open_group.
membership_id`, which the roster brings into view through `?focus=`). The remedy is one act in
every case: the office withdraws E's consent on that roster (the Consent button is drawn on
entries that have left, and now shows its word), moves again, and records consent afresh there
if the family still agrees. The office stays the only one who withdraws or records.

**What rule W cannot see. Each fails towards asking the family, never towards more than was
recorded.** `copy_withdrawn` and `copy_narrowed` are row states, "marker set, and less than the
other side holds", and four ordinary acts erase them, after which a return re-opens the old
consent and the office reads a line, not a refusal: Remove on the student's place where the copy
sits (it deletes the guardian entries beside it; the move no longer invites it, see "Known gaps");
Remove on the GUARDIAN'S OWN ENTRY there, after which the guardian rule's refusal tells the
office to "add them on this roster first" and the entry it adds is unmarked; a merge that
re-issues the row; and recording on the copy as much as the other class holds (which clears the
marker on purpose) before withdrawing or reducing. Down a chain (carried on to a third class and
withdrawn there) a return straight to the first class is not refused: its own copy in the second
is not blank. It has no memory: after the remedy and a fresh record the marked copy still exists,
and a later move out and back is refused again. And it compares rows, so a source recorded for
MORE after an untouched carry is refused as a reduced copy is. "Put back" is guarded on the
screen only (below). All of these are in ASSUMPTIONS.md.

**A withdrawal says where else consent stands.** Both consent verbs answer top-level `notes`,
sentences built on the server, read AFTER the write; a failure in that read is swallowed and
logged at WARNING, so it can never turn a withdrawal into an error. First the same class: the
adult's other current entries there, for any child, that still open more than this entry now
does (withdrawing for one child leaves the class open through a brother's or sister's entry).
Then the same adult's entries for the SAME child in every other class, current or closed, that
still hold consent. The roster keeps its success message up while there are notes.

**The deploy window.** `bin/deploy` serves new code before it migrates. `RosterMove::ready()`
asks one question per column a move needs, each of the class that owns the column (today only
`GroupMembership::consentCarryReady()`, the `StudentAge` pattern). Until it is true every preview
and every move, single or whole-class, is refused through `refuseUnlessReady()` with "Manara is
being updated. Try this move again in a minute." and one WARNING line, `roster.move.not_ready`,
naming the missing column; nothing is locked, read or written. Everything else behaves as
before its column: the consent verbs write the marker key only when the column exists,
`movedToStates()` computes its consent fields only then (every roster read goes through it), and
the roster list loads `consent_carried_from` only then.

**"Holds records": one list, two questions.** `AcademicRecordsHeld::KEYS` is every foreign key
into `group_memberships.id` (eleven; a schema walk fails on a twelfth). All eleven say what STAYS
with the old class after a move. Eight of them, the seven RESTRICT kinds and Arabic daily notes,
are what a delete would DESTROY, and the two roster deleters (Remove, and the undo of a roster
import) refuse on those and on nothing else. They do not refuse on a conversation, a scheduled
message or an addressed file: the paragraphs above ("the record survives, the audience shrinks";
a recipient row cascades) stand. The counts are raw, so a record deleted on its own screen still
refuses; the sentence says so and names "Left the class" as the way out. A contact MERGE has its
own narrower list (`RosterMergeService::carriesRecordsAboutAChild`) and is a third deleter: see
the known gaps below.

**The move day is OWED to one register.** `moved_on` is the day the office chose, on both rows.
The first day in the new class is that day, unless the old class holds a register mark for the
student on or after it: then it is the day after the LAST such mark, and those days stay with the
old class. The old row's `left_on` is the day before the first day, with no clamp (a student
placed and moved on one day gets a leaving day before their joining day, so the old register
expects them on no day instead of on a day both would owe). A return keeps its first `joined_at`
unless the class took a register while the student was away; then it counts from the first day
back, and the old value goes into the log line. Both attendance reads are half-open ranges on the
raw column, as `AttendanceLogController::marksIn`. The request carries what the dialog showed
(`expected_path`, `expected_first_day`, `expected_joined_on`); a difference is 409 "changed while
you were looking". NOT covered: the teacher's register and the office's today band never read
`joined_at`, so the new class's register still lists the student on a day the old class kept.

**"Put back" on a row that was moved, or that left.** `DELETE …/withdrawal` stays ungated: its
docblock rules out an undo that can be refused. The guard is on the screen (`PutBackDialog.vue`,
the only place in the SPA that sends the undo): it reads the roster again, and offers nothing
while `moved_to_state.guardians_not_vouched` names anybody, or while
`moved_to_state.consent_blocks` is not empty (rule W read from this side: one server sentence
per entry, then once what to do). `consent_lines` (every other entry here whose consent would be
in force again) is printed above the button; `bucks_line` is null until a move carries a
balance. The first list is computed against the student's CURRENT rows anywhere in the
organisation, and against the class they were moved to only when they are current nowhere.

THE TWO CONSENT LISTS ARE ALSO SERVED ON A ROW THAT SIMPLY LEFT (no "moved to"), when either has
something to say: "Put back" re-opens the entries beside a row whichever way the student left,
and a carried copy beside it may have had its source withdrawn while the child was in neither
class. Such a row answers `student_there: none`, no class to open and no unvouched guardian; a
row that left with no consent to bring back answers null, as it always did.

What a screen guard leaves open: a hand-made request; a tab loaded before the release that added
a field; a change between the read and the tap; and a student with no row left in the other
class, where there is nothing to compare and the dialog names every guardian instead.

**A row put back by hand that later simply leaves is no longer "moved to" anywhere.**
`returnToRoster()` keeps the three move columns, so the roster can say "put back after a move
to ...". `markLeftByStaff()` clears them when the row was CURRENT and still carried "moved to":
leaving now is a new act and not that move. Left as they were, the roster badged the row as
moved, a move told the office to open the class of a move that had been undone, and the class
store refused to undo a prize "because the student was moved". A move stamps its own three with
`markMovedOut()` right after. A row that has ALREADY left keeps them: correcting a moved row's
leaving date does not un-move it.

**"Add to roster" takes the same contact lock.** `GroupMembershipsController::store` locks the
student's contact row as the FIRST statement of its transaction; every lookup runs before the
transaction and the duplicate check after the lock, as an ordinary read (a locking read over the
roster's index would take next-key locks on the class's range). The unique index cannot dedupe a
student's row: its ward is NULL.

**Class store.** No figure about one child reaches the office: `bucks_staying` is a boolean, said
only for a school that holds `class_store`. The balance stays on the old row (W6-C1 is still the
owner's); the seam for a later transfer is `RosterMove::classStoreBucksStaying`.

**History.** Four nullable columns on the student rows (`moved_from_group_id`,
`moved_to_group_id`, `moved_on`, `moved_by_user_id`; no foreign key, no index, the
`left_recorded_by_user_id` precedent) keep the LATEST move of a row; `markMovedOut` clears
"moved from". Each move also writes `roster.move` at WARNING (production drops info), ids only,
with every guardian entry carried, re-used or re-opened, and now `consent_carried` (pairs of new
entry and source entry), `consent_not_carried` (sources left blank by "never wider") and `run`
(a whole-class run's id; null for a single move).

**Moving a whole class.** `App\Support\RosterClassMove` and `RosterClassMovePlan`, behind `GET` /
`POST …/groups/{group_id}/class-move` (`GroupClassMoveController`, both `permission:manage
contacts`; the URI does not end in `/move`, and the suite pins one URI for each). "Move the
class" on the Roster tab is drawn only on a class with a current student. The preview takes no
lock: it runs the class-level checks once (`RosterMove::refuseUnlessClassesAndDayAllow`, the
single move's own checks 3 to 7, so the office reads one refusal and not N), then the single
preview for every current student, and answers the class as a list of students, each with what
the POST must echo, plus class-level sentences built on the server from the single plans'
fields. The run, in order:

1. the deploy-window guard, then the class-level checks (422);
2. ONE RUN AT A TIME PER CLASS BEING LEFT: a non-blocking lock on `Cache::store('database')`,
   named so the mutex does not depend on `CACHE_STORE` (the suite's default is `array`), 120
   seconds, released in `finally`; held: 409. It needs the framework's `cache_locks` table;
3. three things read once for the whole run: the newest roster row id (`standing_before_id`),
   a ULID for the log lines, and the school's today (a run that crossed school midnight would
   otherwise decide its students by two different days);
4. a pre-flight with no lock and no write: every named row must be a current student of this
   class and preview to the same path, first day, joining day on a return, consent fingerprint
   and grade the request echoes. Any difference for any student is 409 with `data.students`
   (`RosterClassMoveChanged`), and nothing has been written;
5. `RosterMove::move()` once per named student, in the request's order, each in its own
   transaction, with five options no request can supply (`run`, `whole_class`,
   `standing_before_id`, `today`, `attempts` = 1; neither verb's Request has a rule for them).
   THE ROW IS READ AGAIN, through the class, a moment before its move: somebody else may have
   moved or removed that student since the run's first read, and the single move makes its cheap
   refusals from the row it is handed, so a stale copy was answered "busy, try again" about a
   student who was in another class. A refused student is reported with the single move's own
   sentence and THE RUN GOES ON. `retry` is true only when the roster was busy or had changed,
   and then the sentence is the run's own (`RosterClassMove::BUSY`): the single move's "Nothing
   was moved ... read what will happen below" is false in a result that lists the classmates
   who were moved. Any other exception is a fault: it is reported, it STOPS the run, and the
   answer is still 200, because an error page would hide who was already moved;
6. no student is started after `BUDGET_SECONDS` (40); the rest are `not_reached`.

Why per student and not one transaction: a held row would fail the whole class (inside an outer
transaction the framework rethrows a deadlock instead of retrying), and a register save or a
redemption about ANY student would wait for all of them. Between two students the state is always
"these fully moved, those not touched". `MAX_STUDENTS` is 60 per request. Inside a run a student
gets ONE attempt (a single move keeps three): `LOCK_WAIT_SECONDS` is per lock request, so three
times three is the wait behind one held row, not a bound.

Two children of one family in one run: without care the second child's move would find the entry
the first child's move made a moment earlier and be capped by it, so the family would keep or
lose the story by the order of a list. Entries with an id above `standing_before_id` did not
stand in the class before the run and do not cap, except one the family has taken back since it
was carried (blank, or less than the class it came from). What is left fails towards less: two
siblings moved on two occasions, and a run that is split (the answer counts
`siblings_left_behind` and says to read the next check).

ONE SIBLING GOING BACK, ANOTHER ARRIVING. A whole-class preview and run pass `whole_class`, and
then, for an adult who has NO current entry in the class entered, an entry of theirs there that
has LEFT counts after all when the child it names is in the class being left today: that brother
or sister may go back in the same act, and the entry opens again with whatever it holds. Without
this the order of the list decided it: with the returning child first the re-opened blank entry
capped the carry and the second child was reported "changed while you were looking", and with
the other child first the consent was carried and the blank entry opened beside it a moment
later with nothing said. Now the preview shows the cap before the tap and both orders give the
same rows. Among several such entries the narrowest counts; an adult with a current entry is
judged on that alone, so a closed entry never loosens a cap. It errs towards less: a brother who
is not ticked, or cannot move, still caps. The sentence says "has an earlier entry in {class}
for another child who is in {class} too and may go back with this class", never "is already in".
A single move passes nothing and follows its own rule: a closed entry gives no standing.

A PARENT WITH CONSENT FOR ONE CHILD AND NONE FOR ANOTHER, both in the move. Each student is
decided alone, so the second child's plan read "they receive nothing from the class story until
consent is recorded", while the first child's carried entry opens the whole story to that
parent. The class plan tells each single plan which adults have a consent carried for another
student of the same move (`RosterMovePlan::siblingsCarryFor`, from `RosterClassMove::describe`),
and those places are counted and said apart (`consent_none_through_sibling`: "... is carried, so
{class}'s story will reach that family through that child"), before the run over everyone who
can move and after it over the students who were moved. The fingerprint counts both under `n`,
so what the tap echoes is unchanged.

Grades are a choice with no default (`keep`, `set`, `up` through `GradeLevel::next()`; a label
it cannot read, or the last grade, is kept and the row says so). `set` with no grade typed yet is
NO choice (`RosterClassMove::chosenMode`): the field opens only once the choice is ticked, the
dialog asks nothing until a grade is typed, and the server prints no grade line around a blank. With `keep`, a student going
BACK to a place they held gets the grade recorded on that place, which is what puts a class back
as it was. Putting a class back is the same action from the other class: each row carries
`came_from_target`, and the dialog offers "Only the students who came from {class}". Nothing
belonging to the class travels (teachers, story, files, lesson plans, the program that enrols
into it), the run never ends or switches off the old class, and no run is recorded anywhere but
in the log lines' `run`.

The dialog's ticks are the office's: kept by roster row id across every re-check and every 409;
only the first check for a class ticks everyone who can move. An answer with NO LIST (a refusal
about the class as a whole: a day in the future, the minute of a deploy) says nothing about any
student and leaves the ticks as they were; it has its own "Try again". The preview's class
sentences count everyone who CAN move, not the ticks (the server never learns the ticks), and
the dialog says so when some are unticked; the result's sentences are exact. When the answer
never arrives the dialog assumes nothing and never sends the same body again. The shared
`apiErrorText()` would print an object for the 409 that carries `data.students`: the dialog has
its own reader.

Five things the dialog holds to, each found by a review and pinned in the mounted tests. ONLY THE
MOVE BUTTON SENDS: the form has no submit handler and no submit button, because a browser
submits a form on Enter from any field and this one holds a checkbox per student. A REFUSED RUN
IS SAID FIRST in the body, with the keyboard on it, and again beside the button, and Move stays
off until it is acknowledged or a choice or a tick changes (under a long list it was drawn below
the fold; the single dialog draws its refusal first too). THE RESULT STAYS UNTIL OK: a click on
the backdrop closes only a dialog nothing was changed in, never a result, the lost-answer notice
or a list with a changed tick. THE FIRST LINE counts the class as the server last listed it, not
the roster the page drew before. And past `MAX_STUDENTS` the rows that will not be sent are
marked "Next round", and "Move the rest" ticks them again.

**The family's list says whether the child is still in the class.** `in_class_now` on each class
of the family's list and on the family's class (`Family/GroupsController`): true while any entry
the parent stands through has no leaving date; consent is not consulted. The portal draws its
"no consent" notice only when `may_receive_feed` is false and `in_class_now` is not false: after
a move the old class's story is hidden because the child left, not because consent is missing,
and the notice said the opposite. A payload without the key behaves as before. It is not a move
column: the family payload carries no `moved_*` key and no marker (pinned). The portal says
nothing new about a carried consent, in any language; a parent who wants it withdrawn asks the
office.

**Known gaps, for whoever owns them.** A contact merge after a move re-opens the old class's
closed guardian entries as open pending claims (`RosterMergeService::reissue()` drops `left_on`),
and merge's drop path deletes Arabic daily notes and addressed files silently. A place whose
guardian entry is a withdrawn carried copy is no longer offered for Remove by the move's own
sentence (`carriedConsentWithdrawnHere`: "A guardian's withdrawal of a carried consent is
recorded there, so it stays."), but Remove itself is not refused for it, and it erases the state
rule W reads. UNTIL A MOVE CARRIES A BALANCE the class store must stay off: the shipped "has
Manara Bucks ... They stay there for now" sentence and the old-entry line both differ child by
child, and a whole-class preview prints them for every child of a class at once. Never run on
MySQL where they were written: every MySQL case of the consent carry and of
the class move (CI only), and two cases nobody has written because they need two connections
waiting on each other, which this suite's one process cannot stage: two class runs in opposite
directions with siblings who share a parent, and a registration against a move of the same child.

**Registration, narrowed.** `RegistrationService::writeRosterMemberships()` now holds each
child's contact row before its "already there?" checks (`lockRosterSubjects`), so a move and a
registration of the same child queue behind each other. What is left: a move that COMMITTED
during the registration's own transaction, after its first read fixed the read view and before
it took the lock, is invisible to those checks. The visible result is a second, unconfirmed
place for the child that holds nothing, which Remove takes away. Only a key that covers a
student's place closes it for every writer.

Proven by `RosterMoveTest`, `RosterMoveRosterTest`, `RosterClassMoveTest`, `FamilyPortalTest`,
`resources/vue-app/tests/roster-move*.test.ts`, `roster-class-move*.test.ts`,
`student-details.test.ts` and `family-left-class-notice.test.ts`, and on MySQL by
`tests/Mysql/RosterMoveMysqlTest.php`, `tests/MysqlLocks/RosterMoveLocksTest.php` and
`tests/MysqlLocks/RosterClassMoveLocksTest.php` (CI only).

## The office's class list names each class's teachers (2026-10-04)

`GET admin/.../groups` (the index, `view contacts`, the gate the Teachers screen reads under) carries
`teachers` on every row: `[{id, name, subjects}]`, the live staff logins on `group_staff` with the
teacher role, in order of name. `subjects` is what they teach IN THAT CLASS as `[{value, label}]`, in
`GroupStaff::SUBJECTS` order with `SUBJECT_LABELS`, or null for a teacher of the whole class (NULL or
an empty list on the row). A class nobody teaches has `[]`.

- **Set on the page's rows in `GroupsController::index`, never on the model.** No `$appends`, no eager
  `staff` relation (that would serialize whole `User` rows). So show/store/update do not carry it, and
  neither does any payload another realm builds by hand (the teacher realm's `classPayload`, the family
  realm's `serialize`): a teacher's own list of classes names no colleague, and a family's names no
  teacher. Do not move it onto `Group`.
- **One query for the page** (`teachersByGroup`), started from `GroupStaff` so the tenant scope
  constrains it, joined to live users: an archived login keeps its `group_staff` rows and is nobody
  here, as in the reaction digest above.
- **Names only.** A teacher's email and phone stay on the Teachers screen.
- **Proven by** `GroupIndexTeachersTest` (shape, order, archived, another organisation, one query, and
  the key's absence from every other class payload) and `resources/vue-app/tests/class-teachers.test.ts`
  (the screen's column, mounted).

## Tenant isolation

Both models use `BelongsToMasjid`; `group_memberships.masjid_id` is
denormalised so membership queries scope without joining through `groups`. The
controllers keep the `{masjid_id}` route parameter by convention and **never**
hand-filter by it — cross-tenant ids are 404 misses, and a cross-tenant route is
a 403. Proven by `tests/Feature/GroupTenantIsolationTest.php` (model layer) and
`tests/Feature/GroupCrudTest.php` (HTTP). See `.claude/rules/tenant-scoping.md`.
