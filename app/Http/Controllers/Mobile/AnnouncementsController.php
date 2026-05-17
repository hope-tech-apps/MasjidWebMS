<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;

class AnnouncementsController extends Controller
{
    public function index($masjid_id)
    {
        $announcements = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::ANNOUNCEMENTS),
            MobileCache::TTL_SHORT,
            function () use ($masjid_id) {
                $masjid = Masjid::findOrFail($masjid_id);
                return Announcement::where('masjid_id', $masjid->id)
                    ->with('image')
                    ->get();
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $announcements
        ]);
    }
}
