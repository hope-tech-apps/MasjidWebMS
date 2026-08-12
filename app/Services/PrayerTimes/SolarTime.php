<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `SolarTime.js`.
 *
 * All four public values are hours of UNIVERSAL time on the given civil date —
 * they can legitimately fall outside 0..24 (the sun may rise "yesterday" in UT
 * for a location east of Greenwich), which is why {@see TimeComponents} lets
 * the hour field overflow instead of clamping it.
 *
 * Upstream takes a JS `Date` and reads its LOCAL year/month/day. This port
 * takes the civil date components directly: the caller already knows which
 * calendar day it wants, and passing them explicitly removes any dependence on
 * the PHP process timezone.
 */
final class SolarTime
{
    public readonly float $approxTransit;
    public readonly float $transit;
    public readonly float $sunrise;
    public readonly float $sunset;

    private readonly SolarCoordinates $solar;
    private readonly SolarCoordinates $prevSolar;
    private readonly SolarCoordinates $nextSolar;

    /**
     * @param  int  $month  1-based.
     */
    public function __construct(
        int $year,
        int $month,
        int $day,
        private readonly float $latitude,
        private readonly float $longitude,
    ) {
        $julianDay = Astronomical::julianDay($year, $month, $day, 0);

        $this->solar = new SolarCoordinates($julianDay);
        $this->prevSolar = new SolarCoordinates($julianDay - 1);
        $this->nextSolar = new SolarCoordinates($julianDay + 1);

        $m0 = Astronomical::approximateTransit($longitude, $this->solar->apparentSiderealTime, $this->solar->rightAscension);
        $solarAltitude = -50.0 / 60.0;

        $this->approxTransit = $m0;
        $this->transit = Astronomical::correctedTransit(
            $m0,
            $longitude,
            $this->solar->apparentSiderealTime,
            $this->solar->rightAscension,
            $this->prevSolar->rightAscension,
            $this->nextSolar->rightAscension
        );
        $this->sunrise = $this->hourAngle($solarAltitude, false);
        $this->sunset = $this->hourAngle($solarAltitude, true);
    }

    public function hourAngle(float $angle, bool $afterTransit): float
    {
        return Astronomical::correctedHourAngle(
            $this->approxTransit,
            $angle,
            $this->latitude,
            $this->longitude,
            $afterTransit,
            $this->solar->apparentSiderealTime,
            $this->solar->rightAscension,
            $this->prevSolar->rightAscension,
            $this->nextSolar->rightAscension,
            $this->solar->declination,
            $this->prevSolar->declination,
            $this->nextSolar->declination
        );
    }

    public function afternoon(float $shadowLength): float
    {
        $tangent = abs($this->latitude - $this->solar->declination);
        $inverse = $shadowLength + tan(MathUtils::degreesToRadians($tangent));
        $angle = MathUtils::radiansToDegrees(atan(1.0 / $inverse));

        return $this->hourAngle($angle, true);
    }
}
