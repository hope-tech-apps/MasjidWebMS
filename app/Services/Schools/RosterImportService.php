<?php

namespace App\Services\Schools;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Support\AcademicRecordsHeld;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reading, planning, applying and undoing a school roster CSV.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A SERVICE AND NOT A COMMAND
 * ---------------------------------------------------------------------------
 *
 * `schools:import-roster` has done this correctly since R7 closed, and it did
 * it where only an engineer with a shell could reach: the office that owns the
 * roster could not run its own import. Giving it a screen means a SECOND caller
 * over the same file format, the same refusals and the same writes.
 *
 * Re-implementing any of that in a controller would be the whole hazard of this
 * feature. Two readers of one CSV format drift — one strips the BOM and the
 * other does not, one refuses a guardian with no email and the other creates a
 * loginless parent — and the drift shows up as children's rows that the CLI
 * would never have written. So the command became a thin CLI over this class,
 * its behaviour unchanged, and the controller is a thin HTTP surface over the
 * same four methods. There is exactly one definition of "a valid roster".
 *
 * ---------------------------------------------------------------------------
 * WHAT MOVED, AND THE TWO THINGS THAT CHANGED
 * ---------------------------------------------------------------------------
 *
 * `read()`, `plan()`, `apply()`, `findStudent()` and `rollback()` came across
 * from `App\Console\Commands\ImportSchoolRoster` as they stood. Three
 * deliberate differences, each forced by having a second caller:
 *
 *  1. `read()` RETURNS its refusals instead of printing them. A controller has
 *     no `$this->error()`, and a reader that writes to a console is a reader
 *     only one caller can have.
 *  2. `plan()` marks every student and guardian `existing`. The CLI only ever
 *     needed three totals to print a table; an office reading a screen needs
 *     the per-row answer, because "Aisha Khan will be created" is how somebody
 *     notices she is already enrolled as "Ayshah Khan". The command's own table
 *     is now computed from these flags rather than by asking the database a
 *     second time, so the two callers cannot disagree about who is new.
 *  3. `apply()` RETURNS what it did. The CLI printed a batch tag and stopped;
 *     an HTTP caller has to tell the office how many people it just created,
 *     and the count of created contacts is also the count the undo warning has
 *     to name.
 *
 * ---------------------------------------------------------------------------
 * THIS SERVICE NEVER BINDS A TENANT
 * ---------------------------------------------------------------------------
 *
 * `ImportSchoolRoster::handle()` calls `TenantContext::set()` because a console
 * command has no request and therefore no `ResolveMasjidTenant`. That line
 * stayed in the COMMAND on purpose. A service that re-bound the tenant would
 * let an HTTP caller whose context is bound to masjid A write into masjid B by
 * naming B somewhere the middleware never looked — which is precisely the
 * guardrail `.claude/rules/tenant-scoping.md` exists to keep. Every query below
 * runs under whatever `BelongsToMasjid` is already scoped to, and the caller is
 * responsible for that being the right thing.
 *
 * ---------------------------------------------------------------------------
 * WHAT A ROSTER CSV STILL CANNOT DO
 * ---------------------------------------------------------------------------
 *
 * Unchanged from the command's docblock, and load-bearing for the screen: this
 * cannot grant CONSENT (`consent_granted_at` / `consent_scope` are left null on
 * every row it writes — consent is a parent's act), cannot create a CLASS (a
 * typo must not found a second Grade 2), and cannot enable a family LOGIN (that
 * is `FamilyAccessService::enable()`, an audited act with its own screen). A
 * screen that implies otherwise is worse than no screen, because the office
 * would believe the parents are set up.
 */
class RosterImportService
{
    /**
     * The eight columns, exactly. Matching is on a normalised email; a child
     * usually has none.
     */
    public const HEADERS = [
        'class', 'student_first_name', 'student_last_name', 'grade',
        'guardian_first_name', 'guardian_last_name', 'guardian_email', 'guardian_phone',
    ];

