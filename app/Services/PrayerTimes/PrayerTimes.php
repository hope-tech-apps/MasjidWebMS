<?php

namespace App\Services\PrayerTimes;

/**
 * A PHP port of the `adhan` JavaScript library (batoulapps/adhan-js v4.4.x),
 * covering everything `resources/js/fetchPrayerTimes.js` used to do in a
 * subprocess.
 *
 * WHY THIS EXISTS
 * ---------------
 * The mobile prayers endpoint used to build its times with
 * `shell_exec("node resources/js/fetchPrayerTimes.js ...")`. That failed in
 * production because the Node binary is not installed on the droplet:
 * shell_exec returned null, which is indistinguishable from "no prayer times",
 * and the endpoint 500'd. There is no PHP port of adhan published on Packagist
 * (no `batoulapps/adhan` package exists), so the algorithm is ported here —
 * pure PHP, installed by composer with everything else, and testable without a
 * subprocess.
 *
 * TIMEZONE
 * --------
 * Every value produced here is an ABSOLUTE UTC INSTANT, and the calculation
 * itself is timezone-free: adhan derives prayer times from a civil date plus a
 * latitude/longitude, never from a local clock. The masjid's `timezone` column
 * is deliberately NOT consulted. That is not an oversight — the stored
 * `prayers_data` has always held UTC ISO-8601 strings, and
 * `SendDuePrayerNotifications` parses them as UTC to decide when to push.
 * Localising them here would shift every prayer time by the UTC offset while
 * still looking plausible.
 *
 * INVALID TIMES
 * -------------
 * Inside the polar circles the sun may never reach a given altitude, and
 * adhan-js expresses that as an Invalid Date which `JSON.stringify` writes as
 * `null`. This port carries the same meaning as a PHP `null`, so a Tromsø row
 * serializes identically to what the JS produced.
 */
final class PrayerTimes
{
    public readonly ?int $fajr;
    public readonly ?int $sunrise;
    public readonly ?int $dhuhr;
    public readonly ?int $asr;
    public readonly ?int $sunset;
    public readonly ?int $maghrib;
    public readonly ?int $isha;

