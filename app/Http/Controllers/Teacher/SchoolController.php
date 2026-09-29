<?php

namespace App\Http\Controllers\Teacher;

use App\Models\Masjid;
use App\Support\TeacherSchoolHeader;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The school the SERVER bound for this request, for the teacher shell's header.
 *
 * `GET /api/teacher/masjids/{masjid_id}/school`, inside the `tenant` group. A
 * teacher can belong to several schools, and `/teacher/user` names the DEFAULT one;
 * a header read from it paints that school's name and logo over whichever school's
 * classes the picker has selected. This route is the header's honest source: the
 * id in the URL was verified against the teacher's own memberships by
 * ResolveMasjidTenant before this ran (another school's id is a 403), and the
 * body names the tenant the resolver actually bound, never the URL's claim.
 *
 * Name, logo, type and a calendar flag: public facts about a school the teacher
 * already belongs to. No person appears in it.
 */
class SchoolController extends TeacherController
{
    public function show($masjid_id): JsonResponse
    {
        $masjidId = app(TenantContext::class)->get();

        if ($masjidId === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        // Masjid is not tenant-scoped (it IS the tenant), so this is the bound
        // id looked up directly. A membership in a trashed school never binds
        // (TenantResolver drops it), so a missing row here is only a race.
        $masjid = Masjid::withoutGlobalScopes()->findOrFail((int) $masjidId);

        return response()->json([
            'status' => 'success',
            'data' => TeacherSchoolHeader::for($masjid),
        ], Response::HTTP_OK);
    }
}
