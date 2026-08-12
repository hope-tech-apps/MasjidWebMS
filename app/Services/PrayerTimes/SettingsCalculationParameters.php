<?php

namespace App\Services\PrayerTimes;

use App\Models\PrayerCalculationSetting;
use BackedEnum;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a masjid's stored `prayer_calculation_settings` row into the
 * {@see CalculationParameters} the prayer-time port actually calculates with.
 *
 * WHY THIS EXISTS
 * ---------------
 * The settings row was already being SERVED to the mobile apps
 * (`PrayersController::prayersSettings()`), which run adhan locally against it,
 * but the server's own cached `prayers` rows were generated with a hardcoded
 * MoonsightingCommittee. A masjid on any other method therefore saw one set of
 * times in the app and a different set in the cached rows — and in
 * {@see \App\Console\Commands\SendDuePrayerNotifications}, which pushes adhan
 * and iqama reminders off exactly those rows. This mapper is the single place
 * where a stored setting becomes calculation parameters, so both sides can no
 * longer drift.
 *
 * TWO ALPHABETS
 * -------------
 * The stored strings are the backed values of {@see \App\Enums\PrayerCalculationMethod},
 * {@see \App\Enums\Madhab} and {@see \App\Enums\HighLatitudeRule} — StudlyCase,
 * e.g. `MoonsightingCommittee` / `Shafi` / `MiddleOfTheNight`. The port's own
 * constants are adhan-js's lowercase SERIALIZED forms — `shafi`,
 * `middleofthenight` — and those lowercase values are what ends up in
 * `prayers_data.calculationParameters`. The method name is the one field that
 * crosses unchanged: adhan echoes it verbatim, so `method` stays StudlyCase in
 * the payload. Mixing the two alphabets up is precisely the mistake this class
 * is meant to make impossible, and the direction assertions in
 * tests/Feature/PrayerCalculationSettingsWiringTest catch a swapped pair.
 *
 * IT IS TOTAL — IT NEVER THROWS
 * -----------------------------
 * Every branch has an answer. An absent relation, a null column, a string that
 * matches no case, even a failure inside the logging call itself all degrade to
 * the HISTORICAL DEFAULT — MoonsightingCommittee / Shafi / MiddleOfTheNight —
 * which is what every row currently in the `prayers` table was generated with.
 * That is not politeness: these parameters are reached from a public,
 * unauthenticated mobile endpoint and from a per-minute cron push. A throw
 * there is a 500 on a cache-filling path or a silently dead notification
 * backstop. Degrading to the default is visible in the logs and produces the
 * times the masjid was already getting; picking a "nearest" method silently
 * would produce plausible, wrong times, which is worse than either.
 *
 * @see CalculationMethod::fromName() for the method-name half of the table.
 */
final class SettingsCalculationParameters
{
    /**
     * The stored strings that reproduce today's behaviour exactly: the DB
     * defaults of `prayer_calculation_settings`, the values
     * `PrayersController::prayersSettings()` serves when the relation is
     * missing, and the parameters every existing `prayers` row was generated
     * with. A masjid on these — or with no row at all — must regenerate
     * byte-identically.
     *
     * Keyed by column name so a caller comparing "before" against "no row"
     * can use it directly.
     *
     * @var array{method: string, madhab: string, high_latitude_rule: string}
     */
    public const DEFAULTS = [
        'method' => 'MoonsightingCommittee',
        'madhab' => 'Shafi',
        'high_latitude_rule' => 'MiddleOfTheNight',
    ];

    /**
     * @param  PrayerCalculationSetting|null  $settings  The masjid's row, or null
     *         when the hasOne relation is absent. Pass it EAGER-LOADED; this
     *         method is called once per generation run but the caller sits in a
     *         loop over masjids in some paths.
     */
    public static function fromSetting(?PrayerCalculationSetting $settings): CalculationParameters
    {
        $params = self::method($settings);

        // Assigned after the method is chosen, exactly as adhan-js does it: the
        // method factory sets angles and adjustments, and madhab /
        // highLatitudeRule are then overwritten on the returned object. Each
        // accessor hands back a fresh instance, so mutating it is safe.
        $params->madhab = self::madhab($settings);
        $params->highLatitudeRule = self::highLatitudeRule($settings);

        return $params;
    }

