<?php

namespace App\Support\Letters;

use App\Models\ArabicLetterProgress;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Support\Arabic\ArabicCurriculum;
use Illuminate\Support\Collection;

/**
 * One student's tracker, assembled once and read by every surface.
 *
 * The staff screen, the parent's read-only view and the class overview all come
 * through here, so what a teacher marks, what a parent is shown and what the
 * progress bar counts cannot be three different answers. The scope itself is
 * the curriculum's to decide; this only joins it to what the student has
 * actually done.
 *
 * This was `App\Support\Arabic\ArabicTracker` until the school asked for A–Z on
 * the same tab. Nothing about the shape of the answer changed — the payload
 * gained `alphabet` and `direction` and is otherwise key-for-key what it was —
 * but every question it used to put to `ArabicCurriculum` it now puts to the
 * `LetterCurriculum` it was handed, so a second alphabet is a second class and
 * not a second copy of this file.
 *
 * ## EVERY read is filtered by alphabet, including the counts
 *
 * The table holds both tracks. `classOverview()` counts mastered rows with one
 * grouped query, and before English existed that query needed no alphabet
 * filter because there was only one alphabet. Leaving it unfiltered would have
 * made a child's English mastery inflate their Arabic percentage — and the
 * `min($count, $total)` clamp below, which exists for an unrelated reason,
 * would have hidden it by pinning the bar at 100% instead of letting it read
 * 140%. A parent would have seen a finished qāʿidah that was a third done. The
 * filter is not an optimisation; it is the correctness of the number.
 *
 * ## The statuses are the TABLE's, not an alphabet's
 *
 * `not_started | learning | mastered` describe a cell in
 * `arabic_letter_progress` and mean the same thing on both tracks, so they stay
 * on `ArabicCurriculum` where every existing caller already reads them rather
 * than being copied into each curriculum to drift apart.
 */
class LetterTracker
{
    public function __construct(private readonly LetterCurriculum $curriculum) {}

    /** Sugar for the common case: a validated alphabet id straight off a request. */
    public static function for(string $alphabet): self
    {
        return new self(CurriculumRegistry::for($alphabet));
    }

    public function curriculum(): LetterCurriculum
    {
        return $this->curriculum;
    }

    /**
     * How far through this alphabet the CLASS is working.
     *
     * `groups.arabic_stage` is the only stage a class carries, and it belongs to
     * the qāʿidah — so the curriculum is asked to make sense of it rather than
     * the caller. Arabic reads it as written; English has a single stage and
     * collapses anything to it, which is why the English track needs no column
     * of its own and why the mark endpoint refuses to SET a stage for English.
     */
    public function stageFor(Group $group): string
    {
        return $this->curriculum->normaliseStage($group->arabic_stage);
    }

    /**
     * Everything one student's tracker needs: the stage, every letter with how
     * far each has got, and the drills behind them.
     */
    public function forStudent(Group $group, GroupMembership $membership): array
    {
        $stage = $this->stageFor($group);

        $rows = ArabicLetterProgress::query()
            ->where('group_membership_id', $membership->id)
            ->where('alphabet', $this->curriculum->alphabetId())
            ->get()
            ->keyBy('drill_id');

        $letters = [];
        $mastered = 0;
        $total = 0;

        foreach ($this->curriculum->letters() as $id) {
            $letter = $this->curriculum->letter($id);
            $drills = [];
            $letterMastered = 0;

            foreach ($this->curriculum->drillsForLetter($id, $stage) as $drillId) {
                $described = $this->curriculum->describeDrill($drillId);
                $status = $rows[$drillId]->status ?? ArabicCurriculum::STATUS_NOT_STARTED;

                $drills[] = $described + [
                    'status' => $status,
                    'mastered_at' => optional($rows[$drillId]->mastered_at ?? null)->toIso8601String(),
                ];

                $total++;

                if ($status === ArabicCurriculum::STATUS_MASTERED) {
                    $mastered++;
                    $letterMastered++;
                }
            }

            $count = count($drills);

            $letters[] = [
                'id' => $id,
                'glyph' => $letter['glyph'],
                'arabic_name' => $letter['arabic_name'],
                'transliteration' => $letter['transliteration'],
                'connects_forward' => $letter['connects_forward'],
                'positions' => array_map(
                    fn (string $p): array => [
                        'id' => $p,
                        'text' => $this->curriculum->shape($id, $p),
                    ],
                    $this->curriculum->positionsFor($id)
                ),
                'drills' => $drills,
                // Same denominator the totals use, so a letter reading 100% and
                // the bar disagreeing is impossible.
                'completion' => $count > 0 ? round($letterMastered / $count, 4) : 0.0,
                'status' => self::letterStatus($drills),
            ];
        }

        return [
            'alphabet' => $this->curriculum->alphabetId(),
            'direction' => $this->curriculum->direction(),
            'stage' => $this->stagePayload($stage),
            'student' => [
                'membership_id' => (int) $membership->id,
                'contact' => $membership->contact ? [
                    'id' => (int) $membership->contact->id,
                    'first_name' => $membership->contact->first_name,
                    'last_name' => $membership->contact->last_name,
                    'avatar' => $membership->contact->avatar,
                ] : null,
            ],
            'letters' => $letters,
            'totals' => ['mastered' => $mastered, 'total' => $total],
        ];
    }

