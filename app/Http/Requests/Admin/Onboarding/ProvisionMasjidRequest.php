<?php

namespace App\Http\Requests\Admin\Onboarding;

use App\Enums\HighLatitudeRule;
use App\Enums\Madhab;
use App\Enums\PrayerCalculationMethod;
use App\Http\Requests\Admin\Studio\StudioDomainCheckRequest;
use App\Http\Requests\BaseFormRequest;
use App\Models\City;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\MobileAppFeature;
use App\Models\User;
use App\Support\CapabilityCatalogue;
use App\Support\HostName;
use App\Support\Studio\LayoutPresets;
use Closure;
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
 *
 * MANARA STUDIO'S KEYS (docs/manara-studio-w1.md S8, R10). `slug`,
 * `description`, `capabilities`, `layout_preset`, `show_iqama_times` and
 * `web_domain` are optional, and with all of them absent the request and what
 * OrganisationProvisioner does with it are exactly the wizard's
 * (ProvisionResponseSnapshotTest). Studio's provision-from-draft builds THIS
 * request from the draft and validates it with these very rules, so a draft
 * provision and a direct POST cannot be held to different standards.
 */
class ProvisionMasjidRequest extends BaseFormRequest
{
    /** The iqama offsets, keyed as the request and `iqama_time_settings` name them, with the names an operator reads. */
    public const IQAMA_PRAYERS = ['fajr' => 'Fajr', 'dhuhr' => 'Dhuhr', 'asr' => 'Asr', 'maghrib' => 'Maghrib', 'isha' => 'Isha'];

    /**
     * An omitted vertical means `masjid`.
     *
     * The wizard predates verticals and every existing caller posts no
     * org_type, so normalizing here — rather than defaulting in the controller
     * — keeps one answer to "which vertical is this" for both validation and
     * provisioning. An empty string is treated as absent for the same reason:
     * multipart serialization of an unset select sends "".
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('org_type')) {
            $this->merge(['org_type' => Masjid::ORG_TYPE_MASJID]);
        }

        // Accept a posted key in any spelling the catalogue normalises to
        // (`quran` for production's `qur’an`), so the key config/verticals.php
        // documents works everywhere; `exists` below still rejects the rest.
        if (is_array($this->input('feature_keys'))) {
            $this->merge(['feature_keys' => MobileAppFeature::toCatalogueKeys($this->input('feature_keys'))]);
        }

        // Studio's booleans, coerced here because the wizard's serializer and
        // any form-encoded client post "true"/"false", which the `boolean` rule
        // refuses (.claude/rules/shipping.md). FILTER_NULL_ON_FAILURE so real
        // nonsense still fails validation instead of reading as false. Each is
        // touched only when sent, so a request without them is unchanged.
        if ($this->has('show_iqama_times')) {
            $this->merge(['show_iqama_times' => self::bool($this->input('show_iqama_times'))]);
        }

        if (is_array($this->input('capabilities'))) {
            $this->merge(['capabilities' => array_map(self::bool(...), $this->input('capabilities'))]);
        }

        // The slug names a DNS label, so it is validated as the domain check
        // stores it; the custom host and zone likewise. (A blank one reaches
        // no rule but `nullable`'s: the validator runs only implicit rules on
        // an empty string, and `filled('slug')` is false, so it is no slug.)
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => strtolower(trim($this->input('slug')))]);
        }

        if (is_array($this->input('web_domain'))) {
            $domain = $this->input('web_domain');

            foreach (['custom_host', 'custom_zone_apex'] as $field) {
                if (is_string($domain[$field] ?? null)) {
                    $domain[$field] = HostName::normalize($domain[$field]) ?? $domain[$field];
                }
            }

            $this->merge(['web_domain' => $domain]);
        }
    }

    private static function bool(mixed $value): ?bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * The posted org_type as a string, or '' when it is not one. rules() is
     * built before anything is validated, so a malformed value (`org_type[]=…`)
     * reaches it; cast with `(string)` it is PHP's "Array to string conversion"
     * warning, which Laravel turns into a 500 where the `string` rule answers
     * 422.
     */
    private function orgType(): string
    {
        $orgType = $this->input('org_type');

        return is_string($orgType) ? $orgType : '';
    }

