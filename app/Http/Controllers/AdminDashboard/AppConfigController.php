<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppConfig\UpdateAppConfigRequest;
use App\Models\AppVersionSetting;
use App\Support\Errors;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super-admin CRUD for the emergency app-version gate. This is the lever:
 * flip force_update + bump minimum_build here and every stale install is
 * walled off on next launch — no app rebuild, no store-review wait.
 */
class AppConfigController extends Controller
{
    /** All platforms (ios + android) for a single masjid. */
    public function index(int $masjid_id)
    {
        return response()->json([
            'status' => 'success',
            'data' => AppVersionSetting::where('masjid_id', $masjid_id)
                ->orderBy('platform')
                ->get(),
        ], Response::HTTP_OK);
    }

    /** Update (or create) a single masjid+platform's config. */
    public function update(UpdateAppConfigRequest $request, int $masjid_id, string $platform)
    {
        try {
            $setting = AppVersionSetting::updateOrCreate(
                ['masjid_id' => $masjid_id, 'platform' => $platform],
                $request->safe()->only([
                    'minimum_version', 'minimum_build', 'force_update', 'update_message',
                    'latest_version', 'store_url', 'maintenance_mode', 'maintenance_message',
                ])
            );

            // Flush so the public endpoint serves the new config immediately —
            // critical for an emergency lever, where staleness defeats the point.
            MobileCache::flushMasjid($masjid_id, MobileCache::APP_CONFIG);

            return response()->json([
                'status' => 'success',
                'data' => $setting->fresh(),
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
