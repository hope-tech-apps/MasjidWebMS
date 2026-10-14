<?php

namespace App\Http\Controllers\Teacher;

use App\Services\Schools\PeriodAttendance;
use App\Support\SchoolCalendarReaders;
use Illuminate\Http\{JsonResponse, Request};

/** Meeting authority replaces the class-leader gate only on these period-register routes. */
class PeriodAttendanceController extends TeacherController
{
    public function index(Request $request, $masjid_id, $meeting_id): JsonResponse
    {
        $request->validate(['date'=>['sometimes','date_format:Y-m-d']]);
        return app(PeriodAttendance::class)->read($request,(int)$masjid_id,(int)$meeting_id,$request->query('date',SchoolCalendarReaders::for((int)$masjid_id)->today()));
    }
    public function classroom(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $request->validate(['date'=>['sometimes','date_format:Y-m-d']]);
        return app(PeriodAttendance::class)->teacherClass($request,(int)$masjid_id,(int)$group_id,$request->query('date',SchoolCalendarReaders::for((int)$masjid_id)->today()));
    }
    public function save(Request $request, $masjid_id, $meeting_id): JsonResponse { return app(PeriodAttendance::class)->save($request,(int)$masjid_id,(int)$meeting_id); }
}