    /**
     * @param  int  $month  1-based.
     */
    public function __construct(
        float $latitude,
        float $longitude,
        int $year,
        int $month,
        int $day,
        CalculationParameters $params,
    ) {
        $solarTime = new SolarTime($year, $month, $day, $latitude, $longitude);

        $dhuhrTime = TimeComponents::utcInstant($solarTime->transit, $year, $month, $day);
        $sunriseTime = TimeComponents::utcInstant($solarTime->sunrise, $year, $month, $day);
        $sunsetTime = TimeComponents::utcInstant($solarTime->sunset, $year, $month, $day);

        // `polarCircleResolution` is pinned to Unresolved (see
        // CalculationParameters), which is upstream's no-op branch: unreachable
        // prayers stay unresolved rather than being borrowed from a nearby
        // latitude. The resolver branch is therefore not ported.

        [$tomorrowYear, $tomorrowMonth, $tomorrowDay] = self::nextCivilDay($year, $month, $day);
        $tomorrowSolarTime = new SolarTime($tomorrowYear, $tomorrowMonth, $tomorrowDay, $latitude, $longitude);

        $asrTime = TimeComponents::utcInstant(
            $solarTime->afternoon(Madhab::shadowLength($params->madhab)),
            $year,
            $month,
            $day
        );

        $tomorrowSunrise = TimeComponents::utcInstant($tomorrowSolarTime->sunrise, $tomorrowYear, $tomorrowMonth, $tomorrowDay);
        $night = ($tomorrowSunrise === null || $sunsetTime === null)
            ? null
            : ($tomorrowSunrise - $sunsetTime) / 1000;

        $dayOfYear = DateUtils::dayOfYear($year, $month, $day);

        $fajrTime = TimeComponents::utcInstant($solarTime->hourAngle(-1 * $params->fajrAngle, false), $year, $month, $day);

        // Special case for the Moonsighting Committee above latitude 55.
        if ($params->method === 'MoonsightingCommittee' && $latitude >= 55) {
            $fajrTime = DateUtils::addSeconds($sunriseTime, $night === null ? null : -($night / 7));
        }

        if ($params->method === 'MoonsightingCommittee') {
            $safeFajr = Astronomical::seasonAdjustedMorningTwilight($latitude, $dayOfYear, $year, $sunriseTime);
        } else {
            $portion = $params->nightPortions()['fajr'];
            $safeFajr = DateUtils::addSeconds($sunriseTime, $night === null ? null : -($portion * $night));
        }

        // Mirrors `isNaN(fajrTime) || safeFajr > fajrTime` — a NaN safeFajr
        // never wins a comparison, but a NaN fajrTime always yields to it.
        if ($fajrTime === null || ($safeFajr !== null && $safeFajr > $fajrTime)) {
            $fajrTime = $safeFajr;
        }

        if ($params->ishaInterval > 0) {
            $ishaTime = DateUtils::addMinutes($sunsetTime, $params->ishaInterval);
        } else {
            $ishaTime = TimeComponents::utcInstant($solarTime->hourAngle(-1 * $params->ishaAngle, true), $year, $month, $day);

            // Special case for the Moonsighting Committee above latitude 55.
            if ($params->method === 'MoonsightingCommittee' && $latitude >= 55) {
                $ishaTime = DateUtils::addSeconds($sunsetTime, $night === null ? null : $night / 7);
            }

            if ($params->method === 'MoonsightingCommittee') {
                $safeIsha = Astronomical::seasonAdjustedEveningTwilight($latitude, $dayOfYear, $year, $sunsetTime, $params->shafaq);
            } else {
                $portion = $params->nightPortions()['isha'];
                $safeIsha = DateUtils::addSeconds($sunsetTime, $night === null ? null : $portion * $night);
            }

            if ($ishaTime === null || ($safeIsha !== null && $safeIsha < $ishaTime)) {
                $ishaTime = $safeIsha;
            }
        }

        $maghribTime = $sunsetTime;

        if ($params->maghribAngle > 0) {
            $angleBasedMaghrib = TimeComponents::utcInstant(
                $solarTime->hourAngle(-1 * $params->maghribAngle, true),
                $year,
                $month,
                $day
            );

            if ($angleBasedMaghrib !== null
                && $sunsetTime !== null && $sunsetTime < $angleBasedMaghrib
                && $ishaTime !== null && $ishaTime > $angleBasedMaghrib
            ) {
                $maghribTime = $angleBasedMaghrib;
            }
        }

        $rounding = $params->rounding;

        $this->fajr = DateUtils::roundedMinute(DateUtils::addMinutes($fajrTime, self::adjustment($params, 'fajr')), $rounding);
        $this->sunrise = DateUtils::roundedMinute(DateUtils::addMinutes($sunriseTime, self::adjustment($params, 'sunrise')), $rounding);
        $this->dhuhr = DateUtils::roundedMinute(DateUtils::addMinutes($dhuhrTime, self::adjustment($params, 'dhuhr')), $rounding);
        $this->asr = DateUtils::roundedMinute(DateUtils::addMinutes($asrTime, self::adjustment($params, 'asr')), $rounding);
        $this->sunset = DateUtils::roundedMinute($sunsetTime, $rounding);
        $this->maghrib = DateUtils::roundedMinute(DateUtils::addMinutes($maghribTime, self::adjustment($params, 'maghrib')), $rounding);
        $this->isha = DateUtils::roundedMinute(DateUtils::addMinutes($ishaTime, self::adjustment($params, 'isha')), $rounding);
    }

    private static function adjustment(CalculationParameters $params, string $prayer): float
    {
        return ($params->adjustments[$prayer] ?? 0) + ($params->methodAdjustments[$prayer] ?? 0);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function nextCivilDay(int $year, int $month, int $day): array
    {
        $tomorrow = DateUtils::utcMidnight($year, $month, $day + 1) / 1000;

        return [
            (int) gmdate('Y', $tomorrow),
            (int) gmdate('n', $tomorrow),
            (int) gmdate('j', $tomorrow),
        ];
    }
}
