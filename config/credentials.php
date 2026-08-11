<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Volunteer credential documents (T-023, Community vertical)
    |--------------------------------------------------------------------------
    |
    | A credential on a Contact — a medical license, a background check, a BLS
    | card — may carry one scanned document. Unlike form attachments these only
    | ever arrive through the AUTHENTICATED admin endpoints, but the ceiling and
    | the allowlist still live here rather than in any tenant-editable place: an
    | admin's trust level does not widen what this server accepts. Mirrors
    | config/forms.php.
    |
    */

    'document' => [

        /*
         * `local` is storage/app/private, deliberately NOT the web-exposed
         * `public` disk: a scanned medical license must not be readable by
         * anyone who guesses a /storage URL. The bytes are handed back only
         * through ContactCredentialsController::downloadDocument, behind
         * auth:sanctum + admin + tenant + the crm gate. If you point this
         * somewhere else, it must stay a disk with no public URL.
         */
        'disk' => env('CREDENTIAL_DOCUMENT_DISK', 'local'),

        /*
         * Root directory on that disk. A per-masjid / per-contact subtree hangs
         * off it, and the stored filename is random — the original name is kept
         * in the database, never in the path, so a filename can neither collide
         * nor be guessed.
         */
        'directory' => env('CREDENTIAL_DOCUMENT_DIRECTORY', 'credential-documents'),

        /*
         * Allowed types, matched by the `mimetypes` rule against the type
         * SNIFFED from the file's own bytes — never the extension or the
         * Content-Type header the client claims. Narrow on purpose: a license
         * arrives as a PDF or a photo/scan. SVG is absent because it is a
         * script-bearing document, not a picture. Comma-separated in .env so a
         * tenant-specific need can be met without a deploy.
         */
        'mime_types' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'CREDENTIAL_DOCUMENT_MIME_TYPES',
            'application/pdf,'
            . 'image/jpeg,'
            . 'image/png'
        ))))),

        /*
         * Ceiling per file, in kilobytes. Same 8MB reasoning as form
         * attachments: a generous multi-page scan, low enough that PHP holding
         * a handful at once cannot push the 2GB droplet into swap. PHP's own
         * upload_max_filesize / post_max_size still apply underneath.
         */
        'max_size_kb' => (int) env('CREDENTIAL_DOCUMENT_MAX_SIZE_KB', 8192),

    ],

    /*
     * How many days before expiry a credential counts as "expiring" — the
     * default window for the derived status accessor and for the index filter
     * when the caller names no window. 30 days matches how clinics actually
     * chase renewals (a license renewal takes weeks, not days).
     */
    'expiring_within_days' => (int) env('CREDENTIAL_EXPIRING_WITHIN_DAYS', 30),

];
