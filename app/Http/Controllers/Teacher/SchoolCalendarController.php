<?php

namespace App\Http\Controllers\Teacher;

use App\Support\SchoolCalendar;
use App\Support\SchoolCalendarPayload;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The teacher's own school's calendar: meeting days, no-school days, and the
 * next twelve meeting days.
 *
 * Reference data for the bound tenant, like /curriculum beside it. The tenant
 * comes from the teacher's membership (ResolveMasjidTenant), so another school's
 * id in the URL is a 403 before this runs. Not capability-gated: a school with
 * no calendar answers `years: []`. Dates and office-written reasons only — no
 * person appears in it.
 */
class SchoolCalendarController extends TeacherController
{
    public function index($masjid_id): JsonResponse
    {
        $masjidId = app(TenantContext::class)->get();

        if ($masjidId === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'status' => 'success',
            'data' => SchoolCalendarPayload::reader(SchoolCalendar::for((int) $masjidId)),
        ], Response::HTTP_OK);
    }
}
