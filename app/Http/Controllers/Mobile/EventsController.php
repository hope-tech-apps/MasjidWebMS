<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Masjid;
use Carbon\Carbon;

class EventsController extends Controller
{
    public function index ($masjid_id) {
        $masjid = Masjid::findOrFail($masjid_id);
        $from = Carbon::now()->addDays(-1);
        $to = $from->copy()->addDays(7);

        $events = Event::where('masjid_id', $masjid->id)->whereBetween('start', [$from, $to])->get();

        return response()->json([
            'status' => 'success',
            'data' => $events
        ]);
    }
}
