<?php

namespace App\Console\Commands;

use App\Models\CurriculumWeek;
use App\Models\Masjid;
use App\Support\CurriculumImportPlan;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Import a school's weekly pacing guide.
 *
 *   php artisan curriculum:import 14 database/curriculum/al-razi-pacing-2026-27.json
 *   php artisan curriculum:import 14 database/curriculum/al-razi-qai-split-2026-27-q1.json --dry-run
 *   php artisan curriculum:import 14 <file> --expect=delete=32,insert=96,after=1576,plans_touching=0,assignments_touching=0,delete_absent=0 --allow-references
 *   php artisan curriculum:import 14 <file> --verify --expect=after=1576
 *
 * IDEMPOTENT. Every row is an upsert against `curriculum_week_cell_unique`, so
 * re-running after the school revises a week corrects that cell rather than
 * duplicating it. Re-importing does NOT touch any lesson plan: prefill copies
 * text onto the plan at the moment a teacher asks for it, so a guide revised in
 * July cannot rewrite what was taught in October.
 *
 * The masjid is named explicitly and never guessed. This writes one tenant's
 * curriculum, and picking the wrong one would put another school's standards in
 * front of a teacher. A file may also name the tenant it was made for
 * (`for_masjid`: id, name_contains, org_type), and a mismatch stops the command.
 *
 * ## A FILE MAY ALSO REPLACE CELLS
 *
 * `replaces` lists cells to DELETE in the same transaction as the upserts: a
 * newer school document supersedes an older one's cells. Production's guide is
 * data no migration wrote, so this command is the one path that changes it, and
 * it is built to be approved before it is applied and undone after:
 *
 *   --dry-run  Print the plan (delete / insert / update / unchanged, before and
 *              after, and the teacher records that copy a cell being deleted),
 *              once for reading and once as a single `PLAN {json}` line. Writes
 *              nothing, not even a file.
 *   --expect=  Refuse (nothing written) unless the plan's counts equal these, so
 *              an apply only ever does what the owner approved.
 *   --allow-references
 *              An apply REFUSES, with or without --expect, while the plan shows a
 *              teacher record that copies a cell being deleted (plans_touching or
 *              assignments_touching above 0) or a cell to delete that is already
 *              absent (delete_absent above 0: the file's spelling is not the
 *              database's, so it would insert beside what it meant to replace).
 *              This flag is the explicit yes, given after the dry run was read. It
 *              waives the refusal for ANY counts, so pass it only with --expect
 *              pinning plans_touching, assignments_touching and delete_absent (the
 *              dry run prints the exact line): a teacher who saves a plan between
 *              the dry run and the apply then stops it instead of passing unreported.
 *   --verify   Read-only: does the database equal the file byte for byte, and
 *              are the replaced cells gone? Exits non-zero on any mismatch. This
 *              is how MySQL's round trip is shown faithful, which SQLite cannot.
 *              It checks ONLY the file's cells and the replaced keys, never the
 *              rest of the tenant's guide, so it prints the tenant's total cells
 *              next to the plan's `after` (`--verify --expect=after=N` fails on a
 *              different total).
 *
 * Before any change an apply writes, 0600, under storage/app/private/
 * curriculum-imports/: an INVERSE file (itself importable: it deletes what this
 * inserted and restores what this deleted or changed, with each row's own
 * source_label) and a SNAPSHOT of every column of every row the tenant has. Both
 * are read back and counted first. Then ONE transaction does the `--fresh`
 * delete, the `replaces` deletes and the upserts, and checks the row count before
 * it commits. A WARNING line records the source, and is a warning because
 * production logs only that level and up.
 *
 * ROLLBACK restores CONTENT, not ids or timestamps: the inverse file re-creates a
 * deleted cell through an upsert, so it comes back with a new id and fresh
 * created_at / updated_at. Nothing references a cell's id. The inverse file is an
 * ordinary import: once teachers have planned on the split cells, applying it
 * REFUSES (plans_touching above 0) until --allow-references is added, so a
 * rollback dry-runs the inverse first and pins its counts like any other apply.
 *
 * ORDERING HAZARD: re-running a base file after a file that replaces some of its
 * cells re-creates them, because an import only upserts. Re-run the replacing
 * file afterwards; it is idempotent.
 */
