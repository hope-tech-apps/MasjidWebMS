<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Masjid;
use App\Support\MobileCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class EventsController extends Controller
{
    public function index($masjid_id)
    {
        $events = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::EVENTS),
            MobileCache::TTL_SHORT,
            function () use ($masjid_id) {
                $masjid = Masjid::findOrFail($masjid_id);
                $from = Carbon::now()->addDays(-1);
                $to = $from->copy()->addDays(7);

                return Event::where('masjid_id', $masjid->id)
                    ->whereBetween('start', [$from, $to])
                    ->get();
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $events
        ]);
    }
}
