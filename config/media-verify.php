<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the run reports
    |--------------------------------------------------------------------------
    |
    | Null means the application's default log channel. `schedule:run` discards
    | stdout, so for a SCHEDULED run this log line is the entire alert path —
    | see the on-call contract in routes/console.php, and note that the LEVEL
    | carries the verdict so a rule can route without parsing the body.
    |
    */

    'log_channel' => env('MEDIA_VERIFY_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | The bounds — these are the cost controls, not tuning knobs
    |--------------------------------------------------------------------------
    |
    | `max_rows` bounds the DANGLING check, which costs one filesystem stat per
    | media row. On the estate this was written for (226 rows in the last known
    | good backup, 0 today) that is nothing; on a gallery-heavy tenant it is the
    | whole cost of the command, and on a remote disk each stat is a network
    | round trip rather than a syscall. Past the ceiling the scan STOPS, names
    | `truncated_row_budget`, and the run can never be `clean` — a verifier that
    | reports green because it ran out of budget is worse than no verifier,
    | because it is trusted. See TenancyCanary for where that sentence comes
    | from; this command is its sibling by design.
    |
    | `max_files` bounds the ORPHAN walk: the directory listing of each media
    | disk plus one size lookup per unreferenced file. Past the ceiling the walk
    | stops and the orphan figures are reported as a FLOOR ("at least N bytes"),
    | never as a total. That truncation degrades nothing, because orphans are
    | not graded at all — see the docblock on MediaVerify for why.
    |
    */

    'max_rows' => (int) env('MEDIA_VERIFY_MAX_ROWS', 25000),

    'max_files' => (int) env('MEDIA_VERIFY_MAX_FILES', 50000),

    /*
    |--------------------------------------------------------------------------
    | Disks to walk for unreferenced files
    |--------------------------------------------------------------------------
    |
    | The walk always covers every disk NAMED BY A MEDIA ROW plus the configured
    | default (`media-library.disk_name`). List anything else here — a disk that
    | used to hold media and was migrated away from still holds the bytes, and
    | nothing in the `media` table points at it any more, which is precisely the
    | condition this command exists to make visible.
    |
    */

    'extra_disks' => array_values(array_filter(explode(',', (string) env('MEDIA_VERIFY_EXTRA_DISKS', '')))),

    /*
    |--------------------------------------------------------------------------
    | THE MEMORY — where the previous run's census lives
    |--------------------------------------------------------------------------
    |
    | A DELETED row leaves no trace. The dangling scan can only report rows that
    | are still there to be read; a row that was removed is simply absent, and
    | absence is indistinguishable from "that content never existed" unless the
    | run remembers what it counted last time. Without this file the command
    | detects the wipe THAT HAPPENED (0 rows) and misses the same event minus one
    | surviving row, and misses the far likelier partial — somebody deletes the
    | 45 announcement images and leaves the rest.
    |
    | WHERE IT LIVES, AND WHY NOT ANYWHERE ELSE
    |
    |   NOT the cache. `cache:clear` is a normal step in this project's deploy and
    |   in every "turn it off and on again" — which is precisely the moment the
    |   baseline is most valuable. A memory that a routine command erases is not
    |   a memory.
    |
    |   NOT a tracked file in the repo. This codebase deploys by `git checkout`
    |   into a live tree; a tracked file is overwritten by every release and a
    |   dirty one blocks the checkout outright.
    |
    |   NOT the `media` table, and NOT a table beside it. The event being guarded
    |   against is the database losing rows. A baseline kept in the database that
    |   got wiped is not independent evidence of what the database used to hold —
    |   it is part of the same amnesia, and a stale restore would silently
    |   resurrect a stale baseline with it.
    |
    |   `storage/app/` is untracked (`storage/app/.gitignore` is `*`), survives a
    |   `git checkout` into the live tree, survives `cache:clear`, `config:clear`
    |   and `optimize:clear`, is writable by the same `www-data` that runs the
    |   schedule, and is on the same volume the app already requires. It is also
    |   OUTSIDE the media disks this command reads, so the file can never be
    |   mistaken for an orphan by the walk that finds unreferenced bytes.
    |
    | It is a plain JSON file, rewritten atomically (temp + rename), holding one
    | record per `model_type` + `collection_name` plus a platform total. Losing it
    | costs one run: the next run records a fresh baseline and says so.
    |
    */

    'baseline' => [

        'path' => env('MEDIA_VERIFY_BASELINE_PATH') ?: storage_path('app/media-verify/baseline.json'),

        /*
        | THE THRESHOLD. Content is legitimately deleted every day — an
        | announcement comes down, a gallery is cleared, a logo is replaced — so
        | "any drop is a finding" makes this the amber-every-night detector the
        | whole command is written against. What distinguishes an admin deleting
        | a thing from a category of content ceasing to exist is SHAPE, not
        | motion: ordinary editing is a few rows out of one tenant's share of a
        | platform-wide collection; the incident is most of a collection, all at
        | once, across every tenant.
        |
        | So a drop is a finding only when it is BOTH:
        |
        |   `min_drop`    at least this many rows, absolutely. 10 sits above what
        |                 a person plausibly deletes by hand between two runs six
        |                 hours apart (one announcement and its images, a service
        |                 icon replaced, a few avatars) and below the SMALLEST of
        |                 the six groups actually lost on 2026-08-17 — 17 section
        |                 images. Every group of the real event clears it; a
        |                 morning of admin work does not.
        |
        |   `drop_ratio`  and at least this fraction of what the collection held.
        |                 Half of a PLATFORM-WIDE collection vanishing in six
        |                 hours is not a shape human editing produces: to lose
        |                 half of 45 announcement images somebody must delete 23
        |                 of them across several organisations in one sitting.
        |                 One tenant clearing everything it owns is the loudest
        |                 legitimate act available, and on a platform with more
        |                 than two tenants that is well under half.
        |
        | `vanish_floor` is the one place the ratio is not required: a collection
        | that reaches EXACTLY ZERO has stopped existing platform-wide, which is
        | qualitatively the event, so it earns a lower bar — but not a bar of
        | one, because a one- or two-row collection going empty is a single admin
        | action and nothing more.
        |
        | `peak_ratio` / `peak_window_days` are the net under the net. Because a
        | drop inside the threshold LOWERS the baseline (it has to, or twenty
        | ordinary deletions would eventually trip the rule for no reason), a
        | drain of 9 rows per run would empty a collection without ever firing.
        | So each series also carries a high-water mark, and losing three
        | quarters of it inside 30 days is a finding however gradually it
        | happened. The mark forgets after the window, so a collection that
        | genuinely shrinks over a year does not become a permanent page.
        */

        'min_drop' => (int) env('MEDIA_VERIFY_MIN_DROP', 10),

        'drop_ratio' => (float) env('MEDIA_VERIFY_DROP_RATIO', 0.5),

        'vanish_floor' => (int) env('MEDIA_VERIFY_VANISH_FLOOR', 5),

        'peak_ratio' => (float) env('MEDIA_VERIFY_PEAK_RATIO', 0.75),

        'peak_window_days' => (int) env('MEDIA_VERIFY_PEAK_WINDOW_DAYS', 30),

    ],

    /*
    |--------------------------------------------------------------------------
    | The link every media URL is actually served through
    |--------------------------------------------------------------------------
    |
    | `storage/app/public` is reachable over HTTP only because `public/storage`
    | points at it. Break that link and every URL this command validates 404s
    | while every row and every file it checks is perfectly intact — a clean
    | report about a platform showing no images, which is the exact failure
    | shape this whole command exists to refuse.
    |
    | The check only runs where the link is load-bearing: a media disk on this
    | host must actually resolve to `target`. On a host whose media lives on S3,
    | or in a test whose disk is faked elsewhere, it reports `skipped` and says
    | so rather than inventing a verdict.
    |
    */

    'public_link' => [

        'link' => env('MEDIA_VERIFY_PUBLIC_LINK') ?: public_path('storage'),

        'target' => env('MEDIA_VERIFY_PUBLIC_LINK_TARGET') ?: storage_path('app/public'),

    ],

];
