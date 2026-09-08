<?php

namespace App\Http\Controllers\Teacher;

use App\Enums\GroupNotificationEvent;
use App\Http\Requests\Teacher\SaveAssignmentScoresRequest;
use App\Http\Requests\Teacher\StoreClassAssignmentRequest;
use App\Jobs\SendGroupNotificationJob;
use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The class gradebook: work set, and marks against it.
 *
 * This is the FIRST place the teacher realm creates and deletes a CLASS-LEVEL
 * object rather than only a record about a child. It is still not roster
 * mutation — a teacher may say what the class was asked to do and how each child
 * did, never who belongs in the room.
 *
 * ## THE DANGLING-REFERENCE RULE
 *
 * `class_assignments` soft-deletes, so `assignment_scores` rows can outlive a
 * resolvable parent. NO QUERY HERE STARTS FROM `assignment_scores` ALONE: every
 * read joins the assignment or uses `whereHas('assignment')` and inherits its
 * SoftDeletes scope. Breaking that rule resurrects withdrawn work into a child's
 * average — the same shape as the `offerings.group_id` incident in
 * .claude/rules/groups.md.
 *
 * Payloads are built through TeacherController::student(), the realm's
 * names-only boundary.
 */
class GradebookController extends TeacherController
{
    /** Work set for this class, newest first, each with how much of it is marked. */
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $roster = $group->memberships()->participants()->count();

