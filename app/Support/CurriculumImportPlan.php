<?php

namespace App\Support;

use App\Models\ClassAssignment;
use App\Models\CurriculumWeek;
use App\Models\LessonPlan;
use App\Models\Masjid;
use Illuminate\Support\Collection;

/**
 * What `curriculum:import` is about to do, worked out BEFORE it writes anything.
 *
 * The importer changes a school's guide, and production's guide is data no
 * migration wrote. So the owner must be able to see, in exact counts, what a file
 * will delete, insert and change, approve those counts, and have the apply refuse
 * if the world moved between the two. This class is the pure part of that: it
 * validates a payload, diffs it against the tenant's existing rows, and counts
 * the teacher records that copy a cell about to be deleted. It writes nothing.
 * The command owns every write.
 *
 * A payload has:
 *   - `rows`     the cells to upsert (as the importer always took them);
 *   - `replaces` (optional) cells to DELETE in the same transaction, because a
 *                newer school document supersedes them;
 *   - `for_masjid` (optional) the tenant the file was made for;
 *   - `source_label`, `source`, `applies_after` (provenance, informational).
 *
 * Cell equality for "update" against "unchanged" is strict `===` over quarter,
 * focus, objective, learning_outcome, standard_code, assessment_note and
 * source_label. A key a row lacks means NULL, exactly as `?? null` always did.
 */
final class CurriculumImportPlan
{
    /** The columns a cell's content lives in, in the order they are compared and written. */
    public const CONTENT = [
        'quarter', 'focus', 'objective', 'learning_outcome',
        'standard_code', 'assessment_note', 'source_label',
    ];

    /** Column limits (characters, by mb_strlen). `focus` must also be non-empty. */
    public const LIMITS = [
        'grade_label' => 32,
        'subject' => 64,
        'focus' => 500,
        'objective' => 500,
        'learning_outcome' => 500,
        'standard_code' => 32,
        'assessment_note' => 255,
        'source_label' => 120,
    ];

    /** @var list<array<string, mixed>> existing rows (full) that will be deleted */
    public array $deletes = [];

    /** @var list<array<string, mixed>> normalized file rows with no existing cell */
    public array $inserts = [];

    /** @var list<array{before: array<string, mixed>, after: array<string, mixed>}> */
    public array $updates = [];

    /** @var list<array<string, mixed>> */
    public array $unchanged = [];

    /** @var list<array{grade_label: string, subject: string, week_no: int}> replaces cells with no row to delete */
    public array $deleteAbsent = [];

    public int $before = 0;

    public int $after = 0;

    /** Rows skipped for having no grade, subject or week (the base file's long-standing behaviour). */
    public int $skipped = 0;

    /** @var array<string, int> */
    public array $bySubjectAfter = [];

    /** @var list<string> any one stops the command with nothing written */
    public array $errors = [];

    /** @var array<string, int> the teacher records that copy a cell being deleted */
    public array $references = ['plans_touching' => 0, 'plans_combined_subject' => 0, 'assignments_touching' => 0];

