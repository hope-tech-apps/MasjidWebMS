<?php

/*
 * CORS config — drives the Fruitcake HandleCors middleware Laravel applies
 * automatically to all api/* routes.
 *
 * - 'paths' is scoped to API routes only. Web/admin views render same-origin so
 *   they don't need CORS at all.
 * - 'allowed_origins' defaults to '*' for development convenience. In production
 *   set CORS_ALLOWED_ORIGINS=https://burlington-masjid.example.com to lock it down.
 *   '*' is acceptable for our anonymous public endpoints (mobile, v1) because they
 *   don't accept credentials or carry sensitive cookies; for admin endpoints behind
 *   Sanctum, the SPA is served same-origin via Blade so CORS doesn't apply.
 * - 'allowed_origins' is the BASE list. App\Http\Middleware\HandleCorsWithDomains
 *   (Studio W1, S9) adds the origin of every masjid_domains row confirmed serving
 *   our own site, for the CORS decision of a request whose Origin this list does
 *   not already name, so a new client host needs no .env edit. It never removes
 *   an origin, and a '*' list is left alone.
 */

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        explode(',', env('CORS_ALLOWED_ORIGINS', '*'))
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
