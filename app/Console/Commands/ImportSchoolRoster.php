<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Models\User;
use App\Services\Schools\RosterImportService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Import a school's roster from a CSV: children, guardians, and the edges between.
 *
 * Closes R7. Before this, enrolling sixty children meant sixty manual contact
 * creations plus a roster row and a guardian edge for each — or a public form
 * whose rows land self-asserted and need confirming one at a time.
 *
 * ---------------------------------------------------------------------------
 * THE RULES LIVE IN THE SERVICE NOW; THIS IS THE CONSOLE FACE OF THEM
 * ---------------------------------------------------------------------------
 *
 * Everything below about what a roster CSV is, what it refuses and what it may
 * not do is implemented once in `App\Services\Schools\RosterImportService` and
 * shared with the office's upload screen
 * (`App\Http\Controllers\AdminDashboard\RosterImportController`). This command
 * reads options, prints, and exits with a status; it decides nothing about the
 * file. Two implementations of "a valid roster" would drift, and the drift
 * would appear as children's records the CLI would never have written.
 *
 * This command's SIGNATURE, OUTPUT and EXIT CODES are unchanged by that move —
 * they are somebody's runbook.
 *
 * ---------------------------------------------------------------------------
 * SAFE BY CONSTRUCTION, the same way crm:import-ledger is
 * ---------------------------------------------------------------------------
 *
 *   - DRY RUN BY DEFAULT. Writes only with --execute. The preview names every
 *     row it would create, match or refuse, so an office sees the whole outcome
 *     before any of it is real.
 *   - EVERY CREATED CONTACT CARRIES AN import_batch TAG, so a wrong file is
 *     undone with --rollback=<batch> rather than by hand.
 *   - ALL OR NOTHING. One transaction: a roster that imported forty of sixty
 *     children would be worse than one that imported none, because only the
 *     second is obviously unfinished.
 *   - IDEMPOTENT. Re-running the same file matches what exists and creates
 *     nothing. The common real case is a corrected file re-run twice.
 *
 * ---------------------------------------------------------------------------
 * WHAT A CSV CANNOT DO, ON PURPOSE
 * ---------------------------------------------------------------------------
 *
 * It cannot grant CONSENT. `consent_granted_at` and `consent_scope` are left
 * null on every row this creates, and there is no column for them. Consent is a
 * parent's act — it is what opens a child's photographs to a guardian's view —
 * and a spreadsheet a school typed cannot perform it on their behalf. The office
 * records consent through the screen that exists for it, per family.
 *
 * It cannot create a CLASS. A class named in the file that does not exist is a
 * refused row, not a new classroom: a typo in a class name should not silently
 * found a second Grade 2 and split a roster across it.
 *
 * It cannot enable a parent LOGIN. That is `FamilyAccessService::enable()`, an
 * audited act with its own screen.
 *
 * Rows created here are `provenance = confirmed` via `confirmedByStaff()`,
 * because an office uploading its own roster is exactly the authority as an
 * office typing it in — unlike a public form, whose claims land self-asserted
 * and must be judged.
 *
 * ---------------------------------------------------------------------------
 * The file
 * ---------------------------------------------------------------------------
 *
 *   class,student_first_name,student_last_name,grade,guardian_first_name,
 *   guardian_last_name,guardian_email,guardian_phone
 *
 * One row per CHILD–GUARDIAN pair. A child with two guardians is two rows with
 * the same student columns; the child is created once and both edges are made.
 * Guardian columns may be blank for a child with no guardian on file yet.
 */
class ImportSchoolRoster extends Command
{
    protected $signature = 'schools:import-roster
        {csv : Path to the roster CSV}
        {--masjid= : Masjid id to import into}
        {--batch= : Import batch tag (default: auto)}
        {--actor= : User id of the staff member on whose authority this roster was supplied}
        {--execute : Actually write (otherwise dry-run)}
        {--rollback= : Delete contacts created under this batch tag and exit}';

    protected $description = 'Import a school roster CSV — children, guardians and edges (reversible).';

