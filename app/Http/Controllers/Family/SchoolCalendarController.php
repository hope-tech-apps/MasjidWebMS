<?php

namespace App\Http\Controllers\Family;

use App\Support\SchoolCalendar;
use App\Support\SchoolCalendarPayload;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/family/masjids/{masjid_id}/school-calendar
 *
 * The school's calendar, as a parent reads it. The tenant is bound from the
 * TOKEN's contact by `family.tenant`, never from the URL, so a parent cannot
 * read another organisation's calendar by editing the id. A GET: the realm's
 * counted writes are untouched. The payload names no person — dates and the
 * reasons the office wrote — so it needs no audience decision.
 */
class SchoolCalendarController extends FamilyController
{
    public function index(): JsonResponse
    {
        $this->contact();

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
