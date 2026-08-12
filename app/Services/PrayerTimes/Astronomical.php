<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `Astronomical.js`.
 *
 * Equations are from Jean Meeus, "Astronomical Algorithms"; the page
 * references are carried over from upstream so the two can be read side by
 * side. Nothing in here is timezone-aware — every value is either an angle in
 * degrees or a UTC instant, which is the whole reason the calculator can be
 * driven by a bare civil date.
 */
final class Astronomical
{
    /** The geometric mean longitude of the sun in degrees. */
    public static function meanSolarLongitude(float $julianCentury): float
    {
        $T = $julianCentury;
        /* Equation from Astronomical Algorithms page 163 */
        $term1 = 280.4664567;
        $term2 = 36000.76983 * $T;
        $term3 = 0.0003032 * pow($T, 2);
        $L0 = $term1 + $term2 + $term3;

        return MathUtils::unwindAngle($L0);
    }

    /** The geometric mean longitude of the moon in degrees. */
    public static function meanLunarLongitude(float $julianCentury): float
    {
        $T = $julianCentury;
        /* Equation from Astronomical Algorithms page 144 */
        $term1 = 218.3165;
        $term2 = 481267.8813 * $T;
        $Lp = $term1 + $term2;

        return MathUtils::unwindAngle($Lp);
    }

    public static function ascendingLunarNodeLongitude(float $julianCentury): float
    {
        $T = $julianCentury;
        /* Equation from Astronomical Algorithms page 144 */
        $term1 = 125.04452;
        $term2 = 1934.136261 * $T;
        $term3 = 0.0020708 * pow($T, 2);
        $term4 = pow($T, 3) / 450000;
        $Omega = $term1 - $term2 + $term3 + $term4;

        return MathUtils::unwindAngle($Omega);
    }

    /** The mean anomaly of the sun. */
    public static function meanSolarAnomaly(float $julianCentury): float
    {
        $T = $julianCentury;
        /* Equation from Astronomical Algorithms page 163 */
        $term1 = 357.52911;
        $term2 = 35999.05029 * $T;
        $term3 = 0.0001537 * pow($T, 2);
        $M = $term1 + $term2 - $term3;

        return MathUtils::unwindAngle($M);
    }

    /** The Sun's equation of the center in degrees. */
    public static function solarEquationOfTheCenter(float $julianCentury, float $meanAnomaly): float
    {
        $T = $julianCentury;
        /* Equation from Astronomical Algorithms page 164 */
        $Mrad = MathUtils::degreesToRadians($meanAnomaly);
        $term1 = (1.914602 - 0.004817 * $T - 0.000014 * pow($T, 2)) * sin($Mrad);
        $term2 = (0.019993 - 0.000101 * $T) * sin(2 * $Mrad);
        $term3 = 0.000289 * sin(3 * $Mrad);

        return $term1 + $term2 + $term3;
    }

    /**
     * The apparent longitude of the Sun, referred to the true equinox of the
     * date.
     */
    public static function apparentSolarLongitude(float $julianCentury, float $meanLongitude): float
    {
        $T = $julianCentury;
        $L0 = $meanLongitude;
        /* Equation from Astronomical Algorithms page 164 */
        $longitude = $L0 + self::solarEquationOfTheCenter($T, self::meanSolarAnomaly($T));
        $Omega = 125.04 - 1934.136 * $T;
        $Lambda = $longitude - 0.00569 - 0.00478 * sin(MathUtils::degreesToRadians($Omega));

        return MathUtils::unwindAngle($Lambda);
    }

    /**
     * The mean obliquity of the ecliptic, formula adopted by the International
     * Astronomical Union. Represented in degrees.
     */
    public static function meanObliquityOfTheEcliptic(float $julianCentury): float
    {
        $T = $julianCentury;
        /* Equation from Astronomical Algorithms page 147 */
        $term1 = 23.439291;
        $term2 = 0.013004167 * $T;
        $term3 = 0.0000001639 * pow($T, 2);
        $term4 = 0.0000005036 * pow($T, 3);

        return $term1 - $term2 - $term3 + $term4;
    }

