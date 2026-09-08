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

    'session' => [

        /*
         * How long a parent stays signed in, in MINUTES. 30 days.
         *
         * This is the number the design asked for and could not have until the
         * `family` guard got its own expiration: Sanctum enforces ONE global
         * `sanctum.expiration` inside its Guard, and a per-token `expires_at`
         * can only ever shorten a token, never extend it past that global. The
         * global is 480 minutes and must stay there, because raising it would
         * lengthen STAFF sessions too — which .claude/rules/auth-permissions.md
         * forbids ("never change how an existing admin logs in").
         *
         * So the family guard is now built by its own driver with its own
         * expiration (AppServiceProvider), and this is that value. Staff are
         * untouched at 8 hours.
         *
         * WHY SO LONG. A parent checks a school portal a few times a term. At 8
         * hours they re-authenticated on essentially every visit — find the
         * email, wait for a code, type six digits — which is enough friction to
         * make a family stop looking, and a portal nobody opens is worse than no
         * portal. The exposure is bounded: this token reads ONE family's own
         * children, it is revocable at any moment through
         * `contacts.login_revoked_at`, and revoking is a single switch in the
         * admin console rather than a password reset.
         */
        'expiration_minutes' => (int) env('FAMILY_SESSION_MINUTES', 43200),

    ],

    'threads' => [

        /*
         * How many conversations one parent may OPEN in an hour.
         *
         * Replying is deliberately not limited anywhere — a parent mid-
         * conversation must never be told to slow down. Opening is different:
         * it is the verb that creates a new thing a teacher has to triage, so a
         * stuck client retrying, or a frustrated parent tapping Send, must not
         * manufacture twenty threads.
         *
         * Six is generous for a real family and low enough that the teacher's
         * list stays readable. Keyed on the CONTACT, not the IP: a household
         * behind one address may be several families.
         */
        'opened_per_hour' => (int) env('FAMILY_THREADS_OPENED_PER_HOUR', 6),

    ],

    /*
     * The password a parent may choose for themselves (2026-09-08).
     *
     * There is nothing here about issuing, resetting or expiring one, and that
     * is deliberate: the office never holds a family's password, so there is no
     * operator policy to configure. See App\Services\Family\FamilyPasswordService.
     */
    'password' => [

        /*
         * Minimum length, and the ONLY strength rule.
         *
         * No character-class requirements: they push people toward `Password1!`
         * and are explicitly not what NIST 800-63B asks for. Length is.
         *
         * Enforced only where a password is CHOSEN. The sign-in door must never
         * apply it — refusing a short submission with a 422 while a wrong-but-
         * long one gets the uniform 410 would disclose the stored credential's
         * length, and raising this number would silently lock out every parent
         * whose password predates the change.
         */
        'min_length' => (int) env('FAMILY_PASSWORD_MIN_LENGTH', 12),

        /*
         * Check the chosen password against Have I Been Pwned.
         *
         * k-anonymity: five characters of a SHA-1 prefix leave the server, never
         * the password. Laravel fails OPEN if the call cannot be made, so a
         * network problem at the school cannot stop a parent setting a password.
         *
         * Off in `testing` (see phpunit.xml) so the suite makes no outbound
         * request — a test that reaches the public internet is a test that fails
         * on a train. Nothing else should turn it off.
         */
        'check_breaches' => (bool) env('FAMILY_PASSWORD_CHECK_BREACHES', true),

    ],

];
