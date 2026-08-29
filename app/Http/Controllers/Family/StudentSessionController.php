<?php

namespace App\Http\Controllers\Family;

use App\Http\Requests\Admin\Contacts\SetAvatarRequest;
use App\Models\Contact;
use App\Models\GroupMembership;
use App\Support\Avatar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Child mode: the parent hands the device over, and the child gets a screen of
 * their own.
 *
 * ## Why this exists rather than a student login
 *
 * A six-year-old has no email address and cannot be issued a password — this
 * platform says so in `Contact::getAuthPassword()`, and a school office cannot
 * run a reset desk for two hundred families. ClassDojo solves the same problem
 * by letting the youngest students sign in "from your parent's account". This is
 * that, with a real boundary underneath.
 *
 * ## What makes it a boundary
 *
 * The token is minted from the PARENT — they are who the server authenticated —
 * but it carries only `student:{membership}` and NOT `family`. Every parent
 * surface is behind `family.parent`, so with the phone in the child's hands the
 * class feed, the teacher threads and the other sibling's records are refused by
 * the server. The child screen is behind `family.student`, which additionally
 * compares the ability to the membership in the URL, so a hand-off minted for
 * one sibling cannot address the other.
 *
 * It expires in an hour. A device left on the kitchen table stops being a
 * session by itself.
 */
class StudentSessionController extends FamilyController
{
    /**
     * POST .../members/{membership_id}/student-session — the parent starts it.
     *
     * Authorised by the WARD EDGE, exactly like setting the child's avatar: a
     * parent may hand the device to their own child and to nobody else's.
     */
    public function store($masjid_id, $group_id, $membership_id)
    {
        $group = $this->group($group_id);
        $contact = $this->contact();

        $wardContactIds = $this->wardContactIds($group);

        $membership = $group->memberships()->participants()->findOrFail($membership_id);

        if (! in_array((int) $membership->contact_id, $wardContactIds, true)) {
            abort(Response::HTTP_FORBIDDEN, 'That is not your child.');
        }

        $token = $contact->createStudentHandoffToken((int) $membership->id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'token' => $token->plainTextToken,
                'membership_id' => (int) $membership->id,
                'group_id' => (int) $group->id,
                'student' => $this->student($membership),
                // Stated so the client can show it rather than guessing, and so
                // a child is handed back before it lapses mid-choice.
                'expires_in_minutes' => 60,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * GET .../members/{membership_id}/student/me — the one thing child mode
     * shows: who you are, and the face you picked.
     */
    public function show($masjid_id, $group_id, $membership_id)
    {
        $group = $this->group($group_id);
        $membership = $group->memberships()->participants()->findOrFail($membership_id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'student' => $this->student($membership),
                'catalogue' => Avatar::catalogue(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * PUT .../members/{membership_id}/student/avatar — the child picks.
     *
     * Writes the CHILD'S OWN choice, the same column a parent writes on their
     * behalf, and never the staff override — a teacher's override stays in
     * place, and the child's pick is waiting underneath it if it is ever
     * removed.
     */
    public function updateAvatar(SetAvatarRequest $request, $masjid_id, $group_id, $membership_id)
    {
        $group = $this->group($group_id);
        $membership = $group->memberships()->participants()->findOrFail($membership_id);

        $contact = $membership->contact;

        if ($contact === null) {
            abort(Response::HTTP_NOT_FOUND, 'That student has no contact record.');
        }

        $contact->fill([
            'avatar_character' => $request->input('character') ?: null,
            'avatar_tone' => $request->input('tone') ?: null,
            'avatar_color' => $request->input('color') ?: null,
        ])->save();

        return response()->json([
            'status' => 'success',
            'data' => $this->student($membership->fresh('contact')),
        ], Response::HTTP_OK);
    }
}
