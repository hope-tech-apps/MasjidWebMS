<?php

namespace App\Services\PrayerTimes;

use InvalidArgumentException;

/**
 * Port of adhan-js `HighLatitudeRule.js`.
 */
final class HighLatitudeRule
{
    public const MIDDLE_OF_THE_NIGHT = 'middleofthenight';
    public const SEVENTH_OF_THE_NIGHT = 'seventhofthenight';
    public const TWILIGHT_ANGLE = 'twilightangle';

    /**
     * @return array{fajr: float, isha: float}
     */
    public static function nightPortions(string $rule, float $fajrAngle, float $ishaAngle): array
    {
        return match ($rule) {
            self::MIDDLE_OF_THE_NIGHT => ['fajr' => 1 / 2, 'isha' => 1 / 2],
            self::SEVENTH_OF_THE_NIGHT => ['fajr' => 1 / 7, 'isha' => 1 / 7],
            self::TWILIGHT_ANGLE => ['fajr' => $fajrAngle / 60, 'isha' => $ishaAngle / 60],
            default => throw new InvalidArgumentException(
                "Invalid high latitude rule found when attempting to compute night portions: {$rule}"
            ),
        };
    }
}
