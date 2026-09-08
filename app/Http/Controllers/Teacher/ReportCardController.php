<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Requests\Teacher\SaveReportCardRequest;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\ReportCard;
use App\Models\ReportCardMark;
use App\Services\Schools\ReportCardService;
use App\Support\PerformanceLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report cards and progress reports, for the teacher who leads the class.
 *
 * Every route sits inside routes/teacher.php's `teacher.leads` group, so "may
 * this teacher touch this class" is answered before any method runs. What is
 * left is the question this controller answers on every call: is this membership
 * a PARTICIPANT OF THIS CLASS. Resolving through
 * `$group->memberships()->participants()` makes a child of another class, or a
 * guardian edge, resolve to 404 rather than to a report card.
 *
 * Payloads are built through TeacherController::student(), the realm's
 * names-only serialization boundary — never from a model, which would leak a
 * family's email and phone.
 */
class ReportCardController extends TeacherController
{
    public function __construct(private ReportCardService $cards)
    {
    }

    /**
     * The whole class for one period: who has a card, and how far along it is.
     *
     * The screen a teacher opens in report-card week. Deliberately does NOT
     * create cards as a side effect of looking — a class list is a read, and
     * creating twelve draft documents because somebody opened a tab is the kind
     * of write that makes an audit trail meaningless.
     */
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        [$type, $year, $term] = $this->period($request);

        $students = $group->memberships()
            ->participants()
            ->with('contact')
            ->get();

