<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `CalculationMethod.js`, restricted to the methods this
 * application can produce.
 *
 * {@see moonsightingCommittee()} is the ONLY one the mobile prayers endpoint
 * uses — it is what `resources/js/fetchPrayerTimes.js` hardcoded and what every
 * row in the `prayers` table was generated with, so switching it would silently
 * move every prayer time the apps display.
 *
 * The other three are here because they are the parameter combinations that
 * exercise the remaining branches of {@see PrayerTimes} (night portions, a
 * fixed Isha interval, an angle-based Maghrib). Keeping them means those
 * branches are covered by the golden-vector test instead of being carried as
 * untested code.
 */
final class CalculationMethod
{
    /** Moonsighting Committee. */
    public static function moonsightingCommittee(): CalculationParameters
    {
        $params = new CalculationParameters('MoonsightingCommittee', 18, 18);
        $params->methodAdjustments['dhuhr'] = 5;
        $params->methodAdjustments['maghrib'] = 3;

        return $params;
    }

    /** Muslim World League. */
    public static function muslimWorldLeague(): CalculationParameters
    {
        $params = new CalculationParameters('MuslimWorldLeague', 18, 17);
        $params->methodAdjustments['dhuhr'] = 1;

        return $params;
    }

    /** Umm al-Qura University, Makkah — Isha as a fixed interval after Maghrib. */
    public static function ummAlQura(): CalculationParameters
    {
        return new CalculationParameters('UmmAlQura', 18.5, 0, 90);
    }

    /** Institute of Geophysics, University of Tehran — angle-based Maghrib. */
    public static function tehran(): CalculationParameters
    {
        return new CalculationParameters('Tehran', 17.7, 14, 0, 4.5);
    }
}
