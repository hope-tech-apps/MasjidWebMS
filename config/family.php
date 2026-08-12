<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Parent/guardian sign-in codes (T-015d)
    |--------------------------------------------------------------------------
    |
    | Every number a parent's credential depends on lives here rather than as a
    | literal in the service, for the reason .claude/rules/credentials.md gives
    | for `config/credentials.php`: an operator tightening a window during an
    | incident must not need a code change, and a test must be able to state the
    | window it is exercising instead of sleeping through the real one.
    |
    */

    'login' => [

        /*
        | How many digits the emailed code carries.
        |
        | Six is the ceiling on usability, not on security. What actually bounds
        | a guessing attack here is the three independent limits below — 5
        | attempts per code, a 10-minute life, and the per-address/per-IP
        | request throttles — because a 6-digit code alone is 1 in 10^6 per try.
        | Do not raise this and relax those; they are the control.
        */
        'code_length' => (int) env('FAMILY_LOGIN_CODE_LENGTH', 6),

        /*
        | Minutes a code stays usable. The design (§3) says ten and means it: a
        | parent reads the mail and types the code within a minute or two, and
        | a mailbox that is later compromised must not still hold a working
        | credential for a child's records.
        */
        'code_ttl_minutes' => (int) env('FAMILY_LOGIN_CODE_TTL_MINUTES', 10),

        /*
        | Wrong guesses a single code tolerates before it is burned. The 6th
        | attempt cannot succeed even if it is correct — the code is dead, and
        | the parent requests a new one.
        */
        'max_attempts' => (int) env('FAMILY_LOGIN_CODE_MAX_ATTEMPTS', 5),

        /*
        | Codes one ADDRESS may request per hour, and the same per IP.
        |
        | Both are applied to the value the caller SUBMITTED, never to a value
        | we looked up — an address that exists and one that does not are
        | throttled identically, so a 429 is not an existence oracle either.
        */
        'requests_per_hour_per_address' => (int) env('FAMILY_LOGIN_REQUESTS_PER_ADDRESS', 5),
        'requests_per_hour_per_ip' => (int) env('FAMILY_LOGIN_REQUESTS_PER_IP', 20),

        /*
        | Verification attempts per hour, per submitted address and per IP.
        | Layered ON TOP of the per-code attempt counter: the counter stops one
        | code being ground down, these stop an attacker cycling fresh codes.
        */
        'verifications_per_hour_per_address' => (int) env('FAMILY_LOGIN_VERIFY_PER_ADDRESS', 10),
        'verifications_per_hour_per_ip' => (int) env('FAMILY_LOGIN_VERIFY_PER_IP', 40),

    ],

];
