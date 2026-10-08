<?php

namespace App\Http\Controllers\Teacher;

use App\Models\CurriculumWeek;
use App\Models\SchoolSubject;
use App\Support\SchoolSettings;
use App\Support\SubjectFence;
use App\Support\SubjectKey;
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
 * the picker with no code change. That day has come: the school's separated
 * Quarter 1 plan (Pre-K to Grade 2) gave Qur'an, Arabic Language and Islamic
 * Studies their own weekly rows, each with the school's own Objective and
 * Learning Outcome beside the Focus Skill.
 *
 * `objective` and `learning_outcome` are added to a payload ONLY when the row has
 * them, so every row from the July guide answers byte for byte as it always did.
 */
class CurriculumController extends TeacherController
{
    /**
     * The subject keys (SubjectKey::for) of the school's combined "Qur’an & Islamic
     * Studies" weekly column, in either spelling the guide and the catalogue use.
     */
    private const COMBINED_KEYS = ['quran & islamic studies', 'quran and islamic studies'];

    /**
     * The subjects the school's separated plan covers (Pre-K to Grade 2, weeks
     * 1-8). Asked for in a week that plan has no row for, in a grade the plan
     * covers, they fall back to the combined column's line for that week (see
     * combinedFallback).
     */
    private const SEPARATED_KEYS = ['quran', 'islamic studies', 'arabic language', 'arabic'];