    /**
     * The mean obliquity of the ecliptic, corrected for calculating the
     * apparent position of the sun, in degrees.
     */
    public static function apparentObliquityOfTheEcliptic(float $julianCentury, float $meanObliquityOfTheEcliptic): float
    {
        $T = $julianCentury;
        $Epsilon0 = $meanObliquityOfTheEcliptic;
        /* Equation from Astronomical Algorithms page 165 */
        $O = 125.04 - 1934.136 * $T;

        return $Epsilon0 + 0.00256 * cos(MathUtils::degreesToRadians($O));
    }

    /** Mean sidereal time, the hour angle of the vernal equinox, in degrees. */
    public static function meanSiderealTime(float $julianCentury): float
    {
        $T = $julianCentury;
        /* Equation from Astronomical Algorithms page 165 */
        $JD = $T * 36525 + 2451545.0;
        $term1 = 280.46061837;
        $term2 = 360.98564736629 * ($JD - 2451545);
        $term3 = 0.000387933 * pow($T, 2);
        $term4 = pow($T, 3) / 38710000;
        $Theta = $term1 + $term2 + $term3 - $term4;

        return MathUtils::unwindAngle($Theta);
    }

    public static function nutationInLongitude(float $solarLongitude, float $lunarLongitude, float $ascendingNode): float
    {
        $L0 = $solarLongitude;
        $Lp = $lunarLongitude;
        $Omega = $ascendingNode;
        /* Equation from Astronomical Algorithms page 144 */
        $term1 = -17.2 / 3600 * sin(MathUtils::degreesToRadians($Omega));
        $term2 = 1.32 / 3600 * sin(2 * MathUtils::degreesToRadians($L0));
        $term3 = 0.23 / 3600 * sin(2 * MathUtils::degreesToRadians($Lp));
        $term4 = 0.21 / 3600 * sin(2 * MathUtils::degreesToRadians($Omega));

        return $term1 - $term2 - $term3 + $term4;
    }

    public static function nutationInObliquity(float $solarLongitude, float $lunarLongitude, float $ascendingNode): float
    {
        $L0 = $solarLongitude;
        $Lp = $lunarLongitude;
        $Omega = $ascendingNode;
        /* Equation from Astronomical Algorithms page 144 */
        $term1 = 9.2 / 3600 * cos(MathUtils::degreesToRadians($Omega));
        $term2 = 0.57 / 3600 * cos(2 * MathUtils::degreesToRadians($L0));
        $term3 = 0.1 / 3600 * cos(2 * MathUtils::degreesToRadians($Lp));
        $term4 = 0.09 / 3600 * cos(2 * MathUtils::degreesToRadians($Omega));

        return $term1 + $term2 + $term3 - $term4;
    }

    public static function altitudeOfCelestialBody(float $observerLatitude, float $declination, float $localHourAngle): float
    {
        $Phi = $observerLatitude;
        $delta = $declination;
        $H = $localHourAngle;
        /* Equation from Astronomical Algorithms page 93 */
        $term1 = sin(MathUtils::degreesToRadians($Phi)) * sin(MathUtils::degreesToRadians($delta));
        $term2 = cos(MathUtils::degreesToRadians($Phi)) * cos(MathUtils::degreesToRadians($delta)) * cos(MathUtils::degreesToRadians($H));

        return MathUtils::radiansToDegrees(asin($term1 + $term2));
    }

    public static function approximateTransit(float $longitude, float $siderealTime, float $rightAscension): float
    {
        $L = $longitude;
        $Theta0 = $siderealTime;
        $a2 = $rightAscension;
        /* Equation from page Astronomical Algorithms 102 */
        $Lw = $L * -1;
        $m0 = MathUtils::normalizeToScale(($a2 + $Lw - $Theta0) / 360, 1);

        // For locations near the International Date Line, normalizeWithBound can produce
        // an m0 for the wrong calendar date. We detect this by comparing m0 to a
        // generalized transit time based on the longitude. If they differ by more than
        // half a day, m0 is off by one cycle and we adjust in the correct direction.
        $expectedTransit = MathUtils::normalizeToScale((12.0 - $L / 15.0) / 24.0, 1);

        if ($m0 - $expectedTransit > 0.5) {
            return $m0 - 1.0;
        }

        if ($expectedTransit - $m0 > 0.5) {
            return $m0 + 1.0;
        }

        return $m0;
    }

