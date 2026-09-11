<?php

namespace App\Support;

/**
 * How long a 429 asks the caller to wait, in words: "Please try again in a minute." or
 * "… in 40 minutes.", from the same Retry-After the answer carries. So an answer never
 * says "a few minutes" of a window that runs an hour from its first hit, or of a staff
 * code lockout that runs fifteen.
 *
 * One helper for every 429 the forms send: the throttles' own answers
 * (AppServiceProvider) and the staff-code lockout (FormStaffCodes::lockedOutResponse()).
 */
final class TryAgainIn
{
    /** From the wait in seconds: rounded up, and never less than a minute. */
    public static function words(int $retryAfterSeconds): string
    {
        $minutes = max(1, (int) ceil(max(0, $retryAfterSeconds) / 60));

        return $minutes === 1 ? 'Please try again in a minute.' : "Please try again in {$minutes} minutes.";
    }

    /**
     * From the headers the throttle hands a 429's response callback.
     *
     * @param  array<string, mixed>  $headers
     */
    public static function fromHeaders(array $headers): string
    {
        return self::words((int) ($headers['Retry-After'] ?? 60));
    }
}
