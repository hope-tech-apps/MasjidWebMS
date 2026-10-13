# Multi-organisation admin — rollout and rollback

The operational half of `docs/multi-tenant-admin-design.md`. That document says what
was built and why; this one says how it is turned on, what to look at afterwards, and
— the part that matters most — what has to happen **before** it can be turned back off.

The feature is one boolean: `config/tenancy.php`'s `multi_membership`, read from
`TENANCY_MULTI_MEMBERSHIP`. It ships **false** and every environment runs false until
somebody deliberately sets it. With it false, the code below is inert: every admin
holds exactly one grant, the switcher never renders, and
`POST|DELETE /api/admin/admins/masjid/{id}/memberships` refuses.

---

## The rollback trap — read this before granting anybody a second organisation

**Turning the flag off is NOT a complete rollback once multi-organisation grants
exist.** An admin who owns no organisation and holds two or more `masjid_user` rows
resolves as *ambiguous* with the gate shut, and ambiguous is a 403 on **everything** —
not a quiet loss of the second organisation, but a lockout from the first one too.

Why: with the gate shut `TenantResolver::grantsFor()` returns the sole OWNED masjid
(`masjids.user_id`) if there is one, and otherwise the persisted membership rows. Two
rows and nothing in the route to choose between them is refused by design — the
resolver never substitutes a guess for a tenant nobody named. That rule is correct and
should stay; it just means the flag and the data have to be rolled back **together**.

Measured on staging on 2026-09-15: user 30 (one owned organisation: none, memberships:
MEC + IntelliCor) went from working to 403-everywhere the moment the gate closed, and
back to working when the extra row was deleted.

So the rollback is two steps, in this order:

```sh
# 1. remove the extra grants (keep ONE row per admin — the organisation they
#    should fall back to; `is_default` does not save them, ambiguity is refused
#    before any default is consulted)
#    ... then
# 2. TENANCY_MULTI_MEMBERSHIP=false, config:cache
```

An admin who **owns** their fallback organisation (`masjids.user_id`) is immune: with
the gate shut, ownership wins and the extra rows are ignored. If a multi-organisation
admin is also the owner of one of those organisations, closing the flag alone is a
clean rollback for them. None of the five admins provisioned in September 2026 own
anything, so none of them are immune.

---

## Teachers in several schools (Phase 1 of multi-org users)

The same flag, a different risk profile. A **teacher** who belongs to two schools is
bound by the `{masjid_id}` in the URL of every teacher route, so — unlike a two-organisation
admin who owns nothing — closing the gate does **not** lock them out. Measured by
`TeacherMultiSchoolTest` (the same fixture, gate open and gate shut). What the flag does
for teachers:

- **Open:** the Teachers screen may attach an existing teacher's email to a second school
  (`TeachersController::store`, create-or-attach).
- **Shut:** that attach is refused with "Adding an existing login to a second school is
  switched off." Creating a brand-new teacher still works, and so does everything an
  already-attached two-school teacher does.
- **Removal never asks the flag.** `TeachersController::destroy` removes this school's rows
  in either state, re-picks the default and keeps the teacher's sessions while another
  school remains. So the rollback step "delete the extra memberships first" has a door that
  works with the gate shut (`MasjidAdminsController::revokeMembership` does not).

So the rollback rule above is unchanged for admins and is **not needed for teachers**:
closing the flag leaves every existing two-school teacher working and only stops new
attaches. Do not read that as permission to close the gate with two-org admins still in
place.

---

## One login, a role at each organisation (teacher at one, lunch staff at another)

A second switch, `config/tenancy.php`'s `roles_per_organisation`, read from
`TENANCY_ROLES_PER_ORGANISATION` in one place (`App\Support\StaffArea::open()`). It ships
**false**. What it is for: one person, one address, one password, runs Friday lunch at one
organisation and teaches at another. For the two scoped kinds the `masjid_user` row says
what the login is at that organisation (`MasjidUser::kindFor`); `users.type` stays the
kind the login was created as.

- **Off:** nobody enters an area outside their own kind, the Teachers door and the
  lunch-staff door refuse an address that has a login of the other kind with the
  sentences they always had, and no payload carries `areas`.