class ImportCurriculumWeeks extends Command
{
    protected $signature = 'curriculum:import
        {masjid : The masjid id to import into}
        {file : Path to the pacing-guide JSON}
        {--fresh : Delete this tenant\'s existing rows first (inside the transaction)}
        {--dry-run : Print the plan and stop; write nothing}
        {--verify : Read-only: compare the database with the file byte for byte}
        {--allow-references : Apply although the plan shows teacher records that copy a deleted cell, or a cell to delete that is already absent}
        {--expect= : Refuse unless the plan matches, e.g. delete=32,insert=96,update=0,unchanged=0,before=1512,after=1576,plans_touching=0,assignments_touching=0 (with --verify: only after=N)}';

    protected $description = "Import a school's weekly pacing guide into curriculum_weeks";

    /** The columns of a stored cell, in the order they are exported. */
    private const EXPORT = [
        'grade_label', 'subject', 'week_no', 'quarter', 'focus', 'objective',
        'learning_outcome', 'standard_code', 'assessment_note', 'source_label',
    ];

    public function handle(TenantContext $tenant): int
    {
        $masjidId = (int) $this->argument('masjid');
        $path = (string) $this->argument('file');

        $masjid = Masjid::withoutGlobalScopes()->find($masjidId);

        if (! $masjid) {
            $this->error("No masjid with id {$masjidId}.");

            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        if ($this->option('verify') && ($this->option('dry-run') || $this->option('fresh') || $this->option('allow-references'))) {
            $this->error('--verify only reads; it cannot be combined with --dry-run, --fresh or --allow-references.');

            return self::FAILURE;
        }

        if ($this->option('verify') && $this->option('expect') !== null && ! preg_match('/^\s*after=\d+\s*$/', (string) $this->option('expect'))) {
            $this->error('--verify only reads; the one thing --expect can check with it is the total: --expect=after=N.');

            return self::FAILURE;
        }

        $raw = (string) file_get_contents($path);
        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! isset($payload['rows']) || ! is_array($payload['rows'])) {
            $this->error('That file has no `rows` array.');

            return self::FAILURE;
        }

        $guard = CurriculumImportPlan::guardErrors($payload, $masjid, $masjidId);

        if ($guard !== []) {
            foreach ($guard as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        // Bind the tenant so BelongsToMasjid stamps and scopes exactly as it
        // would in a request. Nothing here writes masjid_id by hand.
        $tenant->set($masjidId);

        try {
            return $this->option('verify')
                ? $this->verify($payload, $masjid)
                : $this->import($payload, $masjid, $path, $raw);
        } finally {
            $tenant->forgetTenant();
        }
    }

    private function verify(array $payload, Masjid $masjid): int
    {
        $mismatches = CurriculumImportPlan::verify($payload, $this->existing());
        $total = CurriculumWeek::query()->count();

        // The file's cells and the replaced keys are all this compares. A row the
        // apply should never have touched would pass, so the total is checked
        // against the plan's `after` when the operator gives it.
        $expected = $this->option('expect') !== null ? (int) substr(trim((string) $this->option('expect')), strlen('after=')) : null;

        if ($expected !== null && $expected !== $total) {
            $mismatches[] = "the tenant holds {$total} cells, not the expected {$expected}";
        }

        foreach ($mismatches as $m) {
            $this->line("  MISMATCH {$m}");
        }

        $this->line('mismatches: ' . count($mismatches) . "; {$masjid->name} has {$total} cells"
            . ($expected !== null ? " (plan's after: {$expected})." : " (plan's after: not given; compare it with the dry run's after, or pass --expect=after=N)."));

        if ($mismatches !== []) {
            $this->error('The database is NOT the file.');

            return self::FAILURE;
        }

        $this->info('The database is the file, byte for byte.');

        return self::SUCCESS;
    }

    private function import(array $payload, Masjid $masjid, string $path, string $raw): int
    {
        $masjidId = (int) $masjid->id;
        $existing = $this->existing();
        $fresh = (bool) $this->option('fresh');

        $plan = CurriculumImportPlan::fromPayload($payload, $existing, $fresh);

        if ($plan->errors !== []) {
            foreach ($plan->errors as $e) {
                $this->error($e);
            }

            $this->error('Nothing was written.');

            return self::FAILURE;
        }

        $plan->countReferences();

        $summary = $this->printPlan($plan, $payload, $masjid, $path, $raw);

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $refused = $this->referenceRefusals($plan);

        if ($refused !== []) {
            foreach ($refused as $e) {
                $this->error($e);
            }

            $this->error('Nothing was written. Read the dry run, have the counts approved, then add --allow-references.');

            return self::FAILURE;
        }

        $expectErrors = $this->expectErrors($plan);

        if ($expectErrors !== []) {
            foreach ($expectErrors as $e) {
                $this->error($e);
            }

            $this->error('The plan is not what was approved. Nothing was written.');

            return self::FAILURE;
        }

        try {
            [$inversePath, $snapshotPath] = $this->writeSafetyFiles($plan, $payload, $masjidId, $path, $raw, $existing);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->error('Nothing was changed.');

            return self::FAILURE;
        }

        $this->line("  inverse:  {$inversePath}");
        $this->line("  snapshot: {$snapshotPath}");

        if ($fresh) {
            $this->warn("Deleting {$plan->before} existing rows for {$masjid->name}, inside the transaction.");
        }

        DB::transaction(function () use ($plan, $fresh) {
            if ($fresh) {
                CurriculumWeek::query()->delete();
            } else {
                foreach ($plan->deletes as $row) {
                    CurriculumWeek::query()
                        ->where('grade_label', $row['grade_label'])
                        ->where('subject', $row['subject'])
                        ->where('week_no', (int) $row['week_no'])
                        ->delete();
                }
            }

            foreach (array_merge($plan->inserts, array_column($plan->updates, 'after')) as $row) {
                CurriculumWeek::updateOrCreate(
                    [
                        'grade_label' => $row['grade_label'],
                        'subject' => $row['subject'],
                        'week_no' => $row['week_no'],
                    ],
                    array_intersect_key($row, array_flip(CurriculumImportPlan::CONTENT))
                );
            }

            $now = CurriculumWeek::query()->count();

            if ($now !== $plan->after) {
                throw new RuntimeException("The guide would hold {$now} cells, not the planned {$plan->after}; rolling back.");
            }
        });

        $written = count($plan->inserts) + count($plan->updates) + count($plan->unchanged);

        Log::warning('Curriculum import applied', [
            'masjid_id' => $masjidId,
            'file' => basename($path),
            'file_sha256' => hash('sha256', $raw),
            'source' => $summary['source'],
            'counts' => $plan->counts(),
            'inverse' => $inversePath,
            'snapshot' => $snapshotPath,
        ]);

        $this->info("Imported {$written} cells into {$masjid->name}" . ($plan->skipped ? " ({$plan->skipped} skipped)" : '') . '.');

        $grades = CurriculumWeek::query()->distinct()->pluck('grade_label')->sort()->values();
        $this->line('  grades: ' . $grades->implode(', '));
        $this->line('  with a standard code: ' . CurriculumWeek::query()->whereNotNull('standard_code')->count());

        return self::SUCCESS;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function existing(): Collection
    {
        return CurriculumWeek::query()->get()->map(fn (CurriculumWeek $w): array => $w->getAttributes());
    }

    /** @return array<string, mixed> the PLAN json */
    private function printPlan(CurriculumImportPlan $plan, array $payload, Masjid $masjid, string $path, string $raw): array
    {
        $counts = $plan->counts();
        $source = is_array($payload['source'] ?? null) ? array_diff_key($payload['source'], ['tables' => 1]) : null;

        $summary = [
            'masjid_id' => (int) $masjid->id,
            'file' => basename($path),
            'file_sha256' => hash('sha256', $raw),
            'source' => $source,
            'before' => $counts['before'],
            'delete' => $counts['delete'],
            'delete_absent' => $counts['delete_absent'],
            'insert' => $counts['insert'],
            'update' => $counts['update'],
            'unchanged' => $counts['unchanged'],
            'after' => $counts['after'],
            'by_subject_after' => $plan->bySubjectAfter,
            'plans_touching' => $counts['plans_touching'],
            'plans_combined_subject' => $counts['plans_combined_subject'],
            'assignments_touching' => $counts['assignments_touching'],
            'dry_run' => (bool) $this->option('dry-run'),
        ];

        $this->line("Curriculum import plan for {$masjid->name} (masjid {$masjid->id}) from " . basename($path));
        $this->line("  cells: {$counts['before']} before, {$counts['after']} after");
        $this->line("  delete {$counts['delete']} (already absent: {$counts['delete_absent']}), insert {$counts['insert']}, update {$counts['update']}, unchanged {$counts['unchanged']}");

        foreach ($plan->bySubjectAfter as $subject => $n) {
            $this->line("    {$subject}: {$n}");
        }

        $this->line("  teacher records that copy a cell being deleted: {$counts['plans_touching']} lesson plans, {$counts['assignments_touching']} assignments"
            . " ({$counts['plans_combined_subject']} plans are filed under a deleted cell's subject; none is changed)");

        if ($this->referenceRefusals($plan) !== []) {
            $this->line('  an apply of this plan needs --allow-references (it refuses without it): ' . implode(' ', $this->referenceRefusals($plan)));
            // The flag waives the refusal for any counts, so the apply pins these ones: if a
            // teacher saves a plan between this dry run and the apply, --expect refuses it.
            $this->line('  approved apply: --expect=' . $this->pinnedCounts($counts) . ' --allow-references');
        }

        if ($plan->skipped) {
            $this->line("  skipped: {$plan->skipped} rows with no grade, subject or week");
        }

        if (isset($payload['applies_after'])) {
            $this->line("  note: this file applies after {$payload['applies_after']}. Re-running {$payload['applies_after']} re-creates the cells this file replaces; re-run this file afterwards (it is idempotent).");
        }

        $this->line('PLAN ' . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $summary;
    }

    /**
     * Why an apply must not start without --allow-references. --expect is
     * optional and can pin anything, so a plan with teacher records behind a
     * deleted cell, or a cell to delete that is not there, would otherwise apply
     * on a bare command.
     *
     * @return list<string>
     */
    private function referenceRefusals(CurriculumImportPlan $plan): array
    {
        if ($this->option('allow-references')) {
            return [];
        }

        $counts = $plan->counts();
        $refused = [];

        if ($counts['plans_touching'] > 0) {
            $refused[] = "{$counts['plans_touching']} lesson plans copy a cell this file deletes.";
        }

        if ($counts['assignments_touching'] > 0) {
            $refused[] = "{$counts['assignments_touching']} assignments copy a cell this file deletes.";
        }

        if ($counts['delete_absent'] > 0) {
            $refused[] = "{$counts['delete_absent']} cells the file replaces are not in the guide, so it would add its rows beside what it means to replace"
                . ' (or the cells were already replaced: a second apply).';
        }

        return $refused;
    }

    /**
     * The counts an apply that uses --allow-references must pin: the plan's own
     * shape and the three the flag waives.
     *
     * @param  array<string, int>  $counts
     */
    private function pinnedCounts(array $counts): string
    {
        return implode(',', array_map(
            fn (string $k): string => "{$k}={$counts[$k]}",
            ['delete', 'insert', 'after', 'plans_touching', 'assignments_touching', 'delete_absent'],
        ));
    }

    /** @return list<string> */
    private function expectErrors(CurriculumImportPlan $plan): array
    {
        $option = $this->option('expect');

        if ($option === null) {
            return [];
        }

        $counts = $plan->counts();
        $errors = [];

        foreach (array_filter(array_map('trim', explode(',', (string) $option)), fn ($p) => $p !== '') as $pair) {
            if (! preg_match('/^([a-z_]+)=(\d+)$/', $pair, $m) || ! array_key_exists($m[1], $counts)) {
                $errors[] = "--expect: \"{$pair}\" is not key=number with a key from: " . implode(', ', array_keys($counts)) . '.';

                continue;
            }

            if ((int) $m[2] !== $counts[$m[1]]) {
                $errors[] = "--expect {$m[1]}={$m[2]}, but the plan says {$counts[$m[1]]}.";
            }
        }

        if ($errors === [] && trim((string) $option) === '') {
            $errors[] = '--expect is empty.';
        }

        return $errors;
    }

    /**
     * The inverse file and the snapshot, written 0600 and read back before the
     * transaction starts.
     *
     * @param  Collection<int, array<string, mixed>>  $existing
     * @return array{0: string, 1: string}
     */
    private function writeSafetyFiles(CurriculumImportPlan $plan, array $payload, int $masjidId, string $path, string $raw, Collection $existing): array
    {
        $dir = storage_path('app/private/curriculum-imports');

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        @chmod($dir, 0700);

        $stamp = gmdate('Ymd\THis\Z');
        $basename = basename($path);
        $head = $this->appHead();

        $exportRow = fn (array $row): array => array_map(
            fn (string $c) => $row[$c] ?? null,
            array_combine(self::EXPORT, self::EXPORT)
        );

        // What to put back: every row this deletes, and every row this changes as
        // it was. What to remove: the cells this creates.
        $existingKeys = [];
        foreach ($existing as $row) {
            $existingKeys[CurriculumImportPlan::cell($row['grade_label'], $row['subject'], (int) $row['week_no'])] = true;
        }

        $restore = array_merge(
            array_map($exportRow, $plan->deletes),
            array_map(fn (array $u): array => $exportRow($u['before']), $plan->updates),
        );

        $remove = [];
        foreach ($plan->inserts as $row) {
            if (! isset($existingKeys[CurriculumImportPlan::cell($row['grade_label'], $row['subject'], $row['week_no'])])) {
                $remove[] = ['grade_label' => $row['grade_label'], 'subject' => $row['subject'], 'week_no' => $row['week_no']];
            }
        }

        $inverse = [
            'source_label' => mb_substr("Inverse of {$basename} ({$stamp})", 0, CurriculumImportPlan::LIMITS['source_label']),
            'for_masjid' => $payload['for_masjid'] ?? ['id' => $masjidId],
            'inverse_of' => [
                'file' => $basename,
                'sha256' => hash('sha256', $raw),
                'applied_at' => gmdate('c'),
                'app_head' => $head,
            ],
            'replaces' => $remove,
            'rows' => $restore,
        ];

        $snapshot = [
            'taken_at' => gmdate('c'),
            'masjid_id' => $masjidId,
            'app_head' => $head,
            'file' => $basename,
            'rows' => $existing->values()->all(),
        ];

        $inversePath = $this->writeExclusive($dir, "m{$masjidId}-{$stamp}-inverse-of-{$basename}", $inverse);
        $snapshotPath = $this->writeExclusive($dir, "m{$masjidId}-{$stamp}-snapshot.json", $snapshot);

        $back = json_decode((string) file_get_contents($inversePath), true);

        if (! is_array($back) || count($back['rows'] ?? []) !== count($restore) || count($back['replaces'] ?? []) !== count($remove)) {
            throw new RuntimeException("The inverse file {$inversePath} did not read back as written.");
        }

        $back = json_decode((string) file_get_contents($snapshotPath), true);

        if (! is_array($back) || count($back['rows'] ?? []) !== $plan->before) {
            throw new RuntimeException("The snapshot {$snapshotPath} did not read back as written.");
        }

        return [$inversePath, $snapshotPath];
    }

    /**
     * Create a 0600 file that does not already exist (a second apply within the
     * second must not overwrite the first's inverse) and return its path.
     */
    private function writeExclusive(string $dir, string $name, array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $dot = strrpos($name, '.');

        for ($n = 0; $n < 100; $n++) {
            $candidate = $n === 0 ? $name : substr($name, 0, $dot) . "-{$n}" . substr($name, $dot);
            $file = "{$dir}/{$candidate}";
            $old = umask(0177);
            $handle = @fopen($file, 'x');
            umask($old);

            if ($handle === false) {
                continue;
            }

            chmod($file, 0600);
            $written = fwrite($handle, $json);
            fclose($handle);

            if ($written !== strlen($json)) {
                throw new RuntimeException("Short write to {$file}.");
            }

            return $file;
        }

        throw new RuntimeException("Cannot create {$name} in {$dir}.");
    }

    /** The checked-out commit, when the app is a git checkout; null on a release without one. */
    private function appHead(): ?string
    {
        $head = @file_get_contents(base_path('.git/HEAD'));

        if ($head === false) {
            return null;
        }

        $head = trim($head);

        if (str_starts_with($head, 'ref: ')) {
            $ref = @file_get_contents(base_path('.git/' . substr($head, 5)));

            return $ref === false ? null : trim($ref);
        }

        return $head !== '' ? $head : null;
    }
}
