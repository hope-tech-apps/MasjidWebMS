<?php

namespace App\Http\Requests\Admin\Onboarding;

use App\Enums\HighLatitudeRule;
use App\Enums\Madhab;
use App\Enums\PrayerCalculationMethod;
use App\Http\Requests\BaseFormRequest;
use App\Models\City;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Validation for the Super-Admin masjid onboarding wizard's single provision
 * call. Extends BaseFormRequest so a validation failure throws an
 * HttpResponseException with the legacy { status:'failed', data:<errors> }
 * envelope — NEVER a raw ValidationException (this app's JSON handler 500s on
 * that). Route middleware (auth:sanctum + admin + super) enforces that only a
 * SuperAdmin reaches here.
 *
 * The wizard posts one nested payload; axios serializes the plain object to
 * multipart form-data (bracketed keys), which Laravel re-parses into the nested
 * arrays these dot-notation rules validate. Identity rules mirror
 * StoreMasjidRequest; prayer/theme rules mirror their dedicated save requests.
 */
class ProvisionMasjidRequest extends BaseFormRequest
{
    public function rules(): array
    {
        // Hex color, optional alpha — same shape as SaveThemeSettingsRequest.
        $hex = ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', 'max:9'];

        return [
            // ---- Identity (mirrors StoreMasjidRequest) ----
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:masjids,email',
            'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
            'address' => 'required|string',
            'latitude' => 'required|numeric|min:-90|max:90',
            'longitude' => 'required|numeric|min:-180|max:180',
            'timezone' => 'required|string|timezone',
            'country_id' => 'required|exists:countries,id',
            'city_id' => [
                'required',
                'exists:cities,id',
                function ($attribute, $value, $fail) {
                    $city = City::where('id', $value)
                        ->where('country_id', $this->input('country_id'))
                        ->exists();
                    if (!$city) {
                        $fail('The selected city does not belong to the given country.');
                    }
                },
            ],
            'user_id' => [
                'nullable',
                'exists:users,id',
                'unique:masjids,user_id',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $user = User::where('id', $value)->where('type', 'MasjidAdmin')->first();
                    if (!$user) {
                        $fail('The selected user is not of a Masjid Admin type.');
                    }
                },
            ],

            // ---- Donation link (optional; mirrors SaveDonationLinkRequest) ----
            'donation_link' => 'nullable|url',
            'donation_title' => 'nullable|string|max:255',
            'donation_message' => 'nullable|string|max:255',

            // ---- Social links (optional; stored as MasjidSocialMediaLink) ----
            'facebook_url' => 'nullable|string|max:255',
            'youtube_url' => 'nullable|string|max:255',
            'instagram_url' => 'nullable|string|max:255',
            'whatsapp_url' => 'nullable|string|max:255',
            'whatsapp_number' => 'nullable|string|max:255',

            // ---- Prayer calculation (mirrors SavePrayerCalculationSettingsRequest) ----
            'method' => ['required', 'string', Rule::in(array_column(PrayerCalculationMethod::cases(), 'value'))],
            'madhab' => ['required', 'string', Rule::in(array_column(Madhab::cases(), 'value'))],
            'high_latitude_rule' => ['required', 'string', Rule::in(array_column(HighLatitudeRule::cases(), 'value'))],

            // ---- Iqama (offsets in minutes after adhan) ----
            // The wizard configures the "minutes after adhan" model; the richer
            // fixed-time-range model is set post-onboarding in the dedicated Iqama
            // screen. iqama_type is still recorded so the app knows which model.
            'iqama_type' => ['nullable', 'string', Rule::in(['minutes_after_adhan', 'specific_time_ranges'])],
            'iqama' => 'nullable|array',
            'iqama.fajr' => 'nullable|integer|min:0|max:180',
            'iqama.dhuhr' => 'nullable|integer|min:0|max:180',
            'iqama.asr' => 'nullable|integer|min:0|max:180',
            'iqama.maghrib' => 'nullable|integer|min:0|max:180',
            'iqama.isha' => 'nullable|integer|min:0|max:180',

            // ---- Jumaa (optional fixed iqama time HH:MM) ----
            'jumaa_iqama' => 'nullable|date_format:H:i',

            // ---- Brand / theme (partial theme allowed) ----
            'brand' => 'nullable|array',
            'brand.primary_color' => $hex,
            'brand.secondary_color' => $hex,
            'brand.accent_color' => $hex,
            'brand.background_color' => $hex,

            // ---- Content (about/mission/vision + feature toggles) ----
            'about' => 'nullable|string|max:5000',
            'mission' => 'nullable|string|max:5000',
            'vision' => 'nullable|string|max:5000',
            'feature_keys' => 'nullable|array',
            'feature_keys.*' => 'string|exists:mobile_app_features,key',

            // ---- Platform selection (which apps the masjid wants) ----
            // The wizard's Platforms step. tvOS ships under the iOS Apple account,
            // so it has no account_mode of its own and requires iOS to be chosen
            // too (enforced in withValidator below).
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['string', Rule::in(['ios', 'android', 'tvos', 'web'])],

            // ---- Apps (per-platform managed vs BYO) ----
            // account_mode is optional and defaults to `managed` in the
            // controller; it only matters for platforms actually selected above.
            'apps' => ['nullable', 'array'],
            'apps.ios.account_mode' => ['nullable', Rule::in(['managed', 'byo'])],
            'apps.android.account_mode' => ['nullable', Rule::in(['managed', 'byo'])],
            'apps.web.account_mode' => ['nullable', Rule::in(['managed', 'byo'])],

            // BYO iOS App Store Connect API key — required only when byo.
            'apps.ios.asc_key_p8' => 'nullable|required_if:apps.ios.account_mode,byo|string',
            'apps.ios.asc_key_id' => 'nullable|required_if:apps.ios.account_mode,byo|string|max:255',
            'apps.ios.asc_issuer_id' => 'nullable|required_if:apps.ios.account_mode,byo|string|max:255',

            // BYO Google Play service-account JSON — required only when byo.
            'apps.android.play_service_account_json' => 'nullable|required_if:apps.android.account_mode,byo|json',
        ];
    }

    /**
     * Cross-field platform rules that don't fit a single-field rule.
     *
     * tvOS apps are distributed through the SAME Apple Developer account / App
     * Store Connect record as the iOS app (they share a bundle-id prefix and
     * signing team), so selecting tvOS without iOS is not a valid configuration.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $platforms = (array) $this->input('platforms', []);
            if (in_array('tvos', $platforms, true) && ! in_array('ios', $platforms, true)) {
                $validator->errors()->add(
                    'platforms',
                    'tvOS apps ship under the iOS Apple Developer account — select iOS as well to include tvOS.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'apps.ios.account_mode' => 'iOS account mode',
            'apps.android.account_mode' => 'Android account mode',
            'apps.web.account_mode' => 'web account mode',
            'apps.ios.asc_key_p8' => 'App Store Connect .p8 key',
            'apps.ios.asc_key_id' => 'App Store Connect key ID',
            'apps.ios.asc_issuer_id' => 'App Store Connect issuer ID',
            'apps.android.play_service_account_json' => 'Google Play service-account JSON',
        ];
    }
}
