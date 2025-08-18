<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Masjid;
use Carbon\Carbon;

class AnnouncementsController extends Controller
{
    public function index ($masjid_id) {
        $masjid = Masjid::findOrFail($masjid_id);
        $from = Carbon::now()->addDays(-1);
        $to = $from->copy()->addDays(7);

        $announcements = Announcement::where('masjid_id', $masjid->id)->with('image')->get();
        // ->whereBetween('start_date', [$from, $to])

        return response()->json([
            'status' => 'success',
            'data' => $announcements
        ]);
    }
}
