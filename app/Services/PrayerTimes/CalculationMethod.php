<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `CalculationMethod.js`, covering every method this
 * application can produce.
 *
 * The reference version is **4.4.4**, not the 4.4.3 currently installed in
 * `node_modules/adhan`. CalculationMethod.js itself is unchanged between the
 * two, so nothing below depends on the distinction — but the port as a whole
 * does: `Astronomical::approximateTransit()` carries 4.4.4's
 * International-Date-Line correction, which 4.4.3 does not have, and
 * tests/fixtures/adhan-prayer-times.json is generated against 4.4.4 for that
 * reason. Do not "correct" this to 4.4.3.
 *
 * WHAT SELECTS A METHOD
 * ---------------------
 * Each masjid carries a `prayer_calculation_settings` row whose `method`
 * column is cast to {@see \App\Enums\PrayerCalculationMethod}. That stored
 * string — `MuslimWorldLeague`, `Egyptian`, `Karachi`, `UmmAlQura`, `Dubai`,
 * `MoonsightingCommittee`, `NorthAmerica`, `Kuwait`, `Qatar`, `Singapore`,
 * `Tehran`, `Turkey` — is resolved to parameters by {@see fromName()} and
 * handed to {@see PrayerTimesGenerator::forRange()}. All twelve enum cases are
 * therefore constructible here; there is a one-to-one correspondence between
 * the enum's backed values and the method names below, and the name is
 * serialized verbatim into `prayers_data.calculationParameters.method`.
 *
 * Previously only {@see moonsightingCommittee()} was reachable and the other
 * three existed to exercise {@see PrayerTimes} branches. That is no longer
 * true: a masjid on a non-default setting now genuinely calculates with its
 * own parameters, and the cached `prayers` rows plus
 * {@see \App\Console\Commands\SendDuePrayerNotifications} follow it.
 *
 * WHY MOONSIGHTING COMMITTEE IS STILL THE FALLBACK
 * ------------------------------------------------
 * Every row already in the `prayers` table was generated with
 * MoonsightingCommittee / Shafi / MiddleOfTheNight, and shipped mobile builds
 * read that JSON byte-for-byte. So it stays the default of
 * {@see PrayerTimesGenerator::forRange()} and the value the settings table
 * defaults to: a masjid that never touched its settings — or a name that does
 * not resolve — must regenerate byte-identically to what is stored today.
 *
 * adhan's `Other` method (0, 0) is deliberately not ported: the app's enum does
 * not offer it, so nothing can ever select it.
 *
 * The parameter objects returned here are MUTABLE and are mutated downstream
 * (madhab, highLatitudeRule). Every accessor builds a fresh instance; never
 * cache and share one.
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

    /** Egyptian General Authority of Survey. */
    public static function egyptian(): CalculationParameters
    {
        $params = new CalculationParameters('Egyptian', 19.5, 17.5);
        $params->methodAdjustments['dhuhr'] = 1;

        return $params;
    }

    /** University of Islamic Sciences, Karachi. */
    public static function karachi(): CalculationParameters
    {
        $params = new CalculationParameters('Karachi', 18, 18);
        $params->methodAdjustments['dhuhr'] = 1;

        return $params;
    }

    /**
     * Dubai.
     *
     * Upstream rebuilds `methodAdjustments` with an object spread
     * (`{ ...params.methodAdjustments, sunrise: -3, ... }`), which keeps the
     * original fajr,sunrise,dhuhr,asr,maghrib,isha insertion order and only
     * overwrites values. Assigning into existing PHP keys does exactly that,
     * and the order is what `prayers_data` carries.
     */
    public static function dubai(): CalculationParameters
    {
        $params = new CalculationParameters('Dubai', 18.2, 18.2);
        $params->methodAdjustments['sunrise'] = -3;
        $params->methodAdjustments['dhuhr'] = 3;
        $params->methodAdjustments['asr'] = 3;
        $params->methodAdjustments['maghrib'] = 3;

        return $params;
    }

    /** Islamic Society of North America (ISNA). */
    public static function northAmerica(): CalculationParameters
    {
        $params = new CalculationParameters('NorthAmerica', 15, 15);
        $params->methodAdjustments['dhuhr'] = 1;

        return $params;
    }

    /** Kuwait — no method adjustments. */
    public static function kuwait(): CalculationParameters
    {
        return new CalculationParameters('Kuwait', 18, 17.5);
    }

    /** Qatar — Isha as a fixed interval after Maghrib. */
    public static function qatar(): CalculationParameters
    {
        return new CalculationParameters('Qatar', 18, 0, 90);
    }

    /**
     * Singapore — the ONLY method that changes rounding.
     *
     * `Rounding::UP` makes {@see DateUtils::roundedMinute()} take the
     * `60 - seconds` branch for every prayer, so times round up to the next
     * whole minute instead of to the nearest one (including a whole extra
     * minute when the instant already lands on :00, which is what upstream
     * does). 'up' is also serialized into
     * `prayers_data.calculationParameters.rounding`.
     */
    public static function singapore(): CalculationParameters
    {
        $params = new CalculationParameters('Singapore', 20, 18);
        $params->methodAdjustments['dhuhr'] = 1;
        $params->rounding = Rounding::UP;

        return $params;
    }

    /** Diyanet İşleri Başkanlığı, Turkey. See {@see dubai()} on key order. */
    public static function turkey(): CalculationParameters
    {
        $params = new CalculationParameters('Turkey', 18, 17);
        $params->methodAdjustments['sunrise'] = -7;
        $params->methodAdjustments['dhuhr'] = 5;
        $params->methodAdjustments['asr'] = 4;
        $params->methodAdjustments['maghrib'] = 7;

        return $params;
    }

    /**
     * Resolve a stored method name to a fresh set of parameters.
     *
     * `$name` is the raw string form of {@see \App\Enums\PrayerCalculationMethod}
     * — i.e. `prayer_calculation_settings.method` — matched exactly, because
     * that is the spelling the enum and the serialized `prayers_data` both use.
     *
     * Total and side-effect free: anything unrecognised (including an empty
     * string and adhan's unported `Other`) returns null rather than throwing or
     * guessing, so a caller can fall back to {@see moonsightingCommittee()},
     * the parameters every existing row was generated with.
     */
    public static function fromName(string $name): ?CalculationParameters
    {
        return match ($name) {
            'MuslimWorldLeague' => self::muslimWorldLeague(),
            'Egyptian' => self::egyptian(),
            'Karachi' => self::karachi(),
            'UmmAlQura' => self::ummAlQura(),
            'Dubai' => self::dubai(),
            'MoonsightingCommittee' => self::moonsightingCommittee(),
            'NorthAmerica' => self::northAmerica(),
            'Kuwait' => self::kuwait(),
            'Qatar' => self::qatar(),
            'Singapore' => self::singapore(),
            'Tehran' => self::tehran(),
            'Turkey' => self::turkey(),
            default => null,
        };
    }
}