    public function handle(RosterImportService $importer): int
    {
        $masjidId = (int) $this->option('masjid');

        if ($masjidId <= 0) {
            $this->error('--masjid is required.');

            return self::FAILURE;
        }

        $masjid = Masjid::find($masjidId);

        if (! $masjid) {
            $this->error("No masjid {$masjidId}.");

            return self::FAILURE;
        }

        // Bind the tenant EXPLICITLY. A console command has no request and
        // therefore no ResolveMasjidTenant, so every BelongsToMasjid query here
        // would otherwise run unscoped — which for a write means creating rows
        // in no tenant, and for a read means matching another school's contacts.
        //
        // This line stays in the COMMAND and is deliberately absent from the
        // service: over HTTP the context is already bound by the middleware, and
        // a service that re-bound it would let a caller write into a masjid the
        // middleware never authorised. See .claude/rules/tenant-scoping.md.
        app(TenantContext::class)->set($masjid->id);

        if ($tag = $this->option('rollback')) {
            return $this->rollback($importer, (string) $tag);
        }

        $path = (string) $this->argument('csv');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        ['rows' => $rows, 'problems' => $problems] = $importer->read($path);

        if ($rows === null) {
            // The first problem is the refusal; anything after it is the
            // elaboration ("Expected: class,student_first_name,…"), which was
            // never shouted in red and should not start being.
            $this->error(array_shift($problems));

            foreach ($problems as $problem) {
                $this->line($problem);
            }

            return self::FAILURE;
        }

        $batch = (string) ($this->option('batch') ?: 'roster-' . now()->format('Ymd-His'));
        $execute = (bool) $this->option('execute');

        $plan = $importer->plan($rows);

        $this->render($plan, $batch, $execute);

        if (! $execute) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        if ($plan['refused'] !== []) {
            $this->newLine();
            $this->error('Refusing to write: ' . count($plan['refused']) . ' row(s) could not be read. '
                . 'Fix the file and re-run — a partly-imported roster is worse than none.');

            return self::FAILURE;
        }

        $importer->apply($plan, $batch, $this->actor());

        $this->newLine();
        $this->info("Imported. Batch tag: {$batch}");
        $this->line("Undo with: php artisan schools:import-roster x --masjid={$masjid->id} --rollback={$batch}");

        return self::SUCCESS;
    }

    /**
     * WHO CONFIRMED THESE ROWS. A console command has no signed-in user, so
     * --actor lets whoever runs the import name the staff member on whose
     * authority the file was supplied. Left null it is still `provenance =
     * confirmed` — the office did supply the roster — but the audit trail then
     * names only the batch tag, which is weaker evidence. Prefer to pass it.
     *
     * The upload screen has no equivalent problem: it stamps `Auth::user()`,
     * which is a fact rather than a claim.
     */
    private function actor(): ?User
    {
        $id = (int) $this->option('actor');

        if ($id <= 0) {
            return null;
        }

        $actor = User::find($id);

        if (! $actor) {
            $this->warn("No user {$id}; recording these rows with no named confirmer.");
        }

        return $actor;
    }

    private function render(array $plan, string $batch, bool $execute): void
    {
        $this->newLine();
        $this->info($execute ? "Applying roster import (batch {$batch})" : 'Roster import — DRY RUN');
        $this->newLine();

        // Counted off the plan's own per-row flags rather than asked of the
        // database a second time: the screen shows those flags, so a table that
        // re-derived the numbers could disagree with the preview an office read.
        $existingStudents = count(array_filter($plan['students'], fn ($s) => $s['existing']));
        $existingGuardians = count(array_filter($plan['guardians'], fn ($g) => $g['existing']));

        $this->table(['', 'In the file', 'Already on record', 'Would be created'], [
            ['Students', count($plan['students']), $existingStudents, count($plan['students']) - $existingStudents],
            ['Guardians', count($plan['guardians']), $existingGuardians, count($plan['guardians']) - $existingGuardians],
            ['Guardian edges', count($plan['edges']), '—', '—'],
        ]);

        if ($plan['refused'] !== []) {
            $this->newLine();
            $this->error('Rows that cannot be read:');

            foreach ($plan['refused'] as [$line, $why]) {
                $this->line("  line {$line}: {$why}");
            }
        }

        $this->newLine();
        $this->line('Consent is NOT set by this import — no column, by design. Record it per family.');
    }

    /**
     * THE ONLY UNDO THERE IS, and it is deliberately behind a shell.
     *
     * The upload screen has no undo button: the preview is its safety mechanism,
     * and a one-click reversal of a bulk write over children's rows is a bigger
     * hazard than the mistake it reverses. Reaching this needs the batch tag and
     * a production shell, which is the right amount of friction for it.
     *
     * A batch whose rows are holding academic history is refused IN FULL rather
     * than partly undone — see RosterImportService::rollback().
     */
    private function rollback(RosterImportService $importer, string $batch): int
    {
        $result = $importer->rollback($batch);

        if ($result['refused'] !== []) {
            $this->error("Refusing to undo batch {$batch} — nothing has been removed:");

            foreach ($result['refused'] as $why) {
                $this->line("  {$why}");
            }

            return self::FAILURE;
        }

        if ($result['contacts_removed'] === 0) {
            $this->warn("Nothing carries the batch tag {$batch}.");

            return self::SUCCESS;
        }

        // "Withdrawn and archived", not "deleted": Contact is SoftDeletes, so
        // every name, email and phone number in this batch is still in
        // `contacts` with `deleted_at` set. Saying "removed" here would be the
        // wrong answer to give about children's PII, and the wrong answer to
        // give anyone who ran this to satisfy a deletion request.
        $this->info("Withdrew {$result['contacts_removed']} contact(s) and removed "
            . "{$result['roster_rows_removed']} roster row(s) from batch {$batch}.");
        $this->line('The contact rows are ARCHIVED (soft-deleted), not erased. Erasing a person is a '
            . 'separate, deliberate act.');

        return self::SUCCESS;
    }
}
