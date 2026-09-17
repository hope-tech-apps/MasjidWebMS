<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The hostnames this deployment answers to
    |--------------------------------------------------------------------------
    |
    | nginx on this box is `default_server` on :80 and :443, so EVERY Host
    | header reaches the application — including one an attacker chose. Laravel
    | builds `url()`, `asset()` and `route()` from that header, so a forged Host
    | produces our pages carrying somebody else's links. See
    | App\Http\Middleware\TrustedHosts for what is done about it and
    | App\Support\SiteUrl for the payloads that must not depend on it at all.
    |
    | The effective list is ASSEMBLED IN THE MIDDLEWARE, not here, so that it
    | cannot drift from the configuration the rest of the app already reads —
    | and so that it never depends on the order config files happen to load in
    | (`config('portal.hosts')` read from inside a config file only resolves
    | because `portal` sorts before `trusted_hosts`, which is not a property to
    | build on). It is:
    |
    |   - the host of APP_URL                        (always)
    |   - every key of config('portal.hosts')        (PORTAL_HOSTS — the
    |     organisation domains pointed at this app)
    |   - anything in TRUSTED_HOSTS                  (comma-separated, for hosts
    |     that are neither of the above: manara.hopetechapps.com is served off
    |     the default vhost and appears in no other setting)
    |
    | Only the third of those lives in this file; the first two are read where
    | they are already defined.
    |
    | Hosts are compared literally, lower-cased, with any port stripped. No
    | wildcards and no regular expressions: Laravel's own TrustHosts takes
    | patterns, and `^(.+\.)?example\.com$` admitting every subdomain that ever
    | exists is a larger promise than this application needs to make.
    |
    */

    'extra' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_HOSTS', ''))
    ), static fn (string $host): bool => $host !== '')),

    /*
    |--------------------------------------------------------------------------
    | Enforce, or only report
    |--------------------------------------------------------------------------
    |
    | Default FALSE, deliberately, and this is the one setting here worth an
    | argument.
    |
    | A middleware that rejects unknown Hosts is a middleware that can take the
    | whole site down the moment the list is incomplete — and the list cannot be
    | fully known from the code. Health checks, uptime monitors and anything that
    | reaches the origin by IP rather than by name all send a Host nobody wrote
    | down. This project has already had one outage from a configuration change
    | that looked obviously correct (a bad .env plus `config:cache`, every
    | request 500) and the lesson taken was to make the dangerous half opt-in.
    |
    | So the middleware ships OBSERVING: it logs each unknown Host at `warning`
    | (production's LOG_LEVEL, so the line is actually kept) and passes the
    | request through. Read the log, confirm it names nothing legitimate, then
    | set TRUSTED_HOSTS_ENFORCE=true.
    |
    | Reporting mode is not a fig leaf: the payload defect this closes is fixed
    | by SiteUrl regardless of this switch. Enforcement is the second layer —
    | it takes away the forged-Host page as well as the forged-Host payload.
    |
    */

    'enforce' => (bool) env('TRUSTED_HOSTS_ENFORCE', false),

    /*
    | How often the same unknown Host is logged, in seconds. Without this a
    | scanner walking the origin writes a log line per request; production
    | already sees unsolicited probe traffic on this IP.
    */

    'log_interval' => (int) env('TRUSTED_HOSTS_LOG_INTERVAL', 3600),

    /*
    | How many DIFFERENT unknown hosts are logged per interval, in total. The
    | Host is the caller's choice, so without a ceiling a client inventing a
    | new name per request writes a log line and a cache row per request.
    | Past the ceiling one "Unknown-Host logging paused" line is written and
    | nothing more until the interval ends. Production's nginx error log named
    | 49 distinct hosts (port and trailing dot removed) in the 14 days to
    | 2026-09-17, so 200 new names an hour is far above normal traffic.
    */

    'log_budget' => (int) env('TRUSTED_HOSTS_LOG_BUDGET', 200),

];