    /**
     * THE REFUSAL FOR A FILE EXCEL WROTE IN THE WINDOWS CODEPAGE.
     *
     * "CSV (Comma delimited)" — Excel's default Save As on Windows — writes the
     * ANSI codepage, not UTF-8, so one "Zoë" or "Núr" in a roster is a single
     * byte 0xEB / 0xFA. Those bytes reach `response()->json()`, `json_encode`
     * fails with JSON_ERROR_UTF8, and Laravel throws — the office gets a bare
     * HTTP 500 and "Server Error", which does not tell them the one thing they
     * need to know. On the commit path the same bytes reach utf8mb4 columns and
     * MySQL's strict mode answers "Incorrect string value".
     *
     * Refused rather than transcoded ON PURPOSE. Guessing a codepage guesses at
     * the spelling of a child's name, and a wrong guess is silent and permanent;
     * a refusal that names the remedy costs the office one Save As.
     */
    public const NOT_UTF8 = 'This file is not saved as UTF-8, so some of the names in it cannot be read. '
        . 'In Excel choose Save As and pick "CSV UTF-8 (Comma delimited)", then upload that file. '
        . 'Nothing has been imported.';

    // --------------------------------------------------------------- reading

    /**
     * Parse the file at `$path`.
     *
     * Returns the rows, or `null` rows plus the reason the file as a WHOLE
     * could not be read — an empty file, a missing column. Those are different
     * from a refused ROW: nothing in the file can be trusted, so `plan()` is
     * never reached.
     *
     * @return array{rows: array<int, array<string, string>>|null, problems: array<int, string>}
     */
    public function read(string $path): array
    {
        $fh = fopen($path, 'r');

        if ($fh === false) {
            return ['rows' => null, 'problems' => ['Cannot read that file.']];
        }

        $header = fgetcsv($fh, 0, ',', '"', '');

        if ($header === false) {
            fclose($fh);

            return ['rows' => null, 'problems' => ['The file is empty.']];
        }

        // A file exported from a spreadsheet often carries a UTF-8 BOM on the
        // first header, which would make 'class' unmatchable and every row
        // refused for a reason nobody could see.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        // The encoding is checked BEFORE the header is normalised: `Str::snake`
        // and `strtolower` on bytes that are not UTF-8 are not defined to give
        // anything in particular, and the answer we want is one sentence about
        // the file rather than a mangled column name in a "missing column"
        // refusal.
        foreach ($header as $name) {
            if (! mb_check_encoding((string) $name, 'UTF-8')) {
                fclose($fh);

                return ['rows' => null, 'problems' => [self::NOT_UTF8]];
            }
        }

        $header = array_map(fn ($h) => Str::snake(trim(strtolower((string) $h))), $header);

        $missing = array_diff(self::HEADERS, $header);

        if ($missing !== []) {
            fclose($fh);

            return ['rows' => null, 'problems' => [
                'Missing column(s): ' . implode(', ', $missing),
                'Expected: ' . implode(',', self::HEADERS),
            ]];
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
                $cell = ltrim(trim((string) ($line[$i] ?? '')), "'");

                // ONE bad cell refuses the WHOLE file, like a missing column and
                // unlike a bad row. The encoding is a property of the export, not
                // of the line: if "Zoë" on line 12 is codepage bytes then so is
                // every other accented name in the file, and refusing them one by
                // one would send the office hunting rows instead of re-saving the
                // spreadsheet once.
                if (! mb_check_encoding($cell, 'UTF-8')) {
                    fclose($fh);

                    return ['rows' => null, 'problems' => [self::NOT_UTF8]];
                }

                $row[$name] = $cell;
            }

            $rows[] = $row;
        }

        fclose($fh);

