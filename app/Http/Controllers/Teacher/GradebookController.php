<?php

namespace App\Http\Controllers\Teacher;

use App\Enums\GroupNotificationEvent;
use App\Http\Requests\Teacher\SaveAssignmentScoresRequest;
use App\Http\Requests\Teacher\StoreClassAssignmentRequest;
use App\Jobs\SendGroupNotificationJob;
use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\ClassGradeWeight;
use App\Models\Contact;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Http\Requests\Teacher\SaveGradeWeightsRequest;
use App\Support\ClassSubjects;
use App\Support\GradeRecord;
use App\Support\PerformanceLevel;
use App\Support\SchoolSettings;
use App\Support\SimpleMark;
use App\Support\SubjectFence;
use App\Support\SubjectKey;
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
 *
 * ## THE SUBJECT FENCE (2026-09-29)
 *
 * A teacher whose assignment to the class lists subjects can list, set, edit,
 * withdraw and mark ONLY work in those subjects, and reads only those subjects'
 * marks for a child (App\Support\SubjectFence has the rule and the reasons). The
 * office reads the same controller through the admin realm and is never fenced.
 *
 * ## SUBJECT, TYPE, WEIGHT, STANDARD
 *
 * Each piece of work may carry a subject (a snapshot of a name on the school's
 * list), a type, an optional weight and ONE standard taken from the school's own
 * pacing guide (never typed free, never invented). `PUT grade-weights` sets the
 * class's weight per type; App\Support\GradeRecord applies them.
 */
class GradebookController extends TeacherController
{
    /** Work set for this class, newest first, each with how much of it is marked. */
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $org = SchoolSettings::org($masjid_id);
        $limits = $this->limits($group);

        $roster = $group->memberships()->participants()->current()->count();

        $assignments = $group->assignments()
            ->withCount('scores')
            // The subject fence: a limited teacher lists only their own subjects.
            ->when($limits !== null, fn ($q) => $q->whereIn('subject_key', SubjectFence::allowedKeys($limits)))
            ->orderByDesc('assigned_on')
            ->orderByDesc('id')
            ->get();

        $offered = ClassSubjects::fenced(ClassSubjects::offered($group), $limits);
        $weights = ClassGradeWeight::forGroup((int) $group->id);

