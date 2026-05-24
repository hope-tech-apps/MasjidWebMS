<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\SplashAnnouncement;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public read endpoint for the Nuxt site.
 *
 * Returns the single splash that should be displayed *right now* for the
 * given masjid — the highest-priority row whose [starts_at, ends_at] window
 * contains now() and is_active = true.
 *
 * Returns 204 No Content if no splash is active. The Nuxt composable treats
 * 204 as "render nothing" without firing an error toast.
 *
 * Mobile clients do NOT use this endpoint — they receive splash content via
 * OneSignal's In-App Message system, mirrored from the admin save flow.
 */
class SplashAnnouncementsController extends Controller
{
    public function current($masjid_id)
    {
        $splash = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::SPLASH),
            MobileCache::TTL_SHORT,
            function () use ($masjid_id) {
                return SplashAnnouncement::where('masjid_id', $masjid_id)
                    ->active()
                    ->with('image')
                    ->orderByDesc('priority')
                    ->orderByDesc('created_at')
                    ->first();
            }
        );

        if (!$splash) {
            return response()->noContent();
        }

        return response()->json([
            'status' => 'success',
            'data' => $splash,
        ], Response::HTTP_OK);
    }
}
