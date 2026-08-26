<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Services\Auth\AccountAccessService;
use App\Models\User;
use Illuminate\Support\Str;
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
use App\Support\FormTemplates;
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
     * the vertical picker, the mobile-feature catalog (for the Content step's
     * toggles), the prayer calculation option lists, and the countries list.
     */
    public function options()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                // ---- Verticals (the wizard's Organization-type picker) ----
                // Served straight from config/verticals.php so the SPA renders
                // what provisioning will actually DO — the default feature
                // bundle and the terminology pack — instead of a second copy of
                // the same facts that can drift. Masjid::ORG_TYPES is the
                // authority on the allowed set (.claude/rules/verticals.md), and
                // it is the same constant ProvisionMasjidRequest validates
                // against, so a new vertical appears in the wizard with no SPA
                // change at all.
                'verticals' => collect(Masjid::ORG_TYPES)->map(function (string $orgType) {
                    $config = config("verticals.{$orgType}", []);

                    return [
                        'org_type' => $orgType,
                        'label' => $config['label'] ?? '',
                        'plural' => $config['plural'] ?? '',
                        // DEFAULTS seeded at provisioning time, not an
                        // authorization list — the wizard says as much.
                        'feature_keys' => array_values($config['feature_keys'] ?? []),
                        'terminology' => $config['terminology'] ?? [],
                    ];
                })->values(),
                // The vertical an omitted org_type resolves to. Read from the
                // SAME constant ProvisionMasjidRequest::prepareForValidation()
                // merges, so the wizard's pre-selection and the request's
                // fallback cannot disagree — OnboardingVerticalPickerTest pins
                // that they agree by provisioning without an org_type and
                // comparing.
                'default_org_type' => Masjid::ORG_TYPE_MASJID,
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
    public function provision(ProvisionMasjidRequest $request, ?AccountAccessService $access = null)
    {
        // Optional and container-resolved, NOT because injection is unwanted but
        // because this method has a second caller that does not go through the
        // container: the opt-in demo fixture support class invokes
        // `app(OnboardingController::class)->provision($request)` directly, on
        // purpose — its tenant is built through the REAL wizard path rather than
        // a hand-written Masjid row. A required second parameter silently broke
        // that caller with an ArgumentCountError, which surfaced as ten failing
        // fixture tests and not as anything mentioning this file.
        //
        // Deliberately not naming that class here: a test asserts nothing under
        // app/, database/ or routes/ mentions it, so the fixture cannot become
        // reachable from shipped code by accident.
        $access ??= app(AccountAccessService::class);

        try {
            // Collected inside the transaction, SENT after it commits: an email
            // cannot be un-sent if the transaction later rolls back, and an
            // invitation to an organisation that does not exist is worse than a
            // late one.
            $invitations = [];

            $masjid = DB::transaction(function () use ($request, &$invitations) {
                // ---- Masjid record (mirrors MasjidsController@store, + timezone) ----
                $masjid = Masjid::create([
                    'name' => $request->input('name'),
                    'org_type' => $request->input('org_type'),
                    'email' => $request->input('email'),
                    'phone' => $request->input('phone'),
                    'address' => $request->input('address'),
                    'latitude' => $request->input('latitude'),
                    'longitude' => $request->input('longitude'),
                    'timezone' => $request->input('timezone'),
                    'country_id' => $request->input('country_id'),
                    'city_id' => $request->input('city_id'),
                    'user_id' => $request->input('user_id') ?: null,
                    // AN ORGANISATION IS BORN WITH ITS CRM ON.
                    //
                    // `crm_enabled` defaults false at the column and was written
                    // by exactly two things in the tree: the SuperAdmin toggle
                    // and the demo seeder. Provisioning never touched it, so
                    // every organisation this wizard created was DARK — the
                    // whole CRM route group 403s, the public registration door
                    // 404s, and the admin nav simply hides the screens, so
                    // nobody can tell why the org has no Families, Classrooms,
                    // Programs or Giving. MEASURED 2026-08-26: three of five
                    // production tenants (NAFIS, MEC, Al-Razi) were sitting in
                    // that state, all of them created this way.
                    //
                    // Overridable, because "set it up now, switch it on later"
                    // is a real request — but the DEFAULT is on, because a
                    // half-provisioned org is the failure this caused.
                    'crm_enabled' => $request->has('crm_enabled')
                        ? $request->boolean('crm_enabled')
                        : true,
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
                // the tenant falls back to its VERTICAL's bundle
                // (config/verticals.php), so a school never has the worship
                // modules switched on. For a masjid that bundle is the whole
                // seeded catalog, which is the previous "everything on"
                // behaviour unchanged.
                $explicitFeatures = $request->has('feature_keys_provided');
                $selected = $explicitFeatures
                    ? $request->input('feature_keys', [])
                    : $masjid->defaultFeatureKeys();
                foreach (MobileAppFeature::all() as $feature) {
                    MasjidMobileAppFeature::create([
                        'masjid_id' => $masjid->id,
                        'feature_id' => $feature->id,
                        'is_available' => in_array($feature->key, $selected, true),
                    ]);
                }

                // ---- Vertical form templates (T-011) ----
                // Ready-to-edit starter forms for the tenant's vertical
                // (config/form_templates.php): a school is born with its
                // Admissions Interest / Careers Application / Withdrawal
                // Request forms. Masjid and community list NO templates, so
                // this is a no-op for them — provisioning stays byte-identical.
                // Seeded rows are ordinary forms (same table, same schema
                // vocabulary), indistinguishable from admin-built ones.
                FormTemplates::applyTo($masjid);

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

                // ---- The organisation's own administrator ----
                //
                // AN ORG WITH NO OWNER CANNOT BE ADMINISTERED BY ANYONE BUT A
                // SUPERADMIN. With tenancy.multi_membership off, TenantResolver
                // derives a MasjidAdmin's grant from `masjids.user_id`; when it
                // is null, `soleOwnedMembership()` finds nothing and every
                // masjid-scoped route 403s with "no verified membership". The
                // wizard's Identity step has always had an optional "Admin"
                // field and, left empty, wrote `user_id => null` with no warning
                // — which is how NAFIS, MEC and Al-Razi all ended up unreachable
                // by their own staff.
                //
                // Given an address, the account is created here and INVITED: the
                // platform picks a random secret it never reads, and the person
                // sets their own from an emailed link. Nobody at Manara ever
                // knows another organisation's credential.
                if ($request->filled('admin.email') && ! $request->filled('user_id')) {
                    $admin = User::create([
                        'name' => $request->input('admin.name') ?: $masjid->name.' Administrator',
                        'email' => $request->input('admin.email'),
                        'phone' => $request->input('admin.phone') ?: $masjid->phone,
                        'type' => 'MasjidAdmin',
                        'password' => Str::password(40),
                    ]);

                    $masjid->user_id = $admin->id;
                    $masjid->save();

                    $invitations[] = [$admin, $masjid->name];
                }

                return $masjid;
            });

            foreach ($invitations as [$admin, $orgName]) {
                $access->invite($admin, $orgName);
            }

            // Newly created masjid changes the global mobile masjids list.
            MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

            $masjid->load('logo', 'footer_logo', 'country', 'city', 'appPublishing');
            $masjid->append(Masjid::ADMIN_APPENDS);

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
