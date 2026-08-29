<?php

namespace App\Http\Controllers\Family;

use App\Models\GroupMembership;
use App\Support\Arabic\ArabicTracker;
use Symfony\Component\HttpFoundation\Response;

/**
 * A parent watching their own child's letters — READ ONLY.
 *
 * Marking is the teacher's, exactly as behaviour points and ḥifẓ are: a parent
 * seeing progress is the point, but a parent able to tick "mastered" would make
 * the record say something the classroom never observed.
 *
 * Authorised by the WARD EDGE and not by consent, like the participant thread
 * about the same child: consent gates BROADCASTS, and a child's own academic
 * record is not a broadcast (.claude/rules/groups.md).
 */
class ArabicLettersController extends FamilyController
{
    public function forMember($masjid_id, $group_id, $membership_id)
    {
        $group = $this->group($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);

        if (! in_array((int) $membership->contact_id, $this->wardContactIds($group), true)) {
            abort(Response::HTTP_FORBIDDEN, 'That is not your child.');
        }

        return response()->json([
            'status' => 'success',
            'data' => ArabicTracker::forStudent($group, $membership),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }
}
