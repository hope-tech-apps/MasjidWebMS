<?php

namespace App\Support;

use App\Models\IqamaTimeSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The one place the server decides when a prayer's iqama is.
 *
 * THE RULE (tests/fixtures/iqama-resolution.json, which iOS, tvOS and Android
 * also assert against): for each prayer, if the masjid is on Specific Time
 * Ranges AND a range for that prayer covers the prayer's day (both ends
 * inclusive, the day as the masjid's own calendar reads it), the iqama is that
 * range's clock time on that day in the masjid's zone. Otherwise it is the adhan
 * plus that prayer's offset. The two mix within one day and one masjid: MEC runs
 * fixed Dhuhr/Asr/Isha with Fajr +20 and Maghrib +5, and when its last range
 * ends (the clocks go back on 2026-11-01) every prayer quietly returns to its
 * offset.
 *
 * WHY ONE CLASS. The server had three copies of this rule and they disagreed:
 * the website payload (IqamaTimeSettingResource) read the ranges but not the
 * mode, the dark-device push (prayers:send-due) and the stored
 * `prayers.iqama_times_data` read neither and used adhan + offset only. So a
 * masjid on fixed times got "the iqama has arrived" pushes at adhan + offset,
 * minutes away from the time on its own website and app. Every consumer now asks
 * this class.
 *
 * WHAT IS NOT DECIDED HERE: whether a masjid has iqama settings at all. A masjid
 * with no row resolves to offsets of 0 (iqama == adhan), which is what the stored
 * column has always said for it; the backstop push, separately, has never sent
 * anything for such a masjid and still does not.
 *
 * ONE DELIBERATE EXCEPTION, the website payload: it keeps showing a stored range
 * for a masjid on Minutes After Adhan (coveringTime), as it always has, because
 * live organisations on Minutes After Adhan must see byte-identical times until
 * the owner decides otherwise (DECISIONS.md, 2026-09-25 iqama follow-up).
 */
