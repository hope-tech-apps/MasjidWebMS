<?php

namespace App\Services\PrayerTimes;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Builds the day-by-day prayer payloads the `prayers` table stores.
 *
 * This is the drop-in replacement for the `node resources/js/fetchPrayerTimes.js`
 * subprocess. Its output is byte-identical to what that script printed, because
 * the rows already in production are read by shipped mobile app builds and by
 * `SendDuePrayerNotifications`: same keys, same key ORDER, same UTC ISO-8601
 * strings, same `null` for a prayer that does not occur.
 *
 * See {@see PrayerTimes} for the timezone rationale — everything here is UTC.
 */
final class PrayerTimesGenerator
{
    /**
     * One payload per calendar day from $from to $to INCLUSIVE.
     *
     * Only the year/month/day of the two Carbon instances is read; their clock
     * time is irrelevant to the calculation and is never carried into the
     * output. An empty range (start after end) yields an empty list, which is
     * what the caller's date-range guard already assumes.
     *
     * @return list<array<string, mixed>>
     */
    public function forRange(
        string|int|float $latitude,
        string|int|float $longitude,
        CarbonInterface $from,
        CarbonInterface $to,
        ?CalculationParameters $params = null
    ): array {
        $params ??= CalculationMethod::moonsightingCommittee();

        $days = [];
        $cursor = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        // A range is capped rather than left unbounded: this runs inline on a
        // mobile request, and an accidental multi-year span would be a slow
        // request plus a very large insert.
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($last)) {
            if (++$guard > 3660) {
                throw new InvalidArgumentException('Prayer time range may not exceed ten years.');
            }

            $days[] = $this->forDate($latitude, $longitude, $cursor->year, $cursor->month, $cursor->day, $params);
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * @param  int  $month  1-based.
     * @return array<string, mixed>
     */
    public function forDate(
        string|int|float $latitude,
        string|int|float $longitude,
        int $year,
        int $month,
        int $day,
        ?CalculationParameters $params = null
    ): array {
        $params ??= CalculationMethod::moonsightingCommittee();

        $times = new PrayerTimes((float) $latitude, (float) $longitude, $year, $month, $day, $params);

        return [
            // Echoed back exactly as handed in — adhan-js's Coordinates object
            // stores the constructor arguments verbatim, and the values the
            // controller passes are the masjid's decimal columns as MySQL
            // renders them ("35.78056000"). Every stored row carries that
            // string form; normalising it here would change the payload the
            // apps already parse.
            'coordinates' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            // Midnight UTC of the day these times belong to. It is a LABEL for
            // the civil date, not a local instant — every row in the table has
            // carried a `T00:00:00.000Z` here, and the controller reads it back
            // with Carbon::parse()->format('Y-m-d') for the `date` column.
            'date' => sprintf('%04d-%02d-%02dT00:00:00.000Z', $year, $month, $day),
            'calculationParameters' => $params->toArray(),
            'fajr' => self::iso($times->fajr),
            'sunrise' => self::iso($times->sunrise),
            'dhuhr' => self::iso($times->dhuhr),
            'asr' => self::iso($times->asr),
            'sunset' => self::iso($times->sunset),
            'maghrib' => self::iso($times->maghrib),
            'isha' => self::iso($times->isha),
        ];
    }

    /**
     * `Date.prototype.toISOString()`: UTC, always with milliseconds, and null
     * for an Invalid Date (which is how `JSON.stringify` renders one).
     */
    private static function iso(?int $milliseconds): ?string
    {
        if ($milliseconds === null) {
            return null;
        }

        $seconds = (int) floor($milliseconds / 1000);
        $remainder = $milliseconds - $seconds * 1000;

        return gmdate('Y-m-d\TH:i:s', $seconds).'.'.str_pad((string) $remainder, 3, '0', STR_PAD_LEFT).'Z';
    }
}
