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
    | Group messaging threads (T-005c)
    |--------------------------------------------------------------------------
    |
    | The teacher <-> parent channel: group-wide announcement discussions and
    | participant-scoped private conversations about one member. Like the feed,
    | never a public surface — who may read a thread is decided per request by
    | App\Support\GroupAudience. Text only: attachments are deliberately out of
    | this slice (the feed owns media).
    |
    */

    'messaging' => [

        /*
         * Retention window, in days, applied to a thread that does not carry an
         * explicit `retained_until` — the same default-bounded stance as the
         * feed, and for the same reason: these conversations are about
         * children. The model stamps `retained_until = now + this` on create,
         * and the `groups:purge-feed` sweep removes the thread AND its messages
         * (rows only; no bytes are involved) once it passes.
         *
         * Set to 0 (or a negative number) to keep threads indefinitely unless a
         * caller sets `retained_until` itself.
         */
        'retention_days' => (int) env('GROUP_MESSAGING_RETENTION_DAYS', 365),

        /*
         * Ceiling on one message body, enforced at the request boundary. A
         * thread message is a note between a teacher and a parent, not a
         * document store.
         */
        'max_message_length' => (int) env('GROUP_MESSAGE_MAX_LENGTH', 5000),

    ],

    /*
    |--------------------------------------------------------------------------
    | Behaviour / recognition — the Classroom module (T-013)
    |--------------------------------------------------------------------------
    |
    | Points a leader awards to ONE student against a skill the tenant defined.
    |
    | TWO DESIGN CONSTRAINTS LIVE HERE, and neither is a preference:
    |
    |   1. A CHILD'S RECORD IS PRIVATE. It is disclosed to the group's leaders,
    |      to the student, and to that student's own guardians — never to
    |      another guardian, and never as a class-wide ranking. There is no
    |      leaderboard setting in this block because there is no leaderboard;
    |      the decision is enforced in App\Support\GroupAudience and applied as
    |      a query constraint, not a UI choice a tenant could flip.
    |   2. NOTHING HERE IS PAYWALLED. No key in this file gates a feature behind
    |      a plan, and none should ever be added. Behaviour points, notes, the
    |      per-student summary and the retention sweep are all part of the base
    |      product. See .claude/rules/groups.md.
    |
    */

    'behavior' => [

        /*
         * Retention window, in days, applied to an award that does not carry an
         * explicit `retained_until` — the same default-bounded stance as the
         * feed and the messaging threads, and for the same reason: a behaviour
         * record about a child should not outlive its usefulness by accident.
         * The model stamps `retained_until = now + this` on create, and the
         * shared `groups:purge-feed` sweep force-deletes the row (rows only; no
         * bytes are involved) once it passes.
         *
         * Set to 0 (or a negative number) to keep awards indefinitely unless a
         * caller sets `retained_until` itself.
         */
        'retention_days' => (int) env('GROUP_BEHAVIOR_RETENTION_DAYS', 365),

        /*
         * Ceiling on the note attached to one award, enforced at the request
         * boundary. A note is a sentence of context ("helped a new student
         * settle in"), not a case file — anything longer belongs in a
         * conversation with the guardian, which the messaging threads already
         * carry.
         */
        'max_note_length' => (int) env('GROUP_BEHAVIOR_MAX_NOTE_LENGTH', 1000),

        /*
         * Bound on the magnitude of a single award's point value, enforced at
         * the request boundary in BOTH directions (-N..N). Not a policy about
         * how a school should weight its skills — it is a guard against a
         * fat-fingered 100000 silently dominating every summary a parent reads.
         */
        'max_points' => (int) env('GROUP_BEHAVIOR_MAX_POINTS', 100),

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
