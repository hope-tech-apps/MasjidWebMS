<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Requests\Admin\Contacts\SetAvatarRequest;
use App\Models\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * A teacher setting a student's avatar — the OVERRIDE, never the child's own.
 *
 * The admin equivalent (AdminDashboard\ContactAvatarController) is keyed by
 * {contact_id}, which for a teacher would need a contact→participant→leader-group
 * reverse lookup to scope safely. This route is keyed by {group_id}/{membership_id}
 * instead, so the `teacher.leads` middleware already proved the teacher leads the
 * class and the membership is resolved WITHIN that class — a teacher can only ever
 * dress a student in a class they teach. The override semantics (staff_avatar_*
 * laid on top of, never overwriting, the child's own choice) match
 * ContactAvatarController exactly; restoring the child's choice is destroyOverride.
 *
 * $masjid_id is route-shape only (never trusted); the tenant is bound from the
 * teacher's membership.
 */
class StudentAvatarController extends TeacherController
{
    public function update(SetAvatarRequest $request, $masjid_id, $group_id, $membership_id)
    {
        $membership = $this->participant($group_id, $membership_id);
        $contact = $membership->contact;

        $contact->fill([
            'staff_avatar_character' => $request->input('character') ?: null,
            'staff_avatar_tone' => $request->input('tone') ?: null,
            'staff_avatar_color' => $request->input('color') ?: null,
        ])->save();

        return response()->json([
            'status' => 'success',
            'data' => $this->student($membership->fresh('contact')),
        ], Response::HTTP_OK);
    }

    public function destroyOverride($masjid_id, $group_id, $membership_id)
    {
        $membership = $this->participant($group_id, $membership_id);
        $contact = $membership->contact;

        $contact->fill([
            'staff_avatar_character' => null,
            'staff_avatar_tone' => null,
            'staff_avatar_color' => null,
        ])->save();

        return response()->json([
            'status' => 'success',
            'data' => $this->student($membership->fresh('contact')),
        ], Response::HTTP_OK);
    }

    /**
     * The membership, resolved as a PARTICIPANT of the (already leader-verified)
     * group. A guardian edge or a membership in another class resolves to 404,
     * so a teacher cannot reach past their own class's students. Group findOrFail
     * is tenant-scoped.
     */
    private function participant($group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);

        return $group->memberships()
            ->participants()
            ->with('contact')
            ->findOrFail($membership_id);
    }
}
