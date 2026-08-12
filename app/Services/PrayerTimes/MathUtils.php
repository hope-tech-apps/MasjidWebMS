<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `MathUtils.js`.
 *
 * Every function here mirrors its JavaScript counterpart one-for-one so the
 * whole calculator can be diffed against upstream by eye. See the header of
 * {@see PrayerTimes} for why this library was ported into PHP at all.
 */
final class MathUtils
{
    public static function degreesToRadians(float $degrees): float
    {
        return $degrees * M_PI / 180.0;
    }

    public static function radiansToDegrees(float $radians): float
    {
        return $radians * 180.0 / M_PI;
    }

    public static function normalizeToScale(float $num, float $max): float
    {
        return $num - $max * floor($num / $max);
    }

    public static function unwindAngle(float $angle): float
    {
        return self::normalizeToScale($angle, 360.0);
    }

    public static function quadrantShiftAngle(float $angle): float
    {
        if ($angle >= -180 && $angle <= 180) {
            return $angle;
        }

        return $angle - 360 * self::round($angle / 360);
    }

    /**
     * JavaScript's `Math.round`: half rounds toward +Infinity.
     *
     * PHP's native round() rounds half AWAY FROM ZERO, so round(-2.5) is -3
     * there and -2 in JS. The season-adjusted twilight offsets are negative and
     * can land exactly on .5, which would put the ported result a whole second
     * off the values production already stores — hence this shim rather than
     * round().
     */
    public static function round(float $value): float
    {
        return floor($value + 0.5);
    }

    /**
     * JavaScript's `Math.trunc`: drop the fractional part toward zero.
     */
    public static function trunc(float $value): float
    {
        return $value < 0 ? ceil($value) : floor($value);
    }
}
