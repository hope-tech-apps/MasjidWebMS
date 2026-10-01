<?php

namespace App\Http\Controllers\Teacher;

use App\Models\Group;
use App\Support\GroupThreadUnread;
use Illuminate\Support\Facades\Auth;
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
        $groups = $this->taughtGroups();

        // One query for the unread count of every class, never one per class.
        $unread = GroupThreadUnread::byGroup((int) Auth::id(), $groups->pluck('id')->map(fn ($id) => (int) $id)->all());

        return response()->json([
            'status' => 'success',
            'data' => $groups->map(fn (Group $g): array => $this->classPayload($g, $unread[(int) $g->id] ?? 0))->values(),
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
            'data' => $this->classPayload(
                $group,
                GroupThreadUnread::byGroup((int) Auth::id(), [(int) $group->id])[(int) $group->id] ?? 0
            ),
        ], Response::HTTP_OK);
    }
}
