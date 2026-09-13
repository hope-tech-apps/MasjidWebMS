<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupSet;
use App\Support\Backup\HalfIntegrity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Prove that the newest backup set can actually be restored — by restoring it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY: A RESTORE NOBODY HAS PRACTISED IS NOT A BACKUP
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * `bin/backup` has carried this sentence since the tooling was written: THE
 * DATABASE HALF OF A RESTORE HAS NEVER BEEN EXECUTED. Nothing in this repository
 * had ever applied a dump to a real MySQL server. The file half was executed in
 * the test suite, every refusal was executed, and the dump was proven to reach a
 * real child process on stdin byte for byte — but the client's own behaviour was
 * taken from its manual. That is a verified ARCHIVE, which is not a proven
 * RESTORE, and the difference is only discovered on the day it matters.
 *
 * This command closes it, and it is designed to be safe to run on the production
 * host, because that is the only place the real sets live.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY NOT STAGING — the obvious answer, and it is wrong
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Staging is deliberately scrubbed of real people (App\Console\Commands\StagingScrub),
 * which is the entire reason it can be handed around for testing. Restoring a
 * production set onto it would put real families' contact details, real donation
 * records and real children's school records on the least-guarded box on the
 * estate — trading a backup problem for a privacy incident, and quietly undoing
 * the scrub that makes staging safe to exist.
 *
 * WHY NOT A LOCAL DATABASE SERVER EITHER. The droplet carries the mysql CLIENT
 * and no server: /usr/sbin/mysqld and /usr/sbin/mariadbd do not exist and both
 * units read `inactive`. Installing one to prove production is safe is changing
 * production to check production.
 *
 * So: a SCRATCH SCHEMA on the same managed database server, created by this
 * command and dropped by it. The bytes never leave the estate they already live
 * in, no new host learns anything, and the thing being exercised is the real
 * client against the real server with the real dump.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE SAFETY ARGUMENT — five independent reasons this cannot touch live data
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * This command creates and drops databases on the production server. That is the
 * most dangerous thing in this repository, so each guard is listed with the code
 * that enforces it, and each one is sufficient on its own.
 *
 *  1. THERE IS NO WAY TO NAME A TARGET. The signature has no `--database`. The
 *     scratch name is GENERATED — `backup.drill.scratch_prefix` plus a UTC
 *     timestamp plus six random hex characters — so no argument, environment
 *     variable or typo can aim this at anything. See scratchName().
 *
 *  2. THREE ASSERTIONS BEFORE EVERY SINGLE STATEMENT, re-run immediately before
 *     the DROP rather than trusted from earlier: the scratch name must start
 *     with the prefix, must not equal the live database name, and the live
 *     database name must NOT start with the prefix. That last one is the
 *     interesting one — an installation whose live schema were called
 *     `manara_drill_prod` would make guard 2's DROP indistinguishable from
 *     dropping production, so that configuration REFUSES to drill at all rather
 *     than proceeding carefully. See guard().
 *
 *  3. THE DUMP IS READ BEFORE IT IS FED. A mysqldump taken with `--databases`
 *     contains `USE \`live\`;` and `CREATE DATABASE`, and a dump like that
 *     applied to a scratch schema would silently overwrite PRODUCTION, because
 *     the USE moves the client. `backup:run` never writes one — its options
 *     carry neither flag — but the drill does not rely on the dump having come
 *     from there. The decompressed dump is scanned for any statement that names
 *     a database, and one hit refuses the run before a byte reaches the client.
 *     See refuseIfTheDumpNamesADatabase().
 *
 *  4. EVERY INVOCATION CARRIES `--database=<scratch>` EXPLICITLY. Nothing relies
 *     on the client's default schema, which comes from the credentials file and
 *     is the live one.
 *
 *  5. THE MEDIA HALF NEVER GOES NEAR THE MEDIA DISK. `backup:restore` unpacks
 *     into the disk the application reads from; the drill unpacks into a 0700
 *     temporary directory and cross-checks the restored ROWS against the files
 *     in THAT tree. Nothing is written under storage/, and the live media disk
 *     is not opened. This is why the drill does not call `backup:restore`: that
 *     command's entire job is to overwrite production, and a `--pretend` mode
 *     bolted onto it would be one forgotten branch away from doing so. What IS
 *     reused is the part worth reusing — BackupSet::problems() and HalfIntegrity,
 *     the shared definition of a valid set.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AND NO SCRATCH DATABASE IS LEFT LYING AROUND
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * A drill that leaks schemas fills the managed instance with copies of the
 * production database — every one of them a full, unguarded replica of
 * children's records — which is a worse outcome than never drilling.
 *
 *   - `finally` drops the schema and removes the temporary tree on every exit
 *     path, success and failure alike;
 *   - a shutdown function registered before the first statement covers a fatal
 *     error, which `finally` does not;
 *   - and because neither of those survives `kill -9` or a power loss, EVERY RUN
 *     BEGINS BY SWEEPING: it lists the schemas matching the drill prefix and
 *     drops them before it starts. A leaked schema therefore lives until the
 *     next weekly drill at the outside, and never silently forever.
 *
 * The honest limit: between a SIGKILL and the next scheduled drill, one scratch
 * schema can exist. It is named `<prefix><timestamp>_<hex>`, it is trivially
 * greppable, and it is the residual risk of doing this at all rather than
 * something hidden.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EXIT CODES — the vocabulary tenancy:canary and media:verify already use
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   0  pass     info      the set restored, and what came back matches what its
 *                         manifest said it captured
 *   1  failed   error     THE BACKUP IS NOT A BACKUP: the set did not restore,
 *                         or it restored and the rows or the files are not what
 *                         it claimed                                       PAGE
 *   2  blocked  error     the drill could not run and therefore proves nothing:
 *                         no set, no privilege to create a scratch schema, a
 *                         dump that names a database, an unsafe configuration.
 *                         The restore stays UNPROVEN                       PAGE
 *   3  partial  warning   it ran and could not check everything — the row
 *                         budget was reached, or the manifest predates a field
 *                         it wanted to compare against                   TICKET
 */