        return response()->json([
            'status' => 'success',
            'data' => $assignments->map(fn (ClassAssignment $a): array => $this->assignment($a) + [
                'scored' => (int) $a->scores_count,
                'roster' => $roster,
            ])->values(),
            // A SIBLING of `data`, not a member of it: `data` is a bare list here
            // and every existing caller indexes into it, so nesting it inside
            // would have been a breaking change to read the key off.
            'performance_levels' => PerformanceLevel::key(),
            // The ORGANISATION'S choices (App\Support\SchoolSettings): levels or
            // points everywhere, points or Excellent / Good / Needs work where
            // `simple_marking` is on. `default_scale` is what the form starts on.
            'default_scale' => SchoolSettings::defaultScale($org),
            'scales' => SchoolSettings::gradingScales($org),
            'simple_marks' => SimpleMark::key(),
            // The vocabulary of the new fields, served rather than hardcoded so
            // no screen re-spells a type or re-derives what a teacher may pick.
            'types' => array_map(fn (string $t): array => [
                'key' => $t, 'label' => ClassAssignment::TYPE_LABELS[$t],
            ], ClassAssignment::TYPES),
            // The class's weight per type; `{}` (never `[]`) when unweighted.
            'weights' => (object) $weights,
            'weighting_enabled' => $weights !== [],
            'weight_max' => ClassGradeWeight::MAX,
            // What THIS teacher may file work under, and where the form starts.
            'subjects' => $offered,
            'default_subject' => ClassSubjects::defaultFor($offered, $limits),
            'my_subjects' => $limits,
            // Off where the school teaches no pacing guide (BISS): the form hides
            // the Standard field and the server would not write it.
            'standards_enabled' => SchoolSettings::showsStandards($org),
        ], Response::HTTP_OK);
    }

    public function store(StoreClassAssignmentRequest $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $limits = $this->limits($group);

        $data = $request->validated();

        if ($problem = $this->refuseWork($group, $limits, $data, null)) {
            return $problem;
        }

        $assignment = ClassAssignment::create($data + [
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
        $assignment = $this->work($group, $assignment_id, $this->limits($group));

        $students = $group->memberships()
            ->participants()->current()
            ->with('contact:id,first_name,last_name,' . Contact::AVATAR_COLUMNS)
            ->get();

        $scores = $assignment->scores()->get()->keyBy('group_membership_id');

        return response()->json([
            'status' => 'success',
            'data' => $this->assignment($assignment) + [
                'students' => $students->map(function (GroupMembership $m) use ($scores, $assignment): array {
                    $score = $scores->get($m->id);

                    return $this->student($m) + [
                        // An unmarked child is NULL, never a zero. The register
                        // makes the same distinction for the same reason.
                        'status' => $score?->status,
                        'points_earned' => $score && $score->points_earned !== null
                            ? (float) $score->points_earned
                            : null,
                        'mark_label' => $score ? $this->markLabel($assignment, $score) : null,
                        'note' => $score?->note,
                    ];
                })->values(),
            ],
            // The marking screen is where a teacher most needs the key — it is
            // the moment they choose between a 2 and a 3 for a real child.
            'performance_levels' => PerformanceLevel::key(),
            'simple_marks' => SimpleMark::key(),
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
        $limits = $this->limits($group);
        // Fenced on the work AS IT IS: a Qur'an teacher cannot edit Arabic work.
        $assignment = $this->work($group, $assignment_id, $limits);

        $data = $request->validated();

        // ...and on what it is becoming: nor move their own work into Arabic.
        if ($problem = $this->refuseWork($group, $limits, $data, $assignment)) {
            return $problem;
        }

        // Excellent / Good / Needs work is stored 3/2/1, so moving work onto or
        // off that scale with marks already entered would re-read every "Good"
        // as 2 points or as "Approaching". Refused, never converted. Changes
        // between points and levels behave as they always have.
        $scale = $request->validated('scale');

        if ($scale !== $assignment->scale
            && in_array(ClassAssignment::SCALE_SIMPLE, [$scale, $assignment->scale], true)
            && $assignment->scores()->where('status', AssignmentScore::STATUS_SCORED)->exists()) {
            return response()->json([
                'status' => 'failed',
                'data' => ['scale' => [
                    'This work already has marks, so how it is marked cannot change now. Set new work instead.',
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

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

        $assignment->update($data);

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
        $assignment = $this->work($group, $assignment_id, $this->limits($group));

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
        $assignment = $this->work($group, $assignment_id, $this->limits($group));

        $allowed = $group->memberships()->participants()->current()->pluck('id');
        $rows = collect($request->validated('scores'));

        $unknown = $rows->pluck('membership_id')->map(fn ($id) => (int) $id)->diff($allowed);

        if ($unknown->isNotEmpty()) {
            // Same distinction the register makes: a departed child is a stale
            // page, not a typo.
            $left = $group->memberships()->participants()->withdrawn()->pluck('id');

            return response()->json([
                'status' => 'failed',
                'data' => ['scores' => [
                    $unknown->intersect($left)->isNotEmpty()
                        ? 'That gradebook names a child who has left the class — reload it and save the rest.'
                        : 'That gradebook names someone who is not a student in this class.',
                ]],
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

        // A LEVEL IS ONE OF FOUR WHOLE NUMBERS, not a point score that happens
        // to land in range. The ceiling check above already refuses a 5, but it
        // would happily accept 2.5 or 0 — and "2.5 Approaching-and-a-half" is not
        // a thing the school's scale can express, while 0 is not a level at all
        // (a child who did not hand the work in is `missing`, which is a status).
        // Same reason this lives here rather than in the FormRequest: the rule
        // depends on the assignment's scale, which the request cannot see.
        if ($assignment->usesLevels()) {
            $notALevel = $rows->filter(fn ($r) => ($r['points_earned'] ?? null) !== null
                && ! PerformanceLevel::isValid($r['points_earned']));

            if ($notALevel->isNotEmpty()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => ['scores' => [
                        'This work is marked on performance levels, so each mark must be one of: '
                        . implode(', ', array_reverse(PerformanceLevel::ALL)) . '.',
                    ]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        // The same for Excellent / Good / Needs work: one of three stored codes,
        // never a 2.5 or a 0. The ceiling check has already refused a 4.
        if ($assignment->usesSimpleMarks()) {
            $notAMark = $rows->filter(fn ($r) => ($r['points_earned'] ?? null) !== null
                && ! SimpleMark::isValid($r['points_earned']));

            if ($notAMark->isNotEmpty()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => ['scores' => [
                        'This work is marked Excellent, Good or Needs work, so each mark must be one of those.',
                    ]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
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

        // THE ARITHMETIC IS App\Support\GradeRecord, the one copy the family's
        // endpoint calls too: aggregated in SQL over EVERY mark (it used to come
        // from an unordered `limit(200)`), withdrawn work joined out, the two
        // scales never added together, a levels mark never a percentage.
        //
        // THE SUBJECT FENCE reaches the summary and the list alike: a limited
        // teacher's picture of a child is arithmetically incapable of containing
        // a mark in a subject they do not teach.
        $subjectKeys = SubjectFence::allowedKeys($this->limits($group));
        $summary = GradeRecord::summaryFor((int) $membership->id, $subjectKeys);

        // The LIST is a bounded page, ordered BEFORE the limit so it is honestly
        // "the most recent N" rather than whichever rows the database returned.
        $limit = (int) config('groups.records_page_size', 200);

        $scores = AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membership->id)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->when($subjectKeys !== null, fn ($q) => $q->whereIn('class_assignments.subject_key', $subjectKeys))
            ->orderByDesc('class_assignments.assigned_on')
            ->orderByDesc('class_assignments.id')
            ->select('assignment_scores.*')
            ->with('assignment')
            ->limit($limit)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'student' => $this->student($membership),
                // Aggregated over the whole term, never over the page below.
                'summary' => $summary,
                // TRUE when the summary above counts only the subjects this
                // teacher teaches in the class. The family and the office read
                // every subject, so the screen must say the two can differ.
                'fenced' => $subjectKeys !== null,
                // THE KEY, served with the data rather than hardcoded on each
                // screen, so "what does a 3 mean?" is answerable everywhere in
                // the school's own words. See App\Support\PerformanceLevel.
                'performance_levels' => PerformanceLevel::key(),
                'simple_marks' => SimpleMark::key(),
                'scores' => $scores->map(fn (AssignmentScore $s): array => [
                    'assignment' => $s->assignment ? $this->assignment($s->assignment) : null,
                    'status' => $s->status,
                    'points_earned' => $s->points_earned !== null ? (float) $s->points_earned : null,
                    'mark_label' => $s->assignment ? $this->markLabel($s->assignment, $s) : null,
                    'note' => $s->note,
                ])->values(),
                'scores_shown' => $scores->count(),
                'scores_truncated' => $summary['recorded'] > $scores->count(),
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

    /**
     * The WORD for an Excellent / Good / Needs work mark, so no screen turns the
     * stored 3/2/1 back into a word (or a number) itself. Null on every other
     * scale, where the payload has always carried the number alone.
     */
    private function markLabel(ClassAssignment $a, AssignmentScore $s): ?string
    {
        return $a->usesSimpleMarks() && $s->status === AssignmentScore::STATUS_SCORED
            ? SimpleMark::label($s->points_earned)
            : null;
    }

    private function assignment(ClassAssignment $a): array
    {
        return [
            'id' => (int) $a->id,
            'title' => $a->title,
            'points_possible' => (int) $a->points_possible,
            'scale' => $a->scale,
            'assigned_on' => $a->assigned_on->toDateString(),
            // What the work is FOR. Snapshots: null on work set before they
            // existed, which is shown as blank and never guessed.
            'subject' => $a->subject,
            'type' => $a->type,
            'type_label' => $a->type !== null ? (ClassAssignment::TYPE_LABELS[$a->type] ?? null) : null,
            // The piece's OWN override; the class's weight for its type is in
            // the index payload's `weights`. NULL means "inherit".
            'weight' => $a->weight !== null ? (int) $a->weight : null,
            // The school's guide's words, labelled as such on every screen: the
            // guide carries codes and weekly focus, not the standard's wording.
            'standard_code' => $a->standard_code,
            'curriculum_focus' => $a->curriculum_focus,
            'curriculum_week_no' => $a->curriculum_week_no !== null ? (int) $a->curriculum_week_no : null,
        ];
    }

    // ---------------------------------------------------------------- weights

    /**
     * PUT .../grade-weights: set how much each TYPE of work counts for in this
     * class (one slot in the weighted average, however many pieces it holds), or
     * clear them all.
     *
     * ALL FIVE TYPES OR NONE (SaveGradeWeightsRequest): a half-set would leave a
     * type with no answer to "how much does this count?", and the honest options
     * are to refuse the average or to invent a number. `{"clear": true}` removes
     * the weights, and with them every per-work override in the class, in the same
     * transaction, so a weight typed against a weighted class cannot lie dormant
     * and come back to life the day weights are turned on again.
     *
     * WHO MAY CHANGE THEM (review F5, 2026-09-29, superseding DECISIONS W3-3(e)): a
     * teacher who is NOT limited to some subjects, and the office. The weights are a
     * policy of the whole class, and they change the averages a parent sees for EVERY
     * subject, so a teacher limited to one (a Qur'an-only teacher) must not re-weight
     * what families read for Arabic or Mathematics. Setting and clearing are one rule:
     * a limited teacher is refused (403) and nothing is written. The old special case
     * for clearing (refused only while another subject's work carried its own weight)
     * is gone with it. `SubjectFence::mayWeighClass` is the one answer; the office
     * (an admin, never a limited Teacher) passes it, though it has no route to this
     * verb today (`GradebookWeightingTest::the_office_has_no_route_to_set_weights`).
     */
    public function saveWeights(SaveGradeWeightsRequest $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        if (! SubjectFence::mayWeighClass(Auth::user(), (int) $group->id)) {
            abort(
                Response::HTTP_FORBIDDEN,
                "The class's weights decide how much each type of work counts in every subject's average, "
                .'so only a teacher of all the subjects in this class, or the office, can change them.'
            );
        }

        $cleared = 0;

        DB::transaction(function () use ($request, $group, &$cleared): void {
            if ($request->boolean('clear')) {
                ClassGradeWeight::query()->where('group_id', $group->id)->delete();
                $cleared = ClassAssignment::query()
                    ->where('group_id', $group->id)
                    ->whereNotNull('weight')
                    ->update(['weight' => null]);

                return;
            }

            foreach ($request->validated('weights') as $type => $weight) {
                ClassGradeWeight::query()->updateOrCreate(
                    ['group_id' => $group->id, 'assignment_type' => $type],
                    ['masjid_id' => $group->masjid_id, 'weight' => (int) $weight, 'updated_by_user_id' => Auth::id()],
                );
            }
        });

        $weights = ClassGradeWeight::forGroup((int) $group->id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'weights' => (object) $weights,
                'weighting_enabled' => $weights !== [],
                'cleared_overrides' => $cleared,
            ],
        ], Response::HTTP_OK);
    }

    // ---------------------------------------------------------------- the fence

    /** @return list<string>|null  what the signed-in teacher is limited to here, NULL for all */
    private function limits(Group $group): ?array
    {
        return SubjectFence::limitsFor(Auth::user(), (int) $group->id);
    }

    /**
     * One piece of work of this class, by id, or a 404.
     *
     * To a limited teacher, work in a subject they do not teach and work with NO
     * subject are the same thing: not there. Both answer the one plain 404 (status
     * and body alike), so the refusal neither names a subject nor confirms that
     * work exists behind the id (review F4, 2026-09-29). It used to be a 403 that
     * said "You do not teach Arabic Language in this class", which told a Qur'an
     * teacher walking ids that Arabic work was there. The 403 stays for a subject
     * the teacher TYPES (`refuseWork`), which reveals nothing they did not write.
     */
    private function work(Group $group, $assignmentId, ?array $limits): ClassAssignment
    {
        $assignment = $group->assignments()->findOrFail($assignmentId);

        if (! SubjectFence::allows($limits, $assignment->subject_key)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $assignment;
    }

    /**
     * Everything about a write that needs the CLASS to judge, which the
     * FormRequest cannot see. Returns a 422 to send, NULL to carry on, and aborts
     * with a 403 for a subject the teacher does not teach. `$data` is changed in
     * place only to clear the standard's week when its standard is cleared.
     *
     * @param  list<string>|null  $limits
     * @param  array<string,mixed>  $data  the validated payload
     */
    private function refuseWork(Group $group, ?array $limits, array &$data, ?ClassAssignment $existing): ?JsonResponse
    {
        $subjectSent = array_key_exists('subject', $data);
        // The subject the work will have AFTER this write.
        $subject = $subjectSent ? $data['subject'] : $existing?->subject;

        // A limited teacher must name a subject they teach. Leaving it blank is
        // not a way round the fence.
        if ($limits !== null) {
            if ($subject === null) {
                return $this->failed('subject', 'Choose the subject this work is for.');
            }

            if (! SubjectFence::allows($limits, SubjectKey::for($subject))) {
                SubjectFence::refuse($subject);
            }
        }

        // The subject must be on the school's list, unless it is unchanged (a
        // subject the office later retired must not make old work uneditable).
        if ($subjectSent && $subject !== null
            && ! ($existing !== null && SubjectKey::for($subject) === $existing->subject_key)
            && ! ClassSubjects::accepts(ClassSubjects::offered($group), $subject)) {
            return $this->failed('subject', "That subject is not on this school's list. Choose one from the list.");
        }

        // A weight override only means something against a weighted class.
        if (($data['weight'] ?? null) !== null && ClassGradeWeight::forGroup((int) $group->id) === []) {
            return $this->failed('weight', "Set this class's weights first, then you can change the weight of one piece of work.");
        }

        return $this->refuseStandard($data, $existing);
    }

    /**
     * ONE STANDARD, and only one the school's own guide names. The teacher picks
     * it from the standards search (CurriculumController::standards), which
     * returns guide rows; this checks the pick really is one, so nothing typed or
     * scripted can put a standard on a child's record that the school never wrote.
     * Unchanged on an edit passes even if the guide has since been re-imported:
     * the work keeps what it was set with.
     *
     * @param  array<string,mixed>  $data
     */
    private function refuseStandard(array &$data, ?ClassAssignment $existing): ?JsonResponse
    {
        $sent = array_intersect_key($data, array_flip(['standard_code', 'curriculum_focus', 'curriculum_week_no']));

        if ($sent === []) {
            return null;
        }

        $code = $data['standard_code'] ?? null;
        $focus = $data['curriculum_focus'] ?? null;
        $week = $data['curriculum_week_no'] ?? null;

        if ($code === null && $focus === null) {
            // Cleared: the week belongs to the standard and goes with it.
            $data['curriculum_week_no'] = null;
            $data['standard_code'] = null;
            $data['curriculum_focus'] = null;

            return null;
        }

        if ($existing !== null
            && $code === $existing->standard_code
            && $focus === $existing->curriculum_focus) {
            $data['curriculum_week_no'] = $week ?? $existing->curriculum_week_no;

            return null;
        }

        $row = CurriculumWeek::query()
            ->when($code === null, fn ($q) => $q->whereNull('standard_code'), fn ($q) => $q->where('standard_code', $code))
            ->when($focus === null, fn ($q) => $q->whereNull('focus'), fn ($q) => $q->where('focus', $focus))
            ->when($week !== null, fn ($q) => $q->where('week_no', (int) $week));

        if (! $row->exists()) {
            return $this->failed('standard_code', "That standard is not in this school's pacing guide. Choose one from the list.");
        }

        return null;
    }

    private function failed(string $field, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'data' => [$field => [$message]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
