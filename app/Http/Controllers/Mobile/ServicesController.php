<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\Service;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;

class ServicesController extends Controller
{
    public function index($masjid_id)
    {
        $services = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::SERVICES),
            MobileCache::TTL_MEDIUM,
            function () use ($masjid_id) {
                $masjid = Masjid::findOrFail($masjid_id);
                return Service::where('masjid_id', $masjid->id)
                    ->with('image', 'icon')
                    ->get();
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $services
        ]);
    }
}