class BackupDrill extends Command
{
    protected $signature = 'backup:drill
                            {--set= : Set id, or an absolute path. Default: the newest set in the destination}
                            {--keep-scratch : Leave the scratch schema in place for inspection. For a human debugging a failed drill, never for the scheduler}
                            {--json : Emit the result as JSON on stdout}';

    protected $description = 'Prove the newest backup set restores, into a scratch schema that is created and dropped.';

    private const EXIT_PASS = 0;

    private const EXIT_FAILED = 1;

    private const EXIT_BLOCKED = 2;

    private const EXIT_PARTIAL = 3;

    /** @var list<string> */
    private array $degradedBy = [];

    private ?string $scratch = null;

    private ?string $credentials = null;

    private ?string $workspace = null;

    public function handle(): int
    {
        $config = (array) config('backup');
        $destination = rtrim((string) ($config['destination'] ?? ''), '/');
        $prefix = (string) ($config['drill']['scratch_prefix'] ?? 'manara_drill_');

        $connection = $config['database']['connection'] ?? null;
        $connection = ($connection === null || $connection === '') ? (string) config('database.default') : (string) $connection;
        $db = (array) config("database.connections.{$connection}");
        $live = (string) ($db['database'] ?? '');

        if (($db['driver'] ?? null) !== 'mysql' && ($db['driver'] ?? null) !== 'mariadb') {
            return $this->blocked(sprintf(
                'Connection [%s] uses driver [%s]; the drill restores mysql/mariadb only, which is what `backup:run` dumps.',
                $connection,
                $db['driver'] ?? 'null',
            ));
        }

        // GUARD 2, part one: the configuration itself has to be safe before any
        // name is generated from it.
        if (preg_match('/^[a-z0-9_]{3,32}$/', $prefix) !== 1) {
            return $this->blocked(sprintf(
                'backup.drill.scratch_prefix is [%s]. It must be 3-32 characters of a-z, 0-9 and underscore: this string '
                .'is the ONLY thing separating a schema the drill may drop from one it may not, and a prefix that could '
                .'be quoted or globbed is not a separation.',
                $prefix,
            ));
        }

        if ($live === '') {
            return $this->blocked(sprintf('Connection [%s] names no database, so the drill cannot tell a scratch schema from the live one.', $connection));
        }

        if (str_starts_with($live, $prefix)) {
            return $this->blocked(sprintf(
                'The LIVE database is called [%s] and backup.drill.scratch_prefix is [%s]. The drill drops every schema '
                .'beginning with that prefix, so on this installation its cleanup could not be told apart from dropping '
                .'production. Refusing to drill. Change BACKUP_DRILL_SCRATCH_PREFIX to something the live database does '
                .'not begin with.',
                $live,
                $prefix,
            ));
        }

        $set = $this->resolveSet($destination);

        if ($set === null) {
            return $this->blocked(sprintf(
                'There is no finished backup set in %s to drill. An unproven restore and no backup at all are different '
                .'problems and this is the second one.',
                $destination,
            ));
        }

        // The set has to be sound before it is worth restoring. Same functions
        // `backup:run` and `backup:check` use — there is one definition of a
        // valid set in this codebase and this is not a second one.
        $problems = $set->problems();

        foreach ([
            HalfIntegrity::database($set->databasePath(), $config),
            HalfIntegrity::mediaAgainstManifest($set->mediaPath(), (array) $set->manifest()),
        ] as $problem) {
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        if ($problems !== []) {
            return $this->failed(sprintf('Set %s does not verify, so there is nothing here to restore.', $set->id()), $problems, ['set' => $set->id()]);
        }

        $manifest = (array) $set->manifest();

        $this->line(sprintf('Drilling %s (taken %s).', $set->id(), $manifest['created_at'] ?? '?'));

        try {
            $this->workspace = $this->makeWorkspace();
        } catch (Throwable $e) {
            return $this->blocked('The drill could not create a private working directory: '.$e->getMessage());
        }

        // The try opens HERE, before the credentials file is written, so that
        // every return below — including the safety refusals — still runs the
        // cleanup. An early `return` past a 0600 file holding the production
        // database password is the kind of leak that is invisible until
        // somebody lists /tmp.
        try {
            $this->credentials = $this->writeCredentialsFile($this->workspace, $db);

            // EVERY RUN SWEEPS FIRST. `finally` and the shutdown hook below
            // cannot survive a SIGKILL or a power loss, so the guarantee that no
            // scratch schema outlives a drill is carried by the NEXT drill
            // rather than only by this one.
            $swept = $this->sweepLeftovers($config, $prefix, $live);

            $this->scratch = $this->scratchName($prefix);

            if (($guard = $this->guard($this->scratch, $live, $prefix)) !== null) {
                $this->scratch = null;

                return $this->blocked($guard);
            }

            // Covers a fatal error, which `finally` does not. Registered before
            // the first CREATE and deliberately not after it.
            register_shutdown_function(function () use ($live, $prefix): void {
                if ($this->scratch !== null && ! $this->option('keep-scratch')) {
                    $this->dropScratch($this->scratch, $live, $prefix);
                }
            });

            return $this->drill($set, $manifest, $config, $live, $prefix, $swept);
        } catch (Throwable $e) {
            return $this->failed('The drill threw, so the restore is not proven.', [$e->getMessage()], ['set' => $set->id()]);
        } finally {
            if ($this->scratch !== null && ! $this->option('keep-scratch')) {
                $this->dropScratch($this->scratch, $live, $prefix);
                $this->scratch = null;
            }

            if ($this->option('keep-scratch') && $this->scratch !== null) {
                $this->warn(sprintf('--keep-scratch: schema `%s` is still on the server. Drop it by hand.', $this->scratch));
            }

            if ($this->credentials !== null) {
                @unlink($this->credentials);
                $this->credentials = null;
            }

            if ($this->workspace !== null) {
                $this->deleteTree($this->workspace);
                $this->workspace = null;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $config
     * @param  list<string>  $swept
     */
    private function drill(BackupSet $set, array $manifest, array $config, string $live, string $prefix, array $swept): int
    {
        $scratch = (string) $this->scratch;
        $timeout = (int) ($config['process_timeout'] ?? 1800);

        // 1. Decompress the dump. Separate process with its own exit code, never
        //    a pipe: `mysqldump | gzip` reports GZIP's status and that is how a
        //    20-byte backup came to be written and reported as fine.
        $dump = $this->workspace.'/database.sql';
        copy($set->databasePath(), $dump.'.gz');

        $gunzip = Process::timeout($timeout)->run([$config['database']['gzip_binary'] ?? 'gzip', '-d', $dump.'.gz']);

        if (! $gunzip->successful() || ! is_file($dump)) {
            return $this->failed('The database half could not be decompressed, so this set cannot be restored.', [
                trim($gunzip->errorOutput()) ?: sprintf('gzip exited %d.', $gunzip->exitCode()),
            ], ['set' => $set->id()]);
        }

        // 2. GUARD 3. Read the dump before feeding it to a client that would
        //    happily follow a `USE` into the live schema.
        if (($named = $this->refuseIfTheDumpNamesADatabase($dump)) !== null) {
            return $this->blocked($named);
        }

        // 3. Create the scratch schema. Re-guarded immediately before the
        //    statement rather than trusting the check from a moment ago.
        if (($guard = $this->guard($scratch, $live, $prefix)) !== null) {
            return $this->blocked($guard);
        }

        $create = $this->mysql(['-e', sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $scratch)], $timeout);

        if (! $create->successful()) {
            return $this->blocked(sprintf(
                'The drill could not create the scratch schema `%s`, so the restore stays unproven. The backup user needs '
                .'CREATE and DROP on `%s%%`: GRANT ALL PRIVILEGES ON `%s\\_%%`.* TO the backup user. Server said: %s',
                $scratch,
                $prefix,
                rtrim($prefix, '_'),
                trim($create->errorOutput()) ?: 'nothing',
            ));
        }

        // 4. THE RESTORE. On STDIN, so the client runs in batch mode where the
        //    FIRST error aborts and the exit status is real. `--execute='source
        //    dump.sql'` runs the client's own SOURCE, which prints a rejected
        //    statement, carries on, and exits 0 — a restore that died on
        //    statement 812 of 4000 reporting success. That bug is why this
        //    command exists at all, and reproducing it here would make the drill
        //    a machine for certifying broken backups.
        $stream = fopen($dump, 'rb');

        if ($stream === false) {
            return $this->blocked(sprintf('The decompressed dump at %s could not be read.', $dump));
        }

        try {
            $restore = Process::timeout($timeout)->input($stream)->run($this->mysqlCommand([
                '--database='.$scratch,
                '--default-character-set=utf8mb4',
            ]));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $restore->successful()) {
            return $this->failed(
                sprintf('Set %s DID NOT RESTORE. This set is not a backup.', $set->id()),
                [
                    trim($restore->errorOutput()) ?: sprintf('mysql exited %d.', $restore->exitCode()),
                    'A dump whose routines or triggers carry a DEFINER the restoring user cannot assume fails here without the backup being at fault; look at the error before concluding the set is bad.',
                ],
                ['set' => $set->id()],
            );
        }

        // 5. What came back. A client that exited 0 is not evidence that the
        //    data is there — the whole family of bugs this repository keeps
        //    finding is a surface announcing success about something it never
        //    read back.
        $tables = $this->tablesIn($scratch, $timeout);
        $minimumTables = (int) ($config['drill']['minimum_tables'] ?? 20);

        if ($tables === null) {
            return $this->failed('The restore reported success and the scratch schema could not then be listed.', [], ['set' => $set->id()]);
        }

        if (count($tables) < $minimumTables) {
            return $this->failed(sprintf(
                'Set %s restored into %d table(s), under the %d-table floor. The client exited 0 and almost nothing arrived.',
                $set->id(),
                count($tables),
                $minimumTables,
            ), [], ['set' => $set->id(), 'tables' => count($tables)]);
        }

        $result = [
            'set' => $set->id(),
            'scratch' => $scratch,
            'swept_before_start' => $swept,
            'tables' => count($tables),
        ];

        // 6. THE PAIRING, WHICH IS THE POINT. A database-only restore is the
        //    outage; the drill is only meaningful if it checks that the rows
        //    that came back have the files that came back.
        $media = $this->crossCheckMedia($set, $manifest, $config, $scratch, $tables, $timeout);
        $result += $media;

        if (($media['media_verdict'] ?? '') === 'failed') {
            return $this->failed(sprintf('Set %s restored and its media half does not match what it restored.', $set->id()), $media['media_problems'], $result);
        }

        return $this->pass($result);
    }

    /**
     * Do the rows that just came back have their files in the archive beside
     * them?
     *
     * This is BackupRun's `media_integrity` recomputed from the SET ALONE —
     * restored rows against unpacked files — rather than from the live system.
     * That is the difference between "the backup recorded a consistent estate at
     * 02:40" and "this set, on its own, restores a consistent estate", and only
     * the second is a proven restore.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $config
     * @param  list<string>  $tables
     * @return array<string, mixed>
     */
    private function crossCheckMedia(BackupSet $set, array $manifest, array $config, string $scratch, array $tables, int $timeout): array
    {
        $problems = [];

        $root = $this->workspace.'/media';

        if (! @mkdir($root, 0700, true)) {
            $this->degradedBy[] = 'media_workspace_unavailable';

            return ['media_verdict' => 'not_checked', 'media_problems' => [], 'media_reason' => 'could not create a private directory to unpack into'];
        }

        // Unpacked into a 0700 directory under the system temporary path, never
        // into the media disk the application serves from. See guard 5.
        $untar = Process::timeout($timeout)->run([
            $config['media']['tar_binary'] ?? 'tar', '-xzf', $set->mediaPath(), '-C', $root,
        ]);

        if (! $untar->successful()) {
            return [
                'media_verdict' => 'failed',
                'media_problems' => ['The media half did not unpack: '.(trim($untar->errorOutput()) ?: sprintf('tar exited %d.', $untar->exitCode()))],
            ];
        }

        if (! in_array('media', $tables, true)) {
            // Not a failure. An installation with no media table has no pairing
            // to check, and BackupRun records `not_checked` in exactly this case.
            $this->degradedBy[] = 'no_media_table_in_set';

            return ['media_verdict' => 'not_checked', 'media_problems' => [], 'media_reason' => 'the restored schema has no media table'];
        }

        $recorded = (array) ($manifest['media_integrity'] ?? []);
        $maxRows = (int) ($config['drill']['max_rows'] ?? 25000);

        $rows = $this->query($scratch, sprintf('SELECT id, file_name FROM media ORDER BY id LIMIT %d', $maxRows + 1), $timeout);

        if ($rows === null) {
            return ['media_verdict' => 'failed', 'media_problems' => ['The restored media table could not be read back.']];
        }

        $truncated = count($rows) > $maxRows;

        if ($truncated) {
            $rows = array_slice($rows, 0, $maxRows);
            $this->degradedBy[] = 'truncated_row_budget';
        }

        $withFile = 0;
        $missing = [];

        foreach ($rows as $row) {
            [$id, $fileName] = array_pad(explode("\t", $row, 2), 2, '');

            // The archive was tarred from the media root WITH the disk prefix
            // already applied, so paths inside it are `<id>/<file>` and
            // MediaTarget::relativePathFor's prefix must NOT be added again here.
            if (is_file($root.'/'.$id.'/'.$fileName)) {
                $withFile++;
            } elseif (count($missing) < 20) {
                $missing[] = $id;
            }
        }

        $restoredRows = count($rows);
        $result = [
            'media_rows_restored' => $restoredRows,
            'media_rows_with_file' => $withFile,
            'media_rows_truncated' => $truncated,
            'media_problems' => [],
        ];

        // Against the manifest, when the manifest recorded it. A set written
        // before the field existed makes the comparison inert rather than making
        // it guess — the same rule the retention guard follows.
        if (($recorded['status'] ?? null) === 'checked' && ! $truncated) {
            $expectedRows = (int) ($recorded['rows'] ?? 0);
            $expectedWith = (int) ($recorded['rows_with_file'] ?? 0);

            if ($restoredRows !== $expectedRows) {
                $problems[] = sprintf(
                    'The manifest says this set captured %d media row(s); restoring it produced %d. The dump does not '
                    .'contain what the set claims to contain.',
                    $expectedRows,
                    $restoredRows,
                );
            }

            if ($withFile < $expectedWith) {
                $problems[] = sprintf(
                    'The manifest says %d of those rows had their file; restoring both halves side by side, only %d do. '
                    .'The archive is not the file half of the dump it is bound to — which is the 2026-08-17 outage in a box. '
                    .'First rows with no file: %s',
                    $expectedWith,
                    $withFile,
                    implode(', ', $missing) ?: 'none recorded',
                );
            }
        } else {
            $this->degradedBy[] = $truncated ? 'truncated_row_budget' : 'manifest_records_no_media_integrity';
        }

        $result['media_problems'] = $problems;
        $result['media_verdict'] = $problems === [] ? 'pass' : 'failed';

        return $result;
    }

    /**
     * THE THREE ASSERTIONS. Returns null when it is safe to name this schema in
     * a statement, and a refusal otherwise.
     *
     * Called before the CREATE and again before the DROP, rather than once at
     * the top. A guard checked at the start of a long method and trusted at the
     * end is a guard that survives exactly until somebody adds a line between
     * them.
     */
    private function guard(string $scratch, string $live, string $prefix): ?string
    {
        if (preg_match('/^[a-z0-9_]{1,48}$/', $scratch) !== 1) {
            return sprintf('Refusing to use [%s] as a scratch schema name: it is not plain a-z, 0-9 and underscore.', $scratch);
        }

        if (! str_starts_with($scratch, $prefix)) {
            return sprintf('Refusing to use [%s] as a scratch schema name: it does not begin with the drill prefix [%s].', $scratch, $prefix);
        }

        if ($scratch === $live) {
            return sprintf('Refusing: the scratch schema name [%s] is the LIVE database.', $scratch);
        }

        if (str_starts_with($live, $prefix)) {
            return sprintf('Refusing: the live database [%s] begins with the drill prefix [%s].', $live, $prefix);
        }

        return null;
    }

    /**
     * A dump that carries `USE` or `CREATE DATABASE` moves the client to a
     * schema of the dump's choosing, and the schema it would choose is the live
     * one it came from. Fed to `mysql --database=scratch`, the `--database` is
     * simply overridden and PRODUCTION IS OVERWRITTEN by a command whose whole
     * purpose is to be safe.
     *
     * `backup:run` never produces such a dump — neither `--databases` nor
     * `--all-databases` is in its option list — but a set on disk may have come
     * from anywhere, and this guard costs one pass over a file that is about to
     * be read in full anyway. Comment lines cannot match: mysqldump writes
     * `-- Current Database:` and `/*!40000 …`, and the pattern is anchored to
     * the start of a statement.
     */
    private function refuseIfTheDumpNamesADatabase(string $dump): ?string
    {
        $handle = @fopen($dump, 'rb');

        if ($handle === false) {
            return sprintf('The decompressed dump at %s could not be opened to check what it names.', $dump);
        }

        $line = 0;

        try {
            while (($text = fgets($handle)) !== false) {
                $line++;

                if (preg_match('/^\s*(USE\s|CREATE\s+(DATABASE|SCHEMA)|ALTER\s+(DATABASE|SCHEMA)|DROP\s+(DATABASE|SCHEMA))/i', $text) === 1) {
                    return sprintf(
                        'Line %d of this dump names a database: %s. A dump taken with --databases carries `USE `, which '
                        .'moves the client out of the scratch schema and into the live one — so restoring it here would '
                        .'overwrite PRODUCTION. Refusing before a byte reaches the client. `backup:run` never writes a '
                        .'dump like this; whatever produced this set did.',
                        $line,
                        trim(substr($text, 0, 120)),
                    );
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /**
     * Drop every schema left behind by an earlier drill that did not finish.
     *
     * The list comes from the server, and every name off it is passed through
     * the same three assertions as the one this run creates: a `SHOW DATABASES
     * LIKE` result is input, and input that is about to be interpolated into a
     * DROP is not something to trust because of where it came from.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function sweepLeftovers(array $config, string $prefix, string $live): array
    {
        $timeout = (int) ($config['process_timeout'] ?? 1800);

        // `_` is a single-character wildcard in LIKE, so a prefix containing one
        // would match more than it should. Escaped for that reason and not for
        // quoting.
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix).'%';

        $found = $this->mysql(['--batch', '--skip-column-names', '-e', sprintf("SHOW DATABASES LIKE '%s'", $pattern)], $timeout);

        if (! $found->successful()) {
            // Not fatal. The drill can still run; it simply could not confirm
            // there was nothing left behind, and says so.
            $this->degradedBy[] = 'leftover_sweep_failed';

            return [];
        }

        $dropped = [];

        foreach (preg_split('/\R/', trim($found->output())) ?: [] as $name) {
            $name = trim((string) $name);

            if ($name === '' || $this->guard($name, $live, $prefix) !== null) {
                continue;
            }

            if ($this->dropScratch($name, $live, $prefix)) {
                $dropped[] = $name;
            }
        }

        if ($dropped !== []) {
            $this->warn(sprintf(
                'Swept %d scratch schema(s) left by an earlier drill that did not finish: %s',
                count($dropped),
                implode(', ', $dropped),
            ));
        }

        return $dropped;
    }

    /** The three assertions again, immediately before the statement that drops. */
    private function dropScratch(string $scratch, string $live, string $prefix): bool
    {
        if ($this->guard($scratch, $live, $prefix) !== null) {
            return false;
        }

        $result = $this->mysql(['-e', sprintf('DROP DATABASE IF EXISTS `%s`', $scratch)], 300);

        return $result->successful();
    }

    /** @return list<string>|null */
    private function tablesIn(string $scratch, int $timeout): ?array
    {
        $rows = $this->query($scratch, 'SHOW TABLES', $timeout);

        return $rows;
    }

    /**
     * One batch query against the scratch schema, as lines.
     *
     * The client is used rather than a Laravel connection deliberately: it is
     * the same binary, the same credentials file and the same batch semantics as
     * the restore itself, so the thing being exercised end to end is the thing
     * an operator will use at 3am.
     *
     * @return list<string>|null
     */
    private function query(string $scratch, string $sql, int $timeout): ?array
    {
        $result = $this->mysql(['--batch', '--skip-column-names', '--database='.$scratch, '-e', $sql], $timeout);

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->output());

        return $output === '' ? [] : array_values(array_filter(preg_split('/\R/', $output) ?: [], fn ($line) => $line !== ''));
    }

    private function mysql(array $arguments, int $timeout)
    {
        return Process::timeout($timeout)->run($this->mysqlCommand($arguments));
    }

    /** @return list<string> */
    private function mysqlCommand(array $arguments): array
    {
        $config = (array) config('backup');
        $connection = $config['database']['connection'] ?? null;
        $connection = ($connection === null || $connection === '') ? (string) config('database.default') : (string) $connection;
        $db = (array) config("database.connections.{$connection}");

        return array_merge(
            [$config['database']['restore_binary'] ?? 'mysql', '--defaults-extra-file='.$this->credentials],
            $this->sslArguments($db),
            $arguments,
        );
    }

    private function scratchName(string $prefix): string
    {
        return $prefix.now()->utc()->format('Ymd_His').'_'.bin2hex(random_bytes(3));
    }

    private function makeWorkspace(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'manara-drill-');
        @unlink($path);

        if (! @mkdir($path, 0700)) {
            throw new \RuntimeException(sprintf('could not create %s', $path));
        }

        return $path;
    }

    private function resolveSet(string $destination): ?BackupSet
    {
        $selector = trim((string) ($this->option('set') ?? ''));

        if ($selector === '') {
            return $destination === '' ? null : BackupSet::latest($destination);
        }

        foreach ([$selector, $destination.'/'.$selector] as $candidate) {
            if (is_dir($candidate)) {
                return BackupSet::at($candidate);
            }
        }

        return null;
    }

    /**
     * The password never appears in an argument vector — a command line is
     * readable by every process on the host. Same 0600 defaults file
     * `backup:run` and `backup:restore` use, in a 0700 directory that is deleted
     * on the way out.
     */
    private function writeCredentialsFile(string $directory, array $db): string
    {
        $path = $directory.'/.my.cnf';
        $lines = ['[client]'];

        foreach ([
            'host' => $db['host'] ?? null,
            'port' => $db['port'] ?? null,
            'user' => $db['username'] ?? null,
            'password' => $db['password'] ?? null,
            'socket' => ($db['unix_socket'] ?? '') !== '' ? $db['unix_socket'] : null,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = sprintf('%s="%s"', $key, str_replace('"', '\"', (string) $value));
            }
        }

        file_put_contents($path, implode("\n", $lines)."\n");
        @chmod($path, 0600);

        return $path;
    }

    /** @return list<string> */
    private function sslArguments(array $db): array
    {
        if (! extension_loaded('pdo_mysql') || ! defined('PDO::MYSQL_ATTR_SSL_CA')) {
            return [];
        }

        $ca = $db['options'][\PDO::MYSQL_ATTR_SSL_CA] ?? null;

        return ($ca === null || $ca === '') ? [] : ['--ssl-ca='.$ca];
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach ((array) (@scandir($path) ?: []) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->deleteTree($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }

    /** @param array<string, mixed> $payload */
    private function pass(array $payload): int
    {
        $status = $this->degradedBy === [] ? 'pass' : 'partial';

        return $this->report($status, $status === 'pass'
            ? sprintf('Set %s restored, and what came back is what its manifest said it captured.', $payload['set'])
            : sprintf('Set %s restored, and the drill could not check everything it wanted to.', $payload['set']),
            [], $payload, $status === 'pass' ? self::EXIT_PASS : self::EXIT_PARTIAL);
    }

    /** @param array<string, mixed> $payload */
    private function failed(string $headline, array $detail = [], array $payload = []): int
    {
        return $this->report('failed', $headline, $detail, $payload, self::EXIT_FAILED);
    }

    private function blocked(string $headline, array $detail = []): int
    {
        return $this->report('blocked', $headline, $detail, [], self::EXIT_BLOCKED);
    }

    /**
     * One line per run, whatever the verdict, because `schedule:run` discards
     * stdout and a drill nobody can prove ran is a drill that can stop running
     * unnoticed — which is the shape of the bug this whole slice of work is a
     * response to. The LEVEL carries the verdict so an alert rule routes without
     * parsing the body.
     *
     * @param  list<string>  $detail
     * @param  array<string, mixed>  $payload
     */
    private function report(string $status, string $headline, array $detail, array $payload, int $exit): int
    {
        $summary = ['status' => $status, 'headline' => $headline, 'degraded_by' => array_values(array_unique($this->degradedBy))] + $payload;

        if ($this->option('json')) {
            $this->line((string) json_encode($summary + ['detail' => $detail], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            if ($status === 'pass') {
                $this->info($headline);
            } else {
                $this->error($headline);
            }

            foreach ($detail as $line) {
                $this->line('  '.$line);
            }
        }

        $channel = Log::channel(config('backup.drill.log_channel'));

        match ($status) {
            'pass' => $channel->info('backup:drill pass', $summary),
            // A ticket, not a page: the restore worked and one comparison was
            // out of budget.
            'partial' => $channel->warning('backup:drill partial', $summary),
            default => $channel->error('backup:drill '.$status, $summary + ['detail' => $detail]),
        };

        return $exit;
    }
}
