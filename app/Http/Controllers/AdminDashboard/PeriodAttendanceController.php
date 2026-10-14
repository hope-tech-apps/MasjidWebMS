<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Services\Schools\PeriodAttendance;
use App\Support\SchoolCalendarReaders;
use Illuminate\Http\{JsonResponse, Request};

/** Office corrections use the same register action; routes require manage contacts. */
class PeriodAttendanceController extends Controller
{
    public function index(Request $request, $masjid_id, $meeting_id): JsonResponse
    {
        $request->validate(['date'=>['sometimes','date_format:Y-m-d']]);
        return app(PeriodAttendance::class)->read($request,(int)$masjid_id,(int)$meeting_id,$request->query('date',SchoolCalendarReaders::for((int)$masjid_id)->today()),true);
    }
    public function save(Request $request, $masjid_id, $meeting_id): JsonResponse { return app(PeriodAttendance::class)->save($request,(int)$masjid_id,(int)$meeting_id,true); }
}
