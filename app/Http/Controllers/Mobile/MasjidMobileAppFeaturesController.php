<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class MasjidMobileAppFeaturesController extends Controller
{
    public function index($masjid_id)
    {
        $features = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::FEATURES),
            MobileCache::TTL_MEDIUM,
            fn() => Masjid::with('features.icon')->findOrFail($masjid_id)->features
        );

        return response()->json([
            'status' => 'success',
            'data' => $features
        ], Response::HTTP_OK);
    }
}
