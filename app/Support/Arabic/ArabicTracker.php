<?php

namespace App\Support\Arabic;

use App\Models\ArabicLetterProgress;
use App\Models\Group;
use App\Models\GroupMembership;
use Illuminate\Support\Collection;

/**
 * One student's tracker, assembled once and read by every surface.
 *
 * The staff screen, the parent's read-only view and the class overview all come
 * through here, so what a teacher marks, what a parent is shown and what the
 * progress bar counts cannot be three different answers. The scope itself is
 * `ArabicCurriculum`'s to decide; this only joins it to what the student has
 * actually done.
 */
class ArabicTracker
{
    /**
     * Everything one student's tracker needs: the stage, the 28 letters with
     * how far each has got, and the drills behind them.
     */
    public static function forStudent(Group $group, GroupMembership $membership): array
    {
        $stage = $group->arabicStage();

        $rows = ArabicLetterProgress::query()
            ->where('group_membership_id', $membership->id)
            ->get()
            ->keyBy('drill_id');

        $letters = [];
        $mastered = 0;
        $total = 0;

        foreach (ArabicCurriculum::LETTERS as $id => [$glyph, $arabicName, $translit, $joins]) {
            $drills = [];
            $letterMastered = 0;

            foreach (ArabicCurriculum::drillsForLetter($id, $stage) as $drillId) {
                $described = ArabicCurriculum::describeDrill($drillId);
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
                'glyph' => $glyph,
                'arabic_name' => $arabicName,
                'transliteration' => $translit,
                'connects_forward' => $joins,
                'positions' => array_map(
                    static fn (string $p): array => [
                        'id' => $p,
                        'text' => ArabicCurriculum::shape($id, $p),
                    ],
                    ArabicCurriculum::positionsFor($id)
                ),
                'drills' => $drills,
                // Same denominator the totals use, so a letter reading 100% and
                // the bar disagreeing is impossible.
                'completion' => $count > 0 ? round($letterMastered / $count, 4) : 0.0,
                'status' => self::letterStatus($drills),
            ];
        }

        return [
            'stage' => self::stagePayload($stage),
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
    public static function stages(): array
    {
        return array_map(
            static fn (string $s): array => self::stagePayload($s),
            ArabicCurriculum::STAGES
        );
    }

    public static function stagePayload(string $stage): array
    {
        return [
            'id' => $stage,
            'label' => ArabicCurriculum::STAGE_LABELS[$stage],
            'summary' => ArabicCurriculum::STAGE_SUMMARIES[$stage],
        ];
    }

    /**
     * A whole class at a glance: how far each student has got at the class's
     * stage. One query for the counts rather than one per child.
     *
     * @param  Collection<int,GroupMembership>  $students
     */
    public static function classOverview(Group $group, Collection $students): array
    {
        $stage = $group->arabicStage();
        $total = count(ArabicCurriculum::syllabus($stage));

        $masteredByStudent = ArabicLetterProgress::query()
            ->where('group_id', $group->id)
            ->where('status', ArabicCurriculum::STATUS_MASTERED)
            ->whereIn('group_membership_id', $students->pluck('id'))
            ->selectRaw('group_membership_id, COUNT(*) as mastered')
            ->groupBy('group_membership_id')
            ->pluck('mastered', 'group_membership_id');

        return [
            'stage' => self::stagePayload($stage),
            'stages' => self::stages(),
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
