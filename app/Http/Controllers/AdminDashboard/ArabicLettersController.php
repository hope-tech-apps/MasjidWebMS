<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Arabic\MarkDrillRequest;
use App\Http\Requests\Admin\Arabic\SetClassStageRequest;
use App\Models\ArabicLetterProgress;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Support\Arabic\ArabicCurriculum;
use App\Support\Letters\CurriculumRegistry;
use App\Support\Letters\LetterTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The letter tracker, from the teacher's side.
 *
 * Gated by the CONTACTS permissions like the rest of the classroom module: the
 * roster, the behaviour points and the ḥifẓ diary all sit there, and letter
 * progress is the same kind of record about the same children. Minting a new
 * permission would change the seeded set `RolePermissionBridgeTest` pins.
 *
 * Every read is assembled by `LetterTracker`, and every scope decision is the
 * curriculum's — so the class overview, one child's card and the parent's view
 * cannot disagree about what counts.
 *
 * ## Two alphabets, four routes, no new routes
 *
 * `?alphabet=` on the reads and `alphabet` in the mark body pick the track;
 * absent means Arabic, so every client written before the English track keeps
 * working unchanged. The alternative — a parallel `/english-letters` prefix —
 * would have doubled the route table, the permission entries and the family
 * realm's counted write list to serve the same four questions about the same
 * children.
 *
 * `setStage` is the exception and takes no track: see below.
 */
class ArabicLettersController extends Controller
{
    /** The whole class at a glance, plus the stage ladder. */
    public function index(Request $request, $masjid_id, $group_id)
    {
        $tracker = new LetterTracker(CurriculumRegistry::fromInput($request->query('alphabet')));

        $group = Group::findOrFail($group_id);

        $students = $group->memberships()
            ->participants()->current()
            ->with('contact')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $tracker->classOverview($group, $students),
        ], Response::HTTP_OK);
    }

    /** One student's tracker: every letter, its shapes, and every drill. */
    public function show(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $tracker = new LetterTracker(CurriculumRegistry::fromInput($request->query('alphabet')));

        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);

        return response()->json([
            'status' => 'success',
            'data' => $tracker->forStudent($group, $membership),
        ], Response::HTTP_OK);
    }

    /**
     * Mark one drill for one student.
     *
     * An UPSERT against (student, alphabet, drill), which the unique index
     * enforces — so a teacher tapping twice on a slow connection cannot mint a
     * second cell, and the two tracks cannot overwrite each other.
     */
    public function mark(MarkDrillRequest $request, $masjid_id, $group_id, $membership_id)
    {
        $tracker = LetterTracker::for(
            (string) ($request->validated('alphabet') ?? CurriculumRegistry::ALPHABET_ARABIC)
        );
        $curriculum = $tracker->curriculum();

        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->current()->findOrFail($membership_id);

        $drillId = (string) $request->validated('drill_id');
        $stage = $tracker->stageFor($group);

        // Judged against the CLASS'S stage on the NAMED alphabet. A drill from
        // further up the qāʿidah is not part of this room's denominator, so
        // marking it would put a tick in a cell no progress bar counts and no
        // screen shows — and a drill from the OTHER alphabet is not part of this
        // track at all, so `ba` sent as English and `a` sent as Arabic are both
        // refused here rather than quietly stored where nothing will ever read
        // them.
        if (! $curriculum->isValidDrill($drillId, $stage)) {
            return response()->json([
                'status' => 'error',
                'message' => 'That drill is not part of what this class is working on ('
                    .$curriculum->label().' — '.$tracker->stagePayload($stage)['label'].').',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $row = ArabicLetterProgress::firstOrNew([
            'group_membership_id' => $membership->id,
            'alphabet' => $curriculum->alphabetId(),
            'drill_id' => $drillId,
        ]);

        $row->group_id = $group->id;
        $row->moveTo((string) $request->validated('status'), Auth::id());
        $row->save();

        return response()->json([
            'status' => 'success',
            'data' => $tracker->forStudent($group, $membership->load('contact')),
        ], Response::HTTP_OK);
    }

    /**
     * Set how far through the qāʿidah this CLASS is working.
     *
     * The stage belongs to the room, not to thirty children each carrying a
     * number that has to agree with where they sit. Moving it BACK does not
     * delete anything — a drill mastered at a later stage keeps its row and
     * reappears intact when the class moves forward again.
     *
     * ARABIC ONLY. `groups.arabic_stage` is the qāʿidah's ladder and nothing
     * else's; the English track has a single stage, so there is nothing to set,
     * and accepting the call would write a value that silently moves the class's
     * ARABIC denominator from an English screen.
     */
    public function setStage(SetClassStageRequest $request, $masjid_id, $group_id)
    {
        if ($request->validated('alphabet') === CurriculumRegistry::ALPHABET_ENGLISH) {
            return response()->json([
                'status' => 'error',
                'message' => 'The English track has a single stage, so there is nothing to set. '
                    .'This setting is the qāʿidah\'s, and changing it here would move the class\'s Arabic progress.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tracker = LetterTracker::for(CurriculumRegistry::ALPHABET_ARABIC);

        $group = Group::findOrFail($group_id);
        $group->arabic_stage = (string) $request->validated('stage');
        $group->save();

        $students = $group->memberships()->participants()->current()->with('contact')->get();

        return response()->json([
            'status' => 'success',
            'message' => 'This class is now working on '
                .ArabicCurriculum::STAGE_LABELS[$group->arabicStage()].'.',
            'data' => $tracker->classOverview($group->fresh(), $students),
        ], Response::HTTP_OK);
    }
}
