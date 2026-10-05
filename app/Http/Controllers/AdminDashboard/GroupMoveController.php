<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Exceptions\RosterMoveRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\MoveStudentRequest;
use App\Http\Requests\Admin\Groups\PreviewMoveRequest;
use App\Models\Group;
use App\Models\User;
use App\Support\RosterMove;
use App\Support\SchoolCalendar;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Moving a student to another class: the read ("what will happen") and the
 * write. A sibling of GroupWithdrawalController, and as thin: every rule is in
 * App\Support\RosterMove, and the two verbs here run the same decision so the
 * office is told after the tap what it read before it.
 *
 * The office says who belongs in a class; a teacher records who was in the
 * room. So both routes are `permission:manage contacts` and there is no
 * teacher or family route.
 *
 * Tenant isolation is the guardrail, not hand-filtering: Group and
 * GroupMembership are both BelongsToMasjid, so another organisation's roster id
 * is a 404 and another organisation's class id is the same refusal as a class
 * that does not exist. See .claude/rules/tenant-scoping.md.
 */
class GroupMoveController extends Controller
{
    /**
     * GET .../groups/{group_id}/members/{membership_id}/move?to_group_id=&moved_on=
     *
     * Takes no lock and writes nothing. A move the server would refuse is a
     * 200 with `can_move: false` and the sentence, so the dialog can print it
     * where the office is reading. `open_group` is the class to open to clear
     * the refusal, and may carry `membership_id`: the roster row there that
     * the remedy is about.
     */
    public function show(PreviewMoveRequest $request, RosterMove $mover, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        try {
            $plan = $mover->preview(
                $group,
                $membership,
                Group::query()->find($request->integer('to_group_id')),
                $this->day($request, $group),
            );
        } catch (RosterMoveRefused $refused) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'can_move' => false,
                    'refusal' => $refused->getMessage(),
                    'open_group' => $refused->openGroup(),
                    'path' => null,
                    'grade_label' => $membership->grade_label,
                    'lines' => [],
                ],
            ], Response::HTTP_OK);
        }

        return response()->json(['status' => 'success', 'data' => $plan->toPreview()], Response::HTTP_OK);
    }

    /**
     * POST .../groups/{group_id}/members/{membership_id}/move
     *
     * One transaction. A refusal changes nothing and is answered in the sibling
     * verb's shape, `{status: 'error', message}`: 422 when the request cannot
     * be, 409 when the rosters are in a state the move must not touch or
     * changed while the office was looking.
     *
     * EVERY OPTION IS NAMED HERE. The move also takes options that only a
     * whole-class run may set (`run`, `whole_class`, `standing_before_id`,
     * `today`, `attempts`); none is read from the request, so a body that
     * carries one changes nothing.
     *
     * `consent_must_be_echoed` is this verb's own and always on: the dialog
     * sends back what it showed about consent (`expected_consent`), so a body
     * without it comes from a page opened before a move carried consent, and
     * such a tap is told to reload instead of carrying a consent its screen
     * said would not move (`RosterMove::refuseWhenNotWhatWasShown`).
     */
    public function store(MoveStudentRequest $request, RosterMove $mover, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        try {
            $plan = $mover->move(
                $group,
                $membership,
                $request->integer('to_group_id'),
                $this->day($request, $group),
                [
                    'grade_given' => $request->has('grade_label'),
                    'grade_label' => $request->input('grade_label'),
                    'expected_path' => $request->input('expected_path'),
                    'expected_first_day' => $request->input('expected_first_day'),
                    'expected_joined_on' => $request->input('expected_joined_on'),
                    'expected_consent' => $request->input('expected_consent'),
                    'expected_bucks_rule' => $request->input('expected_bucks_rule'),
                    'consent_must_be_echoed' => true,
                ],
                $this->actor($request),
            );
        } catch (RosterMoveRefused $refused) {
            return response()->json([
                'status' => 'error',
                'message' => $refused->getMessage(),
                'open_group' => $refused->openGroup(),
            ], $refused->status());
        }

        $answer = $plan->toAnswer();

        return response()->json([
            'status' => 'success',
            'message' => implode(' ', $answer['lines']),
            'data' => $answer,
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /** The day the office chose, or today on the school's clock. */
    private function day(Request $request, Group $group): string
    {
        return $request->input('moved_on') ?: SchoolCalendar::for((int) $group->masjid_id)->today();
    }

    /** The staff member behind the act, or null: the same read as the sibling verbs. */
    private function actor(Request $request): ?User
    {
        $principal = $request->user();

        return $principal instanceof User ? $principal : null;
    }
}