    /**
     * The settings AS THEY WILL ACTUALLY BE APPLIED, in the stored
     * `App\Enums\*` string form — i.e. after fallbacks have been resolved.
     *
     * This exists so `/prayers/settings` can tell a client what the server is
     * really generating with, rather than echoing the raw row back. The two must
     * agree: a client calculating locally from a value the server then rejects
     * and falls back on would recreate, one level down, exactly the app-vs-server
     * divergence this whole class exists to remove. A masjid whose stored method
     * is unusable is calculated as MoonsightingCommittee, so that is what the
     * endpoint reports.
     *
     * It is DERIVED from fromSetting() rather than re-validating the columns, so
     * the two answers cannot drift apart. The adhan method name is used directly
     * because all 12 CalculationMethod names are byte-equal to their
     * PrayerCalculationMethod backed values.
     *
     * @return array{method: string, madhab: string, high_latitude_rule: string}
     */
    public static function effectiveTriple(?PrayerCalculationSetting $settings): array
    {
        $params = self::fromSetting($settings);

        return [
            'method' => $params->method,
            'madhab' => $params->madhab === Madhab::HANAFI ? 'Hanafi' : 'Shafi',
            'high_latitude_rule' => match ($params->highLatitudeRule) {
                HighLatitudeRule::SEVENTH_OF_THE_NIGHT => 'SeventhOfTheNight',
                HighLatitudeRule::TWILIGHT_ANGLE => 'TwilightAngle',
                default => 'MiddleOfTheNight',
            },
        ];
    }

    private static function method(?PrayerCalculationSetting $settings): CalculationParameters
    {
        $stored = self::stored($settings, 'method');
        $params = $stored === null ? null : CalculationMethod::fromName($stored);

        if ($params === null) {
            self::reportUnusable($settings, 'method', $stored, self::DEFAULTS['method']);

            return CalculationMethod::moonsightingCommittee();
        }

        return $params;
    }

    private static function madhab(?PrayerCalculationSetting $settings): string
    {
        $stored = self::stored($settings, 'madhab');

        $mapped = match ($stored) {
            'Shafi' => Madhab::SHAFI,
            'Hanafi' => Madhab::HANAFI,
            default => null,
        };

        if ($mapped === null) {
            self::reportUnusable($settings, 'madhab', $stored, self::DEFAULTS['madhab']);

            return Madhab::SHAFI;
        }

        return $mapped;
    }

    private static function highLatitudeRule(?PrayerCalculationSetting $settings): string
    {
        $stored = self::stored($settings, 'high_latitude_rule');

        $mapped = match ($stored) {
            'MiddleOfTheNight' => HighLatitudeRule::MIDDLE_OF_THE_NIGHT,
            'SeventhOfTheNight' => HighLatitudeRule::SEVENTH_OF_THE_NIGHT,
            'TwilightAngle' => HighLatitudeRule::TWILIGHT_ANGLE,
            default => null,
        };

        if ($mapped === null) {
            self::reportUnusable($settings, 'high_latitude_rule', $stored, self::DEFAULTS['high_latitude_rule']);

            return HighLatitudeRule::MIDDLE_OF_THE_NIGHT;
        }

        return $mapped;
    }

    /**
     * Read one column as the plain stored string, whatever state the model is in.
     *
     * THE ENUM CAST IS THE INTERESTING PART. `PrayerCalculationSetting` casts
     * these three columns to backed enums, and Laravel resolves an enum cast
     * with `BackedEnum::from()` (HasAttributes::getEnumCaseFromValue) — which
     * raises a ValueError for a value that is not a case, rather than returning
     * null. So `$settings->method` is a PrayerCalculationMethod on a healthy row
     * and a THROW on a corrupt one.
     *
     * That cast is applied LAZILY, on attribute access — hydration
     * (`setRawAttributes`) stores the raw column untouched. A masjid whose row
     * holds a bad string therefore loads fine and detonates here, which is why
     * this catches and then re-reads `getAttributes()`: that array is the raw,
     * never-cast column value, so it cannot throw. It also reflects an unsaved
     * in-memory assignment, because Laravel's enum SETTER stores the backing
     * value, not the object.
     *
     * (A bad string cannot get in through the model at all — the setter runs the
     * same `from()` and would throw on assignment. It gets in through raw SQL, a
     * migration default that drifted from the enum, or a column written before a
     * case was renamed. That is exactly how the tests plant one: DB::table()
     * insert, bypassing Eloquent.)
     */
    private static function stored(?PrayerCalculationSetting $settings, string $column): ?string
    {
        if ($settings === null) {
            return self::DEFAULTS[$column];
        }

        try {
            $value = $settings->getAttribute($column);
        } catch (Throwable $e) {
            $value = $settings->getAttributes()[$column] ?? null;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_string($value)) {
            return $value;
        }

        // int/float can only arrive from a mangled column; stringify it so the
        // warning names the actual offending value instead of "null".
        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * Record the degradation. Wrapped because the whole point of this class is
     * that it cannot throw — a container that has no log channel bound (or a
     * disk that will not take the write) must not turn a prayer-time
     * calculation into a 500.
     */
    private static function reportUnusable(
        ?PrayerCalculationSetting $settings,
        string $column,
        ?string $stored,
        string $fallback
    ): void {
        try {
            Log::warning('Unusable prayer calculation setting; falling back to the historical default.', [
                'masjid_id' => $settings?->getAttributes()['masjid_id'] ?? null,
                'prayer_calculation_setting_id' => $settings?->getKey(),
                'column' => $column,
                'stored' => $stored,
                'fallback' => $fallback,
            ]);
        } catch (Throwable $e) {
            // Deliberately swallowed. See the method docblock.
        }
    }
}