- **On:** the Teachers door attaches a live lunch-staff login as a teacher of that
  school, and the lunch-staff door gives lunch access to the live login of a person who
  teaches somewhere and runs lunch nowhere (a teacher's login, or a lunch-staff login
  whose lunch access was removed while it went on teaching: that is how lunch access is
  given BACK). The person is emailed that they were added and signs in as before; a
  switch in the header moves them between the two places.
- **In both states** the tenant resolver binds a teacher's or lunch-staff request only
  through a row of the kind it is acting as. That part is not switchable: it only
  removes access.
- **It also needs `TENANCY_MULTI_MEMBERSHIP=true`** to attach a person who already
  belongs somewhere, exactly as attaching a teacher to a second school does. With that
  gate shut nothing new is attached and nothing new is said: each door answers such an
  address in the words it had for it before this feature (the Teachers door "This email
  already has a Manara login that can't be added as a teacher. Contact Manara support."
  for a lunch login; the lunch-staff door "The email has already been taken."). A
  sentence of their own there told an office what kind of login an address had, without
  attaching anything and without the person being emailed.
- **An administrator's address stays refused at both doors.** One login has one global
  set of permissions; an administrator made a teacher elsewhere would carry them into
  the classes.

### Before the release (read-only, on production)

```sql
-- 1. The rows the migration would rewrite. Expected: none. Anything here is read by a person
--    before anything is deployed: it is a row somebody wrote by hand.
SELECT mu.id, mu.masjid_id, mu.user_id, mu.role, u.type
  FROM masjid_user mu JOIN users u ON u.id = mu.user_id
 WHERE (u.type = 'Teacher' AND mu.role = 'lunch-staff')
    OR (u.type = 'LunchStaff' AND mu.role = 'teacher');

-- 2. The whole vocabulary of the column, and who holds a scoped role without being a scoped
--    login (left alone by the migration and never re-read by the code, but worth knowing).
SELECT role, COUNT(*) FROM masjid_user GROUP BY role;
SELECT mu.id, mu.masjid_id, mu.user_id, mu.role, u.type
  FROM masjid_user mu JOIN users u ON u.id = mu.user_id
 WHERE mu.role IN ('teacher', 'lunch-staff') AND u.type NOT IN ('Teacher', 'LunchStaff');
```

Also read `config('tenancy.multi_membership')` there, and the rows of the person the
first attach is for (their `users.type`, and their row at the organisation they already
belong to).

**If query 1 returns a row, do not deploy until a person has read it.** The migration
below cannot tell a row somebody wrote by hand from a role an office gave on purpose
(they are the same two columns), so when it finds one it STOPS with the ids and writes
nothing, and `bin/deploy` stops with it: the new code is in place, the caches are still
the old ones, and any later migration in the same deploy has not run. On the first
release every such row is hand-written (no office could give a second role before this
code existed), so the answer is `rewrite`, given for that one run and nowhere else:

```sh
# in the application directory on the box, as bin/deploy runs artisan
sudo -u www-data HOME=/tmp NORMALISE_SCOPED_ROLES=rewrite php artisan migrate --force
sudo bin/deploy     # again: nothing left to migrate, and it rebuilds the caches
```

The migration arrives with the deploy, so it cannot be answered beforehand. Read query 1
immediately before deploying, and have the line ready if it was not empty.

### The release

1. **Deploy with the switch off.** `migrate --force` runs one data migration,
   `normalise_scoped_roles_on_masjid_user`. With no row of query 1 (the expected case) it
   makes one read, writes nothing and logs "0 rows" (`storage/logs`,
   "normalise_scoped_roles"). With such a row it stops and asks, as above:
   `NORMALISE_SCOPED_ROLES=rewrite` rewrites each to the role of its login's kind (so it
   goes on meaning what it meant), `NORMALISE_SCOPED_ROLES=keep` leaves them and
   finishes. It does nothing at all if the switch is already on. No route is added, no
   token changes; sessions live at the release are untouched.
2. **Staging first, switch on there:** set the switch AFTER the deploy (not before: the
   migration skips itself when it finds the switch on). Walk it in a browser: a login with
   both roles in both areas, the switch between them, a removal on each side, and a
   one-role teacher and a one-role lunch login.
