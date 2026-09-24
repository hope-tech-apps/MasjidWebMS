<?php

/*
|--------------------------------------------------------------------------
| Manara Studio
|--------------------------------------------------------------------------
|
| The SuperAdmin flow that takes a client from a name to a live organisation
| (docs/manara-studio.md). Until Step 3 provisions it, everything Studio knows
| about a client lives in one `studio_drafts` row plus, optionally, its logo.
|
*/

return [

    'logo' => [

        /*
         * `local` is storage/app/private. A draft's logo is not yet anybody's
         * public brand — the client may never sign — so it is kept the way the
         * other private uploads are (.claude/rules/private-uploads.md) and served
         * only through GET /api/admin/studio/drafts/{id}/logo. It is NOT put in
         * medialibrary: that is the public-image mechanism, and Masjid's
         * header_logo()/footer_logo() read the media table with no model_type
         * filter, so a draft's row there could surface as a live org's logo.
         *
         * Whatever this points at must stay a disk with no public URL.
         */
        'disk' => env('STUDIO_LOGO_DISK', 'local'),

        /* One subdirectory per draft; the stored filename is random. */
        'directory' => 'studio-drafts',

        /*
         * Matched by `mimetypes` against the type sniffed from the bytes. PNG and
         * JPEG only: GD cannot rasterise SVG into the favicon and share image
         * Step 3 derives, and SVG is script-bearing, which the private-uploads
         * rule refuses.
         */
        'mime_types' => 'image/png,image/jpeg',

        'max_kb' => 8192,

        /* Smallest edge that still yields a legible 48px favicon when contained. */
        'min_px' => 96,
    ],

    'drafts' => [

        /*
         * Ceiling on the raw `answers` a single PATCH may carry. Every section
         * together is a few kilobytes of identity, brand and switches; a quarter
         * megabyte is room for the longest about/mission/vision text with a wide
         * margin, and small enough that a runaway client cannot fill the row.
         */
        'max_answers_bytes' => 262144,

        /*
         * An untouched draft is deleted (with its logo) after this many days by
         * `studio:purge-drafts`. Drafts hold the client admin's name, email and
         * phone, and the private disk is not backed up, so an abandoned one must
         * not linger for ever.
         */
        'retention_days' => 90,
    ],

];