        $cards = ReportCard::query()
            ->where('group_id', $group->id)
            ->where('school_year', $year)
            ->where('term', $term)
            ->where('type', $type)
            ->withCount([
                'marks',
                'marks as assessed_count' => fn ($q) => $q->whereNotNull('level'),
            ])
            ->get()
            ->keyBy('group_membership_id');

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => ['type' => $type, 'school_year' => $year, 'term' => $term],
                'students' => $students->map(function (GroupMembership $m) use ($cards): array {
                    $card = $cards->get($m->id);

                    return $this->student($m) + [
                        'report_card_id' => $card?->id,
                        'started' => $card !== null,
                        'published' => (bool) $card?->isPublished(),
                        'published_at' => $card?->published_at?->toIso8601String(),
                        // "9 of 16 marked" — the only progress figure that means
                        // anything before a card is finished.
                        'assessed' => (int) ($card?->assessed_count ?? 0),
                        'criteria' => (int) ($card?->marks_count ?? 0),
                    ];
                })->values(),
            ],
            'performance_levels' => PerformanceLevel::key(),
        ], Response::HTTP_OK);
    }

    /**
     * One child's card, created with its rows if this is the first look.
     *
     * A GET that may write, which is worth naming: the write is the creation of
     * an EMPTY document with no judgements in it, and making the teacher press
     * "start" first would be a step that exists only to satisfy a rule about
     * verbs. It is idempotent — a second GET returns the same card and never
     * resets a mark.
     */
    public function show(Request $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);
        [$type, $year, $term] = $this->period($request);

        $card = $this->cards->prepare($membership, $type, $year, $term);

        return response()->json([
            'status' => 'success',
            'data' => $this->card($card, $membership),
            'performance_levels' => PerformanceLevel::key(),
        ], Response::HTTP_OK);
    }

    /**
     * Save the teacher's marks and comment.
     *
     * A published card is REFUSED rather than quietly updated — it is a document
     * a family may already have read, and changing what it says behind them is
     * worse than making the teacher take it back first.
     */
    public function save(SaveReportCardRequest $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);
        [$type, $year, $term] = $this->period($request);

        $card = $this->cards->prepare($membership, $type, $year, $term);

        $saved = $this->cards->saveMarks(
            $card,
            $request->validated('marks', []),
            $request->validated('teacher_comment'),
        );

        if (! $saved) {
            return response()->json([
                'status' => 'failed',
                'data' => ['marks' => [
                    'This report has already been sent to the family. Take it back first if you need to change it.',
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->card($card->refresh(), $membership),
        ], Response::HTTP_OK);
    }

    /**
     * Send it to the family, freezing the attendance figures.
     *
     * `from`/`to` bound the attendance window to the reporting period. Absent,
     * the snapshot is the whole year to date — which is right for a first
     * quarter and wrong for a fourth, so the screen passes them.
     */
    public function publish(Request $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);
        [$type, $year, $term] = $this->period($request);

        $card = $this->cards->prepare($membership, $type, $year, $term);

        $this->cards->publish(
            $card,
            $this->date($request->input('from')),
            $this->date($request->input('to')),
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->card($card->refresh(), $membership),
        ], Response::HTTP_OK);
    }

    /** Take it back. The family stops being able to open it. */
    public function unpublish(Request $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);
        [$type, $year, $term] = $this->period($request);

        $card = $this->cards->prepare($membership, $type, $year, $term);

        $this->cards->unpublish($card);

        return response()->json([
            'status' => 'success',
            'data' => $this->card($card->refresh(), $membership),
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /**
     * The card as a screen needs it: marks grouped by subject, in order.
     *
     * @return array<string, mixed>
     */
    private function card(ReportCard $card, GroupMembership $membership): array
    {
        $marks = $card->marks()->orderBy('position')->get();

        $subjects = $marks->where('kind', ReportCardMark::KIND_ACADEMIC)
            ->groupBy('subject')
            ->map(fn ($rows, $subject) => [
                'subject' => $subject,
                'criteria' => $rows->map(fn (ReportCardMark $m) => $this->mark($m))->values(),
            ])->values();

        return [
            'id' => (int) $card->id,
            'student' => $this->student($membership),
            'type' => $card->type,
            'type_label' => $card->typeLabel(),
            'school_year' => $card->school_year,
            'term' => (int) $card->term,
            'period_label' => $card->periodLabel(),
            'grade_label' => $card->grade_label,
            'teacher_comment' => $card->teacher_comment,
            'published' => $card->isPublished(),
            'published_at' => $card->published_at?->toIso8601String(),
            'attendance' => [
                'present' => $card->days_present,
                'absent' => $card->days_absent,
                'late' => $card->days_late,
            ],
            'subjects' => $subjects,
            // Reported ALONGSIDE the subjects, never inside them — see
            // ReportCardMark::KIND_BEHAVIOUR.
            'learning_behaviours' => $marks->where('kind', ReportCardMark::KIND_BEHAVIOUR)
                ->map(fn (ReportCardMark $m) => $this->mark($m))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function mark(ReportCardMark $m): array
    {
        return [
            'id' => (int) $m->id,
            'criterion' => $m->criterion,
            // NULL is "not assessed", never a zero.
            'level' => $m->level,
            'level_label' => $m->levelLabel(),
            'comment' => $m->comment,
        ];
    }

    /**
     * Which report, for which quarter.
     *
     * Defaults are the CURRENT school year and a report card, so a screen that
     * forgets to pass them lands somewhere sensible rather than on quarter 0 of
     * year 0. The values are clamped rather than validated into a 422 because
     * they arrive as query parameters on a GET, where a helpful error is less
     * useful than a sane default.
     *
     * @return array{0:string, 1:string, 2:int}
     */
    private function period(Request $request): array
    {
        $type = (string) $request->input('type', ReportCard::TYPE_REPORT_CARD);

        if (! in_array($type, ReportCard::TYPES, true)) {
            $type = ReportCard::TYPE_REPORT_CARD;
        }

        $term = (int) $request->input('term', $this->currentTerm());
        $term = in_array($term, ReportCard::TERMS, true) ? $term : 1;

        $year = (string) $request->input('school_year', $this->currentSchoolYear());

        if (! preg_match('/^\d{4}-\d{4}$/', $year)) {
            $year = $this->currentSchoolYear();
        }

        return [$type, $year, $term];
    }

    /**
     * The school year as a label — "2026-2027".
     *
     * A year rolls in AUGUST, not January: a card written in September 2026
     * belongs to 2026-2027, and one written in May 2027 belongs to the same
     * year. Getting this wrong would file half a year's cards under the wrong
     * label with nothing on screen to show it.
     */
    private function currentSchoolYear(): string
    {
        $now = Carbon::now();
        $start = $now->month >= 8 ? $now->year : $now->year - 1;

        return $start . '-' . ($start + 1);
    }

    /** A rough default so the screen opens on the quarter most likely wanted. */
    private function currentTerm(): int
    {
        return match (Carbon::now()->month) {
            8, 9, 10 => 1,
            11, 12, 1 => 2,
            2, 3 => 3,
            default => 4,
        };
    }

    private function date(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