    /**
     * Validate `$payload` and diff it against `$existing`, the tenant's rows as
     * arrays keyed by column.
     *
     * @param  Collection<int, array<string, mixed>>  $existing
     */
    public static function fromPayload(array $payload, Collection $existing, bool $fresh): self
    {
        $plan = new self;
        $hasReplaces = isset($payload['replaces']) && is_array($payload['replaces']) && $payload['replaces'] !== [];

        if (! isset($payload['rows']) || ! is_array($payload['rows'])) {
            $plan->errors[] = 'That file has no `rows` array.';

            return $plan;
        }

        if ($fresh && $hasReplaces) {
            $plan->errors[] = '--fresh cannot be combined with a file that has `replaces`: the file already says exactly which cells go.';
        }

        $fileLabel = isset($payload['source_label']) ? (string) $payload['source_label'] : null;

        if ($fileLabel !== null && mb_strlen($fileLabel) > self::LIMITS['source_label']) {
            $plan->errors[] = 'source_label is ' . mb_strlen($fileLabel) . ' characters; the limit is ' . self::LIMITS['source_label'] . '.';
        }

        $byCell = [];
        foreach ($existing as $row) {
            $byCell[self::cell($row['grade_label'], $row['subject'], (int) $row['week_no'])] = $row;
        }

        $plan->before = count($byCell);

        // ---- rows ----------------------------------------------------------
        $fileRows = [];

        foreach ($payload['rows'] as $i => $raw) {
            if (! is_array($raw)) {
                $plan->errors[] = "rows[{$i}] is not an object.";

                continue;
            }

            // A cell with no grade, subject or week cannot be looked up, so it
            // cannot be prefilled from: skip rather than store junk. That is how
            // the base file has always behaved, so it stays a skip, and becomes an
            // error only in a file that also says what it replaces.
            if (empty($raw['grade_label']) || empty($raw['subject']) || empty($raw['week_no'])) {
                if ($hasReplaces) {
                    $plan->errors[] = "rows[{$i}] has no grade_label, subject or week_no.";
                } else {
                    $plan->skipped++;
                }

                continue;
            }

            $row = self::normalize($raw, $fileLabel);
            $where = "rows[{$i}] ({$row['grade_label']} / {$row['subject']} / week {$row['week_no']})";

            foreach (self::rowErrors($row) as $e) {
                $plan->errors[] = "{$where}: {$e}";
            }

            $key = self::cell($row['grade_label'], $row['subject'], $row['week_no']);

            if (isset($fileRows[$key])) {
                $plan->errors[] = "{$where} appears twice in `rows`.";

                continue;
            }

            $fileRows[$key] = $row;
        }

        // ---- replaces ------------------------------------------------------
        $replaceKeys = [];

        if (isset($payload['replaces'])) {
            if (! is_array($payload['replaces'])) {
                $plan->errors[] = '`replaces` is not an array.';
            } else {
                foreach ($payload['replaces'] as $i => $raw) {
                    if (! is_array($raw) || empty($raw['grade_label']) || empty($raw['subject']) || empty($raw['week_no'])) {
                        $plan->errors[] = "replaces[{$i}] needs a grade_label, subject and week_no.";

                        continue;
                    }

                    $key = self::cell((string) $raw['grade_label'], (string) $raw['subject'], (int) $raw['week_no']);

                    if (isset($replaceKeys[$key])) {
                        $plan->errors[] = "replaces[{$i}] names a cell twice.";

                        continue;
                    }

                    if (isset($fileRows[$key])) {
                        $plan->errors[] = "replaces[{$i}] ({$raw['grade_label']} / {$raw['subject']} / week {$raw['week_no']}) is also in `rows`.";
                    }

                    $replaceKeys[$key] = [
                        'grade_label' => (string) $raw['grade_label'],
                        'subject' => (string) $raw['subject'],
                        'week_no' => (int) $raw['week_no'],
                    ];
                }
            }
        }

        if ($plan->errors !== []) {
            return $plan;
        }

        // ---- the diff ------------------------------------------------------
        $state = $byCell;

        if ($fresh) {
            $plan->deletes = array_values($byCell);
            $state = [];
        } else {
            foreach ($replaceKeys as $key => $cell) {
                if (isset($byCell[$key])) {
                    $plan->deletes[] = $byCell[$key];
                    unset($state[$key]);
                } else {
                    $plan->deleteAbsent[] = $cell;
                }
            }
        }

        foreach ($fileRows as $key => $row) {
            if ($fresh || ! isset($byCell[$key])) {
                $plan->inserts[] = $row;
            } elseif (self::sameContent($row, $byCell[$key])) {
                $plan->unchanged[] = $row;
            } else {
                $plan->updates[] = ['before' => $byCell[$key], 'after' => $row];
            }

            $state[$key] = $row;
        }

        $plan->after = count($state);

        foreach ($state as $row) {
            $plan->bySubjectAfter[$row['subject']] = ($plan->bySubjectAfter[$row['subject']] ?? 0) + 1;
        }

        ksort($plan->bySubjectAfter);

        return $plan;
    }

