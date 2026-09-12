<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\RecordStudentWithdrawalRequest;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A student LEAVING a class — the third roster state.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS NOT `destroy`
 * ---------------------------------------------------------------------------
 *
 * Removing a roster row and recording that a child left are different acts with
 * opposite intentions, and the product had only the first. Removal is for a row
 * that should never have existed — a mis-typed enrolment, a rejected claim — and
 * it is refused outright once the child holds any academic history, because the
 * row is what every register mark, score, report card, ḥifẓ entry, behaviour
 * award and letter-progress row hangs off
 * (`2026_09_09_040000_stop_roster_edits_destroying_academic_records`).
 *
 * A child who LEAVES has history by definition. Their row has to stay so the
 * history stays, while the class stops treating them as present. That is
 * `left_on`: the row survives, every record keeps resolving, and the child drops
 * off the register, the gradebook, the points and ḥifẓ lists and the class
 * counts from the day they left.
 *
 * ---------------------------------------------------------------------------
 * WHAT LEAVING TAKES WITH IT, AND WHAT IT DELIBERATELY DOES NOT
 * ---------------------------------------------------------------------------
 *
 * The guardian edges pointing at this child leave on the same date — done in
 * GroupMembership's `updated` hook so it holds for every caller, exactly as the
 * deletion cascade does. That ends the CLASS-WIDE disclosures for the family
 * (class story, group-scoped threads, handouts and the emails about them), which
 * is the point: a family that left should not keep receiving a running account
 * of a class their child is no longer in.
 *
 * It does NOT touch their records, their conversation history, the consent
 * already recorded on the edge, or the parent's portal sign-in. A parent can
 * still open their own child's report card, and revoking a credential stays a
 * separate, deliberate act on the Contacts screen — a roster edit must never
 * burn one, which an earlier round already learned the hard way
 * (GroupMembershipsController::destroy).
 *
 * Reversal is one DELETE: a family that changes its mind, or a date typed
 * against the wrong child, and everything comes back with the row.
 *
 * Tenant isolation is the guardrail, not hand-filtering: Group and
 * GroupMembership are both BelongsToMasjid, so another organization's ids are a
 * 404 miss. See .claude/rules/tenant-scoping.md.
 */
class GroupWithdrawalController extends Controller
{
    /**
     * PUT .../groups/{group_id}/members/{membership_id}/withdrawal
     *
     * Idempotent: re-recording with a corrected date simply moves the date.
     */
    public function update(RecordStudentWithdrawalRequest $request, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        // A GUARDIAN EDGE DOES NOT LEAVE ON ITS OWN. It is a relationship to a
        // child, so it leaves when that child does — and ending one adult's
        // access while the child stays enrolled is a different act with its own
        // verb (remove the edge, or withdraw its consent). Refusing here keeps
        // "who left the class" answerable from the student rows alone.
        if (! in_array($membership->role, GroupMembership::PARTICIPANT_ROLES, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only a student leaves a class. A guardian entry leaves with the child it names, '
                    . 'so record that child as having left — or remove this entry from the roster instead.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $membership->markLeftByStaff(
            $this->actor($request),
            $request->filled('left_on') ? $request->date('left_on') : null,
        )->save();

        $edges = $this->edgesAlongside($membership)->whereNotNull('left_on')->count();

        return response()->json([
            'status' => 'success',
            'message' => $edges === 0
                ? 'Recorded as having left the class.'
                : sprintf(
                    'Recorded as having left the class, and %d guardian %s left with them.',
                    $edges,
                    $edges === 1 ? 'entry' : 'entries',
                ),
            'data' => $membership->fresh()->load(['contact', 'guardianOf']),
        ], Response::HTTP_OK);
    }

    /**
     * DELETE .../groups/{group_id}/members/{membership_id}/withdrawal
     *
     * Back on the roster. Both columns go to null — the same state as never
     * having left — because that is what reversal means here, and because
     * "absent means still enrolled" only works if the absent state is reachable.
     * Deliberately NOT gated on anything: a wrong leaving date locks a child out
     * of their own class's register, and an undo that can be refused is the one
     * direction this surface must never have.
     */
    public function destroy(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        $membership->returnToRoster()->save();

        $edges = $this->edgesAlongside($membership)->whereNull('left_on')->count();

        return response()->json([
            'status' => 'success',
            'message' => $edges === 0
                ? 'Back on the roster.'
                : sprintf(
                    'Back on the roster, with %d guardian %s.',
                    $edges,
                    $edges === 1 ? 'entry' : 'entries',
                ),
            'data' => $membership->fresh()->load(['contact', 'guardianOf']),
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /** The guardian edges that follow this child in and out of the class. */
    private function edgesAlongside(GroupMembership $membership)
    {
        return GroupMembership::query()
            ->where('group_id', $membership->group_id)
            ->where('guardian_of_contact_id', $membership->contact_id);
    }

    /**
     * The staff member behind the act, when there is one. Null for a console or
     * seeder path, on the same reasoning `confirmedByStaff` records: an act with
     * no named actor is still better evidence than no record of the act at all.
     */
    private function actor(Request $request): ?User
    {
        $principal = $request->user();

        return $principal instanceof User ? $principal : null;
    }
}
