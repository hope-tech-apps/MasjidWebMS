<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sign-up form attachments
    |--------------------------------------------------------------------------
    |
    | A `file` field on a form — a Manara Schools careers form asking for a
    | résumé, a membership form asking for a document — uploads through the same
    | PUBLIC, unauthenticated submit endpoint every other answer goes through.
    | These settings are therefore the first thing an anonymous caller meets, and
    | they are enforced at the request boundary (SubmitFormResponseRequest), not
    | somewhere downstream.
    |
    */

    'attachments' => [

        /*
         * `local` is storage/app/private, and it is deliberately NOT the
         * web-exposed `public` disk: a résumé must not be readable by anyone who
         * guesses a /storage URL. Uploads are handed back only through the
         * authenticated, tenant-checked download endpoint on
         * FormResponsesController — the same arrangement FlyerCutoutController
         * already uses for a janazah photo (see config/flyer.php).
         *
         * If you point this somewhere else, it must stay a disk with no public
         * URL. Anything reachable without auth defeats the whole feature.
         */
        'disk' => env('FORM_ATTACHMENT_DISK', 'local'),

        /*
         * Root directory on that disk. A per-masjid / per-form subtree hangs off
         * it, and the stored filename is random — the original name is kept in
         * the database, never in the path, so a filename can neither collide nor
         * be guessed.
         */
        'directory' => env('FORM_ATTACHMENT_DIRECTORY', 'form-attachments'),

        /*
         * Allowed types, matched by Laravel's `mimetypes` rule against the type
         * SNIFFED from the file's own bytes — not against the extension or the
         * Content-Type header the client claims. Renaming shell.php to
         * resume.pdf does not get past this.
         *
         * Deliberately narrow: documents an office actually reads, plus the two
         * lossless-enough image formats a scanned document arrives as. SVG is
         * absent on purpose — it is a script-bearing document, not a picture.
         *
         * Comma-separated in .env so a tenant-specific need can be met without a
         * deploy.
         */
        'mime_types' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'FORM_ATTACHMENT_MIME_TYPES',
            'application/pdf,'
            . 'application/msword,'
            . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
            . 'image/jpeg,'
            . 'image/png'
        ))))),

        /*
         * Ceiling per file, in kilobytes. 8MB is a generous résumé and a scanned
         * multi-page PDF, and low enough that PHP holding a handful of them at
         * once cannot push the 2GB production droplet into swap.
         *
         * PHP's own `upload_max_filesize` / `post_max_size` still apply and are
         * usually LOWER than this on a stock install — raising this number
         * without raising those does nothing.
         */
        'max_size_kb' => (int) env('FORM_ATTACHMENT_MAX_SIZE_KB', 8192),

    ],

];