        $assignments = $group->assignments()
            ->withCount('scores')
            ->orderByDesc('assigned_on')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $assignments->map(fn (ClassAssignment $a): array => $this->assignment($a) + [
                'scored' => (int) $a->scores_count,
                'roster' => $roster,
            ])->values(),
        ], Response::HTTP_OK);
    }

    public function store(StoreClassAssignmentRequest $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $assignment = ClassAssignment::create($request->validated() + [
            'masjid_id' => $group->masjid_id,
            'group_id' => $group->id,
            'created_by_user_id' => Auth::id(),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->assignment($assignment),
        ], Response::HTTP_CREATED);
    }

    /**
     * One assignment with the LIVE ROSTER left-joined against its marks.
     *
     * The roster leads, so a child enrolled in week six appears on week one's
     * work with a blank cell and no backfill ever runs — and a child who has
     * left stops appearing without their marks being destroyed.
     */
    public function show(Request $request, $masjid_id, $group_id, $assignment_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $assignment = $group->assignments()->findOrFail($assignment_id);

        $students = $group->memberships()
            ->participants()
            ->with('contact:id,first_name,last_name,' . Contact::AVATAR_COLUMNS)
            ->get();

        $scores = $assignment->scores()->get()->keyBy('group_membership_id');

        return response()->json([
            'status' => 'success',
            'data' => $this->assignment($assignment) + [
                'students' => $students->map(function (GroupMembership $m) use ($scores): array {
                    $score = $scores->get($m->id);

                    return $this->student($m) + [
                        // An unmarked child is NULL, never a zero. The register
                        // makes the same distinction for the same reason.
                        'status' => $score?->status,
                        'points_earned' => $score && $score->points_earned !== null
                            ? (float) $score->points_earned
                            : null,
                        'note' => $score?->note,
                    ];
                })->values(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Correct the work itself.
     *
     * Lowering `points_possible` below a mark already entered is REFUSED and the
     * students are named. Silently allowing it would produce 12 out of 10 on a
     * screen a parent may be shown. The maximum is deliberately not snapshotted
     * onto each score, so a legitimate correction here moves every mark on this
     * assignment at once — which is the behaviour you want when the maximum was
     * simply typed wrong.
     */
    public function update(StoreClassAssignmentRequest $request, $masjid_id, $group_id, $assignment_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $assignment = $group->assignments()->findOrFail($assignment_id);

        $ceiling = (int) $request->validated('points_possible');

        $over = $assignment->scores()
            ->where('points_earned', '>', $ceiling)
            ->with('membership.contact:id,first_name,last_name')
            ->get();

        if ($over->isNotEmpty()) {
            $names = $over->map(fn (AssignmentScore $s) => trim(
                ($s->membership?->contact?->first_name ?? '') . ' ' . ($s->membership?->contact?->last_name ?? '')
            ))->filter()->values()->all();

            return response()->json([
                'status' => 'failed',
                'data' => ['points_possible' => [
                    'Some marks are already above that: ' . implode(', ', $names)
                        . '. Lower their marks first, or choose a higher maximum.',
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $assignment->update($request->validated());

        return response()->json([
            'status' => 'success',
            'data' => $this->assignment($assignment->fresh()),
        ], Response::HTTP_OK);
    }

    /**
     * Withdraw work. A SOFT delete: marks a parent may already have been told
     * about are not destroyed, they simply stop counting and stop showing.
     */
    public function destroy(Request $request, $masjid_id, $group_id, $assignment_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $assignment = $group->assignments()->findOrFail($assignment_id);

        $assignment->delete();

        return response()->json(['status' => 'success', 'data' => ['id' => (int) $assignment->id]], Response::HTTP_OK);
    }

    /**
     * Mark the whole class in one request — the same reasoning as the register's
     * single Save, and idempotent for the same reason: every row is an upsert
     * against `gradebook_score_unique`.
     */
    public function saveScores(SaveAssignmentScoresRequest $request, $masjid_id, $group_id, $assignment_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $assignment = $group->assignments()->findOrFail($assignment_id);

        $allowed = $group->memberships()->participants()->pluck('id');
        $rows = collect($request->validated('scores'));

        $unknown = $rows->pluck('membership_id')->map(fn ($id) => (int) $id)->diff($allowed);

        if ($unknown->isNotEmpty()) {
            return response()->json([
                'status' => 'failed',
                'data' => ['scores' => ['That gradebook names someone who is not a student in this class.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The ceiling. The FormRequest cannot check this — it cannot see the
        // assignment without a query.
        $over = $rows->filter(fn ($r) => ($r['points_earned'] ?? null) !== null
            && (float) $r['points_earned'] > (float) $assignment->points_possible);

        if ($over->isNotEmpty()) {
            return response()->json([
                'status' => 'failed',
                'data' => ['scores' => [
                    'A mark is higher than this work is out of (' . $assignment->points_possible . ').',
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Which children's marks actually MOVED. One Save writes the whole class,
        // so notifying on every row would mail six families every time a teacher
        // corrected one typo.
        $moved = [];

        DB::transaction(function () use ($rows, $assignment, $group, &$moved) {
            foreach ($rows as $row) {
                $membershipId = (int) $row['membership_id'];
                $points = $row['status'] === AssignmentScore::STATUS_SCORED
                    ? $row['points_earned']
                    : null;

                $score = AssignmentScore::firstOrNew([
                    'class_assignment_id' => $assignment->id,
                    'group_membership_id' => $membershipId,
                ]);

                $unchanged = $score->exists
                    && $score->status === $row['status']
                    && $this->samePoints($score->points_earned, $points);

                $score->fill([
                    'masjid_id' => $group->masjid_id,
                    'group_id' => $group->id,
                    'status' => $row['status'],
                    'points_earned' => $points,
                    'note' => $row['note'] ?? null,
                    'scored_by_user_id' => Auth::id(),
                ])->save();

                if (! $unchanged) {
                    $moved[] = $membershipId;
                }
            }
        });

        $this->announceMarks($group, $moved);

        return $this->show($request, $masjid_id, $group_id, $assignment_id);
    }

    /**
     * One child's marks across the term.
     *
     * THE DENOMINATOR IS THEIR OWN ROWS, never the class's assignment count —
     * work a child was never given must not count against them. `excused` is
     * excluded from numerator AND denominator; `missing` scores zero but counts
     * in full. Three different sentences, which is why the column has three
     * values. No letter grade, no rank, no comparison with anyone else.
     *
     * `whereHas('assignment')` is what keeps withdrawn work out of the average.
     */
    public function forMember(Request $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);

        $scores = AssignmentScore::query()
            ->where('group_membership_id', $membership->id)
            ->whereHas('assignment')
            ->with('assignment')
            ->limit(200)
            ->get()
            ->sortByDesc(fn (AssignmentScore $s) => $s->assignment?->assigned_on)
            ->values();

        $counting = $scores->filter->countsTowardAverage();
        $earned = $counting->sum(fn (AssignmentScore $s) => (float) ($s->points_earned ?? 0));
        $possible = $counting->sum(fn (AssignmentScore $s) => (float) ($s->assignment?->points_possible ?? 0));

        return response()->json([
            'status' => 'success',
            'data' => [
                'student' => $this->student($membership),
                'summary' => [
                    'recorded' => $scores->count(),
                    'counted' => $counting->count(),
                    'excused' => $scores->where('status', AssignmentScore::STATUS_EXCUSED)->count(),
                    'points_earned' => round($earned, 2),
                    'points_possible' => round($possible, 2),
                ],
                'scores' => $scores->map(fn (AssignmentScore $s): array => [
                    'assignment' => $s->assignment ? $this->assignment($s->assignment) : null,
                    'status' => $s->status,
                    'points_earned' => $s->points_earned !== null ? (float) $s->points_earned : null,
                    'note' => $s->note,
                ])->values(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Two marks, compared as numbers.
     *
     * The `decimal:2` cast reads back "8.00" where the payload sent 8, so a
     * string comparison would call every unchanged row a change and re-mail the
     * family on every Save.
     */
    private function samePoints($stored, $incoming): bool
    {
        if ($stored === null || $incoming === null) {
            return $stored === null && $incoming === null;
        }

        return abs((float) $stored - (float) $incoming) < 0.001;
    }

    /**
     * Tell each affected family, and ONLY their own family.
     *
     * Per child, never per class: a class-wide notification would tell every
     * family that somebody's mark had been entered, which is a small disclosure
     * about a child none of them are entitled to.
     */
    private function announceMarks(Group $group, array $membershipIds): void
    {
        if ($membershipIds === []) {
            return;
        }

        $contactIds = GroupMembership::query()
            ->whereIn('id', $membershipIds)
            ->pluck('contact_id', 'id');

        foreach ($contactIds as $contactId) {
            SendGroupNotificationJob::dispatch(
                (int) $group->masjid_id,
                (int) $group->id,
                GroupNotificationEvent::GRADE_POSTED,
                aboutContactId: (int) $contactId,
                authorUserId: Auth::id(),
                authorContactId: null,
            )->afterCommit();
        }
    }

    private function assignment(ClassAssignment $a): array
    {
        return [
            'id' => (int) $a->id,
            'title' => $a->title,
            'points_possible' => (int) $a->points_possible,
            'assigned_on' => $a->assigned_on->toDateString(),
        ];
    }
}
