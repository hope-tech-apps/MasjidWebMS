<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import a school's roster from a CSV: children, guardians, and the edges between.
 *
 * Closes R7. Before this, enrolling sixty children meant sixty manual contact
 * creations plus a roster row and a guardian edge for each — or a public form
 * whose rows land self-asserted and need confirming one at a time.
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

    /** Matching is on a normalised email; a child usually has none. */
    private const HEADERS = [
        'class', 'student_first_name', 'student_last_name', 'grade',
        'guardian_first_name', 'guardian_last_name', 'guardian_email', 'guardian_phone',
    ];

    public function handle(): int
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
        app(TenantContext::class)->set($masjid->id);

        if ($tag = $this->option('rollback')) {
            return $this->rollback((string) $tag);
        }

        $path = (string) $this->argument('csv');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $rows = $this->read($path);

        if ($rows === null) {
            return self::FAILURE;
        }

        $batch = (string) ($this->option('batch') ?: 'roster-' . now()->format('Ymd-His'));
        $execute = (bool) $this->option('execute');

        $plan = $this->plan($rows);

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

        $this->apply($plan, $batch);

        $this->newLine();
        $this->info("Imported. Batch tag: {$batch}");
        $this->line("Undo with: php artisan schools:import-roster x --masjid={$masjid->id} --rollback={$batch}");

        return self::SUCCESS;
    }

    // --------------------------------------------------------------- reading

    /** @return array<int, array<string, string>>|null */
    private function read(string $path): ?array
    {
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, 0, ',', '"', '');

        if ($header === false) {
            $this->error('The file is empty.');
            fclose($fh);

            return null;
        }

        // A file exported from a spreadsheet often carries a UTF-8 BOM on the
        // first header, which would make 'class' unmatchable and every row
        // refused for a reason nobody could see.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($h) => Str::snake(trim(strtolower((string) $h))), $header);

        $missing = array_diff(self::HEADERS, $header);

        if ($missing !== []) {
            $this->error('Missing column(s): ' . implode(', ', $missing));
            $this->line('Expected: ' . implode(',', self::HEADERS));
            fclose($fh);

            return null;
        }

        $rows = [];

        while (($line = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count(array_filter($line, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;   // a blank line at the end of a spreadsheet export
            }

            $row = [];

            foreach ($header as $i => $name) {
                // Strip a leading apostrophe: our own export writes one in front
                // of anything formula-shaped, so a file that made the round trip
                // must not import a name as "'=Ali".
                $row[$name] = ltrim(trim((string) ($line[$i] ?? '')), "'");
            }

            $rows[] = $row;
        }

        fclose($fh);

        return $rows;
    }

    // ---------------------------------------------------------------- planning

    /**
     * Work out what WOULD happen, touching nothing.
     *
     * The plan is what the dry run prints and what --execute then applies, so
     * the preview cannot drift from the write: they are the same computation.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{students: array, guardians: array, edges: array, refused: array}
     */
    private function plan(array $rows): array
    {
        $classes = Group::whereIn('kind', [Group::KIND_CLASS, Group::KIND_HALAQA])->get();

        $students = [];
        $guardians = [];
        $edges = [];
        $refused = [];

        foreach ($rows as $n => $row) {
            $line = $n + 2;   // +1 for the header, +1 for 1-based
            $className = trim($row['class']);
            $first = trim($row['student_first_name']);
            $last = trim($row['student_last_name']);

            if ($className === '' || $first === '') {
                $refused[] = [$line, 'needs at least a class and a student first name'];

                continue;
            }

            $class = $classes->first(fn (Group $g) => Str::lower($g->name) === Str::lower($className));

            if (! $class) {
                // Never created: a typo must not found a second Grade 2 and
                // split a roster silently across both.
                $refused[] = [$line, "no class named \"{$className}\" — create it first"];

                continue;
            }

            $sKey = Str::lower($class->id . '|' . $first . '|' . $last);
            $students[$sKey] ??= [
                'class' => $class, 'first' => $first, 'last' => $last,
                'grade' => trim($row['grade']) ?: null, 'lines' => [],
            ];
            $students[$sKey]['lines'][] = $line;

            $gEmail = Str::lower(trim($row['guardian_email']));
            $gFirst = trim($row['guardian_first_name']);

            if ($gFirst === '' && $gEmail === '') {
                continue;   // a child with no guardian on file yet is legitimate
            }

            if ($gEmail === '') {
                $refused[] = [$line, 'a guardian needs an email — it is how the family signs in later'];

                continue;
            }

            $guardians[$gEmail] ??= [
                'first' => $gFirst, 'last' => trim($row['guardian_last_name']),
                'email' => $gEmail, 'phone' => trim($row['guardian_phone']) ?: null,
            ];

            $edges[] = ['student' => $sKey, 'guardian' => $gEmail, 'class' => $class, 'line' => $line];
        }

        return compact('students', 'guardians', 'edges', 'refused');
    }

    private function render(array $plan, string $batch, bool $execute): void
    {
        $this->newLine();
        $this->info($execute ? "Applying roster import (batch {$batch})" : 'Roster import — DRY RUN');
        $this->newLine();

        $existingStudents = 0;

        foreach ($plan['students'] as $s) {
            if ($this->findStudent($s)) {
                $existingStudents++;
            }
        }

        $existingGuardians = Contact::whereIn('email', array_keys($plan['guardians']))->count();

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

    // --------------------------------------------------------------- applying

    private function apply(array $plan, string $batch): void
    {
        // WHO CONFIRMED THESE ROWS. A console command has no signed-in user, so
        // --actor lets whoever runs the import name the staff member on whose
        // authority the file was supplied. Left null it is still `provenance =
        // confirmed` — the office did supply the roster — but the audit trail
        // then names only the batch tag, which is weaker evidence. Prefer to
        // pass it.
        $actor = ($id = (int) $this->option('actor')) > 0 ? User::find($id) : null;

        if ($id > 0 && ! $actor) {
            $this->warn("No user {$id}; recording these rows with no named confirmer.");
        }

        DB::transaction(function () use ($plan, $batch, $actor) {
            $studentContacts = [];

            foreach ($plan['students'] as $key => $s) {
                $contact = $this->findStudent($s) ?? Contact::create([
                    'first_name' => $s['first'],
                    'last_name' => $s['last'] ?: null,
                    'import_batch' => $batch,
                ]);

                $studentContacts[$key] = $contact;

                $membership = GroupMembership::firstOrNew([
                    'group_id' => $s['class']->id,
                    'contact_id' => $contact->id,
                    'role' => GroupMembership::ROLE_MEMBER,
                    'guardian_of_contact_id' => null,
                ]);

                $membership->fill([
                    'masjid_id' => $s['class']->masjid_id,
                    'grade_label' => $s['grade'],
                ])->confirmedByStaff($actor)->save();
            }

            $guardianContacts = [];

            foreach ($plan['guardians'] as $email => $g) {
                $guardianContacts[$email] = Contact::where('email', $email)->first() ?? Contact::create([
                    'first_name' => $g['first'],
                    'last_name' => $g['last'] ?: null,
                    'email' => $g['email'],
                    'phone' => $g['phone'],
                    'import_batch' => $batch,
                ]);
            }

            foreach ($plan['edges'] as $e) {
                $student = $studentContacts[$e['student']] ?? null;
                $guardian = $guardianContacts[$e['guardian']] ?? null;

                if (! $student || ! $guardian) {
                    continue;
                }

                $edge = GroupMembership::firstOrNew([
                    'group_id' => $e['class']->id,
                    'contact_id' => $guardian->id,
                    'role' => GroupMembership::ROLE_GUARDIAN,
                    'guardian_of_contact_id' => $student->id,
                ]);

                // consent_granted_at and consent_scope are deliberately untouched:
                // a spreadsheet cannot perform a parent's act.
                $edge->fill(['masjid_id' => $e['class']->masjid_id])
                    ->confirmedByStaff($actor)
                    ->save();
            }
        });
    }

    /** An existing child: same class, same name. */
    private function findStudent(array $s): ?Contact
    {
        $ids = GroupMembership::where('group_id', $s['class']->id)
            ->whereIn('role', GroupMembership::PARTICIPANT_ROLES)
            ->pluck('contact_id');

        return Contact::whereKey($ids)
            ->whereRaw('LOWER(first_name) = ?', [Str::lower($s['first'])])
            ->whereRaw('LOWER(COALESCE(last_name, "")) = ?', [Str::lower($s['last'])])
            ->first();
    }

    // --------------------------------------------------------------- rollback

    private function rollback(string $batch): int
    {
        $contacts = Contact::where('import_batch', $batch)->get();

        if ($contacts->isEmpty()) {
            $this->warn("Nothing carries the batch tag {$batch}.");

            return self::SUCCESS;
        }

        // Memberships go first and explicitly. A contact delete would take them
        // anyway, but doing it here means the count reported is the count that
        // happened rather than a cascade nobody watched.
        $removed = 0;

        DB::transaction(function () use ($contacts, &$removed) {
            foreach ($contacts as $c) {
                $removed += GroupMembership::where('contact_id', $c->id)->delete();
                GroupMembership::where('guardian_of_contact_id', $c->id)->delete();
                $c->delete();
            }
        });

        $this->info("Rolled back {$contacts->count()} contact(s) and {$removed} roster row(s) from batch {$batch}.");

        return self::SUCCESS;
    }
}
