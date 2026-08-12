<?php

namespace App\Services\PrayerTimes;

use InvalidArgumentException;

/**
 * Port of adhan-js `Madhab.js`. The lowercase literals are the serialized form
 * stored in `prayers_data.calculationParameters.madhab`.
 */
final class Madhab
{
    public const SHAFI = 'shafi';
    public const HANAFI = 'hanafi';

    public static function shadowLength(string $madhab): int
    {
        return match ($madhab) {
            self::SHAFI => 1,
            self::HANAFI => 2,
            default => throw new InvalidArgumentException("Invalid Madhab: {$madhab}"),
        };
    }
}
