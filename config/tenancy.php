<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-membership tenant resolution  (S5 lever — it must stay FALSE)
    |--------------------------------------------------------------------------
    |
    | S3 of docs/multi-tenant-admin-design.md replaced the tenant resolver: the
    | binding is now derived from a VERIFIED `masjid_user` membership instead of
    | a scalar id (App\Support\TenantResolver, App\Support\TenantContext::
    | setFromMembership). That resolver can express something production is not
    | ready for — one human administering SEVERAL organisations — and switching
    | between them needs the API surface (S4) and the SPA switcher, the request
    | epoch and the store resets (S5) to exist first. Until those land, a second
    | membership would be a tenant a user can hold but never safely leave.
    |
    | So S3 ships behind this flag, DEFAULT FALSE, and the design calls that the
    | "exactly one membership" path:
    |
    |   FALSE (production, today) — a user's grants are exactly the ONE
    |     organisation they own (`masjids.user_id`, unique among live rows since
    |     S0, which S2's backfill wrote into `masjid_user` as their single
    |     default membership). Any further membership row is INERT: it cannot be
    |     bound, and naming its masjid in the URL is the same 403 it was before
    |     this slice. Behaviour is byte-for-byte what it was.
    |
    |   TRUE (S5) — a user's grants become every membership naming a live
    |     masjid. Nothing else about the resolver changes; this flag is the
    |     whole of the gate, and TenantResolver::grantsFor() is the only place
    |     it is read.
    |
    | Do NOT flip this on its own. Turning it true before S4 writes a membership
    | row on every provisioning path would ALSO drop the ownership fallback that
    | keeps admins working in environments the S2 backfill has not reached — see
    | TenantResolver::soleOwnedMembership().
    |
    */

    'multi_membership' => env('TENANCY_MULTI_MEMBERSHIP', false),

    /*
    |--------------------------------------------------------------------------
    | One login, a different role at each organisation
    |--------------------------------------------------------------------------
    |
    | One person, with one address and one password, can run Friday lunch at one
    | organisation and teach at another. For the two scoped kinds (Teacher and
    | LunchStaff) the `masjid_user` row says what the login is AT THAT
    | organisation (App\Models\MasjidUser::kindFor), whatever `users.type` says.
    |
    |   FALSE (the shipped default) - no login enters an area outside its own
    |     kind, the Teachers and lunch-staff doors refuse an address that has a
    |     login of the other kind with the sentences they always had, sign-in
    |     never lands outside the login's own kind, and no payload carries
    |     `areas`.
    |
    |   TRUE - a lunch-staff login may be given the teacher role at a school (and
    |     a teacher lunch access at one organisation) through those two doors,
    |     and then reaches both areas with one sign-in.
    |
    | Read in ONE place at run time, App\Support\StaffArea::open(). It does NOT
    | switch off the part that only ever removes access: the tenant resolver binds
    | an organisation only through a row of the kind the request is acting as, in
    | both states.
    |
    | Turning it off again is the safe way back: a login with both roles keeps
    | its own kind's area and loses the other, and no row is deleted. Do NOT roll
    | the code back while a cross-kind row exists (`php artisan
    | staff:cross-kind-roles` lists them): code from before this switch reads
    | only the kind, so a lunch login's teacher row would open the LUNCH area at
    | the school. docs/multi-org-admin-rollout.md has the order of steps.
    |
    | The cross-kind attach of a person who already belongs somewhere also needs
    | `multi_membership` above, exactly as attaching a teacher to a second school
    | does.
    |
    */

    'roles_per_organisation' => (bool) env('TENANCY_ROLES_PER_ORGANISATION', false),

];
