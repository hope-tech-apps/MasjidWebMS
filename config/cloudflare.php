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
    // `preview` is the live-preview host (preview.manara.hopetechapps.com,
    // owner decision 2026-09-24): it serves no tenant, and an org holding that
    // label would turn every editor's preview pane into its site.
    'reserved_labels' => ['www', 'api', 'admin', 'app', 'staging', 'portal', 'mail', 'manara', 'mec', 'alrazi', 'preview'],

    // Custom domains one Pages project may carry: 100 on Free, 250 on Pro, 500
    // on Business (developers.cloudflare.com/pages/platform/limits, "Last
    // updated Sep 5, 2026"). Studio reports how close the project is and
    // refuses to attach past it. A plan upgrade is an env change, not a code
    // one (W2 S1). Anything that is not a whole number above zero (a blank
    // line, which the staging deny-list leaves for every CLOUDFLARE_* key, or
    // a typo) reads as the Free plan's 100: a ceiling of 0 would refuse every
    // attach, and a guess above the real one would let a POST discover it.
    'pages_domain_ceiling' => filter_var(
        env('CLOUDFLARE_PAGES_DOMAIN_CEILING'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]],
    ) ?: 100,

    // Percentages of the ceiling at which `domains:capacity` emails the owner,
    // once each (owner, 2026-09-24: "keep me up to date"; W2 §8 OQ7).
    'pages_domain_notice_at' => [50, 70, 85, 95],

    // Apex↔www canonical redirects (W2 S5). Studio writes a redirect rule, or
    // the placeholder record a redirect host needs, ONLY in a zone it created
    // itself (`cf_zone_created` on a row of that zone) or in a zone listed
    // here. An allowlist, not a denylist, and it ships EMPTY: every zone that
    // was in the account before S5 (burlingtonmasjid.com, alrazischool.org,
    // the owner's product zones) is refused unless the owner adds it.
    //
    // From the environment, never from this file: this repository is public,
    // and a client's zone listed here would be published with it. A
    // comma-separated list of apexes in CLOUDFLARE_REDIRECT_ZONES, e.g.
    // "test-zone.example,other.example"; case and spaces are ignored, and
    // blank (what the staging deny-list leaves) is none.
    'redirect_zones' => array_values(array_unique(array_filter(array_map(
        fn (string $zone) => strtolower(trim($zone)),
        explode(',', (string) env('CLOUDFLARE_REDIRECT_ZONES', '')),
    )))),

    // What a redirect host's DNS record points at: the IPv4 documentation
    // address (RFC 5737) that Cloudflare's own redirect examples use. The
    // record exists only so the host is proxied; the rule answers before any
    // request could reach the address.
    'redirect_placeholder_address' => '192.0.2.1',

    // Re-confirming hosts already seen serving (W2 S4). Each confirmed host is
    // probed at most once every `every_hours`: one GET of its own
    // /api/tenant, a no-store renderer route, so a day's cost is one request
    // per host. A Studio host loses its CORS and card-payment-return admission
    // only after `demote_after_misses` misses in a row spanning at least
    // `demote_after_hours` (owner, 2026-09-24: "three misses over at least 72
    // hours"; W2 §8 OQ6). With daily probes that is the fourth miss, three
    // days after the first: one blip, a Cloudflare outage or a 5-second
    // timeout never withdraws it (domains recon R3). An imported or adopted
    // host is never demoted; the owner is emailed at the third miss instead.
    // `per_run` bounds the re-probes one five-minute reconcile run sends (review
    // follow-up 9): the first run after deploy finds every confirmed host due,
    // and twenty a run spreads a hundred hosts over twenty-five minutes. Each
    // host's next re-probe also lands up to an hour later than the day, by a
    // fixed offset of its own, so they stay spread.
    'reconfirm' => [
        'every_hours' => 24,
        'demote_after_misses' => 3,
        'demote_after_hours' => 72,
        'per_run' => 20,
    ],

    'api_base' => 'https://api.cloudflare.com/client/v4',

    // Seconds, for every Cloudflare API call.
    'timeout' => 15,

];
