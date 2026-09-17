<?php

namespace App\Http\Controllers\Family;

use App\Models\ArabicDailyNote;
use App\Models\GroupMembership;
use App\Support\Letters\CurriculumRegistry;
use App\Support\Letters\LetterTracker;
use Illuminate\Http\Request;
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
 *
 * `?alphabet=` picks the Arabic or the English track and changes nothing else —
 * same gate, same route, same shape of answer. It adds no non-GET route, so the
 * counted write list in `tests/Feature/FamilyPortalTest.php` is untouched: the
 * family realm stays read-mostly, and a second alphabet did not make it less so.
 */
class ArabicLettersController extends FamilyController
{
    public function forMember(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $tracker = new LetterTracker(CurriculumRegistry::fromInput($request->query('alphabet')));

        $group = $this->group($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);

        if (! in_array((int) $membership->contact_id, $this->wardContactIds($group), true)) {
            abort(Response::HTTP_FORBIDDEN, 'That is not your child.');
        }

        return response()->json([
            'status' => 'success',
            'data' => $tracker->forStudent($group, $membership),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../groups/{group_id}/members/{membership_id}/arabic-notes
     *
     * The teacher's daily notes on this child's Arabic, newest first — READ ONLY,
     * like everything else on this controller.
     *
     * Gated exactly as `forMember` is, by the WARD EDGE, because it is the same
     * record about the same child from the same teacher: a parent who may read
     * the letters may read what the teacher wrote about the lessons that
     * produced them, and nobody else may. Owner decision 2026-09-17: parents
     * see these, on the ground the ḥifẓ payload already states — a record a
     * parent cannot read the detail of is not a record they have been given.
     *
     * A GET, so the family realm's counted write list is untouched.
     */
    public function dailyNotes(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = $this->group($group_id);
        $membership = $group->memberships()->participants()->findOrFail($membership_id);

        if (! in_array((int) $membership->contact_id, $this->wardContactIds($group), true)) {
            abort(Response::HTTP_FORBIDDEN, 'That is not your child.');
        }

        $notes = ArabicDailyNote::where('group_membership_id', $membership->id)
            ->with('markedBy:id,name')
            ->orderByDesc('session_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 25))
            ->through(fn (ArabicDailyNote $n) => [
                'id' => (int) $n->id,
                // The stored DAY, as a string. Never an ISO timestamp: a date at
                // UTC midnight renders as the day before for every parent west
                // of UTC, and a note about the wrong lesson is worse than none.
                'session_date' => $n->session_date?->toDateString(),
                'note' => $n->note,
                'written_by' => $n->markedBy ? ['name' => $n->markedBy->name] : null,
            ]);

        return response()->json([
            'status' => 'success',
            'data' => $notes,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }
}