    /** The time at which the sun is at its highest point in the sky (in universal time). */
    public static function correctedTransit(
        float $approximateTransit,
        float $longitude,
        float $siderealTime,
        float $rightAscension,
        float $previousRightAscension,
        float $nextRightAscension
    ): float {
        $m0 = $approximateTransit;
        $L = $longitude;
        $Theta0 = $siderealTime;
        $a2 = $rightAscension;
        $a1 = $previousRightAscension;
        $a3 = $nextRightAscension;
        /* Equation from page Astronomical Algorithms 102 */
        $Lw = $L * -1;
        $Theta = MathUtils::unwindAngle($Theta0 + 360.985647 * $m0);
        $a = MathUtils::unwindAngle(self::interpolateAngles($a2, $a1, $a3, $m0));
        $H = MathUtils::quadrantShiftAngle($Theta - $Lw - $a);
        $dm = $H / -360;

        return ($m0 + $dm) * 24;
    }

    /**
     * Returns NAN when the requested altitude is never reached on this day —
     * `acos` of an out-of-range ratio. adhan-js leans on that NaN to mean "no
     * such prayer time today", and so does this port.
     */
    public static function correctedHourAngle(
        float $approximateTransit,
        float $angle,
        float $latitude,
        float $longitude,
        bool $afterTransit,
        float $siderealTime,
        float $rightAscension,
        float $previousRightAscension,
        float $nextRightAscension,
        float $declination,
        float $previousDeclination,
        float $nextDeclination
    ): float {
        $m0 = $approximateTransit;
        $h0 = $angle;
        $Theta0 = $siderealTime;
        $a2 = $rightAscension;
        $a1 = $previousRightAscension;
        $a3 = $nextRightAscension;
        $d2 = $declination;
        $d1 = $previousDeclination;
        $d3 = $nextDeclination;

        /* Equation from page Astronomical Algorithms 102 */
        $Lw = $longitude * -1;
        $term1 = sin(MathUtils::degreesToRadians($h0)) - sin(MathUtils::degreesToRadians($latitude)) * sin(MathUtils::degreesToRadians($d2));
        $term2 = cos(MathUtils::degreesToRadians($latitude)) * cos(MathUtils::degreesToRadians($d2));
        $H0 = MathUtils::radiansToDegrees(acos($term1 / $term2));
        $m = $afterTransit ? $m0 + $H0 / 360 : $m0 - $H0 / 360;
        $Theta = MathUtils::unwindAngle($Theta0 + 360.985647 * $m);
        $a = MathUtils::unwindAngle(self::interpolateAngles($a2, $a1, $a3, $m));
        $delta = self::interpolate($d2, $d1, $d3, $m);
        $H = $Theta - $Lw - $a;
        $h = self::altitudeOfCelestialBody($latitude, $delta, $H);
        $term3 = $h - $h0;
        $term4 = 360 * cos(MathUtils::degreesToRadians($delta)) * cos(MathUtils::degreesToRadians($latitude)) * sin(MathUtils::degreesToRadians($H));
        $dm = $term3 / $term4;

        return ($m + $dm) * 24;
    }

    /**
     * Interpolation of a value given equidistant previous and next values and a
     * factor equal to the fraction of the interpolated point's time over the
     * time between values.
     */
    public static function interpolate(float $y2, float $y1, float $y3, float $n): float
    {
        /* Equation from Astronomical Algorithms page 24 */
        $a = $y2 - $y1;
        $b = $y3 - $y2;
        $c = $b - $a;

        return $y2 + $n / 2 * ($a + $b + $n * $c);
    }

    /** Interpolation of three angles, accounting for angle unwinding. */
    public static function interpolateAngles(float $y2, float $y1, float $y3, float $n): float
    {
        /* Equation from Astronomical Algorithms page 24 */
        $a = MathUtils::unwindAngle($y2 - $y1);
        $b = MathUtils::unwindAngle($y3 - $y2);
        $c = $b - $a;

        return $y2 + $n / 2 * ($a + $b + $n * $c);
    }

    /**
     * The Julian Day for the given Gregorian date components.
     *
     * @param  int  $month  1-based.
     */
    public static function julianDay(int $year, int $month, int $day, float $hours = 0): float
    {
        /* Equation from Astronomical Algorithms page 60 */
        $Y = MathUtils::trunc($month > 2 ? $year : $year - 1);
        $M = MathUtils::trunc($month > 2 ? $month : $month + 12);
        $D = $day + $hours / 24;
        $A = MathUtils::trunc($Y / 100);
        $B = MathUtils::trunc(2 - $A + MathUtils::trunc($A / 4));
        $i0 = MathUtils::trunc(365.25 * ($Y + 4716));
        $i1 = MathUtils::trunc(30.6001 * ($M + 1));

        return $i0 + $i1 + $D + $B - 1524.5;
    }

