<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `TimeComponents.js`.
 *
 * Splits a (possibly negative, possibly > 24) count of universal hours into
 * h/m/s and pins it to a civil date as a UTC instant. `hours` may fall outside
 * 0..23 — that is how a solar event on one civil date lands on the UTC day
 * before or after it — and the arithmetic below carries the overflow the same
 * way `Date.UTC` does.
 */
final class TimeComponents
{
    /**
     * @param  int  $month  1-based.
     * @return int|null Milliseconds since the Unix epoch, or null when $hours
     *                  is NAN (the sun never reaches the requested altitude).
     */
    public static function utcInstant(float $hours, int $year, int $month, int $day): ?int
    {
        if (is_nan($hours) || is_infinite($hours)) {
            return null;
        }

        $h = floor($hours);
        $m = floor(($hours - $h) * 60);
        $s = floor(($hours - ($h + $m / 60)) * 60 * 60);

        return DateUtils::utcMidnight($year, $month, $day)
            + (int) $h * 3600000
            + (int) $m * 60000
            + (int) $s * 1000;
    }
}
