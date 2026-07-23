<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\HighLatitudeRule;
use App\Enums\Madhab;
use App\Enums\PrayerCalculationMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Onboarding\ProvisionMasjidRequest;
use App\Models\Country;
use App\Models\DonationLink;
use App\Models\IqamaTimeSetting;
use App\Models\JumaaSetting;
use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MasjidMobileAppFeature;
use App\Models\MasjidSocialMediaLink;
use App\Models\MobileAppFeature;
use App\Models\PrayerCalculationSetting;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super-Admin masjid onboarding wizard backend.
 *
 * Gating: the route is registered under the admin auth group with the `super`
 * middleware (bootstrap/app.php alias -> SuperAdminMiddleware), so only a
 * SuperAdmin can provision. This turns the previously manual per-tenant
 * onboarding (create masjid, seed theme/about/prayer/jumaa/donation/features,
 * decide app-publishing mode) into one transactional call.
 */
class OnboardingController extends Controller
{
    /**
     * Catalog the wizard needs to render its selects in a single fetch:
     * the mobile-feature catalog (for the Content step's toggles), the prayer
     * calculation option lists, and the countries list.
     */
    public function options()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'features' => MobileAppFeature::orderBy('name')->get(['id', 'key', 'name']),
                'prayer' => [
                    'methods' => collect(PrayerCalculationMethod::cases())->map(fn($c) => [
                        'value' => $c->value,
                        'label' => $c->label(),
                    ]),
                    'madhabs' => collect(Madhab::cases())->map(fn($c) => [
                        'value' => $c->value,
                        'label' => $c->label(),
                    ]),
                    'high_latitude_rules' => collect(HighLatitudeRule::cases())->map(fn($c) => [
                        'value' => $c->value,
                        'label' => $c->label(),
                    ]),
                ],
                'countries' => Country::orderBy('name')->get(['id', 'name']),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Provision a brand-new masjid tenant end-to-end in one DB transaction:
     * the masjid record plus its core related config (theme, about, iqama,
     * prayer calc, jumaa, donation link, social links, default feature toggles)
     * and the app-publishing config (managed vs BYO per platform).
     *
     * Returns the created masjid id. BYO app-publishing credentials are stored
     * encrypted and are NEVER echoed back — the response exposes only presence
     * booleans (has_asc_key / has_play_service_account).
     */
    public function provision(ProvisionMasjidRequest $request)
    {
        try {
            $masjid = DB::transaction(function () use ($request) {
                // ---- Masjid record (mirrors MasjidsController@store, + timezone) ----
                $masjid = Masjid::create([
                    'name' => $request->input('name'),
                    'email' => $request->input('email'),
                    'phone' => $request->input('phone'),
                    'address' => $request->input('address'),
                    'latitude' => $request->input('latitude'),
                    'longitude' => $request->input('longitude'),
                    'timezone' => $request->input('timezone'),
                    'country_id' => $request->input('country_id'),
                    'city_id' => $request->input('city_id'),
                    'user_id' => $request->input('user_id') ?: null,
                    'created_by' => Auth::id(),
                ]);

                // ---- Theme (from chosen base colors; partial theme allowed) ----
                $brand = $request->input('brand', []);
                $masjid->themeSettings()->create([
                    'primary_color' => $brand['primary_color'] ?? null,
                    'secondary_color' => $brand['secondary_color'] ?? null,
                    'accent_color' => $brand['accent_color'] ?? null,
                    'background_color' => $brand['background_color'] ?? null,
                ]);

                // ---- About / Mission / Vision (only when prose was supplied) ----
                if ($request->filled('about') || $request->filled('mission') || $request->filled('vision')) {
                    $masjid->masjidAbout()->create([
                        'about' => $request->input('about', ''),
                        'mission' => $request->input('mission', ''),
                        'vision' => $request->input('vision', ''),
                    ]);
                }

                // ---- Prayer calculation settings ----
                $masjid->prayerCalculationSettings()->create([
                    'method' => $request->input('method'),
                    'madhab' => $request->input('madhab'),
                    'high_latitude_rule' => $request->input('high_latitude_rule'),
                ]);

                // ---- Iqama time settings (minutes-after-adhan offsets) ----
                $iqama = $request->input('iqama', []);
                IqamaTimeSetting::create([
                    'masjid_id' => $masjid->id,
                    'iqama_type' => $request->input('iqama_type', 'minutes_after_adhan'),
                    'show_iqama_times' => true,
                    'fajr' => $iqama['fajr'] ?? 20,
                    'dhuhr' => $iqama['dhuhr'] ?? 10,
                    'asr' => $iqama['asr'] ?? 10,
                    'maghrib' => $iqama['maghrib'] ?? 5,
                    'isha' => $iqama['isha'] ?? 10,
                ]);

                // ---- Jumaa settings (fixed iqama time; sensible default) ----
                $masjid->jumaaSettings()->create([
                    'iqama' => $request->input('jumaa_iqama') ?: '13:30',
                    'athans' => [],
                ]);

                // ---- Donation link (only when a URL was supplied) ----
                if ($request->filled('donation_link')) {
                    DonationLink::create([
                        'masjid_id' => $masjid->id,
                        'link' => $request->input('donation_link'),
                        'title' => $request->input('donation_title') ?: 'Donation Link',
                        'message' => $request->input('donation_message') ?: 'Donate Now',
                    ]);
                }

                // ---- Social media links (optional) ----
                $socials = [
                    'Facebook' => $request->input('facebook_url'),
                    'YouTube' => $request->input('youtube_url'),
                    'Instagram' => $request->input('instagram_url'),
                    'WhatsApp_URL' => $request->input('whatsapp_url'),
                    'WhatsApp_Number' => $request->input('whatsapp_number'),
                ];
                foreach ($socials as $type => $value) {
                    if (filled($value)) {
                        MasjidSocialMediaLink::create([
                            'masjid_id' => $masjid->id,
                            'type' => $type,
                            'value' => $value,
                        ]);
                    }
                }

                // ---- Default feature toggles ----
                // The wizard signals an explicit selection with the
                // `feature_keys_provided` flag: when present, enable only the
                // chosen keys (an all-unchecked selection legitimately enables
                // none — the flag disambiguates it from an absent field, since
                // multipart serialization drops empty arrays). Without the flag
                // (defensive / non-wizard callers) every feature is on, matching
                // MasjidsController@store.
                $explicitFeatures = $request->has('feature_keys_provided');
                $selected = $request->input('feature_keys', []);
                foreach (MobileAppFeature::all() as $feature) {
                    MasjidMobileAppFeature::create([
                        'masjid_id' => $masjid->id,
                        'feature_id' => $feature->id,
                        'is_available' => $explicitFeatures
                            ? in_array($feature->key, $selected, true)
                            : true,
                    ]);
                }

                // ---- App-publishing config (platform selection + managed/BYO) ----
                // `platforms` (the Platforms step) is the source of truth for WHICH
                // apps the masjid wants; `apps[*].account_mode` describes HOW each
                // selected platform ships. account_mode defaults to `managed`.
                $platforms = array_values($request->input('platforms', []));
                $apps = $request->input('apps', []);

                $iosMode = $apps['ios']['account_mode'] ?? 'managed';
                $androidMode = $apps['android']['account_mode'] ?? 'managed';
                $webMode = $apps['web']['account_mode'] ?? 'managed';

                $publishing = [
                    'masjid_id' => $masjid->id,
                    'enabled_platforms' => $platforms,
                    'ios_account_mode' => $iosMode,
                    'android_account_mode' => $androidMode,
                    'web_account_mode' => $webMode,
                ];
                // Only persist BYO credentials for a platform that is BOTH selected
                // AND in BYO mode.
                if (in_array('ios', $platforms, true) && $iosMode === 'byo') {
                    $publishing['asc_key_p8'] = $apps['ios']['asc_key_p8'] ?? null;
                    $publishing['asc_key_id'] = $apps['ios']['asc_key_id'] ?? null;
                    $publishing['asc_issuer_id'] = $apps['ios']['asc_issuer_id'] ?? null;
                }
                if (in_array('android', $platforms, true) && $androidMode === 'byo') {
                    $publishing['play_service_account_json'] = $apps['android']['play_service_account_json'] ?? null;
                }
                MasjidAppPublishing::create($publishing);

                return $masjid;
            });

            // Newly created masjid changes the global mobile masjids list.
            MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

            $masjid->load('logo', 'footer_logo', 'country', 'city', 'appPublishing');

            return response()->json([
                'status' => 'success',
                'data' => [
                    'masjid_id' => $masjid->id,
                    'masjid' => $masjid,
                    // Echo only non-secret app-publishing shape. $appends on the
                    // model already reduces the secrets to presence booleans, but
                    // we build this explicitly so the contract is unambiguous.
                    'app_publishing' => [
                        'enabled_platforms' => $masjid->appPublishing?->enabled_platforms,
                        'ios_account_mode' => $masjid->appPublishing?->ios_account_mode,
                        'android_account_mode' => $masjid->appPublishing?->android_account_mode,
                        'web_account_mode' => $masjid->appPublishing?->web_account_mode,
                        'has_asc_key' => (bool) $masjid->appPublishing?->has_asc_key,
                        'has_play_service_account' => (bool) $masjid->appPublishing?->has_play_service_account,
                    ],
                ],
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