    public function rules(): array
    {
        // Hex color, optional alpha — same shape as SaveThemeSettingsRequest.
        $hex = ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', 'max:9'];

        return [
            // Switched ON by default at provisioning; present-and-false is
            // the deliberate "set it up dark for now".
            'crm_enabled' => ['sometimes', 'boolean'],

            // The organisation's own administrator. Given an address, the
            // account is created and invited to set its own password — an org
            // with no owner cannot be reached by any MasjidAdmin at all.
            'admin' => ['sometimes', 'array'],
            'admin.name' => ['nullable', 'string', 'max:255'],
            'admin.email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'admin.phone' => ['nullable', 'string', 'max:40'],

            // ---- Vertical (Manara org_type) ----
            // Masjid::ORG_TYPES — not a DB enum — is the authority on the
            // allowed set (.claude/rules/verticals.md), so a new vertical needs
            // no migration and no change here. Always present by the time this
            // runs; see prepareForValidation.
            'org_type' => ['required', 'string', Rule::in(Masjid::ORG_TYPES)],

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

            // ---- Manara Studio (S8): every key optional ----

            // The organisation's managed subdomain label ({slug}.{managed_suffix})
            // and, in W3, its repositories' name. The label rules are the
            // domain check's own, and a host some row already holds (reserved
            // ones included) is refused as the check would call it taken.
            // `bail`, so a value that is not a string stops at `string` (422)
            // before the closure casts it (a 500); likewise the domain keys.
            'slug' => [
                'bail',
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (($refusal = StudioDomainCheckRequest::labelRefusal((string) $value)) !== null) {
                        $fail($refusal);

                        return;
                    }

                    $host = $value . '.' . config('cloudflare.managed_suffix');
                    $holder = MasjidDomain::query()->where('host', $host)->value('masjid_id');

                    if ($holder !== null) {
                        $fail("{$host} is already recorded for organisation #{$holder}.");
                    }
                },
                'unique:masjids,slug',
            ],

            // Public copy in the client's words (R12): the lookup, the hero
            // subtitle and the home page's meta description publish it verbatim.
            'description' => ['nullable', 'string', 'max:300'],

            // Step 1's switch map. Only keys the catalogue serves to this org
            // type (withValidator), and never alongside the wizard's own
            // feature fields: one request, one way of saying what the org has.
            'capabilities' => ['sometimes', 'array', 'prohibits:crm_enabled,feature_keys,feature_keys_provided'],
            'capabilities.*' => ['required', 'boolean'],

            // A starter website: one of this org type's presets, web only.
            'layout_preset' => ['nullable', 'string', Rule::in(LayoutPresets::keysFor($this->orgType()))],

            // Written instead of the wizard's hard-coded `true`: Studio shows
            // iqama only when the client gave times, and then all five
            // (withValidator), because a missing one would be invented.
            'show_iqama_times' => ['sometimes', 'boolean'],

            // The client's own domain, beside the managed subdomain.
            'web_domain' => ['nullable', 'array'],
            'web_domain.custom_host' => [
                'bail',
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (($refusal = StudioDomainCheckRequest::customHostRefusal((string) $value)) !== null) {
                        $fail($refusal);

                        return;
                    }

                    $holder = MasjidDomain::query()->where('host', HostName::normalize((string) $value))->value('masjid_id');

                    if ($holder !== null) {
                        $fail("{$value} is already recorded for organisation #{$holder}.");
                    }
                },
            ],
            'web_domain.custom_zone_apex' => [
                'bail',
                'nullable',
                'required_with:web_domain.custom_host',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    $host = $this->input('web_domain.custom_host');
                    $refusal = StudioDomainCheckRequest::zoneApexRefusal((string) $value, is_string($host) ? $host : '');

                    if ($refusal !== null) {
                        $fail($refusal);
                    }
                },
            ],
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

            $web = in_array('web', $platforms, true);

            // A starter website and a web domain are the web deliverable; asked
            // for without it they would be written for a site nobody ordered,
            // or silently dropped.
            if ($this->filled('layout_preset') && ! $web) {
                $validator->errors()->add('layout_preset', 'A starter website needs the web platform selected.');
            }

            if ($this->filled('web_domain.custom_host')) {
                if (! $web) {
                    $validator->errors()->add('web_domain.custom_host', 'A web domain needs the web platform selected.');
                } elseif (! $this->filled('slug')) {
                    $validator->errors()->add('web_domain.custom_host', 'A custom domain needs the managed subdomain (slug) as well.');
                }
            }

            // Studio says to show iqama only when the client gave times, and
            // the provisioner fills a missing offset with nothing it could
            // show; so shown means all five were given.
            if ($this->input('show_iqama_times') === true) {
                $missing = array_values(array_filter(
                    self::IQAMA_PRAYERS,
                    fn (string $salah) => blank($this->input("iqama.{$salah}")),
                    ARRAY_FILTER_USE_KEY,
                ));

                if ($missing !== []) {
                    $validator->errors()->add('iqama', self::iqamaIncomplete($missing));
                }
            }

            // Hidden or unknown keys are refused, never ignored: the operator
            // chose them, and dropping one would provision a different org
            // from the one they approved.
            if (is_array($this->input('capabilities'))) {
                // `prohibits` passes an EMPTY map (it is not "required"), and an
                // empty map still selects the capabilities path, which would
                // drop the wizard's fields without a word.
                if ($this->input('capabilities') === [] && $this->hasAny(['crm_enabled', 'feature_keys', 'feature_keys_provided'])) {
                    $validator->errors()->add('capabilities', 'Send either the capabilities map or the wizard\'s feature fields, not both.');
                }

                // A catalogue key is a top-level key of config/capabilities.php,
                // looked up as one: config("capabilities.{$key}") reads a dot as
                // a path, so `web_pages.defaults` would find a nested array,
                // pass, and then be ignored by the writer, which walks only the
                // real keys (as UpdateStudioDraftRequest checks them).
                $catalogue = config('capabilities', []);

                foreach (array_keys($this->input('capabilities')) as $key) {
                    $definition = array_key_exists($key, $catalogue) ? $catalogue[$key] : null;

                    if (! is_array($definition)
                        || CapabilityCatalogue::visibility((string) $key, $definition, $this->orgType()) === CapabilityCatalogue::HIDDEN) {
                        $validator->errors()->add("capabilities.{$key}", "\"{$key}\" is not offered to this kind of organisation.");
                    }
                }
            }
        });
    }

    /**
     * The refusal for a partial set of iqama offsets, naming what is missing.
     * Step 3 says the same sentence before the button is pressed
     * (core/studio/provision.ts iqamaBlockers).
     *
     * @param  list<string>  $missing  display names, in prayer order
     */
    public static function iqamaIncomplete(array $missing): string
    {
        return 'The iqama times are incomplete: ' . implode(', ', $missing) . ' '
            . (count($missing) === 1 ? 'is' : 'are')
            . ' missing. Enter all five in Foundation, or tick "Client has not given iqama times".';
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