    /**
     * Refuse a file made for another school. Empty when it is fine or the file
     * names no tenant (the base file does not).
     *
     * @return list<string>
     */
    public static function guardErrors(array $payload, Masjid $masjid, int $argumentId): array
    {
        $for = $payload['for_masjid'] ?? null;

        if ($for === null) {
            return [];
        }

        if (! is_array($for)) {
            return ['`for_masjid` is not an object.'];
        }

        $errors = [];

        if (isset($for['id']) && (int) $for['id'] !== $argumentId) {
            $errors[] = "This file is for masjid {$for['id']}, not {$argumentId}.";
        }

        if (isset($for['name_contains']) && $for['name_contains'] !== ''
            && mb_stripos((string) $masjid->name, (string) $for['name_contains']) === false) {
            $errors[] = "Masjid {$argumentId} is \"{$masjid->name}\"; this file is for a masjid whose name contains \"{$for['name_contains']}\".";
        }

        if (isset($for['org_type']) && $for['org_type'] !== $masjid->orgType()) {
            $errors[] = "Masjid {$argumentId} is a {$masjid->orgType()}; this file is for a {$for['org_type']}.";
        }

        return $errors;
    }

    /**
     * How many teacher records copy a cell this import is about to delete. Plans
     * and assignments hold copied text and no foreign key, so deleting a guide
     * row changes none of them. The owner still gets the numbers, and `--expect`
     * pins them, so that he approves with the true ones.
     *
     *  - plans_touching: distinct lesson plans whose (grade, week, subject key)
     *    is a deleted cell's, or whose objective is a deleted cell's focus;
     *  - assignments_touching: assignments (deleted ones too) with no standard
     *    code whose curriculum_focus is a deleted cell's focus, in that week or
     *    with no week;
     *  - plans_combined_subject: plans filed under a deleted cell's subject at
     *    any week (information only).
     *
     * Reads are tenant-scoped through the bound TenantContext.
     */
    public function countReferences(): void
    {
        $refs = ['plans_touching' => 0, 'plans_combined_subject' => 0, 'assignments_touching' => 0];

        if ($this->deletes === []) {
            $this->references = $refs;

            return;
        }

        $cells = [];      // "grade\0week\0subjectKey" => true
        $subjectKeys = []; // subjectKey => true
        $focusWeeks = [];  // focus => [week => true]

        foreach ($this->deletes as $row) {
            $key = SubjectKey::for($row['subject']);
            $week = (int) $row['week_no'];

            $cells[$row['grade_label'] . "\0" . $week . "\0" . $key] = true;
            $subjectKeys[$key] = true;
            $focusWeeks[$row['focus']][$week] = true;
        }

        foreach (LessonPlan::query()->get(['id', 'subject', 'grade_label', 'curriculum_week_no', 'objective']) as $plan) {
            $key = SubjectKey::for($plan->subject);
            $touches = isset($cells[$plan->grade_label . "\0" . (int) $plan->curriculum_week_no . "\0" . $key])
                || ($plan->objective !== null && isset($focusWeeks[$plan->objective]));

            $refs['plans_touching'] += $touches ? 1 : 0;
            $refs['plans_combined_subject'] += isset($subjectKeys[$key]) && $key !== '' ? 1 : 0;
        }

        $assignments = ClassAssignment::withTrashed()
            ->whereNull('standard_code')
            ->whereNotNull('curriculum_focus')
            ->get(['id', 'curriculum_focus', 'curriculum_week_no']);

        foreach ($assignments as $assignment) {
            $weeks = $focusWeeks[$assignment->curriculum_focus] ?? null;

            if ($weeks === null) {
                continue;
            }

            if ($assignment->curriculum_week_no === null || isset($weeks[(int) $assignment->curriculum_week_no])) {
                $refs['assignments_touching']++;
            }
        }

        $this->references = $refs;
    }

