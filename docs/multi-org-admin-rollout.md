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
