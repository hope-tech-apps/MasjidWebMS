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
     * "3.nf.1", "nf1") or the words the guide uses for it ("fractions",
     * "counting", "hifz" for the guide's "ḥifẓ").
     *
     * The whole guide is searched and the form's grade and subject rank FIRST
     * rather than filter: a Grade 3 Maths plan that cites an ELA code is
     * unusual, not wrong, and a filter would answer it with nothing.
     *
     * Ranked in PHP, not SQL: a school's guide is a few thousand rows at most,
     * and "3nf1" meeting "NC.3.NF.1", or "tahara" meeting "ṭahāra", needs both
     * sides folded the same way, which no portable LIKE or collation does.
     */
    public function standards(Request $request, $masjid_id): JsonResponse
    {
        // Called per keystroke, so the query is bounded before any work: a long
        // or array-valued parameter is a 422, never a 500 or a slow scan.
        $valid = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'grade' => ['nullable', 'string', 'max:32'],
            'subject' => ['nullable', 'string', 'max:64'],
            'week' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);

        $q = trim((string) ($valid['q'] ?? ''));
        $grade = (string) ($valid['grade'] ?? '');
        $subject = (string) ($valid['subject'] ?? '');
        $week = (int) ($valid['week'] ?? 0);

        $needle = self::squash($q);

        if (mb_strlen($needle) < 2) {
            return $this->matches([]);
        }

        $words = self::words($q);
        // A digit means a code: "RI.3.1", "MP1", "NF 1". Topic words never
        // carry one, and a query without one ("NC" for the NC-history weeks)
        // must keep its word matches.
        $codeQuery = (bool) preg_match('/\p{N}/u', $q);

        $rows = CurriculumWeek::query()
            ->orderBy('grade_label')->orderBy('subject')->orderBy('week_no')
            ->get(['grade_label', 'subject', 'week_no', 'quarter', 'focus',
                'standard_code', 'assessment_note', 'source_label']);

        // One suggestion per distinct wording of a standard. The guide repeats
        // a code across weeks, often with a different focus each time (a
        // practice standard can carry nineteen), and each wording is a
        // different thing to put in a plan.
        $found = [];
        $codeMatched = false;

        foreach ($rows as $row) {
            $score = self::score($row, $needle, $words);

            if ($score === 0) {
                continue;
            }

            $codeMatched = $codeMatched || ($codeQuery && $score >= self::CODE_MATCH);
            $key = implode("\0", [$row->grade_label, $row->subject, (string) $row->standard_code, $row->focus]);

            if (! isset($found[$key])) {
                $found[$key] = [
                    'score' => $score,
                    'scope' => self::scope($row, $grade, $subject),
                    'in_scope' => ($grade === '' || $row->grade_label === $grade)
                        && ($subject === '' || $row->subject === $subject),
                    'row' => $row,
                    'weeks' => [],
                ];
            }

            $found[$key]['weeks'][] = (int) $row->week_no;
        }

        // A code-shaped query that found its code is a code search. The words
        // its letters happen to start ("ri" from "RI.5.1") are noise beside
        // the standard the teacher actually typed.
        if ($codeMatched) {
            $found = array_filter($found, fn (array $f): bool => $f['score'] >= self::CODE_MATCH);
        }

        $ranked = collect($found)
            ->sortBy([
                // The form's own grade and subject first, then its grade alone.
                // Scope before score: Social Studies' "3.G.1" is an exact match
                // for "3.G.1", but on a Grade 3 Maths form the teacher means
                // NC.3.G.1, typed without its state prefix.
                fn (array $a, array $b): int => $b['scope'] <=> $a['scope'],
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
                    // False when the form names a grade or subject this row is
                    // not, so the list can say where its own suggestions end.
                    'in_scope' => $f['in_scope'],
                ];
            })
            ->values()
            ->all();

        return $this->matches($ranked);
    }

    /** Enough to choose from; a longer list means the teacher should type more. */
    private const MAX_MATCHES = 12;

    /** Words past this add nothing a teacher meant and cost a scan each. */
    private const MAX_WORDS = 6;

    private const EXACT = 100;

    /** The lowest score that means "the query is inside a code". */
    private const CODE_MATCH = 60;

    /** Words that describe nothing, so a row need not contain them. */
    private const STOP_WORDS = [
        'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'in', 'into',
        'is', 'it', 'of', 'on', 'or', 'the', 'to', 'with',
    ];

    /**
     * Endings that make another form of the same word, longest first, each with
     * what replaces it and the fewest letters that must remain. Two words meet
     * only when their roots are EQUAL, never when one is a prefix of the other:
     * "plants" must not meet "plan", nor "counterclaims" meet "count".
     *
     * @var list<array{string, string, int}>
     */
    private const ENDINGS = [
        ['ication', 'y', 3],   // multiplication → multiply
        ['ition', '', 3],      // addition → add, composition → compose
        ['ision', 'ide', 3],   // division → divide
        ['ations', '', 3], ['ation', '', 3],
        ['ingly', '', 3], ['ings', '', 3], ['ing', '', 3],
        ['ments', '', 3], ['ment', '', 3],
        ['ions', '', 3], ['ion', '', 3],   // subtraction → subtract
        ['ency', 'ent', 3],    // fluency → fluent
        ['ancy', 'ant', 3],
        ['ies', 'y', 3],
        ['edly', '', 3], ['ed', '', 3],
        ['ers', '', 3], ['er', '', 3],
        ['ly', '', 4],         // fluently → fluent; "early" is not "ear"
        ['es', '', 3],         // shapes → shape
        ['s', '', 4],          // sounds → sound; "this" is not "thi"
    ];

    /** @var array<string, string> */
    private static array $roots = [];

    private function matches(array $matches): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['matches' => $matches],
        ], Response::HTTP_OK);
    }

    /**
     * Lower case, without diacritics or apostrophes, so a teacher typing plain
     * letters on a phone meets the guide's transliterations: "hifz" is
     * "ḥifẓ", "tahara" is "ṭahāra", and "quran" is "Qur’an" (U+2019). The
     * ʿayn and hamza half-rings are letters to Unicode, not marks, so they are
     * dropped by name with the apostrophes.
     */
    private static function fold(string $s): string
    {
        $s = str_replace(["\u{2019}", "'", "\u{02BC}", "\u{2018}", "\u{02BE}", "\u{02BF}"], '', $s);

        if (class_exists(\Normalizer::class)) {
            $s = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
        }

        return mb_strtolower($s);
    }

    /** Lower-case letters and digits only, so "NC.3.NF.1" and "3 nf 1" meet. */
    private static function squash(?string $s): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', self::fold((string) $s));
    }

    /**
     * The words of a query worth matching. A stop word is dropped only from a
     * phrase and never when it is the LAST word: "is" may be the start of
     * "Islamic" that the teacher is still typing.
     *
     * @return list<string>
     */
    private static function words(string $s): array
    {
        $all = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', self::fold($s)) ?: [],
            fn (string $w): bool => mb_strlen($w) >= 2
        ));
        $last = count($all) - 1;
        $words = [];

        foreach ($all as $i => $w) {
            if ($i < $last && in_array($w, self::STOP_WORDS, true)) {
                continue;
            }
            $words[] = $w;
        }

        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }

    /** @return list<string> */
    private static function tokens(string $s): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', self::fold($s)) ?: [],
            fn (string $t): bool => $t !== ''
        )));
    }

    /**
     * How near a row is to the form: 3 its grade and subject, 2 its grade, 1 its
     * subject, 0 neither. A same-grade row in another subject is the same
     * children, so it outranks the form's subject in another grade.
     */
    private static function scope(CurriculumWeek $row, string $grade, string $subject): int
    {
        return ($grade !== '' && $row->grade_label === $grade ? 2 : 0)
            + ($subject !== '' && $row->subject === $subject ? 1 : 0);
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
                return self::EXACT;
            }
            // Typed without the state prefix: "3nf1" for "NC.3.NF.1".
            if (str_ends_with($code, $needle)) {
                return 90;
            }
            if (str_starts_with($code, $needle)) {
                return 80;
            }
            if (str_contains($code, $needle)) {
                return self::CODE_MATCH;
            }
        }

        if ($words === []) {
            return 0;
        }

        // Every typed word must meet a word of the row. The focus is the row's
        // own words: a typed word meets one it starts ("fract" → "Fractions")
        // or another form of it ("counting" → "Count", "tajweed" → "Tajwīd").
        // The grade and subject labels only narrow ("math fractions") and meet
        // by their start alone — as roots, "Pre-Kindergarten" would answer
        // every "pre…" and "Studies" every "student".
        $focus = self::tokens((string) $row->focus);
        $labels = self::tokens($row->subject . ' ' . $row->grade_label);

        foreach ($words as $w) {
            $hit = false;

            foreach ($focus as $t) {
                if (self::meets($w, $t)) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                foreach ($labels as $t) {
                    if (str_starts_with($t, $w)) {
                        $hit = true;
                        break;
                    }
                }
            }

            if (! $hit) {
                return 0;
            }
        }

        return 40;
    }

    private static function meets(string $word, string $token): bool
    {
        if (str_starts_with($token, $word)) {
            return true;
        }

        if (self::root($word) === self::root($token) && mb_strlen(self::root($word)) >= 3) {
            return true;
        }

        // The guide spells one Arabic term two ways across grades: "tajweed"
        // and "Tajwīd", "noon" and "Nūn", "meem" and "Mīm". Doubled vowels are
        // folded for an EQUAL comparison only, so English words cannot drift.
        $w = self::root(self::longVowels($word));

        return mb_strlen($w) >= 3 && $w === self::root(self::longVowels($token));
    }

    private static function longVowels(string $s): string
    {
        return str_replace(['ee', 'oo', 'aa'], ['i', 'u', 'a'], $s);
    }

    /** The word without an inflecting ending, and without a final silent "e". */
    private static function root(string $word): string
    {
        if (isset(self::$roots[$word])) {
            return self::$roots[$word];
        }

        // Two passes, on both sides alike, so a replaced ending is reduced the
        // same way as the plain word: "multiplication" → "multiply" → "multip",
        // "multiply" → "multip".
        $root = $word;

        for ($pass = 0; $pass < 2; $pass++) {
            foreach (self::ENDINGS as [$ending, $with, $keep]) {
                if (str_ends_with($root, $ending) && mb_strlen($root) - mb_strlen($ending) >= $keep) {
                    $root = mb_substr($root, 0, -mb_strlen($ending)) . $with;
                    break;
                }
            }
        }

        // "rhyme" and "rhyming", "compare" and "comparing", "shape" and "shapes".
        if (mb_strlen($root) >= 4 && str_ends_with($root, 'e')) {
            $root = mb_substr($root, 0, -1);
        }

        return self::$roots[$word] = $root;
    }
}
