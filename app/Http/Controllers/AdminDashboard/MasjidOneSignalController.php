<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Onesignal\ProvisionOnesignalAppRequest;
use App\Models\Masjid;
use App\Services\OneSignalProvisioningService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-masjid OneSignal push configuration (Super-Admin only).
 *
 * Two endpoints:
 *   - show()     GET  — the NON-secret config: whether this masjid has its own
 *                       app (onesignal_app_id + has_onesignal_key boolean). The
 *                       REST key itself is NEVER returned.
 *   - provision() POST — mint a per-masjid OneSignal app via the OneSignal Apps
 *                       API (OneSignalProvisioningService) and persist the app
 *                       id + (encrypted) REST key onto masjid_app_publishing.
 *
 * Tenant safety: the masjid is ALWAYS resolved from the route {masjid_id}
 * (server-derived) — never from the request body — so a masjid can never be
 * provisioned/targeted on another's behalf. The route also sits behind the
 * `super` middleware, so only a SuperAdmin can create OneSignal apps (an
 * org-level action). See routes/admin.php.
 */
class MasjidOneSignalController extends Controller
{
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
     * Provision (create) a per-masjid OneSignal app and store its credentials.
     */
    public function provision(
        ProvisionOnesignalAppRequest $request,
        OneSignalProvisioningService $provisioner,
        $masjid_id
    ) {
        try {
            // Server-derived tenant: the route id, not the body.
            $masjid = Masjid::findOrFail($masjid_id);

            $result = $provisioner->provisionApp(
                $masjid,
                $request->input('bundle_id'),
                array_filter(['name' => $request->input('name')])
            );

            if (! ($result['ok'] ?? false)) {
                // Config/API failure — surfaced as a 422 (nothing was created)
                // with a clear, non-sensitive message. The REST key is never
                // part of this payload.
                return response()->json([
                    'status' => 'error',
                    'data' => $result['error'] ?? 'OneSignal provisioning failed.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'masjid_id' => (int) $masjid->id,
                    'onesignal_app_id' => $result['app_id'],
                    'has_onesignal_key' => (bool) ($result['has_onesignal_key'] ?? true),
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
