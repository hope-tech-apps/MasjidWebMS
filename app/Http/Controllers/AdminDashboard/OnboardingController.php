<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Services\Auth\AccountAccessService;
use App\Enums\HighLatitudeRule;
use App\Enums\Madhab;
use App\Enums\PrayerCalculationMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Onboarding\ProvisionMasjidRequest;
use App\Models\Country;
use App\Models\Masjid;
use App\Models\MobileAppFeature;
use App\Support\MobileCache;
use App\Support\Studio\OrganisationProvisioner;
use App\Support\Studio\ProvisionContext;
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

            // A full closure, not `fn () =>`: an arrow function captures by
            // value, so the provisioner would append to a copy and every
            // invitation would be silently dropped.
            //
            // The transaction is opened HERE and must stay the outermost one.
            // What follows it sends invitations and flushes the mobile cache on
            // the assumption that returning from this call means committed;
            // inside a caller's transaction it would only have released a
            // savepoint, and the catch below turns a failure into a 500 rather
            // than rethrowing it to that caller.
            $masjid = DB::transaction(function () use ($request, &$invitations) {
                return app(OrganisationProvisioner::class)->create($request, $invitations, ProvisionContext::fromAuth());
            });

            foreach ($invitations as [$admin, $orgName]) {
                $access->invite($admin, $orgName);
            }

            // Newly created masjid changes the global mobile masjids list.
            MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

            return response()->json([
                'status' => 'success',
                'data' => self::provisionedPayload($masjid),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * `{masjid_id, masjid, app_publishing}` for a just-provisioned organisation:
     * the wizard's 201 body, and the head of Studio's provision-from-draft body
     * (docs/manara-studio-w1.md S8), built in one place so the two cannot
     * drift. BYO credentials are never echoed, only whether each is present.
     *
     * @return array{masjid_id: int, masjid: Masjid, app_publishing: array<string, mixed>}
     */
    public static function provisionedPayload(Masjid $masjid): array
    {
        $masjid->load('logo', 'footer_logo', 'country', 'city', 'appPublishing');
        $masjid->append(Masjid::ADMIN_APPENDS);

        return [
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
        ];
    }
}
