<?php

namespace App\Http\Controllers\Teacher;

use App\Models\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * A teacher's classes — the list they land on and one class's roster.
 *
 * "Only my classes" is enforced two ways that agree: index() reads through
 * Group::scopeLedBy (group_staff), and show() sits behind the `teacher.leads`
 * middleware which refuses a group this teacher does not lead. Both payloads are
 * names-only by construction (TeacherController::classPayload).
 *
 * The $masjid_id argument is present only to match the family-style route shape
 * (/api/teacher/masjids/{masjid_id}/...); it is never trusted — the tenant is
 * bound from the teacher's membership, and every query is tenant-scoped.
 */
class GroupsController extends TeacherController
{
    /** Every class this teacher leads. */
    public function index($masjid_id)
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->taughtGroups()->map(fn (Group $g): array => $this->classPayload($g))->values(),
        ], Response::HTTP_OK);
    }

    /**
     * One class the teacher leads. The `teacher.leads` middleware has already
     * verified they lead it, so findOrFail here only re-resolves the (already
     * authorized, tenant-scoped) group for serialization.
     */
    public function show($masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        return response()->json([
            'status' => 'success',
            'data' => $this->classPayload($group),
        ], Response::HTTP_OK);
    }
}
