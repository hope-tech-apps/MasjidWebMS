<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `CalculationParameters.js`.
 *
 * This object is SERIALIZED VERBATIM into `prayers_data.calculationParameters`
 * — the property order in {@see toArray()} is the property order adhan-js
 * declares, because that is the key order every row already in the `prayers`
 * table carries. Changing it would rewrite a shape the mobile apps read.
 */
final class CalculationParameters
{
    /** Madhab to determine how Asr is calculated. */
    public string $madhab = Madhab::SHAFI;

    /**
     * Rule to determine the earliest time for Fajr and latest time for Isha,
     * needed for high latitude locations where they may not truly exist or may
     * present a hardship unless bound to a reasonable time.
     */
    public string $highLatitudeRule = HighLatitudeRule::MIDDLE_OF_THE_NIGHT;

    /** @var array{fajr:int,sunrise:int,dhuhr:int,asr:int,maghrib:int,isha:int} Manual adjustments in minutes. */
    public array $adjustments = ['fajr' => 0, 'sunrise' => 0, 'dhuhr' => 0, 'asr' => 0, 'maghrib' => 0, 'isha' => 0];

    /** @var array{fajr:int,sunrise:int,dhuhr:int,asr:int,maghrib:int,isha:int} Set by a calculation method; not manually modified. */
    public array $methodAdjustments = ['fajr' => 0, 'sunrise' => 0, 'dhuhr' => 0, 'asr' => 0, 'maghrib' => 0, 'isha' => 0];

    /**
     * Rule for resolving prayer times inside the Polar Circle. Pinned to
     * "Unresolved" — the adhan default, the value in every stored row, and the
     * one that makes an unreachable prayer serialize as null rather than being
     * invented from a neighbouring latitude.
     */
    public string $polarCircleResolution = 'Unresolved';

    /** How seconds are rounded when calculating prayer times. */
    public string $rounding = Rounding::NEAREST;

    /** Used by the MoonsightingCommittee method to determine how to calculate Isha. */
    public string $shafaq = Shafaq::GENERAL;

    public function __construct(
        /** Name of the method; drives the MoonsightingCommittee special cases. */
        public string $method,
        /** Angle of the sun below the horizon used for calculating Fajr. */
        public float $fajrAngle = 0,
        /** Angle of the sun below the horizon used for calculating Isha. */
        public float $ishaAngle = 0,
        /** Minutes after Maghrib for Isha; when > 0 the Isha angle is unused. */
        public float $ishaInterval = 0,
        /** Angle of the sun below the horizon used for calculating Maghrib. */
        public float $maghribAngle = 0,
    ) {
    }

    /**
     * @return array{fajr: float, isha: float}
     */
    public function nightPortions(): array
    {
        return HighLatitudeRule::nightPortions($this->highLatitudeRule, $this->fajrAngle, $this->ishaAngle);
    }

    /**
     * The exact JSON shape `JSON.stringify(CalculationParameters)` produces.
     *
     * The angle fields go out as ints when they are whole numbers so that
     * json_encode writes `18` and not `18.0` — adhan-js emits JS numbers, and
     * the stored rows read `"fajrAngle":18`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'madhab' => $this->madhab,
            'highLatitudeRule' => $this->highLatitudeRule,
            'adjustments' => $this->adjustments,
            'methodAdjustments' => $this->methodAdjustments,
            'polarCircleResolution' => $this->polarCircleResolution,
            'rounding' => $this->rounding,
            'shafaq' => $this->shafaq,
            'method' => $this->method,
            'fajrAngle' => self::number($this->fajrAngle),
            'ishaAngle' => self::number($this->ishaAngle),
            'ishaInterval' => self::number($this->ishaInterval),
            'maghribAngle' => self::number($this->maghribAngle),
        ];
    }

    private static function number(float $value): int|float
    {
        return $value == (int) $value ? (int) $value : $value;
    }
}