    public function index(Request $request, $masjid_id): JsonResponse
    {
        $grade = $request->query('grade');
        $subject = $request->query('subject');
        $week = $request->query('week');

        // Named with `?group_id=`, the same limits subjectsFor applies to the subject
        // list apply to the weeks, the cell and its siblings below: a Qur'an-only
        // teacher is never handed Arabic or Islamic Studies rows by asking for them.
        $limits = $this->limits($request);
        $subjectsOn = SchoolSettings::classSubjects(SchoolSettings::org($masjid_id));
        if ($subjectsOn) $subject = $this->guideSubject($request, is_string($subject) ? $subject : null);
        $fenced = fn (?string $name): bool => SubjectFence::allows($limits, SubjectKey::for($name));

        $grades = CurriculumWeek::query()
            ->distinct()->orderBy('grade_label')->pluck('grade_label');

        // With a grade: that grade's subjects. With none, only where the school has
        // NO guide (BISS teaches from none): there is no grade to choose, and the
        // school's own list is the whole answer. A school with a guide still
        // chooses a grade first, exactly as it always did.
        $subjects = $grade
            ? $this->subjectsFor($request, (string) $grade)
            : ($grades->isEmpty() ? $this->subjectsFor($request, null) : collect());

        $weeks = ($grade && $subject && $fenced((string) $subject))
            ? CurriculumWeek::query()
                ->where('grade_label', $grade)->where('subject', $subject)
                ->orderBy('week_no')
                ->get(['week_no', 'quarter', 'focus', 'objective', 'standard_code'])
                ->map(fn (CurriculumWeek $w): array => [
                    'week_no' => (int) $w->week_no,
                    'quarter' => $w->quarter !== null ? (int) $w->quarter : null,
                    'focus' => $w->focus,
                    'standard_code' => $w->standard_code,
                ] + (filled($w->objective) ? ['objective' => $w->objective] : []))
            : collect();

        // The cell itself, only when all three are named. This is what the
        // Prefill button writes into the form.
        $cell = null;

        if ($grade && $subject && $week !== null && $week !== '' && $fenced((string) $subject)) {
            $row = CurriculumWeek::query()
                ->where('grade_label', $grade)
                ->where('subject', $subject)
                ->where('week_no', (int) $week)
                ->first();

            // A separated subject the split has no row for (weeks 9 on): the school's
            // combined line for that week, labelled as such. Never a made-up row.
            $combined = $row ? null : $this->combinedFallback((string) $grade, (string) $subject, (int) $week, $fenced);

            if ($combined) {
                $row = $combined;
            }

            if ($row) {
                $cell = $row->toPrefillArray();

                if ($combined) {
                    $cell['from_combined_guide'] = true;
                    $cell['guide_subject'] = $combined->subject;
                }

                // The rest of that week for the SAME grade, so the form can
                // offer real cross-subject integration lines instead of asking
                // a teacher to remember what Science is doing.
                $cell['siblings'] = CurriculumWeek::query()
                    ->where('grade_label', $grade)
                    ->where('week_no', (int) $week)
                    // A combined-line fallback is the cell itself: not its own sibling.
                    ->where('subject', '!=', $combined ? $combined->subject : $subject)
                    ->orderBy('subject')
                    ->get(['subject', 'focus', 'objective'])
                    // Only the subjects a staff subject covers (Qur'an, Arabic, Islamic Studies) are
                    // fenced. Mathematics, Science and the rest belong to no staff subject, so a
                    // limited teacher still gets their integration lines.
                    ->filter(fn (CurriculumWeek $s): bool => $subjectsOn
                        ? $fenced($s->subject) : (SubjectKey::staffKeys(SubjectKey::for($s->subject)) === [] || $fenced($s->subject)))
                    // The school's separated Qur'an, Arabic and Islamic Studies weeks keep the
                    // surah and the specifics in the Objective, not the Focus Skill, so a
                    // sibling carries its objective when it has one. A row without one
                    // (every row of the base guide) is exactly what it always was.
                    ->map(fn (CurriculumWeek $s): array => [
                        'subject' => $s->subject,
                        'focus' => $s->focus,
                    ] + (filled($s->objective) ? ['objective' => $s->objective] : []))
                    ->values();
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
     * What `?group_id=` limits this teacher to in that class, or NULL for all: the
     * one reading subjectsFor, the cell, the siblings and the standards search
     * share (App\Support\SubjectFence).
     *
     * @return list<string>|null
     */
    private function guideSubject(Request $request, ?string $name): ?string
    {
        if (SubjectKey::clean($name) === null || ! $request->filled('group_id')) return $name;
        $group = \App\Models\Group::findOrFail((int) $request->query('group_id'));
        abort_unless($group->teachesStudents() && app(\App\Support\GroupAudience::class)->isLeaderOf($request->user(), $group), 404);
        $ids = SubjectFence::assignedIds((int) $group->id, (int) $request->user()->id);
        $subjects = \App\Models\ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->whereNull('hidden_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get();
        $subject = $subjects->first(fn ($s) => in_array(SubjectKey::for($name), $s->matchingKeys(), true));
        return $subject ? $subject->guide_subject : $name;
    }

    private function limits(Request $request): ?array
    {
        if (SchoolSettings::classSubjects(SchoolSettings::org($request->route('masjid_id')))) {
            if ($request->filled('group_id')) {
                $group = \App\Models\Group::findOrFail((int) $request->query('group_id'));
                abort_unless($group->teachesStudents() && app(\App\Support\GroupAudience::class)->isLeaderOf($request->user(), $group), 404);
                return SubjectFence::limitsForIds(SubjectFence::assignedIds((int) $group->id, (int) $request->user()->id), $group, true);
            }
            $ids = []; $keys = [];
            foreach (\App\Models\Group::where('kind', 'class')->whereIn('id', app(\App\Support\GroupAudience::class)->leaderGroupIdsFor($request->user()))->get() as $group) {
                $limits = SubjectFence::limitsForIds(SubjectFence::assignedIds((int) $group->id, (int) $request->user()->id), $group, true);
                if ($limits === null) return null;
                $ids = [...$ids, ...$limits['class_subject_ids']];
                $keys = [...$keys, ...$limits['keys']];
            }
            return ['class_subject_ids' => array_values(array_unique($ids)), 'keys' => array_values(array_unique($keys))];
        }
        return $request->filled('group_id')
            ? SubjectFence::limitsFor($request->user(), (int) $request->query('group_id'))
            : null;
    }

    /**
     * The combined "Qur’an & Islamic Studies" row for a grade and week, when a
     * plan for Qur'an, Islamic Studies or Arabic Language asks for a week the
     * school's separated plan has no row for. The separated weeks stop at 8; the
     * combined column carries the school's own line for weeks 9-36, and a teacher
     * planning week 12 should get it, not "nothing for that week".
     *
     * Null for any other subject, for a grade the separated plan does not cover
     * (Grades 3-5 have only the combined column, so nothing there is "not
     * separated yet", and Arabic has no column at any grade but the plan's),
     * when the week has a row of its own (the caller checked), when the fence
     * does not let this teacher see the combined column (an Arabic-only
     * teacher), or when the guide has no combined row that week.
     *
     * @param  callable(?string): bool  $fenced
     */
    private function combinedFallback(string $grade, string $subject, int $week, callable $fenced): ?CurriculumWeek
    {
        if (! in_array(SubjectKey::for($subject), self::SEPARATED_KEYS, true) || ! $this->gradeIsSeparated($grade)) {
            return null;
        }

        return CurriculumWeek::query()
            ->where('grade_label', $grade)
            ->where('week_no', $week)
            ->orderBy('subject')
            ->get()
            ->first(fn (CurriculumWeek $r): bool => in_array(SubjectKey::for($r->subject), self::COMBINED_KEYS, true) && $fenced($r->subject));
    }

    /** Whether the school's separated plan has rows for this grade (Pre-K to Grade 2). */
    private function gradeIsSeparated(string $grade): bool
    {
        return CurriculumWeek::query()
            ->where('grade_label', $grade)
            ->distinct()
            ->pluck('subject')
            ->contains(fn (string $subject): bool => in_array(SubjectKey::for($subject), self::SEPARATED_KEYS, true));
    }

    /**
     * The subjects a grade's lesson plans may be filed under: the guide's own
     * (spelled as the guide spells them, so the week and standards lookups below
     * still match exactly), then the school's list (T-001.3) for any subject the
     * guide does not have, e.g. Arabic Language, which the weekly guide has no
     * column for. Matched by subject KEY, so the guide's "Qur’an" and the list's
     * "Qur'an" are one entry.
     *
     * A subject that only the school's list has offers no weeks and no standards:
     * the standards search returns nothing for it and never a made-up standard.
     *
     * With `?group_id=` the list is limited to the subjects THAT teacher teaches
     * in THAT class (App\Support\SubjectFence). A courtesy: the boundary is the
     * lesson-plan write, which refuses the same subjects.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function subjectsFor(Request $request, ?string $grade): \Illuminate\Support\Collection
    {
        $group = $request->filled('group_id') ? \App\Models\Group::find((int) $request->query('group_id')) : null;
        if ($group?->teachesStudents() && SchoolSettings::classSubjects(SchoolSettings::org($group->masjid_id))) {
            return collect(\App\Support\ClassSubjects::fenced(\App\Support\ClassSubjects::offered($group), SubjectFence::limitsFor($request->user(), (int) $group->id)))
                ->pluck('name')->values();
        }

        $out = [];

        $guide = $grade === null
            ? collect()
            : CurriculumWeek::query()->where('grade_label', $grade)
                ->distinct()->orderBy('subject')->pluck('subject');

        foreach ($guide as $name) {
            $out[SubjectKey::for($name)] ??= $name;
        }

        foreach (SchoolSubject::query()->orderBy('position')->orderBy('name')->get() as $subject) {
            // No grade named (a school with no guide): every subject on its list.
            if ($grade === null || $subject->appliesToGrade($grade)) {
                $out[SubjectKey::for($subject->name)] ??= $subject->name;
            }
        }

        $limits = $this->limits($request);

        return collect($out)
            ->filter(fn (string $name, string $key) => SubjectFence::allows($limits, $key))
            ->sortBy(fn (string $name) => mb_strtolower($name))
            ->values();
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
            'group_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = trim((string) ($valid['q'] ?? ''));
        $grade = (string) ($valid['grade'] ?? '');
        $subject = (string) ($valid['subject'] ?? '');
        if (SchoolSettings::classSubjects(SchoolSettings::org($masjid_id))) $subject = (string) $this->guideSubject($request, $subject);
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

        // The same limits as the subject list: a limited teacher searches only the
        // subjects they teach in this class.
        $limits = $this->limits($request);

        $rows = CurriculumWeek::query()
            ->orderBy('grade_label')->orderBy('subject')->orderBy('week_no')
            ->get(['grade_label', 'subject', 'week_no', 'quarter', 'focus', 'objective',
                'learning_outcome', 'standard_code', 'assessment_note', 'source_label'])
            ->filter(fn (CurriculumWeek $r): bool => SubjectFence::allows($limits, SubjectKey::for($r->subject)));

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
            // The objective is part of the wording: the school's plan repeats a
            // code and focus ("K.QUR.MEM.1 Memorization") in two weeks with a
            // different Objective each time, and those are two things to pick.
            $key = implode("\0", [$row->grade_label, $row->subject, (string) $row->standard_code, $row->focus, (string) $row->objective]);

            if (! isset($found[$key])) {
                $found[$key] = [
                    'score' => $score,
                    'scope' => self::scope($row, $grade, $subject),
                    // By subject KEY, so the guide's "Qur'an" and the catalogue's "Qur’an"
                    // (the form's subject comes from either) are the same subject.
                    'in_scope' => ($grade === '' || $row->grade_label === $grade)
                        && ($subject === '' || SubjectKey::for($row->subject) === SubjectKey::for($subject)),
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

                $match = [
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

                // The school's own Objective and Learning Outcome, only on the
                // rows that have them, so an older row's payload is unchanged.
                if (filled($row->objective)) {
                    $match['objective'] = $row->objective;
                }

                if (filled($row->learning_outcome)) {
                    $match['learning_outcome'] = $row->learning_outcome;
                }

                return $match;
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
     * Endings that make another form of the same word: the ending, what may
     * replace it, and the fewest letters the result must keep. Every ending a
     * word carries is tried, and two words meet when the forms they could be
     * an ending away from OVERLAP — "investigation" and "investigate" share
     * "investigate", "planning" and "plans" share "plan". A plain prefix is
     * never enough: "plants" must not meet "plan", nor "partition" "parts".
     *
     * @var list<array{string, list<string>, int}>
     */
    private const ENDINGS = [
        ['ications', ['y'], 3], ['ication', ['y'], 3],       // multiplication → multiply
        // The guide is written in verbs; teachers type the nouns.
        ['isons', ['e'], 4], ['ison', ['e'], 4],             // comparison → compare
        ['aries', ['arize', 'arise'], 4], ['ary', ['arize', 'arise'], 4], // summary → summarize
        ['iptions', ['ibe'], 3], ['iption', ['ibe'], 3],     // description → describe
        ['utions', ['ve'], 3], ['ution', ['ve'], 3],         // solution → solve
        ['anations', ['ain'], 3], ['anation', ['ain'], 3],   // explanation → explain
        ['wth', ['w'], 3],                                    // growth → grow (not "th": health is not heal)
        ['dition', ['d'], 3],                                 // addition → add
        ['itions', ['e'], 4], ['ition', ['e'], 4],            // composition → compose
        ['isions', ['ide', 'ise'], 3], ['ision', ['ide', 'ise'], 3], // division → divide
        ['ations', ['', 'ate'], 4], ['ation', ['', 'ate'], 4],  // investigation → investigate
        ['atives', ['ate'], 4], ['ative', ['ate'], 4],        // cooperative → cooperate
        ['ingly', [''], 3], ['ings', [''], 3], ['ing', [''], 3],
        ['ments', [''], 4], ['ment', [''], 4],
        ['ions', [''], 4], ['ion', [''], 4],                  // subtraction → subtract
        ['ency', ['ent'], 4], ['ence', ['ent'], 4],           // fluency → fluent
        ['ancy', ['ant'], 4], ['ance', ['ant'], 4],
        ['ies', ['y'], 3], ['ied', ['y'], 3],
        ['edly', [''], 3], ['ed', [''], 3],
        ['ers', [''], 3], ['er', [''], 3],
        ['ally', ['al', ''], 4], ['ly', [''], 4],             // fluently → fluent; "early" is not "ear"
        ['al', [''], 5],                                      // emotional → emotion; "total" stays
        ['es', [''], 3],                                      // boxes → box (after s, x, z, ch, sh only)
        ['s', [''], 3],                                       // maps → map, tens → ten
    ];

    /** Endings that begin with a vowel: the stem may have lost an "e" or doubled a letter. */
    private const VOWEL_ENDINGS = ['ing', 'ings', 'ingly', 'ed', 'edly', 'er', 'ers', 'ation', 'ations', 'ion', 'ions', 'al', 'ally'];

    /** @var array<string, array<string, true>> */
    private static array $bases = [];

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
        // "1,000" is "1000": Grade 2 writes one, Grade 3 the other.
        $s = (string) preg_replace('/(?<=\p{N}),(?=\p{N}{3})/u', '', $s);
        // "2-D" is "2D": Kindergarten writes one, Grades 1 and 5 the other. A
        // lone letter only, so "0-5" and "4-letter" stay apart.
        $s = (string) preg_replace('/(?<=\p{N})-(?=\p{L}(?!\p{L}))/u', '', $s);

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
            + ($subject !== '' && SubjectKey::for($row->subject) === SubjectKey::for($subject) ? 1 : 0);
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

        // Every typed word must meet a word of the row. The focus (with the
        // Objective and Learning Outcome, where the school's plan gives them) is
        // the row's own words: a typed word meets one it starts ("fract" → "Fractions")
        // or another form of it ("counting" → "Count", "tajweed" → "Tajwīd").
        // The grade and subject labels only narrow ("math fractions") and meet
        // by their start alone — as roots, "Pre-Kindergarten" would answer
        // every "pre…" and "Studies" every "student".
        $focus = self::tokens(trim($row->focus . ' ' . $row->objective . ' ' . $row->learning_outcome));
        $labels = self::tokens($row->subject . ' ' . $row->grade_label);

        $inFocus = false;

        foreach ($words as $w) {
            $hit = false;

            foreach ($focus as $t) {
                if (self::meets($w, $t)) {
                    $hit = $inFocus = true;
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

        // A row matched only by its subject or grade name ("quran" on a Qur'an
        // form is every week of it) ranks below one whose own words match.
        return $inFocus ? 40 : 30;
    }

    private static function meets(string $word, string $token): bool
    {
        if (str_starts_with($token, $word)) {
            return true;
        }

        // One Arabic term, two transliterations across grades: "tajweed" and
        // "Tajwīd", "noon" and "Nūn", "baa" and "Bā". Doubled vowels fold and
        // the WHOLE words must then be equal, so "seed" does not become "side".
        if (mb_strlen($word) >= 3 && self::longVowels($word) === self::longVowels($token)) {
            return true;
        }

        return array_intersect_key(self::bases($word), self::bases($token)) !== [];
    }

    private static function longVowels(string $s): string
    {
        return str_replace(['ee', 'oo', 'aa'], ['i', 'u', 'a'], $s);
    }

    /**
     * The word and every form it could be an ending away from, as keys.
     *
     * @return array<string, true>
     */
    private static function bases(string $word): array
    {
        if (isset(self::$bases[$word])) {
            return self::$bases[$word];
        }

        $out = [$word => true];
        $add = function (string $base, int $min) use (&$out): void {
            if (mb_strlen($base) >= $min) {
                $out[$base] = true;
            }
        };

        foreach (self::ENDINGS as [$ending, $withs, $min]) {
            if (! str_ends_with($word, $ending) || $word === $ending) {
                continue;
            }

            $stem = mb_substr($word, 0, -mb_strlen($ending));

            // A plural "es" follows s, x, z, ch or sh ("boxes"); otherwise the
            // "e" is the word's own ("planes" is "plane", never "plan").
            if ($ending === 'es' && ! preg_match('/(s|x|z|ch|sh)$/', $stem)) {
                continue;
            }
            // "class", "focus" and "basis" are not plurals.
            if ($ending === 's' && preg_match('/(ss|us|is)$/', $word)) {
                continue;
            }

            foreach ($withs as $with) {
                $add($stem . $with, $min);
            }

            if (in_array($ending, self::VOWEL_ENDINGS, true) && in_array('', $withs, true)) {
                // "rhyming" → "rhyme", "composing" → "compose".
                $add($stem . 'e', $min);
                // "planning" → "plan", but "adding" stays "add".
                if (preg_match('/([b-df-hj-np-tv-z])\1$/', $stem) && ! preg_match('/(ll|ss|zz|ff|dd)$/', $stem)) {
                    $add(mb_substr($stem, 0, -1), 3);
                }
            }
        }

        // The guide ends some Arabic words with and without an "h":
        // "Fatihah" and "Fatiha", "taharah" and "tahara".
        if (str_ends_with($word, 'ah') && mb_strlen($word) >= 4) {
            $out[mb_substr($word, 0, -1)] = true;
        }

        return self::$bases[$word] = $out;
    }
}
