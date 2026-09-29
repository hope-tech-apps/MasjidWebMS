<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Onesignal\ProvisionOnesignalAppRequest;
use App\Models\Masjid;
use App\Services\OneSignalProvisioningService;
use App\Services\OneSignalResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-masjid OneSignal push configuration (Super-Admin only).
 *
 * Two endpoints:
 *   - show()      GET  — the NON-secret config: whether this masjid has its own
 *                        app (onesignal_app_id + has_onesignal_key boolean) and
 *                        which platforms it is configured for. The REST key is
 *                        NEVER returned.
 *   - provision() POST — OneSignalProvisioningService::ensureApp (W2 S14): the
 *                        same guards as Studio. Before any request it refuses an
 *                        organisation on the never-provision list (the live apps
 *                        on the shared app) and, when the call would move the
 *                        organisation's sends, one with a subscribed device. It
 *                        makes no second app for an organisation that has one on
 *                        file or whose earlier app OneSignal still lists.
 *
 * Tenant safety: the masjid is ALWAYS resolved from the route {masjid_id}
 * (server-derived) — never from the request body. The route sits behind the
 * `super` middleware. See routes/admin.php.
 */
class MasjidOneSignalController extends Controller
{
    /** How each outcome answers. */
    private const STATUS = [
        OneSignalResult::CREATED => Response::HTTP_CREATED,
        OneSignalResult::ADOPTED => Response::HTTP_OK,
        OneSignalResult::EXISTS => Response::HTTP_OK,
        OneSignalResult::PLATFORM_ADDED => Response::HTTP_OK,
        OneSignalResult::KEY_MINTED => Response::HTTP_OK,
        OneSignalResult::REFUSED_LIVE_ORG => Response::HTTP_CONFLICT,
        OneSignalResult::HAS_AUDIENCE => Response::HTTP_CONFLICT,
        OneSignalResult::AMBIGUOUS_APP => Response::HTTP_CONFLICT,
        OneSignalResult::NOT_CONFIGURED => Response::HTTP_UNPROCESSABLE_ENTITY,
        OneSignalResult::MISSING_APNS => Response::HTTP_UNPROCESSABLE_ENTITY,
        OneSignalResult::MISSING_FCM => Response::HTTP_UNPROCESSABLE_ENTITY,
        OneSignalResult::REJECTED => Response::HTTP_UNPROCESSABLE_ENTITY,
        OneSignalResult::TRANSIENT => Response::HTTP_SERVICE_UNAVAILABLE,
        // The app exists and its id is stored: the same call again mints the key.
        OneSignalResult::KEY_PENDING => Response::HTTP_SERVICE_UNAVAILABLE,
    ];

    /**
     * Read the masjid's OneSignal config — non-secret fields only.
     */
    public function show($masjid_id)
    {
        try {
            $masjid = Masjid::with('appPublishing')->findOrFail($masjid_id);
            $config = $masjid->appPublishing;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'masjid_id' => (int) $masjid->id,
                    'onesignal_app_id' => $config?->onesignal_app_id,
                    // Boolean only — the REST key is never echoed.
                    'has_onesignal_key' => (bool) $config?->has_onesignal_key,
                    'onesignal_platforms' => $config?->onesignal_platforms ?? [],
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Ensure the masjid has its own OneSignal app, configured for the platforms asked.
     */
    public function provision(
        ProvisionOnesignalAppRequest $request,
        OneSignalProvisioningService $provisioner,
        $masjid_id
    ) {
        try {
            // Server-derived tenant: the route id, not the body.
            $masjid = Masjid::findOrFail($masjid_id);

            // Read-only here: the service stores the bundle id only once its guards
            // pass, so a refused call writes nothing to a live organisation's row.
            $bundleId = (string) $request->input('bundle_id');
            if ($provisioner->bundleIdIsTakenByAnother($bundleId, $masjid)) {
                return response()->json([
                    'status' => 'error',
                    'data' => 'That bundle id belongs to another organisation.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $result = $provisioner->ensureApp($masjid, $request->input('platforms', ['ios']), false, $bundleId);

            if (! $result->succeeded()) {
                // Nothing was created, or nothing more was: a clear, non-sensitive
                // message and the outcome. The REST key is never part of this payload.
                return response()->json([
                    'status' => 'error',
                    'outcome' => $result->outcome,
                    'data' => $result->message,
                ], self::STATUS[$result->outcome]);
            }

            return response()->json([
                'status' => 'success',
                'outcome' => $result->outcome,
                'data' => [
                    'masjid_id' => (int) $masjid->id,
                    'onesignal_app_id' => $result->appId,
                    'has_onesignal_key' => $result->hasKey,
                    'onesignal_platforms' => $result->platforms,
                    'message' => $result->message,
                ],
            ], self::STATUS[$result->outcome]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
