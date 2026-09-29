<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Requests\Teacher\SetPointsPeriodRequest;
use App\Models\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * A teacher opting their class into (or out of) the weekly points view
 * (T-003.2, owner 2026-09-28: "points need a reset option at the end of the week
 * that teachers can opt into").
 *
 * ## The reset is a VIEW boundary. Nothing is deleted or revoked.
 *
 * `groups.points_period` decides only how the class's points are SHOWN: 'weekly'
 * makes the teacher's totals and the family's headline figure the current week,
 * with the running history kept beside it. It never touches a `behavior_awards`
 * row, so turning it off again gives the class its running total back exactly as
 * it was, which is the whole reason this is safe to hand to a teacher without a
 * backup or a confirmation dialog.
 *
 * ## It applies to EVERY teacher of the class
 *
 * `points_period` is a column on the class, not on the teacher, because a family
 * sees one number for their child and two teachers of one room must not be
 * showing it two ways. The screen says so beside the toggle, and the response
 * repeats it (`applies_to`), so a teacher never discovers it from a colleague.
 *
 * ## One write, a new verb
 *
 * `PUT .../points-period` is the teacher realm's +1 write
 * (TeacherRealmTest). PUT because setting the same value twice changes nothing.
 */
class PointsPeriodController extends TeacherController
{
    public function update(SetPointsPeriodRequest $request, $masjid_id, $group_id)
    {
        // Tenant-scoped, and `teacher.leads` has already proven this teacher leads it.
        $group = Group::findOrFail($group_id);

        $group->points_period = (string) $request->validated('points_period');
        $group->save();

        return response()->json([
            'status' => 'success',
            'data' => [
                'points_period' => $group->pointsPeriod(),
                // Said out loud, because it is not obvious that it is not per teacher.
                'applies_to' => 'every teacher of this class',
            ],
        ], Response::HTTP_OK);
    }
}
