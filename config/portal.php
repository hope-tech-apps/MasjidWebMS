<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Organisation portal hostnames
    |--------------------------------------------------------------------------
    |
    | Hostnames an organisation has pointed at this application so the portal
    | runs on THEIR domain rather than ours, mapped to the organisation each one
    | belongs to.
    |
    | This exists because `/portal` carries no id. The id-bearing `/portal/14`
    | is what this app's own domain serves; the id-less form is what a school's
    | own hostname serves, and something has to say which school that is. Until
    | now the only answer was `window.__PORTAL_MASJID__`, injected by the
    | Cloudflare Worker that proxied alrazischool.org/portal — which works only
    | for the proxied arrangement. A hostname pointed straight at this app has
    | no proxy to inject anything, so the app has to know the mapping itself.
    |
    | Kept in env rather than in a database column so that pointing a new
    | hostname here is a configuration change and not a migration, and so that
    | the set of domains permitted to present themselves as an organisation is
    | reviewable in one place. An unlisted host simply gets the ordinary app.
    |
    |   PORTAL_HOSTS=portal.alrazischool.org=14,portal.example.org=22
    |
    */

    'hosts' => collect(explode(',', (string) env('PORTAL_HOSTS', '')))
        ->map(fn (string $pair): array => array_map('trim', explode('=', $pair, 2)))
        ->filter(fn (array $parts): bool => count($parts) === 2
            && $parts[0] !== ''
            && ctype_digit($parts[1]))
        ->mapWithKeys(fn (array $parts): array => [strtolower($parts[0]) => (int) $parts[1]])
        ->all(),

];
