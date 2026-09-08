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
    | Qur'an memorization — hifz tracking (T-014)
    |--------------------------------------------------------------------------
    |
    | One recitation heard from ONE student: sabak (the new lesson), sabqi
    | (recent memorisation under revision) or manzil (the long rotation).
    |
    | THE SAME TWO DESIGN CONSTRAINTS AS THE BEHAVIOUR BLOCK ABOVE APPLY HERE,
    | and neither is a preference:
    |
    |   1. A CHILD'S RECORD IS PRIVATE. It is disclosed to the halaqa's
    |      leaders, to the student, and to that student's own guardians — never
    |      to another guardian, and never as a class-wide ranking of who has
    |      memorised most. There is no leaderboard setting in this block because
    |      there is no leaderboard; the decision is enforced in
    |      App\Support\GroupAudience, as a query constraint, not a UI choice.
    |   2. NOTHING HERE IS PAYWALLED. No key gates a feature behind a plan, and
    |      none should ever be added.
    |
    | THERE IS DELIBERATELY NO `retention_days` KEY, breaking with the feed, the
    | messaging threads and the behaviour awards — and its absence is a decision,
    | not an omission. Those surfaces describe a MOMENT (a class photo, a point
    | given on a Tuesday), so bounding them by default is right. A hifz record is
    | an ACADEMIC RECORD: it is the only evidence of what a student has
    | memorised, a school that loses last year's sabak entries cannot tell a new
    | teacher where the child is, and families reasonably expect a memorisation
    | history to outlast a school year. Decisively, the student's current
    | position is DERIVED from the sabak entries rather than stored, so a sweep
    | that removed the newest one would silently move a child BACKWARDS in the
    | mushaf. `hifz_entries` is therefore absent from `groups:purge-feed` on
    | purpose. Its lifetime is bounded by the roster instead: the DB cascade off
    | `group_memberships` takes a student's entries with them when they leave.
    | See .claude/rules/groups.md.
    |
    */

    'hifz' => [

        /*
         * Ceiling on the teacher's note attached to one recitation, enforced at
         * the request boundary. A note is a sentence of context ("struggled
         * with the waqf on ayah 12"), not a report card — anything longer
         * belongs in a conversation with the guardian, which the messaging
         * threads already carry.
         */
        'max_note_length' => (int) env('GROUP_HIFZ_MAX_NOTE_LENGTH', 1000),

        /*
         * Bound on either mistake counter for one recitation, enforced at the
         * request boundary. Not a judgement about how many mistakes a portion
         * may contain — it is a guard against a fat-fingered 10000 dominating
         * every summary a parent reads.
         */
        'max_mistakes' => (int) env('GROUP_HIFZ_MAX_MISTAKES', 100),

        /*
         * Default window, in days, for the REVISION section of a student's
         * progress report ("what has actually been revised lately?"). Only that
         * section is windowed: the position and the memorisation totals are
         * cumulative and are never date-filtered. A caller may override per
         * request with `?window=`, bounded to a year.
         *
         * Configurable because the cadence is genuinely different between a
         * full-time academy hearing manzil daily and a weekend circle hearing it
         * monthly.
         */
        'revision_window_days' => (int) env('GROUP_HIFZ_REVISION_WINDOW_DAYS', 30),

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

    /*
     * How many rows a per-student history returns in one payload.
     *
     * A PAGE SIZE, not a cap on what is counted. Every summary beside these
     * lists — attendance totals, gradebook averages — is aggregated in SQL over
     * the whole history, because a total computed from a truncated page is a
     * wrong number rather than a missing one, and it was wrong silently: past
     * 200 rows a child's attendance summary quietly became "the last 200 days"
     * and a gradebook average was taken over whatever the database happened to
     * return. Both payloads now say `*_truncated` so the screen can tell a
     * teacher the list is a page and the total is not.
     */
    'records_page_size' => (int) env('GROUP_RECORDS_PAGE_SIZE', 200),

    /*
     * The scale a NEW piece of work is marked on when the teacher does not say.
     *
     * `levels` — the school's four performance levels (4 Exceeds down to
     * 1 Needs Support), which is what Al-Razi's own rubrics use across every
     * subject. `points` — marked out of a number, the way a spelling quiz is.
     * Both remain available per assignment; this only decides what the form is
     * pre-set to.
     *
     * NOT the same knob as the COLUMN default in the migration, which is
     * `points` and must stay `points`: every assignment that existed before this
     * feature was marked out of points, and a column default of `levels` would
     * have relabelled real marks as performance levels. One is about history,
     * the other about what the next form offers.
     *
     * See App\Support\PerformanceLevel for what the four levels mean, and why a
     * level is never rendered as a percentage.
     */
    'default_grading_scale' => env('GROUP_DEFAULT_GRADING_SCALE', 'levels'),

    /*
     * Report cards and progress reports.
     *
     * Both bounds are request-boundary guards, not editorial policy. A teacher
     * writing a long, careful comment about a child must never hit a limit that
     * makes them cut it short — that comment is often the most useful thing on
     * the document — so the per-card ceiling is deliberately generous and the
     * per-criterion one is sized for a sentence or two.
     */
    'report_cards' => [
        'max_comment_length' => (int) env('GROUP_REPORT_MARK_COMMENT_LENGTH', 1000),
        'max_teacher_comment_length' => (int) env('GROUP_REPORT_TEACHER_COMMENT_LENGTH', 4000),
    ],

    'lessons' => [

        /*
         * Ceiling on a lesson plan's body, at the request boundary. Generous
         * because a plan is genuinely prose — a week of objectives, materials
         * and a closing activity — unlike the one-sentence notes elsewhere in
         * this file.
         *
         * There is NO retention setting here, and its absence is a decision: a
         * lesson plan is the teacher's note about a ROOM, carrying no record
         * about any child, so `groups:purge-feed` deliberately does not sweep it.
         */
        'max_body_length' => (int) env('GROUP_LESSON_MAX_BODY_LENGTH', 5000),

        /*
         * Ceiling on each of the template's other prose sections — objective,
         * differentiation, cross-integration, assessment, reflection.
         *
         * Lower than the body on purpose. Activities is the narrative of a
         * lesson; the rest are a line or two each on the school's own paper
         * form, and a section that invites an essay gets left blank.
         */
        'max_section_length' => (int) env('GROUP_LESSON_MAX_SECTION_LENGTH', 2000),

    ],

    'gradebook' => [

        /*
         * Ceiling on the note attached to one child's mark. A sentence of
         * context ("did this with help"), not a report card.
         */
        'max_note_length' => (int) env('GROUP_GRADEBOOK_MAX_NOTE_LENGTH', 1000),

        /*
         * Bound on an assignment's maximum. A FAT-FINGER GUARD, not a policy
         * about how work should be weighted: nothing here says a piece of work
         * ought to be out of 10 or out of 100, only that a stray keystroke
         * cannot make it out of 100000.
         */
        'max_points_possible' => (int) env('GROUP_GRADEBOOK_MAX_POINTS', 1000),

        /*
         * No retention setting, deliberately: a mark is an academic record
         * bounded by the roster, the same call `hifz` makes above. It dies when
         * the enrolment does, not on a clock.
         */

    ],

    'resources' => [

        /*
         * The PRIVATE disk. These files are worksheets and handouts for one
         * class; nothing here is ever served from a public URL, and the
         * download route streams bytes after re-resolving the ownership chain.
         *
         * NOTE, because it is a real limitation rather than an oversight: the
         * private disk is NOT covered by any backup target
         * (.claude/rules/backups.md — MediaTarget derives from the PUBLIC
         * media-library disk). Files here are one disk failure from gone.
         */
        'disk' => env('GROUP_RESOURCE_DISK', 'local'),
        'directory' => env('GROUP_RESOURCE_DIRECTORY', 'group-resources'),

        /*
         * Its OWN allowlist, deliberately not `groups.media.mime_types`. That
         * one is the children's photo feed, whose config states documents are
         * absent on purpose ("a feed post is a photo of an activity, not a
         * filing cabinet"), and it is driven by a single shared env var —
         * widening it to carry PDFs would widen the photo feed too.
         *
         * The same five types the form-attachment allowlist uses. SVG is on
         * neither list: it is script-bearing markup, not an image.
         */
        'mime_types' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'GROUP_RESOURCE_MIME_TYPES',
            'application/pdf,'
            . 'application/msword,'
            . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
            . 'image/jpeg,'
            . 'image/png'
        ))))),

        'max_size_kb' => (int) env('GROUP_RESOURCE_MAX_SIZE_KB', 8192),

        /*
         * A ceiling per class. The feed is bounded by how many posts a teacher
         * bothers to write; a resource library is an unbounded append surface
         * with no purge sweep behind it, so it needs a stated end.
         */
        'max_per_class' => (int) env('GROUP_RESOURCE_MAX_PER_CLASS', 200),

    ],

];