        return ['rows' => $rows, 'problems' => []];
    }

    // -------------------------------------------------------------- planning

    /**
     * Work out what WOULD happen, touching nothing.
     *
     * The plan is what the dry run prints, what the preview screen shows, and
     * what the write then applies, so neither preview can drift from the write:
     * they are the same computation. That argument used to carry one caller and
     * now carries two, which makes it more load-bearing rather than less — do
     * not add a shortcut for either path.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{students: array, guardians: array, edges: array, refused: array}
     */
    public function plan(array $rows): array
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
                'lines' => [],
            ];
            $guardians[$gEmail]['lines'][] = $line;

            $edges[] = ['student' => $sKey, 'guardian' => $gEmail, 'class' => $class, 'line' => $line];
        }

        // WHO IS ALREADY ON RECORD, per row rather than as a total.
        //
        // Three numbers were enough for a console table. They are not enough for
        // an office deciding whether to press Import: "57 will be created" hides
        // the one child who is about to be created a second time under a
        // slightly different spelling, and that duplicate is the failure mode a
        // bulk roster import actually has.
        foreach ($students as $key => $student) {
            $students[$key]['existing'] = $this->findStudent($student) !== null;
        }

        // Guardians match on a lower-cased email across the tenant's WHOLE
        // contact table, not only its school rosters — a parent who already
        // gives, orders lunch or attends events is one person, and
        // .claude/rules/groups.md is explicit that groups reference people and
        // never duplicate them. That is correct and it is also surprising, so
        // the preview says out loud how many of these guardians the office
        // already has on record somewhere else.
        //
        // NORMALISED ON BOTH SIDES OF THE COMPARISON, and that is not tidiness.
        // `$guardians` is keyed on a lower-cased address; the database matched
        // `whereIn` under MySQL's utf8mb4_*_ci collation, which is
        // case-INSENSITIVE, so a contact stored as `Musa.Salim@Gmail.com` comes
        // back spelled the way it was typed. Comparing that to the lower-cased
        // key with a strict `in_array` said "not on record" for a parent
        // `apply()` then matched — the preview promising twelve new parents and
        // the commit reporting eleven, which is precisely the preview/commit
        // divergence this screen exists to make impossible. SQLite's BINARY
        // collation hides it (neither side matches), so CI could never see it.
        // …AND THE COMPARISON HAS TO BE LOWERED IN THE QUERY, not only in PHP.
        // Lowering the plucked rows fixes MySQL, where the collation matched the
        // row in the first place. It fixes nothing on SQLite, whose `=` and `IN`
        // are BINARY: `Musa.Salim@Gmail.com` is simply not returned, so there is
        // no row left to lower and the preview says "will be created" for a
        // parent `apply()` — which matches with LOWER(email) — then links. The
        // two halves have to ask the database the same question.
        $addresses = array_keys($guardians);

        $onRecord = $addresses === [] ? [] : Contact::query()
            ->whereRaw(
                'LOWER(email) IN (' . implode(',', array_fill(0, count($addresses), '?')) . ')',
                $addresses
            )
            ->pluck('email')
            ->map(fn ($e) => Str::lower((string) $e))
            ->all();

        foreach ($guardians as $email => $guardian) {
            $guardians[$email]['existing'] = in_array($email, $onRecord, true);
        }

        return compact('students', 'guardians', 'edges', 'refused');
    }

    // -------------------------------------------------------------- applying

    /**
     * Write the plan. ALL OR NOTHING, in one transaction.
     *
     * A roster that imported forty of sixty children is worse than one that
     * imported none, because only the second is obviously unfinished. The
     * caller refuses to reach this method at all while `$plan['refused']` is
     * non-empty; the transaction covers everything after that.
     *
     * `$actor` is the staff member on whose authority the file was supplied.
     * From HTTP that is the signed-in admin, which is strictly better evidence
     * than the CLI's `--actor` flag: the console has no session, so the flag is
     * a claim, and `Auth::user()` is a fact.
     *
     * @return array{created: array{students: int, guardians: int, memberships: int, edges: int}, matched: array{students: int, guardians: int}}
     */
    public function apply(array $plan, string $batch, ?User $actor): array
    {
        $counts = [
            'created' => ['students' => 0, 'guardians' => 0, 'memberships' => 0, 'edges' => 0],
            'matched' => ['students' => 0, 'guardians' => 0],
        ];

        DB::transaction(function () use ($plan, $batch, $actor, &$counts) {
            $studentContacts = [];

            foreach ($plan['students'] as $key => $s) {
                $existing = $this->findStudent($s);

                if ($existing) {
                    $counts['matched']['students']++;
                } else {
                    $counts['created']['students']++;
                }

                $contact = $existing ?? Contact::create([
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

                if (! $membership->exists) {
                    $counts['created']['memberships']++;
                }

                $membership->fill([
                    'masjid_id' => $s['class']->masjid_id,
                    'grade_label' => $s['grade'],
                ])->confirmedByStaff($actor)->save();
            }

            $guardianContacts = [];

            foreach ($plan['guardians'] as $email => $g) {
                // LOWER() on the column, matching `plan()`'s normalisation and
                // `findStudent()`'s. A bare `where('email', $email)` is
                // case-insensitive on MySQL and case-SENSITIVE on SQLite, so the
                // two engines disagree about whether this parent already exists
                // — and the engine the tests run on is the one that disagrees
                // with production. Single quotes in the raw fragment for the
                // reason `findStudent()` gives.
                $existing = Contact::whereRaw('LOWER(email) = ?', [$email])->first();

                if ($existing) {
                    $counts['matched']['guardians']++;
                } else {
                    $counts['created']['guardians']++;
                }

                $guardianContacts[$email] = $existing ?? Contact::create([
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

                if (! $edge->exists) {
                    $counts['created']['edges']++;
                }

                // consent_granted_at and consent_scope are deliberately untouched:
                // a spreadsheet cannot perform a parent's act.
                $edge->fill(['masjid_id' => $e['class']->masjid_id])
                    ->confirmedByStaff($actor)
                    ->save();
            }
        });

        return $counts;
    }

    /** An existing child: same class, same name. */
    public function findStudent(array $s): ?Contact
    {
        $ids = GroupMembership::where('group_id', $s['class']->id)
            ->whereIn('role', GroupMembership::PARTICIPANT_ROLES)
            ->pluck('contact_id');

        // SINGLE quotes around the empty string, not double. Double quotes are a
        // string literal here only by two separate accidents — MySQL's sql_mode
        // carries no ANSI_QUOTES, and SQLite falls back to treating an unmatched
        // double-quoted identifier as a literal. A sql_mode change on a server
        // nobody connected to a roster import would turn this into "no column
        // named ''", and the failure would look like a corrupt CSV.
        return Contact::whereKey($ids)
            ->whereRaw('LOWER(first_name) = ?', [Str::lower($s['first'])])
            ->whereRaw("LOWER(COALESCE(last_name, '')) = ?", [Str::lower($s['last'])])
            ->first();
    }

    // -------------------------------------------------------------- rollback

    /**
     * Undo an import by its batch tag. CONSOLE ONLY.
     *
     * ---------------------------------------------------------------------------
     * THERE IS NO UNDO BUTTON, AND THAT IS THE DESIGN
     * ---------------------------------------------------------------------------
     *
     * `schools:import-roster --rollback=<tag>` has existed since R7 and is what
     * this method serves. The upload screen deliberately has NO caller here: the
     * preview is the safety mechanism, and a one-click undo over children's rows
     * is a bigger hazard than the mistake it reverses — an office can press it in
     * October against a tag that is still on screen, by which time attendance,
     * marks and report cards hang off the rows it would take.
     *
     * ---------------------------------------------------------------------------
     * ACADEMIC RECORDS ARE NOT REMOVABLE BY AN UNDO EITHER
     * ---------------------------------------------------------------------------
     *
     * Six tables hang off `group_memberships.id`. Migration
     * 2026_09_09_040000 made those foreign keys RESTRICT so that no verb can
     * cascade them away — but it RETURNS EARLY ON SQLITE, so on the CI engine the
     * original `cascadeOnDelete` is still in force and a mass delete here would
     * quietly destroy a term of a child's history while every test stayed green.
     * On production MySQL the same statement raises a bare 1451 that rolls the
     * whole undo back and names nobody.
     *
     * So the rows are counted first, exactly as `GroupMembershipsController::
     * destroy()` counts them, and a batch holding any history is REFUSED IN FULL
     * with a sentence per child. Nothing is deleted on a refusal: a partial undo
     * over a roster is the same hazard as a partial import.
     *
     * ---------------------------------------------------------------------------
     * INCOMPLETE BY CONSTRUCTION, AND THE CALLER MUST SAY SO
     * ---------------------------------------------------------------------------
     *
     * Only contacts this import CREATED carry the tag, so a roster row or a
     * guardian edge written against somebody already on record — a child who was
     * already enrolled, a parent who was already in the CRM as a donor — has
     * nothing to identify it as this import's work and survives the undo.
     *
     * That is the right trade. The alternative is tagging edges too and deleting
     * relationships the office may have since confirmed by hand; deleting a
     * guardian edge that a person has stood behind is not something a bulk undo
     * should be able to do quietly.
     *
     * `Contact` is `SoftDeletes`, so `contacts_removed` counts people WITHDRAWN
     * AND ARCHIVED, not erased — their names, emails and phone numbers stay in
     * `contacts` with `deleted_at` set. Callers must not describe this as
     * deletion; a data-deletion request is a different act with a different
     * screen.
     *
     * @return array{contacts_removed: int, roster_rows_removed: int, refused: array<int, string>}
     */
    public function rollback(string $batch): array
    {
        $contacts = Contact::where('import_batch', $batch)->get();

        // Every roster row this undo would take: the imported person's own
        // memberships, and the guardian edges pointing AT them. Collected as
        // MODELS and de-duplicated by id, because a mass `->delete()` query skips
        // the `deleting` hook GroupMembership registers to clear the guardian
        // edges of a departing child, and because each row has to be asked what
        // it is holding before any of them goes.
        $rows = collect();

        foreach ($contacts as $c) {
            $rows = $rows
                ->merge(GroupMembership::where('contact_id', $c->id)->get())
                ->merge(GroupMembership::where('guardian_of_contact_id', $c->id)->get());
        }

        $rows = $rows->unique('id')->values();

        $refused = [];

        foreach ($rows as $row) {
            $held = AcademicRecordsHeld::counts($row);

            if (! AcademicRecordsHeld::any($held)) {
                continue;
            }

            // NAMED, not counted. "3 rows are blocked" tells an operator nothing
            // they can act on; "Bilal Khan has 42 register marks, 2 report cards"
            // tells them which child's history is in the way and lets them decide
            // whether the import was really the mistake.
            $contact = $row->contact;
            $who = $contact
                ? trim($contact->first_name . ' ' . $contact->last_name)
                : "roster row {$row->id}";

            $refused[] = "{$who} has school records from this class ("
                . AcademicRecordsHeld::describe($held)
                . '), so this batch cannot be undone — removing the row would delete them. '
                . 'Take the wrongly-imported people off the roster by hand instead.';
        }

        if ($refused !== []) {
            return ['contacts_removed' => 0, 'roster_rows_removed' => 0, 'refused' => $refused];
        }

        // Memberships go first and explicitly. A contact delete would take them
        // anyway, but doing it here means the count reported is the count that
        // happened rather than a cascade nobody watched.
        DB::transaction(function () use ($contacts, $rows) {
            foreach ($rows as $row) {
                // Through the MODEL, one row at a time, so the `deleting` hook
                // that clears a departing child's guardian edges fires — a mass
                // `->delete()` query fires no model events and leaves guardianship
                // over somebody who is no longer in the group.
                //
                // A row this hook already took is gone by the time the loop
                // reaches it, and deleting a missing model is a no-op.
                if (GroupMembership::whereKey($row->id)->exists()) {
                    $row->delete();
                }
            }

            foreach ($contacts as $c) {
                $c->delete();
            }
        });

        // COUNTED AFTERWARDS, BY ASKING. The guardian edge pointing at a deleted
        // child is removed by the cascade rather than by the loop, and the
        // previous version of this method threw that delete's return value away
        // — so an undo that took three roster rows told the office it had taken
        // two. Counting what is actually gone is the only version of this number
        // that cannot drift from the damage.
        $removed = $rows
            ->reject(fn (GroupMembership $row) => GroupMembership::whereKey($row->id)->exists())
            ->count();

        return [
            'contacts_removed' => $contacts->count(),
            'roster_rows_removed' => $removed,
            'refused' => [],
        ];
    }
}