    /** Every stage, so a client can render the ladder without hardcoding it. */
    public function stages(): array
    {
        return $this->curriculum->stages();
    }

    /**
     * One stage as the client reads it. Looked up in the ladder rather than
     * built here, so an alphabet cannot describe its stage two ways.
     */
    public function stagePayload(string $stage): array
    {
        foreach ($this->curriculum->stages() as $payload) {
            if ($payload['id'] === $stage) {
                return $payload;
            }
        }

        // Unreachable for a stage that came through normaliseStage(); the first
        // stage is the same answer normaliseStage() would have given anyway.
        return $this->curriculum->stages()[0];
    }

    /**
     * A whole class at a glance: how far each student has got at the class's
     * stage. One query for the counts rather than one per child.
     *
     * @param  Collection<int,GroupMembership>  $students
     */
    public function classOverview(Group $group, Collection $students): array
    {
        $stage = $this->stageFor($group);
        $total = count($this->curriculum->syllabus($stage));

        $masteredByStudent = ArabicLetterProgress::query()
            ->where('group_id', $group->id)
            // Without this the other alphabet's mastered rows are counted into
            // this alphabet's percentage, and the clamp below hides it at 100%.
            // See the class docblock.
            ->where('alphabet', $this->curriculum->alphabetId())
            ->where('status', ArabicCurriculum::STATUS_MASTERED)
            ->whereIn('group_membership_id', $students->pluck('id'))
            ->selectRaw('group_membership_id, COUNT(*) as mastered')
            ->groupBy('group_membership_id')
            ->pluck('mastered', 'group_membership_id');

        return [
            'alphabet' => $this->curriculum->alphabetId(),
            'direction' => $this->curriculum->direction(),
            'stage' => $this->stagePayload($stage),
            'stages' => $this->stages(),
            'total' => $total,
            'students' => $students->map(function (GroupMembership $m) use ($masteredByStudent, $total): array {
                // A drill mastered at a LATER stage still counts as mastered,
                // but it is not part of this stage's denominator — so the count
                // is clamped rather than allowed to read 110%.
                $mastered = min((int) ($masteredByStudent[$m->id] ?? 0), $total);

                return [
                    'membership_id' => (int) $m->id,
                    'contact' => $m->contact ? [
                        'id' => (int) $m->contact->id,
                        'first_name' => $m->contact->first_name,
                        'last_name' => $m->contact->last_name,
                        'avatar' => $m->contact->avatar,
                    ] : null,
                    'mastered' => $mastered,
                    'completion' => $total > 0 ? round($mastered / $total, 4) : 0.0,
                ];
            })->values()->all(),
        ];
    }

    /** @param array<int,array<string,mixed>> $drills */
    private static function letterStatus(array $drills): string
    {
        if ($drills === []) {
            return ArabicCurriculum::STATUS_NOT_STARTED;
        }

        $statuses = array_column($drills, 'status');

        if (! in_array(ArabicCurriculum::STATUS_NOT_STARTED, $statuses, true)
            && ! in_array(ArabicCurriculum::STATUS_LEARNING, $statuses, true)) {
            return ArabicCurriculum::STATUS_MASTERED;
        }

        return in_array(ArabicCurriculum::STATUS_NOT_STARTED, $statuses, true)
            && count(array_unique($statuses)) === 1
                ? ArabicCurriculum::STATUS_NOT_STARTED
                : ArabicCurriculum::STATUS_LEARNING;
    }
}
