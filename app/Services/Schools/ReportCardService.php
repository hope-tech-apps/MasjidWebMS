<?php

namespace App\Services\Schools;

use App\Models\AttendanceRecord;
use App\Models\GroupMembership;
use App\Models\ReportCard;
use App\Models\ReportCardMark;
use App\Support\PerformanceLevel;
use App\Support\ReportCardTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Building, saving and publishing a report card.
 *
 * ---------------------------------------------------------------------------
 * A CARD IS PREPARED, NOT COMPUTED
 * ---------------------------------------------------------------------------
 *
 * The tempting design generates every level from the gradebook. It is wrong for
 * two reasons, and both matter more than the convenience:
 *
 *  1. A report card criterion is not an assignment. "Reading Fluency" is a
 *     judgement across a quarter of reading with a child, not the mean of three
 *     marks that happened to have that word in the title. Manufacturing one from
 *     an average would produce a number with a teacher's name on it that no
 *     teacher agreed to.
 *  2. A child who joined in week eight, or who was ill, has gaps. An average
 *     over two marks is not a quarter's judgement, but it looks exactly like one
 *     on a printed card.
 *
 * So `prepare()` builds the ROWS — the right criteria for this child's grade, in
 * order — and leaves every level NULL for the teacher to fill in.
 * `suggestionsFor()` offers what the gradebook knows ALONGSIDE, clearly labelled
 * as a suggestion, so a teacher has the evidence to hand without the system
 * pretending to have made the judgement.
 */
class ReportCardService
{
    /**
     * The card for this child and period, creating its rows if it does not
     * exist yet.
     *
     * Idempotent: calling it twice returns the same card with the same rows, and
     * never duplicates or resets a mark a teacher has already entered. That is
     * what makes it safe to call on every page load, which is how the teacher's
     * screen uses it.
     */
    public function prepare(
        GroupMembership $membership,
        string $type,
        string $schoolYear,
        int $term,
    ): ReportCard {
        return DB::transaction(function () use ($membership, $type, $schoolYear, $term): ReportCard {
            $card = ReportCard::firstOrNew([
                'group_membership_id' => $membership->id,
                'school_year' => $schoolYear,
                'term' => $term,
                'type' => $type,
            ]);

            if (! $card->exists) {
                $card->fill([
                    'masjid_id' => $membership->masjid_id,
                    'group_id' => $membership->group_id,
                    // Snapshotted now, so a child who is promoted mid-year keeps
                    // the grade the card was written for.
                    'grade_label' => $membership->grade_label,
                    'created_by_user_id' => Auth::id(),
                ])->save();
            }

            $this->ensureRows($card);

            return $card->load(['marks' => fn ($q) => $q->orderBy('position')]);
        });
    }

    /**
     * Create any template row this card is missing, and touch nothing else.
     *
     * `firstOrCreate` per row rather than a delete-and-rebuild: a teacher may be
     * half way through a card when the school adds a criterion, and rebuilding
     * would silently discard the marks they have already made. Rows the template
     * no longer contains are deliberately LEFT in place for the same reason — a
     * card in progress is not the place to lose a teacher's work, and a card
     * already published must never change at all.
     */
    private function ensureRows(ReportCard $card): void
    {
        if ($card->isPublished()) {
            return;
        }

        $position = 0;

        foreach (ReportCardTemplate::rowsForGrade($card->grade_label) as $row) {
            $this->ensureRow($card, ReportCardMark::KIND_ACADEMIC, $row['subject'], $row['criterion'], $position++);
        }

        foreach (ReportCardTemplate::LEARNING_BEHAVIOURS as $criterion) {
            $this->ensureRow($card, ReportCardMark::KIND_BEHAVIOUR, 'Learning Behaviours', $criterion, $position++);
        }
    }

    private function ensureRow(ReportCard $card, string $kind, string $subject, string $criterion, int $position): void
    {
        $mark = ReportCardMark::firstOrNew([
            'report_card_id' => $card->id,
            'subject' => $subject,
            'criterion' => $criterion,
        ]);

        // The position may legitimately change when the template changes; the
        // LEVEL never gets touched here.
        $mark->fill([
            'masjid_id' => $card->masjid_id,
            'kind' => $kind,
            'position' => $position,
        ]);

        if (! $mark->exists) {
            $mark->level = null;
        }

        $mark->save();
    }