3. **Before the switch goes on in production**, two things that are not the deploy's:
   - **The owner's word on what an attaching office is shown.** After an attach, the
     attaching office's next list shows the person's STORED name and phone (the lunch
     list, the Teachers list, Team & Access) and `invited: false`, so it learns the
     address already had a login and reads a phone it did not type. Assumed acceptable
     from the 2026-09-29 decision that every school that has a teacher may see their
     phone; that decision was about schools sharing a teacher, and this widens it to any
     organisation with Friday lunch and any teacher's address, and to any school and any
     lunch volunteer's. The person is emailed that they were added; that is the only
     check. If the answer is no, the change is in the three list payloads (no phone for
     a person the office attached), not in the doors. `ASSUMPTIONS.md` has it.
   - **The suite, run with the switch on** (see "Test posture for this switch" below).
     From the day the switch is on, that run is the one that describes production.
4. **Switch on in production** with the inode-preserving editor, on the owner's yes:
   `set-env.sh <ssh-host> <http-host> TENANCY_ROLES_PER_ORGANISATION true`.
   Never before the deploy of step 1 is live there: it carries the Vue app's half. With
   the server on and the previous bundle, a lunch login that an office makes a teacher is
   answered by `/api/teacher/user` first on every load and can no longer reach her lunch
   board. (A tab opened before the deploy still runs the previous bundle until it is
   reloaded.)
5. The first real use is an office act: the school adds the person's address on its
   Teachers screen with their class.

### Going back

- **The safe lever: turn the switch off** (and re-cache config). Every login with both
  roles keeps the area of the kind it was created as and loses the other (401 at that
  gate). No row is deleted; turning it on again restores them. A login whose only rows are
  of the other kind cannot sign in while it is off.
- **Do NOT roll the CODE back while a cross-kind row exists.** Code from before this
  release reads only `users.type`: a lunch login's `teacher` row at a school is, to it, a
  live row of a lunch login, so it would bind the LUNCH area at that school (its guides at
  once; its board if that school has Friday lunch). The mirror is as true: a teacher's
  `lunch-staff` row at a masjid would bind the TEACHER area there. `php artisan
  staff:cross-kind-roles` answers whether any exists (exit 0: none, 3: some) and lists
  them by id.
- If the code must go back with such rows present, the Teachers screen is not the way to
  remove them: a teacher on timetable meetings cannot be removed there, and Library is a
  timetable assignment. Do it by query, keeping the export:

  ```sh
  php artisan staff:cross-kind-roles --json > /root/cross-kind-rows-$(date -u +%F).json
  ```

  ```sql
  DELETE mu FROM masjid_user mu JOIN users u ON u.id = mu.user_id
   WHERE (u.type = 'LunchStaff' AND mu.role = 'teacher')
      OR (u.type = 'Teacher' AND mu.role = 'lunch-staff');
  ```

  Timetable meetings and class assignments reference the person, not the membership row,
  so they stay. To the older code that person is then not a teacher of the school: the
  timetable's teacher list will not offer them and switching the timetable off and on
  would end their meetings. Then roll back. On the way forward again, re-insert the
  exported rows (`masjid_id`, `user_id`, `role`, `is_default`, timestamps) only AFTER the
  forward deploy's `migrate` has run, and before turning the switch on. (Put back before
  it, they are rows the migration finds with the switch off: it would stop the deploy.)
  Check that nobody is left with rows and no default.
- **If the migration meets such rows again** (it was rolled back and re-run, on staging
  or by `migrate:refresh`, with the switch off): it stops with their ids and writes
  nothing. It used to rewrite them, which turned each role an office had given back into
  its login's own kind: a lunch login's teacher row became a lunch row AT THE SCHOOL.
  Once any office has given a second role the answer is `NORMALISE_SCOPED_ROLES=keep`,
  never `rewrite`.

### What a hand edit must not forget

For a teacher's or lunch-staff login the row now decides. After changing a `users.type`
in tinker, look at that login's `masjid_user` rows as well, and run
`php artisan staff:cross-kind-roles`.

