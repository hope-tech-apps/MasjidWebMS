<?php

namespace App\Http\Controllers\Family;

use App\Models\Group;
use App\Models\ReportCard;
use App\Models\ReportCardMark;
use App\Support\PerformanceLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A parent reads their own child's report cards.
 *
 * Both routes are GETs — this adds nothing to the family realm's counted write
 * list, which routes/family.php and FamilyPortalTest enumerate.
 *
 * ---------------------------------------------------------------------------
 * TWO GATES, AND BOTH ARE SCOPES RATHER THAN CHECKS
 * ---------------------------------------------------------------------------
 *
 * 1. THE WARD EDGE. The membership must be a child this caller is the guardian
 *    of. `FamilyController::subject()` is that gate and is shared with every
 *    other per-child route in this realm, so a parent naming another family's
 *    child gets a 404 — not a 403, which would confirm the child exists.
 *
 * 2. PUBLICATION. `->published()` is applied as a SCOPE, not as an `if` after
 *    the fetch. A draft card is therefore a 404 in exactly the way a
 *    nonexistent one is, and a parent cannot discover that a report about their
 *    child exists but is being withheld — which is a thing they would ask about,
 *    and a thing a teacher mid-draft has not decided yet.
 *
 * The same reasoning ResourcesController uses for staff-only files: a
 * visibility rule applied as a scope cannot leak the existence of what it hides,
 * and one applied as a 403 always does.
 */
class ReportCardsController extends FamilyController
{
    /**
     * Every published report for one of this parent's children, newest first.
     */
    public function index(Request $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = $this->group($group_id);
        $membership = $this->subject($group, $membership_id);

        $cards = ReportCard::query()
            ->where('group_membership_id', $membership->id)
            ->published()
            ->orderByDesc('school_year')
            ->orderByDesc('term')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $cards->map(fn (ReportCard $c): array => [
                'id' => (int) $c->id,
                'type' => $c->type,
                'type_label' => $c->typeLabel(),
                'period_label' => $c->periodLabel(),
                'school_year' => $c->school_year,
                'term' => (int) $c->term,
                'published_at' => $c->published_at?->toIso8601String(),
            ])->values(),
        ], Response::HTTP_OK);
    }

    /** One published report, in full. */
    public function show(Request $request, $masjid_id, $group_id, $membership_id, $report_card_id): JsonResponse
    {
        $group = $this->group($group_id);
        $membership = $this->subject($group, $membership_id);

        $card = ReportCard::query()
            ->where('group_membership_id', $membership->id)
            ->published()
            ->findOrFail($report_card_id);

        $marks = $card->marks()->orderBy('position')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => (int) $card->id,
                'type' => $card->type,
                'type_label' => $card->typeLabel(),
                'period_label' => $card->periodLabel(),
                'grade_label' => $card->grade_label,
                'published_at' => $card->published_at?->toIso8601String(),
                'teacher_comment' => $card->teacher_comment,
                // The figures FROZEN at publication, not recomputed — a document
                // a family keeps must not rewrite its own attendance months
                // later. See the migration.
                'attendance' => [
                    'present' => $card->days_present,
                    'absent' => $card->days_absent,
                    'late' => $card->days_late,
                ],
                'subjects' => $marks->where('kind', ReportCardMark::KIND_ACADEMIC)
                    ->groupBy('subject')
                    ->map(fn ($rows, $subject) => [
                        'subject' => $subject,
                        'criteria' => $rows->map(fn (ReportCardMark $m) => $this->mark($m))->values(),
                    ])->values(),
                'learning_behaviours' => $marks->where('kind', ReportCardMark::KIND_BEHAVIOUR)
                    ->map(fn (ReportCardMark $m) => $this->mark($m))->values(),
            ],
            // THE KEY travels with the document. A report card a parent cannot
            // decode is a report card that has not communicated anything, and
            // "what does a 3 mean?" must be answerable without emailing the
            // school.
            'performance_levels' => PerformanceLevel::key(),
        ], Response::HTTP_OK);
    }

    /** @return array<string, mixed> */
    private function mark(ReportCardMark $m): array
    {
        return [
            'criterion' => $m->criterion,
            // NULL renders as "not assessed", never as a zero.
            'level' => $m->level,
            'level_label' => $m->levelLabel(),
            'comment' => $m->comment,
        ];
    }
}
