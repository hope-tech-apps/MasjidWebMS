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

    /*
    |--------------------------------------------------------------------------
    | Payment and staff entry (DECISIONS.md 2026-09-11)
    |--------------------------------------------------------------------------
    |
    | A form whose settings turn payment on takes a card payment through Stripe
    | Checkout, or cash at the gate recorded with a staff member's own code. The
    | keys below govern that public, unauthenticated path and are set by an
    | operator in .env — parse-check a candidate .env before config:cache (a bad
    | .env plus config:cache once returned 500 on every request).
    |
    */

    /*
     * The public submit limit for a request WITHOUT a staff code: submissions
     * per hour per connection per form. 8 is the limit the endpoint has always
     * had; the key exists so festival week can raise it without a code change.
     * Floored at 1, so a typo cannot close registration outright.
     */
    'submit_per_hour' => max(1, (int) env('FORMS_SUBMIT_PER_HOUR', 8)),

    /*
     * The ONLY origins a Stripe return URL may be built on: the public sites
     * that host a paying form, as exact scheme://host[:port], comma-separated
     * (https://mec.hopetechapps.com,https://mec-web.pages.dev).
     *
     * NO DEFAULT, on purpose: unset means no form may open a card payment — the
     * checkout's preflight must refuse before anything is written, never fall
     * back to APP_URL or a client-supplied URL. Deliberately NOT
     * config('cors.allowed_origins'): that defaults to ['*'], an exact match
     * against '*' fails, and the obvious "fix" — reading '*' as allow-all —
     * would let any site send a payer back to a page of its choosing with a
     * live registration uuid in the URL. So a wildcard, a path, or anything
     * else that is not a bare origin is DROPPED here rather than trusted
     * downstream. Lower-cased, trailing slash removed: browsers send the Origin
     * header that way.
     */
    'payment_return_origins' => array_values(array_unique(array_filter(
        array_map(
            static fn (string $origin): string => strtolower(rtrim(trim($origin), '/')),
            explode(',', (string) env('FORMS_PAYMENT_RETURN_ORIGINS', ''))
        ),
        static fn (string $origin): bool => preg_match('#^https?://[a-z0-9.-]+(:[0-9]{1,5})?\z#', $origin) === 1
    ))),

    /*
     * A valid staff code's own bucket: cash entries per hour per code, whatever
     * network the phone is on (festival brief, blocker 3). Keyed by the code's
     * HMAC (FormStaffCode::hashFor()), never a bare hash of the code.
     */
    'code_per_hour' => max(1, (int) env('FORMS_CODE_PER_HOUR', 60)),

    /*
     * How long the signed staff token a code is exchanged for stays good, in
     * minutes: 12 hours covers the event day. Whoever mints the token caps it
     * at the code's own expires_at as well — a token must never outlive the
     * code it stands for.
     */
    'staff_token_ttl_minutes' => max(1, (int) env('FORMS_STAFF_TOKEN_TTL_MINUTES', 720)),

    /*
     * The flood guard in front of any work, per minute. On the submit it counts
     * requests carrying a staff code, or a token that does not check out, per
     * connection per form. On the code exchange it counts per connection, per
     * DEVICE, per form. A verified staff token never meets it, so junk sent from
     * the venue wifi cannot stop a phone that holds one. Generous on purpose:
     * every phone at the venue shares one address.
     */
    'code_per_minute_per_ip' => max(1, (int) env('FORMS_CODE_PER_MINUTE_PER_IP', 30)),

    /*
     * Wrong staff codes (App\Support\FormStaffCodes). Checked BEFORE any lookup and
     * hit by every failure, whatever its cause:
     *
     *   per_device  per connection AND device_id, so one attendee typing junk on the
     *               venue wifi locks out their own phone, not every phone behind
     *               the same address;
     *   per_form    every failure on the form from anywhere, because device_id is
     *               the client's to choose.
     *
     * A signed staff token never touches either, so a phone that has already
     * exchanged its code keeps recording cash through a lockout; an admin can clear
     * one. Both windows are decay_minutes long.
     */
    'staff_code_failures' => [
        'per_device' => max(1, (int) env('FORMS_STAFF_CODE_FAILURES_PER_DEVICE', 5)),
        'per_form' => max(1, (int) env('FORMS_STAFF_CODE_FAILURES_PER_FORM', 50)),
        'decay_minutes' => max(1, (int) env('FORMS_STAFF_CODE_FAILURE_DECAY_MINUTES', 15)),
    ],

];