    /**
     * Record the teacher's marks on a DRAFT card.
     *
     * A published card is refused rather than quietly updated: it is a document
     * a family may already have read, and silently changing what it says is
     * worse than making the teacher unpublish first. The caller turns `false`
     * into the message a teacher sees.
     *
     * `$comment` is only written when `$updateComment` is true. The two
     * arguments exist rather than one nullable because "leave the comment alone"
     * and "clear the comment" are different instructions that arrive looking
     * identical: `ConvertEmptyStringsToNull` is global middleware in this
     * application, so a teacher who selects their comment and deletes it sends
     * `teacher_comment: ""` and the controller reads NULL. Branching on
     * `$comment !== null` therefore made clearing a comment impossible — the
     * emptied text silently reappeared after a green "Saved". The caller uses
     * `$request->has()`, which sees the key the middleware nulled, NOT
     * `filled()`, which does not.
     *
     * @param  array<int, array{id:int, level:int|null, comment:string|null}>  $marks
     */
    public function saveMarks(ReportCard $card, array $marks, bool $updateComment = false, ?string $comment = null): bool
    {
        if ($card->isPublished()) {
            return false;
        }

        DB::transaction(function () use ($card, $marks, $updateComment, $comment): void {
            // Scoped to THIS card's rows, so a payload naming another child's
            // mark id updates nothing rather than updating them.
            $owned = $card->marks()->get()->keyBy('id');

            foreach ($marks as $row) {
                $mark = $owned->get((int) ($row['id'] ?? 0));

                if ($mark === null) {
                    continue;
                }

                $level = $row['level'] ?? null;

                $mark->update([
                    // NULL is a real value here — it means "not assessed this
                    // quarter", which is a true thing to say about a child who
                    // joined in week eight. It is never coerced to a zero.
                    'level' => PerformanceLevel::isValid($level) ? (int) $level : null,
                    'comment' => $row['comment'] ?? null,
                ]);
            }

            if ($updateComment) {
                // '' clears it. See the docblock for why this cannot branch on
                // the value being null.
                $card->update(['teacher_comment' => $comment]);
            }
        });

        return true;
    }

    /**
     * Issue the card to the family, freezing its attendance figures.
     *
     * The figures are computed HERE and stored, rather than joined on read: a
     * card issued in November that silently changed its own attendance numbers
     * in March as the register filled in would not be a record of anything. The
     * window is the reporting period the caller passes, not the whole year.
     */
    public function publish(ReportCard $card, ?Carbon $from = null, ?Carbon $to = null): ReportCard
    {
        if ($card->isPublished()) {
            return $card;
        }

        $counts = $this->attendanceCounts($card->group_membership_id, $from, $to);

        $card->forceFill([
            'days_present' => $counts['present'],
            'days_absent' => $counts['absent'],
            'days_late' => $counts['late'],
            'published_at' => now(),
            'published_by_user_id' => Auth::id(),
        ])->save();

        return $card;
    }

    /**
     * Take a card back out of a family's hands.
     *
     * Clears the snapshot as well as the timestamp. Leaving stale figures on a
     * re-opened draft would mean a card republished in March silently carried
     * November's attendance — the exact failure snapshotting exists to prevent,
     * arriving from the other direction.
     */
    public function unpublish(ReportCard $card): ReportCard
    {
        $card->forceFill([
            'published_at' => null,
            'published_by_user_id' => null,
            'days_present' => null,
            'days_absent' => null,
            'days_late' => null,
        ])->save();

        return $card;
    }

    /**
     * @return array{present:int, absent:int, late:int}
     */
    private function attendanceCounts(int $membershipId, ?Carbon $from, ?Carbon $to): array
    {
        $counts = AttendanceRecord::query()
            ->where('group_membership_id', $membershipId)
            ->when($from, fn ($q) => $q->whereDate('session_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('session_date', '<=', $to))
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')
            ->pluck('n', 'status');

        return [
            // The SAME definition the attendance summary uses — late is
            // attendance. Two screens disagreeing about whether a late child was
            // present is the drift AttendanceRecord::PRESENT_STATUSES exists to
            // stop, and a report card is the worst place for it to surface.
            'present' => (int) collect(AttendanceRecord::PRESENT_STATUSES)
                ->sum(fn (string $s) => (int) $counts->get($s, 0)),
            'absent' => (int) $counts->get(AttendanceRecord::STATUS_ABSENT, 0),
            'late' => (int) $counts->get(AttendanceRecord::STATUS_LATE, 0),
        ];
    }
}
