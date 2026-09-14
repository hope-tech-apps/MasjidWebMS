<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App member sign-up / sign-in codes
    |--------------------------------------------------------------------------
    |
    | The self-serve half of contact identity: a congregant who downloads a
    | tenant's app and signs themselves in, as opposed to a parent whose access
    | an administrator granted (config/family.php).
    |
    | Same reasoning as config/family.php for why these are config and not
    | literals — an operator tightening a window mid-incident must not need a
    | deploy, and a test must be able to state the window it exercises.
    |
    | The defaults deliberately MATCH the family realm. This flow is reached by
    | strictly more people than that one, so it has no business being looser;
    | if the two ever diverge it should be because this one got tighter.
    |
    */

    'signup' => [

        /*
        | Digits in the emailed code. As in the family realm, six is the
        | usability ceiling and NOT the control — the attempt cap, the TTL and
        | the throttles below are. Do not raise this and relax those.
        */
        'code_length' => (int) env('MEMBER_SIGNUP_CODE_LENGTH', 6),

        /*
        | Minutes a code stays usable.
        */
        'code_ttl_minutes' => (int) env('MEMBER_SIGNUP_CODE_TTL_MINUTES', 10),

        /*
        | Wrong guesses one code tolerates before it is burned. A wrong guess
        | charges EVERY live code for the address, not just the newest, so that
        | requesting twenty codes does not buy a hundred guesses.
        */
        'max_attempts' => (int) env('MEMBER_SIGNUP_MAX_ATTEMPTS', 5),

        /*
        | Request/verify ceilings, per address and per IP.
        |
        | These sit ON TOP of `app_signup_codes.attempts`. The column is the
        | per-code lockout and survives a cache flush because it is a fact about
        | a record; these stop an attacker cycling FRESH codes, which a per-code
        | counter cannot see. Both are required.
        |
        | The per-address ceiling is also what stands between a stranger who
        | knows somebody's address and an unbounded stream of "your sign-in
        | code" mail apparently from their masjid.
        */
        'requests_per_hour_per_address' => (int) env('MEMBER_SIGNUP_REQUESTS_PER_HOUR_PER_ADDRESS', 5),
        'requests_per_hour_per_ip' => (int) env('MEMBER_SIGNUP_REQUESTS_PER_HOUR_PER_IP', 20),
        'verifications_per_hour_per_address' => (int) env('MEMBER_SIGNUP_VERIFICATIONS_PER_HOUR_PER_ADDRESS', 10),
        'verifications_per_hour_per_ip' => (int) env('MEMBER_SIGNUP_VERIFICATIONS_PER_HOUR_PER_IP', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | The public account-deletion page (/account-deletion)
    |--------------------------------------------------------------------------
    |
    | Google Play's account-deletion policy asks the page to name the app or
    | developer as the store listing shows them, and to say how long any data
    | kept after deletion is retained. The page is otherwise tenant-neutral, so
    | those facts live here, where the owner can correct them without a deploy.
    |
    | `publisher` and `apps` must match the Play listings exactly. `log_retention_days`
    | is how long server logs (which record that a deletion happened, never the
    | address) are kept. Leave it null until that period is confirmed: the page
    | then makes no numeric promise it cannot keep.
    |
    */

    'account_deletion' => [
        // Empty until the owner confirms the developer name exactly as the store
        // listings show it; the page then names only the apps. Never a guess on a
        // public page.
        'publisher' => env('ACCOUNT_DELETION_PUBLISHER', ''),
        'apps' => ['Burlington Masjid', 'NAFIS Apex Mosque', 'Muslim Education Center'],
        'log_retention_days' => env('ACCOUNT_DELETION_LOG_RETENTION_DAYS') !== null
            ? (int) env('ACCOUNT_DELETION_LOG_RETENTION_DAYS')
            : null,
    ],
];
