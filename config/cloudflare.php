<?php

/*
|--------------------------------------------------------------------------
| Cloudflare, as Manara Studio uses it (docs/manara-studio-w1.md, S3)
|--------------------------------------------------------------------------
|
| Every organisation's website is one Cloudflare Pages project, the renderer,
| answering on many hosts. Studio records those hosts in `masjid_domains`, and
| from S7 attaches new ones to the project through the API. S3 reads only the
| names and limits below and makes no Cloudflare call at all.
|
| Only the token is a secret. The ids are not, and they default to production's
| account so a blank value can never point Studio at some other account: the
| `?:` below treats an empty `KEY=` line (which is what the staging deny-list
| leaves behind for every CLOUDFLARE_* key) as absent rather than as "".
|
| STAGING NEVER HOLDS THE TOKEN. There is one Pages project and it is
| production's, so a staging box with a working token would attach and detach
| domains on the live renderer. deploy/staging/provision.sh blanks every
| CLOUDFLARE_* key for that reason.
|
*/

return [

    // Account API token with Pages and DNS edit on the account's zones. Blank
    // means Studio cannot talk to Cloudflare: it says so and hands the operator
    // the manual steps instead (MasjidDomain::manualSteps()).
    'studio_token' => env('CLOUDFLARE_STUDIO_TOKEN') ?: null,

    'account_id' => env('CLOUDFLARE_ACCOUNT_ID') ?: '86cec9c5e0efe76fedb5698a2be91beb',

    // The renderer's production Pages project and the name its custom domains
    // are CNAMEd to.
    'pages_project' => env('CLOUDFLARE_PAGES_PROJECT') ?: 'manara-renderer',
    'pages_target' => env('CLOUDFLARE_PAGES_TARGET') ?: 'manara-renderer.pages.dev',

    // The zone managed subdomains live in, and the suffix they are given:
    // `<slug>.manara.hopetechapps.com`. The zone id is the one
    // deploy/staging/cloudflare-dns.sh already uses for this zone.
    'managed_zone' => env('CLOUDFLARE_MANAGED_ZONE') ?: 'hopetechapps.com',
    'managed_zone_id' => env('CLOUDFLARE_MANAGED_ZONE_ID') ?: '859eddb9bce48f4f35e6197f6c0b8e15',
    'managed_suffix' => env('CLOUDFLARE_MANAGED_SUFFIX') ?: 'manara.hopetechapps.com',

    // Labels no organisation may take as its managed subdomain: our own
    // infrastructure names, and the two live tenants whose labels predate
    // Studio (mec, alrazi) so a new org cannot be handed a look-alike claim.
    'reserved_labels' => ['www', 'api', 'admin', 'app', 'staging', 'portal', 'mail', 'manara', 'mec', 'alrazi'],

    // Custom domains one Pages project may carry, as the plan records it
    // (docs/manara-studio-w1.md, S3). Studio reports how close the project is
    // and refuses to attach past it.
    'pages_domain_ceiling' => 100,

    'api_base' => 'https://api.cloudflare.com/client/v4',

    // Seconds, for every Cloudflare API call.
    'timeout' => 15,

];
