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
    /** All platforms (ios + android). */
    public function index()
    {
        return response()->json([
            'status' => 'success',
            'data' => AppVersionSetting::orderBy('platform')->get(),
        ], Response::HTTP_OK);
    }

    /** Update a single platform's config. */
    public function update(UpdateAppConfigRequest $request, string $platform)
    {
        try {
            $setting = AppVersionSetting::where('platform', $platform)->firstOrFail();

            $setting->update($request->safe()->only([
                'minimum_version', 'minimum_build', 'force_update', 'update_message',
                'latest_version', 'store_url', 'maintenance_mode', 'maintenance_message',
            ]));

            // Flush so the public endpoint serves the new config immediately —
            // critical for an emergency lever, where staleness defeats the point.
            MobileCache::flushGlobal(MobileCache::APP_CONFIG);

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