final class IqamaResolver
{
    public const PRAYERS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];

    /**
     * Names that all mean UTC. `masjids.timezone` was added with a default of
     * 'UTC' for every masjid that existed then, so for a masjid these read as
     * "never set", not as a place on the prime meridian (a real masjid there is
     * Europe/London or Africa/Abidjan, which follow their own clocks).
     */
    private const UTC_NAMES = [
        'UTC', 'Etc/UTC', 'Etc/UCT', 'UCT', 'Etc/Universal', 'Universal', 'Etc/Zulu', 'Zulu',
        'GMT', 'Etc/GMT', 'Etc/GMT0', 'Etc/GMT+0', 'Etc/GMT-0', 'GMT0', 'GMT+0', 'GMT-0',
        'Etc/Greenwich', 'Greenwich',
    ];

    /** @var array<string, true>|null valid IANA identifiers, built once per process */
    private static ?array $zones = null;

    private function __construct(
        private readonly ?IqamaTimeSetting $setting,
        private readonly string $zone,
        private readonly bool $zoneIsTheMasjids,
    ) {
    }

    /**
     * A resolver for one masjid's settings in that masjid's zone.
     *
     * The ranges are read from the `timeRanges` relation, so a caller resolving
     * many days or masjids should eager-load it (`iqamaTimeSettings.timeRanges`).
     */
    public static function for(?IqamaTimeSetting $setting, ?string $zone): self
    {
        $resolved = self::zone($zone);

        return new self($setting, $resolved, $resolved === (string) $zone && ! in_array($resolved, self::UTC_NAMES, true));
    }

    /**
     * The zone a masjid's calendar day is read in: its own, when it is a real
     * IANA identifier. A blank or unknown zone falls back to the app's (UTC)
     * rather than throwing, because every caller here is a public read or a
     * minute-by-minute push loop where one bad row must not take down the rest.
     * Offsets such as "+05:00" and abbreviations are refused on purpose: they do
     * not follow daylight saving, which is the one thing a range boundary needs.
     */
    public static function zone(?string $zone): string
    {
        self::$zones ??= array_fill_keys(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);

        $zone = (string) $zone;

        return $zone !== '' && isset(self::$zones[$zone])
            ? $zone
            : (string) config('app.timezone', 'UTC');
    }

    public function timezone(): string
    {
        return $this->zone;
    }

    /**
     * Today's date in the masjid's zone, as Y-m-d.
     *
     * For a New York masjid the UTC date turns over at 8 PM EDT, exactly when
     * Isha is prayed, so "today" is never the server's date.
     */
    public function today(): string
    {
        return Carbon::now($this->zone)->format('Y-m-d');
    }

    /**
     * Whether the masjid is on Specific Time Ranges.
     *
     * Read from the raw attribute rather than through the enum cast: a stored
     * value the enum does not know makes the cast throw, and this runs inside
     * the every-minute push loop for every masjid. An unknown mode reads as
     * offsets, which is what that loop always did.
     */
    public function usesRanges(): bool
    {
        return ($this->setting?->getAttributes()['iqama_type'] ?? null) === 'specific_time_ranges';
    }

    /**
     * Whether a fixed clock time can be turned into an instant for this masjid.
     *
     * Only in the masjid's own zone. When its `timezone` is blank, unknown or a
     * name for UTC (the column's default for every masjid that predates it), a
     * fixed 1:45 PM would be placed at 13:45 UTC, hours away from the 1:45 PM its
     * website prints, so iqamaAt() keeps adhan + offset instead: what the push and
     * the stored column did before they read ranges at all. The push logs it.
     */
    public function placesFixedTimes(): bool
    {
        return $this->zoneIsTheMasjids;
    }

    /**
     * The fixed clock time ("HH:MM:SS" as stored) that governs $salah on $day, or
     * null when the masjid is not on ranges or none covers that day.
     */
    public function fixedTime(string $salah, string $day): ?string
    {
        return $this->usesRanges() ? $this->coveringTime($salah, $day) : null;
    }

    /**
     * The stored range time covering $salah on $day, WHATEVER the mode.
     *
     * Only the website payload asks this: it has always shown a covering range
     * even for a masjid on Minutes After Adhan (the apps and the push honour the
     * mode through fixedTime()), and that stays byte-identical until the owner
     * decides otherwise. Everything else asks fixedTime().
     *
     * $day is the prayer's own day in the masjid's calendar, Y-m-d. The first
     * covering range in relation order wins, as it does on every client. Dates
     * compare as Y-m-d strings, both ends inclusive: comparing instants instead
     * drops the last day of every range for a masjid west of UTC, since the
     * stored dates read as midnight UTC.
     */
    public function coveringTime(string $salah, string $day): ?string
    {
        if ($this->setting === null) {
            return null;
        }

        $range = $this->setting->timeRanges
            ->where('salah', $salah)
            ->first(function ($range) use ($day) {
                $start = Carbon::parse($range->start_date)->format('Y-m-d');
                $end = Carbon::parse($range->end_date)->format('Y-m-d');

                return $start <= $day && $day <= $end;
            });

        return $range ? (string) $range->specific_time : null;
    }

    /**
     * The iqama instant for $salah on its day, in UTC, or null when the prayer
     * itself does not occur (polar latitudes; see PrayersController::iqamaTimes).
     *
     * A fixed time is that clock time on $day in the masjid's zone, so it stays
     * 1:45 PM on the wall across a daylight-saving change. With no zone of the
     * masjid's own to place it in, the offset applies (placesFixedTimes).
     *
     * The offset path adds minutes to the adhan instant exactly as the two old
     * copies did, so a masjid on Minutes After Adhan resolves to the same
     * instant, byte for byte.
     */
    public function iqamaAt(string $salah, string $day, CarbonInterface|string|null $adhan): ?Carbon
    {
        if ($adhan === null) {
            return null;
        }

        $fixed = $this->placesFixedTimes() ? $this->fixedTime($salah, $day) : null;

        if ($fixed !== null) {
            return Carbon::parse("{$day} {$fixed}", $this->zone)->utc();
        }

        return Carbon::parse($adhan)->utc()->addMinutes($this->offset($salah));
    }

    /** The per-prayer offset in minutes; a missing row or column is 0. */
    public function offset(string $salah): int
    {
        return (int) ($this->setting?->{$salah} ?? 0);
    }
}
