<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Support\Carbon;

/**
 * Translates between the two clocks a masjid admin lives between.
 *
 * Datetime columns are stored as UTC instants (config('app.timezone') is UTC and
 * every comparison — MealMenu::scopeCurrentlyOpen, for one — runs against now()).
 * But an admin filling in "ordering closes at" is reading a wall clock in the
 * masjid's own timezone, and an <input type="datetime-local"> submits a NAIVE
 * string with no offset at all. Handing that string straight to Eloquent stores
 * a New York 11:00 as 11:00 UTC and closes ordering four hours early.
 *
 * The rule, applied in both directions:
 *   - a naive string ("2026-09-11T11:00") is the masjid's wall clock
 *   - a string carrying its own offset ("...Z", "...-04:00") is already absolute
 *
 * The second case needs no special handling: PHP's DateTime ignores the fallback
 * timezone whenever the input carries an offset, so Carbon::parse($v, $tz) is
 * correct for both. That behaviour is load-bearing here, so it is covered by a test.
 */
final class MasjidTime
{
    /**
     * The masjid's timezone, falling back to the app's.
     *
     * Masjids created before the timezone column existed, and any id that no
     * longer resolves, fall back to config('app.timezone') so callers always get
     * a usable zone rather than an exception.
     */
    public static function zoneFor(int|string|null $masjidId): string
    {
        $tz = $masjidId === null
            ? null
            : Masjid::withoutGlobalScopes()->whereKey($masjidId)->value('timezone');

        return $tz ?: (string) config('app.timezone', 'UTC');
    }

    /**
     * A submitted datetime -> the UTC string Eloquent should store.
     *
     * Returns null for null/empty so a cleared optional field stays cleared.
     */
    public static function toUtc(mixed $value, string $tz): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, $tz)->utc()->format('Y-m-d H:i:s');
    }

    /**
     * A stored UTC instant -> the value a datetime-local input expects.
     *
     * datetime-local accepts no offset and no seconds, so this is deliberately
     * 'Y-m-d\TH:i' — anything longer is silently rejected by the browser.
     */
    public static function toLocalInput(mixed $value, string $tz): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone($tz)->format('Y-m-d\TH:i');
    }
}
