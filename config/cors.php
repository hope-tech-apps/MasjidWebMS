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
