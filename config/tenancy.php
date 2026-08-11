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

];
