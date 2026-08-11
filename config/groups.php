<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Group feed (the "class story")
    |--------------------------------------------------------------------------
    |
    | A group's activity feed is PRIVATE to that group: its leaders, its members,
    | and the guardians of its participants who have consented. It is never a
    | public surface, and it is never visible to the whole tenant merely because
    | somebody is a Contact — see .claude/rules/groups.md.
    |
    */

    'feed' => [

        /*
         * Retention window, in days, applied to a post that does not carry an
         * explicit `retained_until`. These posts are about children, so keeping
         * them forever by default is the wrong default: the model stamps
         * `retained_until = now + this` on create, and `groups:purge-feed`
         * removes the row AND the bytes once it passes.
         *
         * Set to 0 (or a negative number) to keep posts indefinitely unless a
         * caller sets `retained_until` itself.
         */
        'retention_days' => (int) env('GROUP_FEED_RETENTION_DAYS', 365),

        /*
         * Ceiling on the body of one post, enforced at the request boundary. A
         * feed entry is a short note about a lesson, not a document store.
         */
        'max_body_length' => (int) env('GROUP_FEED_MAX_BODY_LENGTH', 5000),

    ],

    /*
    |--------------------------------------------------------------------------
    | Group media
    |--------------------------------------------------------------------------
    |
    | Photographs of minors. Everything here follows .claude/rules/private-uploads.md
    | exactly; spatie/laravel-medialibrary and the public gallery disk are for
    | PUBLIC images and must never be used for anything a group produces.
    |
    */

    'media' => [

        /*
         * `local` is storage/app/private and has no public URL. It is
         * deliberately NOT `public`, which is symlinked into the web root — a
         * classroom photo there would be readable by anyone who guessed the
         * path. The ONLY way back out is the authenticated, chain-resolved
         * download endpoint on GroupPostsController.
         *
         * If you repoint this, it must stay a disk with no public URL.
         */
        'disk' => env('GROUP_MEDIA_DISK', 'local'),

        /*
         * Root directory on that disk. A per-masjid / per-group subtree hangs
         * off it and the stored filename is random — the uploader's filename is
         * kept in the database, never in the path, so a photo can neither
         * collide nor be guessed.
         */
        'directory' => env('GROUP_MEDIA_DIRECTORY', 'group-media'),

        /*
         * Allowed types, matched by Laravel's `mimetypes` rule against the type
         * SNIFFED from the file's own bytes — not the extension, not the
         * Content-Type header the client claims.
         *
         * Images only, and deliberately narrow. SVG is absent on purpose: it is
         * a script-bearing document, not a picture. PDFs and documents are
         * absent because a feed post is a photo of an activity, not a filing
         * cabinet — a document about a child belongs on a form response, which
         * already has its own private pipeline.
         */
        'mime_types' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'GROUP_MEDIA_MIME_TYPES',
            'image/jpeg,image/png,image/webp'
        ))))),

        /*
         * Ceiling per image, in kilobytes. A phone photo straight out of the
         * camera is comfortably under 8MB, and this is low enough that PHP
         * holding a handful at once cannot push the 2GB production droplet into
         * swap. PHP's own upload_max_filesize / post_max_size still apply and
         * are usually lower on a stock install.
         */
        'max_size_kb' => (int) env('GROUP_MEDIA_MAX_SIZE_KB', 8192),

        /*
         * How many images one post may carry. A bound exists so a single
         * request cannot be used to fill the droplet's disk.
         */
        'max_per_post' => (int) env('GROUP_MEDIA_MAX_PER_POST', 8),

    ],

];
