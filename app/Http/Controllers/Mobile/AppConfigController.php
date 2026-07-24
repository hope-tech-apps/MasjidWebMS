<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AppVersionSetting;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public app-config endpoint. iOS + Android call this on launch to decide
 * whether to hard-gate (force update), show a maintenance screen, or
 * surface a soft "update recommended" prompt.
 *
 * Returns all platforms keyed by name so a single response serves every
 * client; each app reads its own platform's block. Cached per masjid (config
 * changes rarely; admin edits flush the key). When a masjid has no rows yet,
 * `data` is {} so the apps fail open (proceed without gating).
 */
class AppConfigController extends Controller
{
    public function index(int $masjid_id)
    {
        $config = Cache::remember(
            MobileCache::masjidKey($masjid_id, MobileCache::APP_CONFIG),
            MobileCache::TTL_MEDIUM,
            function () use ($masjid_id) {
                return AppVersionSetting::where('masjid_id', $masjid_id)
                    ->get()
                    ->keyBy('platform')
                    ->map(function ($row) {
                        return [
                            'minimum_version' => $row->minimum_version,
                            'minimum_build' => $row->minimum_build,
                            'force_update' => $row->force_update,
                            'update_message' => $row->update_message,
                            'latest_version' => $row->latest_version,
                            'store_url' => $row->store_url,
                            'maintenance_mode' => $row->maintenance_mode,
                            'maintenance_message' => $row->maintenance_message,
                        ];
                    });
            }
        );

        return response()->json([
            'status' => 'success',
            // Force an empty object ({}) rather than [] when the masjid has no
            // rows, so apps consistently read data.ios / data.android and fail open.
            'data' => $config->isEmpty() ? (object) [] : $config,
        ], Response::HTTP_OK);
    }
}
