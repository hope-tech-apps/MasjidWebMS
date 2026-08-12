<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `SolarCoordinates.js`.
 */
final class SolarCoordinates
{
    /**
     * The declination of the sun: the angle between the rays of the Sun and
     * the plane of the Earth's equator, in degrees.
     */
    public readonly float $declination;

    /**
     * Right ascension of the Sun: the angular distance on the celestial
     * equator from the vernal equinox to the hour circle, in degrees.
     */
    public readonly float $rightAscension;

    /** Apparent sidereal time, the hour angle of the vernal equinox, in degrees. */
    public readonly float $apparentSiderealTime;

    public function __construct(float $julianDay)
    {
        $T = Astronomical::julianCentury($julianDay);
        $L0 = Astronomical::meanSolarLongitude($T);
        $Lp = Astronomical::meanLunarLongitude($T);
        $Omega = Astronomical::ascendingLunarNodeLongitude($T);
        $Lambda = MathUtils::degreesToRadians(Astronomical::apparentSolarLongitude($T, $L0));
        $Theta0 = Astronomical::meanSiderealTime($T);
        $dPsi = Astronomical::nutationInLongitude($L0, $Lp, $Omega);
        $dEpsilon = Astronomical::nutationInObliquity($L0, $Lp, $Omega);
        $Epsilon0 = Astronomical::meanObliquityOfTheEcliptic($T);
        $EpsilonApparent = MathUtils::degreesToRadians(Astronomical::apparentObliquityOfTheEcliptic($T, $Epsilon0));

        /* Equation from Astronomical Algorithms page 165 */
        $this->declination = MathUtils::radiansToDegrees(asin(sin($EpsilonApparent) * sin($Lambda)));

        /* Equation from Astronomical Algorithms page 165 */
        $this->rightAscension = MathUtils::unwindAngle(
            MathUtils::radiansToDegrees(atan2(cos($EpsilonApparent) * sin($Lambda), cos($Lambda)))
        );

        /* Equation from Astronomical Algorithms page 88 */
        $this->apparentSiderealTime = $Theta0 + $dPsi * 3600 * cos(MathUtils::degreesToRadians($Epsilon0 + $dEpsilon)) / 3600;
    }
}
