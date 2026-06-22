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
 * client; each app reads its own platform's block. Cached (config changes
 * rarely; admin edits flush the key).
 */
class AppConfigController extends Controller
{
    public function index()
    {
        $config = Cache::remember(
            MobileCache::globalKey(MobileCache::APP_CONFIG),
            MobileCache::TTL_MEDIUM,
            function () {
                return AppVersionSetting::all()->keyBy('platform')->map(function ($row) {
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
            'data' => $config,
        ], Response::HTTP_OK);
    }
}
