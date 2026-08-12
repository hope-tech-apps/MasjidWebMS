<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `DateUtils.js`.
 *
 * An "instant" here is what a JavaScript `Date` is: an integer number of
 * MILLISECONDS since the Unix epoch, in UTC — or `null`, which stands in for
 * JS's Invalid Date (`NaN` time value). Every helper propagates null the way
 * arithmetic on NaN propagates in JavaScript, because adhan-js relies on that
 * propagation to express "this prayer does not occur here today" (polar
 * summer / winter), and `JSON.stringify` turns those Invalid Dates into
 * `null` — which is exactly what the stored `prayers_data` must keep saying.
 */
final class DateUtils
{
    /**
     * `new Date(ms)` clips its argument with ToIntegerOrInfinity, i.e. it
     * truncates toward zero. Sub-millisecond fractions therefore vanish, and
     * they really do occur: the MoonsightingCommittee high-latitude branch
     * divides the length of the night by 7.
     */
    public static function clip(?float $milliseconds): ?int
    {
        if ($milliseconds === null || is_nan($milliseconds) || is_infinite($milliseconds)) {
            return null;
        }

        return (int) MathUtils::trunc($milliseconds);
    }

    public static function addSeconds(?int $instant, ?float $seconds): ?int
    {
        if ($instant === null || $seconds === null || is_nan($seconds)) {
            return null;
        }

        return self::clip($instant + $seconds * 1000.0);
    }

    public static function addMinutes(?int $instant, ?float $minutes): ?int
    {
        return self::addSeconds($instant, $minutes === null ? null : $minutes * 60.0);
    }

    /**
     * The UTC seconds-of-minute field, as `Date.prototype.getUTCSeconds`
     * reports it — floor-based, so it stays in 0..59 for pre-epoch instants.
     */
    public static function utcSeconds(int $instant): int
    {
        $seconds = (int) floor($instant / 1000);
        $seconds %= 60;

        return $seconds < 0 ? $seconds + 60 : $seconds;
    }

    /**
     * Snap to a whole minute. Only `Rounding::NEAREST` is reachable from this
     * app (it is the adhan default and what every stored row was written
     * with), but the other two are one line each and keep the port faithful.
     */
    public static function roundedMinute(?int $instant, string $rounding = Rounding::NEAREST): ?int
    {
        if ($instant === null) {
            return null;
        }

        $seconds = self::utcSeconds($instant);
        $offset = $seconds >= 30 ? 60 - $seconds : -1 * $seconds;

        if ($rounding === Rounding::UP) {
            $offset = 60 - $seconds;
        } elseif ($rounding === Rounding::NONE) {
            $offset = 0;
        }

        return self::addSeconds($instant, $offset);
    }

    public static function isLeapYear(int $year): bool
    {
        if ($year % 4 !== 0) {
            return false;
        }

        if ($year % 100 === 0 && $year % 400 !== 0) {
            return false;
        }

        return true;
    }

    /**
     * @param  int  $month  1-based, as everywhere else in this namespace.
     */
    public static function dayOfYear(int $year, int $month, int $day): int
    {
        $dayOfYear = 0;
        $feb = self::isLeapYear($year) ? 29 : 28;
        $months = [31, $feb, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        for ($i = 0; $i < $month - 1; $i++) {
            $dayOfYear += $months[$i];
        }

        return $dayOfYear + $day;
    }

    /**
     * The UTC instant at midnight of the given civil date, normalising day
     * overflow the way `Date.UTC` does (so day 32 of January is 1 February).
     *
     * @param  int  $month  1-based.
     */
    public static function utcMidnight(int $year, int $month, int $day): int
    {
        return gmmktime(0, 0, 0, $month, $day, $year) * 1000;
    }
}