    /**
     * What `--verify` reports: every way the tenant's rows differ from the file,
     * byte for byte (strict `===` on every column). A file row missing from the
     * database, a differing column, and a `replaces` cell still present are each
     * one mismatch. Empty means the database IS the file.
     *
     * @param  Collection<int, array<string, mixed>>  $existing
     * @return list<string>
     */
    public static function verify(array $payload, Collection $existing): array
    {
        $byCell = [];
        foreach ($existing as $row) {
            $byCell[self::cell($row['grade_label'], $row['subject'], (int) $row['week_no'])] = $row;
        }

        $fileLabel = isset($payload['source_label']) ? (string) $payload['source_label'] : null;
        $mismatches = [];

        foreach ((array) ($payload['rows'] ?? []) as $raw) {
            if (! is_array($raw) || empty($raw['grade_label']) || empty($raw['subject']) || empty($raw['week_no'])) {
                continue;
            }

            $row = self::normalize($raw, $fileLabel);
            $label = "{$row['grade_label']} / {$row['subject']} / week {$row['week_no']}";
            $have = $byCell[self::cell($row['grade_label'], $row['subject'], $row['week_no'])] ?? null;

            if ($have === null) {
                $mismatches[] = "{$label}: missing from the database";

                continue;
            }

            foreach (self::CONTENT as $column) {
                $value = $have[$column] ?? null;

                if ($column === 'quarter' && $value !== null) {
                    $value = (int) $value;
                }

                if ($row[$column] !== $value) {
                    $mismatches[] = "{$label}: {$column} differs";
                }
            }
        }

        foreach ((array) ($payload['replaces'] ?? []) as $raw) {
            if (is_array($raw) && isset($raw['grade_label'], $raw['subject'], $raw['week_no'])
                && isset($byCell[self::cell((string) $raw['grade_label'], (string) $raw['subject'], (int) $raw['week_no'])])) {
                $mismatches[] = "{$raw['grade_label']} / {$raw['subject']} / week {$raw['week_no']}: a replaced cell is still present";
            }
        }

        return $mismatches;
    }

    /**
     * The counts `--expect` may pin, and the plan JSON carries.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'before' => $this->before,
            'delete' => count($this->deletes),
            'delete_absent' => count($this->deleteAbsent),
            'insert' => count($this->inserts),
            'update' => count($this->updates),
            'unchanged' => count($this->unchanged),
            'after' => $this->after,
        ] + $this->references;
    }

    /** @return list<array<string, mixed>> */
    public function fileRows(): array
    {
        return array_merge(
            $this->inserts,
            array_column($this->updates, 'after'),
            $this->unchanged,
        );
    }

    public static function cell(string $grade, string $subject, int $week): string
    {
        return $grade . "\0" . $subject . "\0" . $week;
    }

    /**
     * A file row as the columns it will be stored in. A key it lacks is NULL;
     * `source_label` is the row's own, else the file's.
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $raw, ?string $fileLabel): array
    {
        $string = fn (string $k): ?string => isset($raw[$k]) ? (string) $raw[$k] : null;
        // An empty Objective or Learning Outcome is an absent one: stored as NULL,
        // so every read path sees the same thing.
        $optional = fn (string $k): ?string => ($v = $string($k)) === '' ? null : $v;

        return [
            'grade_label' => (string) $raw['grade_label'],
            'subject' => (string) $raw['subject'],
            'week_no' => (int) $raw['week_no'],
            'quarter' => isset($raw['quarter']) ? (int) $raw['quarter'] : null,
            'focus' => (string) ($raw['focus'] ?? ''),
            'objective' => $optional('objective'),
            'learning_outcome' => $optional('learning_outcome'),
            'standard_code' => $string('standard_code'),
            'assessment_note' => $string('assessment_note'),
            // An explicit null is kept: an inverse file restores a row whose own
            // label was NULL as NULL, not as the inverse file's label.
            'source_label' => array_key_exists('source_label', $raw) ? $string('source_label') : $fileLabel,
        ];
    }

    /** @return list<string> */
    private static function rowErrors(array $row): array
    {
        $errors = [];

        foreach (self::LIMITS as $column => $limit) {
            if (isset($row[$column]) && mb_strlen($row[$column]) > $limit) {
                $errors[] = "{$column} is " . mb_strlen($row[$column]) . " characters; the limit is {$limit}.";
            }
        }

        if ($row['focus'] === '') {
            $errors[] = 'focus is empty.';
        }

        if ($row['week_no'] < 1 || $row['week_no'] > 255) {
            $errors[] = "week_no {$row['week_no']} is outside 1-255.";
        }

        if ($row['quarter'] !== null && ($row['quarter'] < 0 || $row['quarter'] > 255)) {
            $errors[] = "quarter {$row['quarter']} is outside 0-255.";
        }

        return $errors;
    }

    /** @param array<string, mixed> $row a normalized file row */
    private static function sameContent(array $row, array $existing): bool
    {
        foreach (self::CONTENT as $column) {
            $have = $existing[$column] ?? null;

            if ($column === 'quarter' && $have !== null) {
                $have = (int) $have;
            }

            if ($row[$column] !== $have) {
                return false;
            }
        }

        return true;
    }
}
