<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ContactReason;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;

class ContactReasonsController extends Controller
{
    public function index($masjid_id)
    {
        $contactReasons = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::CONTACT_REASONS),
            MobileCache::TTL_LONG,
            function () use ($masjid_id) {
                $masjid = Masjid::findOrFail($masjid_id);
                return ContactReason::where('masjid_id', $masjid->id)
                    ->active()
                    ->orderBy('order')
                    ->get();
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $contactReasons
        ]);
    }
}
