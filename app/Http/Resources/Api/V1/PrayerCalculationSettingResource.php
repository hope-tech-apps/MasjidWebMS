<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PrayerCalculationSetting;
use App\Services\PrayerTimes\SettingsCalculationParameters;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The masjid's prayer calculation settings, as served to the web surface
 * (`GET /api/v1/settings`, App\Http\Controllers\Api\V1\SettingController).
 *
 * ## It reports the EFFECTIVE settings, not the stored row
 *
 * This used to read `$this->method->value` — i.e. through the model's enum
 * casts. Laravel resolves an enum cast with `BackedEnum::from()`
 * (HasAttributes::getEnumCaseFromValue), which raises a `ValueError` rather than
 * returning null for a string that matches no case, and the cast is applied
 * LAZILY on attribute access. So a masjid whose row holds a bad value hydrated
 * fine and detonated here — taking down the entire `/api/v1/settings` payload
 * (masjid details, theme, features, iqama, jumaa) over one bad column, on a
 * public read.
 *
 * A bad string cannot be written through the model — the enum SETTER runs the
 * same `from()` — so it arrives by raw SQL, a migration default that drifted
 * from the enum, or a column written before a case was renamed.
 *
 * {@see SettingsCalculationParameters::effectiveTriple()} is total: it reads the
 * raw columns and degrades an unusable one to the historical default
 * (MoonsightingCommittee / Shafi / MiddleOfTheNight). Reporting the effective
 * values is also the CORRECT answer independent of the crash — a client
 * calculating locally from a value the server rejects and falls back on would
 * compute times the server never generated. That is the same reasoning behind
 * `PrayersController::prayersSettings()`, and this resource now agrees with it.
 *
 * Shape and key order are unchanged: method / madhab / high_latitude_rule, in
 * the same `App\Enums\*` string alphabet a healthy row already produced.
 */
class PrayerCalculationSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = $this->resource;

        return SettingsCalculationParameters::effectiveTriple(
            $settings instanceof PrayerCalculationSetting ? $settings : null
        );
    }
}