    /** Julian century from the epoch. */
    public static function julianCentury(float $julianDay): float
    {
        /* Equation from Astronomical Algorithms page 163 */
        return ($julianDay - 2451545.0) / 36525;
    }

    public static function seasonAdjustedMorningTwilight(float $latitude, int $dayOfYear, int $year, ?int $sunrise): ?int
    {
        $a = 75 + 28.65 / 55.0 * abs($latitude);
        $b = 75 + 19.44 / 55.0 * abs($latitude);
        $c = 75 + 32.74 / 55.0 * abs($latitude);
        $d = 75 + 48.1 / 55.0 * abs($latitude);

        $adjustment = self::twilightAdjustment(
            self::daysSinceSolstice($dayOfYear, $year, $latitude),
            $a,
            $b,
            $c,
            $d
        );

        return DateUtils::addSeconds($sunrise, MathUtils::round($adjustment * -60.0));
    }

    public static function seasonAdjustedEveningTwilight(float $latitude, int $dayOfYear, int $year, ?int $sunset, string $shafaq): ?int
    {
        if ($shafaq === Shafaq::AHMER) {
            $a = 62 + 17.4 / 55.0 * abs($latitude);
            $b = 62 - 7.16 / 55.0 * abs($latitude);
            $c = 62 + 5.12 / 55.0 * abs($latitude);
            $d = 62 + 19.44 / 55.0 * abs($latitude);
        } elseif ($shafaq === Shafaq::ABYAD) {
            $a = 75 + 25.6 / 55.0 * abs($latitude);
            $b = 75 + 7.16 / 55.0 * abs($latitude);
            $c = 75 + 36.84 / 55.0 * abs($latitude);
            $d = 75 + 81.84 / 55.0 * abs($latitude);
        } else {
            $a = 75 + 25.6 / 55.0 * abs($latitude);
            $b = 75 + 2.05 / 55.0 * abs($latitude);
            $c = 75 - 9.21 / 55.0 * abs($latitude);
            $d = 75 + 6.14 / 55.0 * abs($latitude);
        }

        $adjustment = self::twilightAdjustment(
            self::daysSinceSolstice($dayOfYear, $year, $latitude),
            $a,
            $b,
            $c,
            $d
        );

        return DateUtils::addSeconds($sunset, MathUtils::round($adjustment * 60.0));
    }

    /**
     * The piecewise-linear seasonal interpolation shared verbatim by both
     * MoonsightingCommittee twilight functions upstream.
     */
    private static function twilightAdjustment(int $dyy, float $a, float $b, float $c, float $d): float
    {
        if ($dyy < 91) {
            return $a + ($b - $a) / 91.0 * $dyy;
        }

        if ($dyy < 137) {
            return $b + ($c - $b) / 46.0 * ($dyy - 91);
        }

        if ($dyy < 183) {
            return $c + ($d - $c) / 46.0 * ($dyy - 137);
        }

        if ($dyy < 229) {
            return $d + ($c - $d) / 46.0 * ($dyy - 183);
        }

        if ($dyy < 275) {
            return $c + ($b - $c) / 46.0 * ($dyy - 229);
        }

        return $b + ($a - $b) / 91.0 * ($dyy - 275);
    }

    public static function daysSinceSolstice(int $dayOfYear, int $year, float $latitude): int
    {
        $northernOffset = 10;
        $southernOffset = DateUtils::isLeapYear($year) ? 173 : 172;
        $daysInYear = DateUtils::isLeapYear($year) ? 366 : 365;

        if ($latitude >= 0) {
            $daysSinceSolstice = $dayOfYear + $northernOffset;

            if ($daysSinceSolstice >= $daysInYear) {
                $daysSinceSolstice -= $daysInYear;
            }
        } else {
            $daysSinceSolstice = $dayOfYear - $southernOffset;

            if ($daysSinceSolstice < 0) {
                $daysSinceSolstice += $daysInYear;
            }
        }

        return $daysSinceSolstice;
    }
}
