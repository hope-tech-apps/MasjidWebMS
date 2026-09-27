<?php

namespace App\Http\Controllers\Teacher;

use App\Models\CurriculumWeek;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The school's own pacing guide, as the lesson-plan form needs it.
 *
 * Reference data for the bound tenant, so it is not group-scoped and carries no
 * authorization beyond being a signed-in teacher — the same shape as
 * /behavior-skills and /quran-surahs beside it. `CurriculumWeek` is
 * tenant-scoped, so one school can never see another's standards.
 *
 * ONE endpoint, progressively narrowed by query parameters, because the form
 * asks three questions in sequence — which grade, which subject, which week —
 * and a round trip per dropdown would be three endpoints that must agree.
 *
 * The subject list is DISTINCT over the tenant's own imported rows rather than a
 * constant, so the day the school authors an Arabic pacing column it appears in
 * the picker with no code change.
 */
class CurriculumController extends TeacherController
{
    public function index(Request $request, $masjid_id): JsonResponse
    {
        $grade = $request->query('grade');
        $subject = $request->query('subject');
        $week = $request->query('week');

        $grades = CurriculumWeek::query()
            ->distinct()->orderBy('grade_label')->pluck('grade_label');

        $subjects = $grade
            ? CurriculumWeek::query()->where('grade_label', $grade)
                ->distinct()->orderBy('subject')->pluck('subject')
            : collect();

        $weeks = ($grade && $subject)
            ? CurriculumWeek::query()
                ->where('grade_label', $grade)->where('subject', $subject)
                ->orderBy('week_no')
                ->get(['week_no', 'quarter', 'focus', 'standard_code'])
                ->map(fn (CurriculumWeek $w): array => [
                    'week_no' => (int) $w->week_no,
                    'quarter' => $w->quarter !== null ? (int) $w->quarter : null,
                    'focus' => $w->focus,
                    'standard_code' => $w->standard_code,
                ])
            : collect();

        // The cell itself, only when all three are named. This is what the
        // Prefill button writes into the form.
        $cell = null;

        if ($grade && $subject && $week !== null && $week !== '') {
            $row = CurriculumWeek::query()
                ->where('grade_label', $grade)
                ->where('subject', $subject)
                ->where('week_no', (int) $week)
                ->first();

            if ($row) {
                $cell = $row->toPrefillArray();

                // The rest of that week for the SAME grade, so the form can
                // offer real cross-subject integration lines instead of asking
                // a teacher to remember what Science is doing.
                $cell['siblings'] = CurriculumWeek::query()
                    ->where('grade_label', $grade)
                    ->where('week_no', (int) $week)
                    ->where('subject', '!=', $subject)
                    ->orderBy('subject')
                    ->get(['subject', 'focus'])
                    ->map(fn (CurriculumWeek $s): array => [
                        'subject' => $s->subject,
                        'focus' => $s->focus,
                    ])->values();
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'grades' => $grades->values(),
                'subjects' => $subjects->values(),
                'weeks' => $weeks->values(),
                'cell' => $cell,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * The guide's standards, matched against what a teacher is typing into the
     * lesson plan's Standard field — a code in any spelling ("NC.3.NF.1",
     * "3.nf.1", "nf1") or the words the guide uses for it ("fractions").
     *
     * The whole guide is searched and the grade and subject the form already
     * names rank FIRST rather than filter: a Grade 3 Maths plan that cites an
     * ELA code is unusual, not wrong, and a filter would answer it with nothing.
     *
     * Ranked in PHP, not SQL: a school's guide is a few thousand rows at most,
     * and "3nf1" matching "NC.3.NF.1" needs punctuation stripped on both sides,
     * which no portable LIKE can do.
     */
    public function standards(Request $request, $masjid_id): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $grade = (string) $request->query('grade', '');
        $subject = (string) $request->query('subject', '');
        $week = (int) $request->query('week', 0);

        $needle = self::squash($q);
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', self::fold($q)) ?: [],
            fn (string $w): bool => mb_strlen($w) >= 2
        ));

        if (mb_strlen($needle) < 2) {
            return $this->matches([]);
        }