**Never set `users.type` to `MasjidAdmin` on a login that holds a row anywhere it should
not administer.** An administrator binds through ANY live row, whatever its role says
(an administrator's rows are never re-read): a login with a lunch row at a masjid and a
teacher row at a school, re-typed by hand, is a full administrator of BOTH, the school
included, which only ever made it a teacher. This was already true of a teacher at two
schools; it now also reaches a lunch login. No screen does it: Change access on Team &
Access is the one door that re-types, it refuses a login that belongs to another
organisation, and it decides under the lock the attach doors take. Nothing in code stops
a hand edit. Before one, list the rows that would become an administrator's:

```sql
SELECT mu.masjid_id, mu.role FROM masjid_user mu WHERE mu.user_id = <the login's id>;
```

and afterwards query 2 above must not list that login (an administrator holding a
`teacher` or `lunch-staff` row).

### Test posture for this switch

The suite is run both ways, in CI on every push and pull request
(`.github/workflows/tests.yml`, jobs `suite` and `suite-roles-on`):

```sh
php -d memory_limit=-1 vendor/bin/pest                                      # switch off: how it ships
TENANCY_ROLES_PER_ORGANISATION=true php -d memory_limit=-1 vendor/bin/pest --log-junit junit-roles-on.xml
php scripts/junit-expected-failures.php junit-roles-on.xml tests/fixtures/roles-switch-on-expected-failures.txt
```

The second run is NOT expected to be green. Four existing tests pin the switched-off
behaviour without setting the switch and are deliberately left unedited (they are the
proof that a school and a login the feature does not touch run what they always ran):
the add, the edit and the removal of a teacher each run one more statement with the
switch on, and the Teachers door attaches a lunch login instead of refusing it. The
third line passes only when exactly those four fail; the list says why for each. Every
new test of this feature sets the switch itself and passes in both runs.

---

## Turning it on

1. **Ship the code with the flag still false.** The deploy itself changes no
   behaviour; that is the point of shipping it separately from flipping it.

   ```sh
   scripts/ship.sh staging feat/multi-org-admin    # then verify
   echo "ship production" | scripts/ship.sh production
   ```

2. **Flip the flag** with the inode-preserving editor — never `mv` a candidate over
   `.env`, and never edit it by hand (see `env-edit-ownership-trap`: a root-owned
   `.env` makes `config:cache` fail, and it clears the cache before it writes, so the
   site 500s with a message that blames `APP_KEY`).

   ```sh
   set-env.sh <ssh-host> <http-host> TENANCY_MULTI_MEMBERSHIP true
   ```

   The script parses the candidate before installing it, writes through the existing
   inode, re-caches config as `www-data`, fetches `/auth/sign-in`, and rolls back
   automatically on anything but a 200.

3. **Grant the second and third organisations**, SuperAdmin only:

   ```
   POST   /api/admin/admins/masjid/{masjid_id}/memberships   {"user_id": N}
   DELETE /api/admin/admins/masjid/{masjid_id}/memberships/{user_id}
   ```

   Exactly one row per admin may carry `is_default`; the database enforces it.

---

## What to check after the flip

Checked on staging 2026-09-15 with a real two-organisation admin (MEC + IntelliCor),
driving the deployed SPA rather than a test:

| What | Expected |
|---|---|
| `GET /api/admin/user` | `memberships[]` lists both organisations, each with its `masjid` block |
| Header on every admin response | `X-Tenant-Id: <id>`, or the literal `unbound` |
| `/api/admin/masjids/{granted}/details` | 200, `X-Tenant-Id` = that id, that organisation's data |
| `/api/admin/masjids/{not granted}/details` | 403, `X-Tenant-Id: unbound` |
| The org switcher | lists both, ticks the current one, and swaps logo, name, menu and data |
| Reload after switching | **stays** on the switched organisation |
| Sign out, sign in as somebody else | no rows from the previous administrator |
| Mosque Settings → Prayer Calculation | dropdowns populated (12 methods, 2 madhabs, 3 rules) |

That last row is there because it is the one thing this feature broke that nothing
caught: `GET /api/admin/masjids/prayer-calculation/options` names no masjid, so the
several-grants branch refused it, and the tab rendered with three empty selects and no
error. It is allowlisted now. A `route:list` sweep says it was the only reachable case
— the other 48 unscoped admin routes are `super`-gated, and a SuperAdmin never reaches
that branch.

---

## Test posture

The suite is run **both ways** before any flip:

```sh
php artisan test                               # gate shut — production's configuration
TENANCY_MULTI_MEMBERSHIP=true php artisan test # gate open — what the flip will produce
```

Tests that assert gate-shut behaviour set `tenancy.multi_membership => false`
themselves rather than inheriting it, so the gate-open run is meaningful instead of
producing six failures that are not defects.

Results on 2026-09-15 (`/root/ci-multiorg`, sqlite in memory):

- gate shut: 3656 passed, 1 skipped, 0 failed
- gate open: recorded in `LOG.md` alongside the deploy
