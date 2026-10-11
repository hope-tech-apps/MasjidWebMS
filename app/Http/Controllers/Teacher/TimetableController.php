<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Support\{TeacherSchedule, TenantContext};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Auth;

/** Read-only named meetings in the bound school; teacher, tenant and timetable gates precede this reader. */
class TimetableController extends Controller
{
    public function __construct(private TeacherSchedule $schedule) {}

    /** One response holds school-local today and each dated day of an ISO Monday-to-Sunday week. */
    public function index(Request $request, $masjid_id): JsonResponse
    {
        $input=$request->validate(['week'=>'nullable|date_format:Y-m-d']);
        $page=$this->schedule->read((int)app(TenantContext::class)->get(), (int)Auth::id(), $input['week']??null);
        return response()->json(['status'=>'success', 'data'=>$page])->header('Cache-Control', 'no-store');
    }
}