        $rows = CurriculumWeek::query()
            ->orderBy('grade_label')->orderBy('subject')->orderBy('week_no')
            ->get(['grade_label', 'subject', 'week_no', 'quarter', 'focus',
                'standard_code', 'assessment_note', 'source_label']);

        // One suggestion per distinct wording of a standard. The guide repeats
        // a code across weeks, often with a different focus each time (a
        // practice standard can carry nineteen), and each wording is a
        // different thing to put in a plan.
        $found = [];

        foreach ($rows as $row) {
            $score = self::score($row, $needle, $words);

            if ($score === 0) {
                continue;
            }

            $key = implode("\0", [$row->grade_label, $row->subject, (string) $row->standard_code, $row->focus]);

            if (! isset($found[$key])) {
                $found[$key] = [
                    'score' => $score,
                    'in_scope' => ($grade === '' || $row->grade_label === $grade)
                        && ($subject === '' || $row->subject === $subject),
                    'row' => $row,
                    'weeks' => [],
                ];
            }

            $found[$key]['weeks'][] = (int) $row->week_no;
        }

        $ranked = collect($found)
            ->sortBy([
                fn (array $a, array $b): int => $b['in_scope'] <=> $a['in_scope'],
                fn (array $a, array $b): int => $b['score'] <=> $a['score'],
                // The week the form is on, when the guide teaches it then.
                fn (array $a, array $b): int => in_array($week, $b['weeks'], true) <=> in_array($week, $a['weeks'], true),
            ])
            ->take(self::MAX_MATCHES)
            ->map(function (array $f) use ($week): array {
                /** @var CurriculumWeek $row */
                $row = $f['row'];

                return [
                    'standard_code' => $row->standard_code,
                    // The guide's weekly focus, NOT the standard's official
                    // wording, which the guide does not carry.
                    'focus' => $row->focus,
                    'grade_label' => $row->grade_label,
                    'subject' => $row->subject,
                    'weeks' => $f['weeks'],
                    // The week a pick should land on: the form's own week when
                    // the guide teaches this then, otherwise its first.
                    'week_no' => in_array($week, $f['weeks'], true) ? $week : $f['weeks'][0],
                    'assessment_formative' => $row->assessment_note,
                    'prefill_source' => $row->source_label,
                ];
            })
            ->values()
            ->all();

        return $this->matches($ranked);
    }

    /** Enough to choose from; a longer list means the teacher should type more. */
    private const MAX_MATCHES = 12;

    private function matches(array $matches): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['matches' => $matches],
        ], Response::HTTP_OK);
    }

    /**
     * Lower case, with apostrophes dropped rather than treated as a break:
     * the guide's "Qur’an" (U+2019) must answer a teacher typing "quran".
     */
    private static function fold(string $s): string
    {
        return mb_strtolower(str_replace(["\u{2019}", "'", "\u{02BC}", "\u{2018}"], '', $s));
    }

    /** Lower-case letters and digits only, so "NC.3.NF.1" and "3 nf 1" meet. */
    private static function squash(?string $s): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $s));
    }

    /**
     * How well one guide row answers the query; 0 is no match. A code match
     * outranks a words match, because a teacher who types a code knows which
     * standard they mean.
     */
    private static function score(CurriculumWeek $row, string $needle, array $words): int
    {
        $code = self::squash($row->standard_code);

        if ($code !== '') {
            if ($code === $needle) {
                return 100;
            }
            // Typed without the state prefix: "3nf1" for "NC.3.NF.1".
            if (str_ends_with($code, $needle)) {
                return 90;
            }
            if (str_starts_with($code, $needle)) {
                return 80;
            }
            if (str_contains($code, $needle)) {
                return 60;
            }
        }

        if ($words === []) {
            return 0;
        }

        // Every typed word must START a word of the row — "fract" finds
        // "Fractions", but "oa" (from "OA.5") must not find "goal". The grade
        // and subject count, so "math fractions" narrows rather than misses.
        $tokens = preg_split(
            '/[^\p{L}\p{N}]+/u',
            self::fold($row->focus . ' ' . $row->subject . ' ' . $row->grade_label)
        ) ?: [];

        foreach ($words as $w) {
            $hit = false;

            foreach ($tokens as $t) {
                if ($t !== '' && str_starts_with($t, $w)) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                return 0;
            }
        }

        return 40;
    }
}
