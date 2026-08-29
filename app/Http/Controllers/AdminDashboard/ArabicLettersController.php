<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Arabic\MarkDrillRequest;
use App\Http\Requests\Admin\Arabic\SetClassStageRequest;
use App\Models\ArabicLetterProgress;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Support\Arabic\ArabicCurriculum;
use App\Support\Arabic\ArabicTracker;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Arabic letter tracker, from the teacher's side.
 *
 * Gated by the CONTACTS permissions like the rest of the classroom module: the
 * roster, the behaviour points and the ḥifẓ diary all sit there, and letter
 * progress is the same kind of record about the same children. Minting a new
 * permission would change the seeded set `RolePermissionBridgeTest` pins.
 *
 * Every read is assembled by `ArabicTracker`, and every scope decision is
 * `ArabicCurriculum`'s — so the class overview, one child's card and the parent's
 * view cannot disagree about what counts.
 */
class ArabicLettersController extends Controller
{
    /** The whole class at a glance, plus the stage ladder. */
    public function index($masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $students = $group->memberships()
            ->participants()
            ->with('contact')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => ArabicTracker::classOverview($group, $students),
        ], Response::HTTP_OK);
    }

    /** One student's tracker: 28 letters, their shapes, and every drill. */
    public function show($masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);

        return response()->json([
            'status' => 'success',
            'data' => ArabicTracker::forStudent($group, $membership),
        ], Response::HTTP_OK);
    }

    /**
     * Mark one drill for one student.
     *
     * An UPSERT against (student, drill), which the unique index enforces — so a
     * teacher tapping twice on a slow connection cannot mint a second cell.
     */
    public function mark(MarkDrillRequest $request, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->findOrFail($membership_id);

        $drillId = (string) $request->validated('drill_id');
        $stage = $group->arabicStage();

        // Judged against the CLASS'S stage: a drill from further up the qāʿidah
        // is not part of this room's denominator, so marking it would put a tick
        // in a cell no progress bar counts and no screen shows.
        if (! ArabicCurriculum::isValidDrill($drillId, $stage)) {
            return response()->json([
                'status' => 'error',
                'message' => 'That drill is not part of what this class is working on ('
                    .ArabicCurriculum::STAGE_LABELS[$stage].').',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $row = ArabicLetterProgress::firstOrNew([
            'group_membership_id' => $membership->id,
            'drill_id' => $drillId,
        ]);

        $row->group_id = $group->id;
        $row->moveTo((string) $request->validated('status'), Auth::id());
        $row->save();

        return response()->json([
            'status' => 'success',
            'data' => ArabicTracker::forStudent($group, $membership->load('contact')),
        ], Response::HTTP_OK);
    }

    /**
     * Set how far through the qāʿidah this CLASS is working.
     *
     * The stage belongs to the room, not to thirty children each carrying a
     * number that has to agree with where they sit. Moving it BACK does not
     * delete anything — a drill mastered at a later stage keeps its row and
     * reappears intact when the class moves forward again.
     */
    public function setStage(SetClassStageRequest $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);
        $group->arabic_stage = (string) $request->validated('stage');
        $group->save();

        $students = $group->memberships()->participants()->with('contact')->get();

        return response()->json([
            'status' => 'success',
            'message' => 'This class is now working on '
                .ArabicCurriculum::STAGE_LABELS[$group->arabicStage()].'.',
            'data' => ArabicTracker::classOverview($group->fresh(), $students),
        ], Response::HTTP_OK);
    }
}
