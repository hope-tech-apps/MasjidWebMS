<?php

namespace App\Console\Commands;

use App\Support\Backup\ArchiveIntegrity;
use App\Support\Backup\BackupSet;
use App\Support\Backup\MediaTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Take one backup: the database AND the files its rows point at, together.
 *
 * WHY THIS EXISTS
 *
 * On 2026-08-17 a lobby television read "No announcements" while ten were live.
 * The `media` table was empty platform-wide — 226 rows gone against an
 * auto_increment of 422 — and every masjid was serving no logo. There were four
 * backups on the server. Every one of them could restore those 226 ROWS and not
 * one of them held a single FILE, because they are `mysqldump` output and
 * nothing else. The announcement posters had to be found on the operator's
 * laptop. The logos are still not back.
 *
 * The trap in that shape is worse than the gap: restoring one of those dumps
 * RE-CREATES 226 rows pointing at files that are not on this server — the exact
 * dangling state that emptied the feed. A backup that makes the problem worse
 * on restore is worse than no backup, so this command has no `--database-only`
 * and no fallback: if the file half cannot be reached, nothing is written.
 *
 * WHAT A SET IS
 *
 *     <destination>/<id>/database.sql.gz
 *     <destination>/<id>/media.tar.gz
 *     <destination>/<id>/manifest.json      <- written LAST, binds the two
 *
 * Assembled in `<id>.partial/` and renamed into place only after both halves
 * are written, verified and checksummed. A directory in the destination is
 * therefore a finished run, and `backup:restore` needs no heuristics.
 *
 * WHAT `complete: true` IS A CLAIM ABOUT, AND HOW IT IS CHECKED
 *
 * The file half being WHOLE is the one guarantee this set has that the four
 * legacy dumps did not, so it is checked three ways and each one can refuse:
 *
 *   FILES AGAINST FILES. A tar header is not a file — `tar -czf … -C root .`
 *   writes one for every directory too, GNU tar writes an `L` record for any
 *   path over 99 bytes, and a symlink is a record with no data. The media disk
 *   is 91 files in 68 DIRECTORIES, so its archive carries ~160 headers, and an
 *   archive holding a third of the files still has more headers than the disk
 *   has files. Only `ArchiveIntegrity`'s regular-file count is ever compared to
 *   a file count, and the disk walk skips symlinks so both sides are one unit.
 *
 *   EVERY DISK THE ROWS NAME. A set covers ONE disk. Every row is on `public`
 *   today, which is a fact about today's configuration and not a property of
 *   the design, so each run asks `select distinct disk from media` (and
 *   `conversions_disk`) and refuses if a row names a disk this archive does not
 *   cover — and refuses again if the rows cannot be read at all, because a
 *   claim nobody could check is not one to write down.
 *
 *   A FLOOR, AND A COMPARISON WITH THE SET BEING REPLACED. On 2026-08-17 the
 *   FILES went first. A nightly run inside that window would have dumped a
 *   healthy database, tarred an empty directory, passed every check honestly,
 *   and then pruned the sets that still held the files. So an archive under
 *   `backup.media.minimum_files` refuses outright, and one that captured
 *   markedly less than the newest existing set is still written — it is a
 *   faithful capture — but PRUNES NOTHING and exits 3.
 *
 * WHY THE DUMP IS NOT PIPED INTO GZIP
 *
 * `mysqldump … | gzip > out.gz` exits with GZIP's status. mysqldump can fail
 * outright and the shell reports success. There is a 20-byte file in
 * /root/backups that decompresses to zero bytes, dated 2026-07-28, sitting
 * beside four real dumps under a name of the same shape — that is what that
 * failure mode looks like once nobody is watching. So the dump is written with
 * `--result-file` and compressed as a separate process with its own exit code,
 * and then the result is opened and checked for the line mysqldump writes only
 * when it finished.
 *
 * EXIT CODES
 *
 *   0  a complete set was written and verified
 *   1  no usable set was written — the reason is on stderr and in the log
 *   2  a complete set was written and verified, AND the media it captured is
 *      internally inconsistent: rows whose files are missing. The BACKUP is
 *      sound; what it faithfully captured is not.
 *   3  a complete set was written and verified, AND it captured markedly fewer
 *      media files than the set it would otherwise have replaced. RETENTION
 *      WAS SKIPPED so the older, fuller sets survive — they are the only copies
 *      of the files this one no longer holds. Also sound; also not reassuring.
 *
 * The log line is the alert path for scheduled runs, because `schedule:run`
 * discards stdout — exactly one line per run, `info` / `warning` / `error`
 * carrying those states, on every run including a clean one, so a backup that
 * quietly stops happening does not look like a backup that is fine.
 *
 * WHAT THIS COMMAND DOES NOT COVER — see bin/backup for the full list.
 * Retention is a bounded count on ONE volume; there is no offsite copy; and the
 * relationship between these files and DigitalOcean's own managed-database
 * backups is written down nowhere.
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run
                            {--dry-run : Report the plan — target, commands, destination, retention, sizes — and write nothing}
                            {--label= : Name the set, e.g. --label=pre-r9. Default is a UTC timestamp}
                            {--keep= : Override backup.keep_sets for this run}
                            {--json : Emit the summary as JSON on stdout}';

    protected $description = 'Back up the database and the media disk together as one restorable set.';

    private const EXIT_OK = 0;

    private const EXIT_FAILED = 1;

    private const EXIT_INCONSISTENT_MEDIA = 2;

    private const EXIT_MEDIA_SHRANK = 3;

    public function handle(): int
    {
        $config = (array) config('backup');

        // 1. Where the files are, and whether we can reach them AT ALL. This is
        //    first on purpose: a run that cannot archive media must not dump a
        //    database, because a lone dump is the loaded gun.
        $media = MediaTarget::resolve(
            $config,
            (array) config('filesystems.disks', []),
            config('media-library.disk_name'),
            config('media-library.prefix'),
        );

        if (! $media->isSupported()) {
            return $this->refuse('The media disk cannot be archived, so no backup will be taken.', [$media->reason]);
        }

        if ($media->strategy === MediaTarget::STRATEGY_TAR && ! is_dir((string) $media->archiveRoot())) {
            return $this->refuse('The media disk cannot be archived, so no backup will be taken.', [
                sprintf('%s does not exist. Media is configured on disk [%s] whose root is that path.', $media->archiveRoot(), $media->disk),
            ]);
        }

        if ($media->strategy === MediaTarget::STRATEGY_S3_SYNC) {
            return $this->refuse('The media disk cannot be archived, so no backup will be taken.', [
                sprintf(
                    'Disk [%s] is s3 and backup.media.strategies.s3 is "%s", but no s3 sync is implemented in this build '
                    .'and there is no aws binary on this host. Install it and set BACKUP_AWS_BINARY, or move media back to a local disk. '
                    .'Refusing to write a database-only set.',
                    $media->disk,
                    MediaTarget::STRATEGY_S3_SYNC,
                ),
            ]);
        }

        // 2. The database half's target.
        $connection = $config['database']['connection'] ?? null;
        $connection = ($connection === null || $connection === '') ? (string) config('database.default') : (string) $connection;
        $db = (array) config("database.connections.{$connection}");

        if (($db['driver'] ?? null) !== 'mysql' && ($db['driver'] ?? null) !== 'mariadb') {
            return $this->refuse('The database cannot be dumped, so no backup will be taken.', [
                sprintf(
                    'Connection [%s] uses driver [%s]; this command dumps mysql/mariadb only. Set BACKUP_DB_CONNECTION to the connection that holds the application data.',
                    $connection,
                    $db['driver'] ?? 'null',
                ),
            ]);
        }

        // 3. Destination and space.
        $destination = rtrim((string) ($config['destination'] ?? ''), '/');

        if ($destination === '') {
            return $this->refuse('No backup destination is configured (backup.destination).');
        }

        // Collected rather than returned, so that --dry-run — the first thing an
        // operator runs — still prints the whole plan and then says a real run
        // would refuse, instead of stopping at the first missing directory.
        $destinationProblems = [];

        if (! is_dir($destination)) {
            $destinationProblems[] = sprintf(
                '%s is not a directory. Create it once, owned by the user the scheduler runs as: sudo bin/backup --install',
                $destination,
            );
        } elseif (! is_writable($destination)) {
            $destinationProblems[] = sprintf(
                '%s is not writable by %s. The system cron runs `schedule:run` as www-data, so a destination only root can write to is a scheduled backup that never runs. Fix with: sudo bin/backup --install',
                $destination,
                self::currentUser(),
            );
        }

        $mediaBytes = $media->strategy === MediaTarget::STRATEGY_TAR
            ? self::directoryBytes((string) $media->archiveRoot())
            : 0;
        $mediaFiles = $media->strategy === MediaTarget::STRATEGY_TAR
            ? self::directoryFileCount((string) $media->archiveRoot())
            : 0;

        // Which disks the rows actually name, versus the ONE disk this set is
        // about to hold. Read before anything is written, because the answer
        // can make the whole run a refusal: a set that covers `public` while
        // rows sit on `s3` is a set whose manifest would say `complete: true`
        // about files it never went near.
        $coverage = $this->mediaDiskCoverage($media);

        // Collected, not returned, for the same reason as the destination
        // problems below: --dry-run must print the whole plan and then say what
        // a real run would refuse, rather than stopping at the first one.
        $mediaProblems = $this->coverageProblems($coverage, $media);

        // The media-half floor. A media root that has become empty is not a
        // small backup, it is the incident: on 2026-08-17 the files were
        // already gone before the rows were deleted, and a run that faithfully
        // archives nothing would then have pruned the sets that still had them.
        $minimumFiles = (int) ($config['media']['minimum_files'] ?? 1);

        if ($minimumFiles > 0 && $mediaFiles < $minimumFiles) {
            $mediaProblems[] = sprintf(
                '%s holds %d file(s), under the %d-file floor (backup.media.minimum_files, env BACKUP_MEDIA_MINIMUM_FILES). '
                .'An archive of an empty media root is not a small backup, it is the shape of the disk having been lost — '
                .'and taking one would let retention prune the sets that still hold the files. Set the floor to 0 if this '
                .'installation genuinely has no media.',
                $media->archiveRoot(),
                $mediaFiles,
                $minimumFiles,
            );
        }

        $previous = BackupSet::latest($destination);
        $estimate = $mediaBytes + max($previous?->bytes() ?? 0, 2 * 1024 * 1024);
        $headroom = (float) ($config['headroom_multiple'] ?? 3.0);
        $required = (int) ceil($estimate * $headroom);
        // Guarded: the destination may not exist yet, and Laravel's error
        // handler turns disk_free_space()'s warning into an exception — which
        // would make --dry-run on a fresh server throw instead of reporting
        // that the destination is what is missing.
        $free = is_dir($destination) ? (int) disk_free_space($destination) : 0;

        $keep = (int) ($this->option('keep') ?? $config['keep_sets'] ?? 14);
        $label = trim((string) ($this->option('label') ?? ''));
        $id = ($label === '' ? '' : preg_replace('/[^A-Za-z0-9_.-]/', '-', $label).'-').now()->utc()->format('Ymd-His');

        if ($this->option('dry-run')) {
            return $this->reportPlan($media, $connection, $db, $destination, $id, $keep, $mediaBytes, $mediaFiles, $estimate, $required, $free, $coverage, array_merge($destinationProblems, $mediaProblems));
        }

        if ($destinationProblems !== []) {
            return $this->refuse('The backup destination is not usable, so nothing was written.', $destinationProblems);
        }

        if ($mediaProblems !== []) {
            return $this->refuse('The file half of this set would not be the whole file half, so no backup will be taken.', $mediaProblems);
        }

        // The refusal that keeps a backup from filling the volume the
        // application is serving from. Pruning happens AFTER a verified write,
        // never before, so this never trades the last good set for a run that
        // might fail.
        if ($free < $required) {
            return $this->refuse('Not enough free space to take a backup safely.', [
                sprintf(
                    '%s free at %s; this run needs about %s (%s estimated x %.1f headroom). %d set(s) are held here. '
                    .'Lower BACKUP_KEEP_SETS (currently %d) or give the volume room; nothing was written and nothing was deleted.',
                    self::humanBytes($free),
                    $destination,
                    self::humanBytes($required),
                    self::humanBytes($estimate),
                    $headroom,
                    count(BackupSet::all($destination)),
                    $keep,
                ),
            ]);
        }

        $partial = $destination.'/'.$id.'.partial';

        if (is_dir($partial) || is_dir($destination.'/'.$id)) {
            return $this->refuse(sprintf('A backup set called %s is already here.', $id));
        }

        if (! @mkdir($partial, 0750, true)) {
            return $this->refuse(sprintf('Could not create %s.', $partial));
        }

        $credentials = null;

        try {
            // 4. The database half. The password goes in a 0600 defaults file,
            //    never on the command line, because a command line is readable
            //    by every process on the host through /proc and `ps`.
            $credentials = $this->writeCredentialsFile($partial, $db);

            $sqlPath = $partial.'/database.sql';

            $dump = Process::timeout((int) ($config['process_timeout'] ?? 1800))->run(array_merge(
                [$config['database']['dump_binary'] ?? 'mysqldump', '--defaults-extra-file='.$credentials],
                array_values((array) ($config['database']['options'] ?? [])),
                $this->sslArguments($db),
                ['--result-file='.$sqlPath, (string) ($db['database'] ?? '')],
            ));

            if (! $dump->successful()) {
                return $this->refuseAndClean($partial, 'The database dump failed; no set was written.', [
                    trim($dump->errorOutput()) ?: trim($dump->output()) ?: sprintf('mysqldump exited %d with no output.', $dump->exitCode()),
                ]);
            }

            $gzip = Process::timeout((int) ($config['process_timeout'] ?? 1800))->run([$config['database']['gzip_binary'] ?? 'gzip', '-9', $sqlPath]);

            if (! $gzip->successful() || ! is_file($sqlPath.'.gz')) {
                return $this->refuseAndClean($partial, 'The database dump could not be compressed; no set was written.', [
                    trim($gzip->errorOutput()) ?: sprintf('gzip exited %d.', $gzip->exitCode()),
                ]);
            }

            $databaseFile = $partial.'/'.BackupSet::DATABASE_FILE;
            rename($sqlPath.'.gz', $databaseFile);

            $dbCheck = $this->verifyDatabaseHalf($databaseFile, $config);

            if ($dbCheck !== null) {
                return $this->refuseAndClean($partial, 'The database dump did not verify; no set was written.', [$dbCheck]);
            }

            // 5. The file half. The half no backup on this server has ever had.
            $mediaFile = $partial.'/'.BackupSet::MEDIA_FILE;

            $tar = Process::timeout((int) ($config['process_timeout'] ?? 1800))->run([
                $config['media']['tar_binary'] ?? 'tar',
                '-czf', $mediaFile,
                '-C', (string) $media->archiveRoot(),
                '.',
            ]);

            if (! $tar->successful() || ! is_file($mediaFile)) {
                return $this->refuseAndClean($partial, 'The media archive failed; no set was written.', [
                    trim($tar->errorOutput()) ?: sprintf('tar exited %d.', $tar->exitCode()),
                ]);
            }

            // Recounted after the archive: a file legitimately deleted while tar
            // was running must not read as a short archive. The floor is the
            // smaller of the two counts; a real shortfall still fails the run.
            $mediaFilesFloor = min($mediaFiles, self::directoryFileCount((string) $media->archiveRoot()));

            $entries = ArchiveIntegrity::countTarEntries($mediaFile);

            if (! $entries['ok']) {
                return $this->refuseAndClean($partial, 'The media archive did not verify; no set was written.', [
                    sprintf('%s: %s', BackupSet::MEDIA_FILE, $entries['error']),
                ]);
            }

            // FILES against files. The count on the left used to be every tar
            // header — and a tar header is not a file: directories, symlinks
            // and GNU long-name records all get one. The production disk is 91
            // files in 68 directories, so an archive holding a third of the
            // files still had more headers than the disk had files and this
            // check passed it. Comparing the two counts in the same unit is the
            // whole of what makes a set's file half a guarantee rather than a
            // hope. See ArchiveIntegrity::countTarEntries().
            if ($entries['files'] < $mediaFilesFloor) {
                return $this->refuseAndClean($partial, 'The media archive is short; no set was written.', [
                    sprintf(
                        '%s holds %d regular file(s) across %d tar header(s), and %s held %d file(s) when the run started. '
                        .'An archive that is missing files is the failure this pairing exists to prevent.',
                        BackupSet::MEDIA_FILE,
                        $entries['files'],
                        $entries['entries'],
                        $media->archiveRoot(),
                        $mediaFilesFloor,
                    ),
                ]);
            }

            // 6. What the set actually captured. Not a judgement on the backup —
            //    a statement about the data inside it, so that a restore months
            //    from now is not a surprise.
            $integrity = $this->mediaIntegrity($media);

            // 7. The manifest, written last. Its presence IS the set.
            $manifest = [
                'manifest_version' => BackupSet::MANIFEST_VERSION,
                'set' => $id,
                'created_at' => now()->utc()->toIso8601String(),
                'complete' => true,
                'app' => array_filter([
                    'env' => (string) config('app.env'),
                    'url' => (string) config('app.url'),
                    'commit' => self::gitCommit(),
                ]),
                'halves' => [
                    'database' => [
                        'file' => BackupSet::DATABASE_FILE,
                        'bytes' => (int) filesize($databaseFile),
                        'sha256' => hash_file('sha256', $databaseFile),
                        'connection' => $connection,
                        'database' => (string) ($db['database'] ?? ''),
                        'driver' => (string) ($db['driver'] ?? ''),
                    ],
                    'media' => [
                        'file' => BackupSet::MEDIA_FILE,
                        'bytes' => (int) filesize($mediaFile),
                        'sha256' => hash_file('sha256', $mediaFile),
                        'entries' => $entries['entries'],
                        // The number the next run compares itself against. It is
                        // recorded separately from `entries` because the two are
                        // different units and conflating them is what let an
                        // archive missing two thirds of its files pass.
                        'files' => $entries['files'],
                        'files_on_disk_at_start' => $mediaFiles,
                    ] + $media->toManifest(),
                ],
                'media_integrity' => $integrity,
                // What `complete: true` is a claim ABOUT. A set covers one disk;
                // this records which disks the rows named when it was taken, so
                // the claim can be audited later instead of trusted.
                'media_disks' => $coverage,
            ];

            file_put_contents($partial.'/'.BackupSet::MANIFEST_FILE, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            foreach ([$databaseFile, $mediaFile, $partial.'/'.BackupSet::MANIFEST_FILE] as $file) {
                @chmod($file, 0640);
            }

            if ($credentials !== null) {
                @unlink($credentials);
                $credentials = null;
            }

            $final = $destination.'/'.$id;

            if (! @rename($partial, $final)) {
                return $this->refuseAndClean($partial, sprintf('Could not move the finished set into place at %s.', $final));
            }

            // Retention deletes the only other copies of these files, so it
            // asks one question first: did this set capture roughly what the
            // set it is about to replace captured? A run that archives almost
            // nothing is exactly the run that must not be allowed to prune —
            // the older sets are the only place the missing files still are.
            $shrank = $this->retentionRegression($previous, $entries['files'], $integrity, $config);

            $pruned = $shrank === null ? $this->prune($destination, $keep, $id) : [];

            return $this->report($final, $manifest, $pruned, $destination, $shrank);
        } catch (Throwable $e) {
            return $this->refuseAndClean($partial, 'The backup run threw and no set was written.', [$e->getMessage()]);
        } finally {
            if ($credentials !== null) {
                @unlink($credentials);
            }
        }
    }

    /**
     * mysqldump writes a `-- Dump completed` line only when it finished. This is
     * the check that tells a real dump from the 20-byte one in /root/backups.
     */
    private function verifyDatabaseHalf(string $file, array $config): ?string
    {
        $minimum = (int) ($config['database']['minimum_bytes'] ?? 4096);
        $bytes = (int) filesize($file);

        if ($bytes < $minimum) {
            return sprintf(
                '%s is %d bytes, under the %d-byte floor. /root/backups/pre-forms-migration-20260728-233129.sql.gz is 20 bytes and decompresses to nothing; that is what this floor is for.',
                BackupSet::DATABASE_FILE,
                $bytes,
                $minimum,
            );
        }

        $gz = ArchiveIntegrity::inspectGzip($file);

        if (! $gz['ok']) {
            return sprintf('%s: %s', BackupSet::DATABASE_FILE, $gz['error']);
        }

        if ($gz['bytes'] === 0) {
            return sprintf('%s is a valid gzip stream that decompresses to nothing — the dump captured no rows.', BackupSet::DATABASE_FILE);
        }

        $marker = (string) ($config['database']['completion_marker'] ?? '-- Dump completed');

        if ($marker !== '' && ! str_contains($gz['tail'], $marker)) {
            return sprintf(
                '%s does not end with "%s", the line mysqldump writes only when it finished. The dump was truncated.',
                BackupSet::DATABASE_FILE,
                $marker,
            );
        }

        return null;
    }

    /**
     * Which disks the media rows actually name — the question `complete: true`
     * is an answer to.
     *
     * A set holds ONE disk: whatever `MediaTarget` resolved, tarred from one
     * root. `config/filesystems.php` defines three (`local`, `public`, `s3`)
     * and every row is on `public` today, so the set covers everything and the
     * manifest is telling the truth. That is a fact about today's
     * configuration, not a property of the design: one row written to another
     * disk — a masjid uploading to s3, a migration half-finished, a queue
     * worker running with a stale MEDIA_DISK — and the archive silently stops
     * being the file half of the database it is bound to, while the manifest
     * goes on saying `complete: true`.
     *
     * So the rows are asked, every run, and a disk they name that the set does
     * not cover REFUSES the run. Refusing is the conservative move here: the
     * alternative is a set that reads as whole and is not, and the entire point
     * of this tooling is that a backup nobody can trust is worse than a gap
     * somebody can see. `conversions_disk` is asked too — a thumbnail on an
     * uncovered disk is still a file this set claims to hold and does not.
     *
     * @return array<string, mixed>
     */
    private function mediaDiskCoverage(MediaTarget $target): array
    {
        $base = ['archived' => $target->disk, 'named_by_rows' => [], 'uncovered' => []];

        try {
            if (! Schema::hasTable('media')) {
                // No rows exist, so no row names a disk this set misses.
                return ['status' => 'not_checked', 'reason' => 'this application has no media table'] + $base;
            }

            $named = [];

            foreach (['disk', 'conversions_disk'] as $column) {
                $counts = DB::table('media')
                    ->selectRaw(sprintf('%s as disk_name, count(*) as row_count', $column))
                    ->groupBy($column)
                    ->pluck('row_count', 'disk_name')
                    ->all();

                foreach ($counts as $name => $rows) {
                    // conversions_disk is nullable, and null means "the same
                    // disk as the original" — already covered by definition.
                    if ($column === 'conversions_disk' && ($name === null || $name === '')) {
                        continue;
                    }

                    $key = (string) $name;
                    $named[$column][$key] = (int) $rows;
                }
            }

            $uncovered = [];

            foreach ($named as $column => $counts) {
                foreach ($counts as $name => $rows) {
                    // Cast: PHP turns an array key that looks like an integer
                    // into one, and a disk compared as int to a disk compared
                    // as string never matches.
                    if ((string) $name !== $target->disk) {
                        $uncovered[] = ['disk' => (string) $name, 'column' => $column, 'rows' => $rows];
                    }
                }
            }

            return [
                'status' => 'checked',
                'archived' => $target->disk,
                'named_by_rows' => $named,
                'uncovered' => $uncovered,
            ];
        } catch (Throwable $e) {
            // Not "assume it is fine". If the rows cannot be asked, nothing here
            // is entitled to write a manifest claiming to cover them.
            return ['status' => 'unknown', 'reason' => $e->getMessage()] + $base;
        }
    }

    /**
     * Turn a coverage reading into refusals. Empty means this set may claim to
     * be the whole file half of the database beside it.
     *
     * @param  array<string, mixed>  $coverage
     * @return list<string>
     */
    private function coverageProblems(array $coverage, MediaTarget $target): array
    {
        if (($coverage['status'] ?? '') === 'unknown') {
            return [sprintf(
                'Could not read which disks the media rows name (%s), so there is no way to know whether a tar of disk [%s] '
                .'is the whole file half. Refusing rather than writing a set whose manifest would claim a completeness '
                .'nobody checked.',
                $coverage['reason'] ?? 'no reason given',
                $target->disk,
            )];
        }

        $uncovered = (array) ($coverage['uncovered'] ?? []);

        if ($uncovered === []) {
            return [];
        }

        $problems = [];

        foreach ($uncovered as $row) {
            $name = (string) ($row['disk'] ?? '');

            $problems[] = sprintf(
                '%d media row(s) name disk [%s] in `%s`, and this set archives disk [%s] only. Those files would not be in it.',
                (int) ($row['rows'] ?? 0),
                $name === '' ? '(empty)' : $name,
                (string) ($row['column'] ?? 'disk'),
                $target->disk,
            );
        }

        $problems[] = sprintf(
            'A set that covers some of the disks is not a smaller backup: its manifest says `complete: true`, and '
            .'restoring it re-creates every one of those rows pointing at a file it never held — which is the outage. '
            .'Either archive those disks too (backup.media.strategies.*) or move the rows back onto [%s].',
            $target->disk,
        );

        return $problems;
    }

    /**
     * How much of what this set just captured is actually whole.
     *
     * Read through the APPLICATION's own connection and the media disk it is
     * configured on, because that is what the number is about: of the media rows
     * this application has, how many have their file. The dump target is the
     * same connection in this system; the manifest records both so a reader can
     * see if that ever stops being true.
     *
     * GRADED ASYMMETRICALLY, ON PURPOSE. A row with no file is the restore trap
     * — it comes back pointing at nothing — so it downgrades the run and logs a
     * warning. A file with no row costs disk and breaks nothing, and this disk
     * has 68 directories of stock seed art that will never have rows; grading
     * that would put a permanent amber on every nightly run, and an alarm that
     * fires every ordinary night is an alarm that gets silenced.
     *
     * @return array<string, mixed>
     */
    private function mediaIntegrity(MediaTarget $target): array
    {
        try {
            if (! Schema::hasTable('media')) {
                return ['status' => 'not_checked', 'reason' => 'this application has no media table'];
            }

            $disk = Storage::disk($target->disk);

            $rows = 0;
            $withFile = 0;
            $dangling = [];

            DB::table('media')->select('id', 'file_name')->orderBy('id')->chunk(500, function ($chunk) use (&$rows, &$withFile, &$dangling, $disk, $target) {
                foreach ($chunk as $row) {
                    $rows++;

                    if ($disk->exists($target->relativePathFor($row->id, (string) $row->file_name))) {
                        $withFile++;
                    } elseif (count($dangling) < 20) {
                        $dangling[] = (int) $row->id;
                    }
                }
            });

            return [
                'status' => 'checked',
                'rows' => $rows,
                'rows_with_file' => $withFile,
                'rows_without_file' => $rows - $withFile,
                'first_rows_without_file' => $dangling,
            ];
        } catch (Throwable $e) {
            return ['status' => 'not_checked', 'reason' => $e->getMessage()];
        }
    }

    /**
     * Would pruning throw away a set that holds more files than the one just
     * written? Null means no, retention may proceed.
     *
     * The failure this guards is the one that actually happened, run forward
     * one night: the files go missing first, the next scheduled run faithfully
     * archives what is left, and retention deletes the sets that still had
     * them. Every check upstream of this one passes — the dump is complete, the
     * archive matches the disk, the manifest is honest — and the only copies of
     * the lost files are destroyed by a backup working exactly as designed.
     *
     * So the comparison is against the newest EXISTING set, and it is made
     * against its `files` count only. A set written before that field existed
     * records `entries` (tar headers) and nothing else; comparing files to
     * headers is the mistake this whole area is a fix for, so an older set
     * simply makes the guard inert rather than making it guess.
     *
     * The ratio, not equality: one deleted announcement image is an ordinary
     * Tuesday, and a guard that stops pruning on any decrease at all stops
     * pruning permanently the first time somebody tidies up — which fills the
     * volume the application serves from and ends with `backup:run` refusing
     * every night for lack of space. 0.9 tolerates ordinary churn and catches
     * the shape of a disk that emptied. Being wrong here costs disk, never data:
     * the set is still written, only retention is skipped.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function retentionRegression(
        ?BackupSet $previous,
        int $capturedFiles,
        array $capturedIntegrity,
        array $config
    ): ?array {
        if ($previous === null) {
            return null;
        }

        $ratio = (float) ($config['media']['retention_regression_ratio'] ?? 0.9);

        // BOTH HALVES, and the row half is not optional.
        //
        // This guard originally compared FILES alone, and a reviewer walked the
        // chain that left open: a run taken while the `media` table is empty
        // captures 0 rows, its file count is unchanged (the files are still on
        // disk), so the guard sees no regression and prunes the last set that
        // still recorded 226 rows. The set that could have proved what was lost
        // is deleted BY the run that observed the loss — the incident's
        // aftermath, reproduced, with the evidence destroyed afterwards.
        //
        // A row count and a file count fail apart, which is the whole reason
        // this platform is in this position: the files went first and the rows
        // followed weeks later. Guarding one and not the other guards neither.
        $checks = [
            'files' => [self::recordedMediaFiles($previous), $capturedFiles],
            'rows' => [self::recordedMediaRows($previous), self::capturedMediaRows($capturedIntegrity)],
        ];

        foreach ($checks as $unit => [$before, $captured]) {
            // A set written before the field existed, or a run that could not
            // count (no media table), makes THAT comparison inert rather than
            // making it guess. The other one still applies.
            if ($before === null || $before <= 0 || $captured === null) {
                continue;
            }

            $floor = (int) ceil($before * $ratio);

            if ($captured >= $floor) {
                continue;
            }

            return [
                'unit' => $unit,
                'previous_set' => $previous->id(),
                'previous_files' => self::recordedMediaFiles($previous),
                'captured_files' => $capturedFiles,
                'previous_rows' => self::recordedMediaRows($previous),
                'captured_rows' => self::capturedMediaRows($capturedIntegrity),
                'floor' => $floor,
                'ratio' => $ratio,
            ];
        }

        return null;
    }

    /**
     * The media ROW count a finished set recorded, or null if it predates the
     * field or was written by a run that could not count.
     */
    private static function recordedMediaRows(?BackupSet $set): ?int
    {
        $integrity = $set?->manifest()['media_integrity'] ?? null;

        if (! is_array($integrity) || ($integrity['status'] ?? null) === 'not_checked') {
            return null;
        }

        $recorded = $integrity['rows'] ?? null;

        return is_int($recorded) || (is_string($recorded) && ctype_digit($recorded)) ? (int) $recorded : null;
    }

    /** The same field off the integrity block this run just built. */
    private static function capturedMediaRows(array $integrity): ?int
    {
        if (($integrity['status'] ?? null) === 'not_checked') {
            return null;
        }

        $rows = $integrity['rows'] ?? null;

        return is_int($rows) || (is_string($rows) && ctype_digit($rows)) ? (int) $rows : null;
    }

    /**
     * The media file count a finished set recorded, or null if it predates the
     * field. Never falls back to `entries`: headers are not files.
     */
    private static function recordedMediaFiles(?BackupSet $set): ?int
    {
        $recorded = $set?->manifest()['halves']['media']['files'] ?? null;

        return is_int($recorded) || (is_string($recorded) && ctype_digit($recorded)) ? (int) $recorded : null;
    }

    /**
     * Keep the newest $keep finished sets. Never removes the set just written,
     * and never empties the destination.
     *
     * @return list<string>
     */
    private function prune(string $destination, int $keep, string $justWritten): array
    {
        if ($keep < 1) {
            return [];
        }

        $sets = BackupSet::all($destination);
        $pruned = [];

        foreach (array_slice($sets, $keep) as $set) {
            if ($set->id() === $justWritten) {
                continue;
            }

            $pruned[] = $set->id();
            $set->delete();
        }

        return $pruned;
    }

    /**
     * The password never appears in an argument vector. `--defaults-extra-file`
     * is read by the client before anything else; the file is 0600 inside the
     * set directory being assembled and is deleted before the set is moved into
     * place, so it is never part of a backup.
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

    /**
     * The production database is managed and reached over the network with
     * MYSQL_ATTR_SSL_CA set. Without this the dump would be the one connection
     * in the system that talks to it in the clear.
     *
     * @return list<string>
     */
    private function sslArguments(array $db): array
    {
        if (! extension_loaded('pdo_mysql') || ! defined('PDO::MYSQL_ATTR_SSL_CA')) {
            return [];
        }

        $ca = $db['options'][\PDO::MYSQL_ATTR_SSL_CA] ?? null;

        return ($ca === null || $ca === '') ? [] : ['--ssl-ca='.$ca];
    }

    private function reportPlan(
        MediaTarget $media,
        string $connection,
        array $db,
        string $destination,
        string $id,
        int $keep,
        int $mediaBytes,
        int $mediaFiles,
        int $estimate,
        int $required,
        int $free,
        array $coverage = [],
        array $problems = [],
    ): int {
        $this->info('backup:run --dry-run — nothing will be written.');
        $this->newLine();
        $this->line(sprintf('  set             %s', $id));
        $this->line(sprintf('  destination     %s', $destination));
        $this->line(sprintf('  database        %s on connection [%s]', $db['database'] ?? '?', $connection));
        $this->line(sprintf('  media           %s', $media->describe()));
        $this->line(sprintf('  media on disk   %s in %d files', self::humanBytes($mediaBytes), $mediaFiles));
        $this->line(sprintf('  disks covered   %s', self::describeCoverage($coverage)));
        $this->line(sprintf('  estimated set   %s', self::humanBytes($estimate)));
        $this->line(sprintf(
            '  free space      %s (needs %s incl. headroom)',
            // Not "0 B", which would read as a full volume rather than an
            // unasked question.
            is_dir($destination) ? self::humanBytes($free) : 'unknown — the destination does not exist',
            self::humanBytes($required),
        ));
        $this->line(sprintf('  retention       keep %d set(s); %d here now', $keep, count(BackupSet::all($destination))));
        $this->newLine();

        foreach ($problems as $problem) {
            $this->warn('  A real run would REFUSE: '.$problem);
        }

        if ($problems === [] && $free < $required) {
            $this->warn('  A real run would REFUSE: not enough free space.');
        }

        return self::EXIT_OK;
    }

    private function report(string $path, array $manifest, array $pruned, string $destination, ?array $shrank = null): int
    {
        $integrity = $manifest['media_integrity'];
        $dangling = (int) ($integrity['rows_without_file'] ?? 0);

        $summary = [
            'set' => $manifest['set'],
            'path' => $path,
            'database_bytes' => $manifest['halves']['database']['bytes'],
            'media_bytes' => $manifest['halves']['media']['bytes'],
            'media_entries' => $manifest['halves']['media']['entries'],
            'media_files' => $manifest['halves']['media']['files'],
            'media_disks' => $manifest['media_disks'] ?? [],
            'media_integrity' => $integrity,
            'retention_regression' => $shrank,
            'pruned' => $pruned,
            'sets_held' => count(BackupSet::all($destination)),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf('Backup set %s written and verified.', $manifest['set']));
            $this->line(sprintf('  %s', $path));
            $this->line(sprintf('  database  %s', self::humanBytes((int) $summary['database_bytes'])));
            $this->line(sprintf(
                '  media     %s — %d file(s) in %d tar entries',
                self::humanBytes((int) $summary['media_bytes']),
                $summary['media_files'],
                $summary['media_entries'],
            ));

            if (($integrity['status'] ?? '') === 'checked') {
                $this->line(sprintf('  media rows %d, with a file %d, without %d', $integrity['rows'], $integrity['rows_with_file'], $dangling));
            } else {
                $this->line(sprintf('  media rows not checked (%s)', $integrity['reason'] ?? 'no reason'));
            }

            if ($pruned !== []) {
                $this->line(sprintf('  pruned    %s', implode(', ', $pruned)));
            }
        }

        // Exactly one log line per run. schedule:run discards stdout, so this is
        // the only evidence a scheduled backup leaves — including a clean one,
        // because a backup that stops happening silently looks identical to one
        // that is fine.
        if ($shrank !== null) {
            $this->warn(sprintf(
                '  This set captured %d media file(s); %s captured %d. Retention was SKIPPED — the older sets are the only '
                .'copies of the files this one no longer holds. Nothing was deleted.',
                (int) $shrank['captured_files'],
                (string) $shrank['previous_set'],
                (int) $shrank['previous_files'],
            ));

            if ($dangling > 0) {
                $this->warn(sprintf('  %d media row(s) in this set also point at files that are not on the disk.', $dangling));
            }

            Log::warning('backup:run wrote a verified set that captured fewer media files than the set before it; retention was skipped', $summary);

            return self::EXIT_MEDIA_SHRANK;
        }

        if ($dangling > 0) {
            Log::warning('backup:run wrote a verified set whose media rows are missing files', $summary);
            $this->warn(sprintf(
                '  %d media row(s) in this set point at files that are not on the disk. The backup is sound; what it captured is not. Restoring it restores that state.',
                $dangling,
            ));

            return self::EXIT_INCONSISTENT_MEDIA;
        }

        Log::info('backup:run wrote a verified set', $summary);

        return self::EXIT_OK;
    }

    private function refuse(string $headline, array $detail = []): int
    {
        $this->error($headline);

        foreach ($detail as $line) {
            $this->line('  '.$line);
        }

        Log::error('backup:run took no backup: '.$headline, ['detail' => $detail]);

        return self::EXIT_FAILED;
    }

    private function refuseAndClean(string $partial, string $headline, array $detail = []): int
    {
        foreach ((array) (@scandir($partial) ?: []) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($partial.'/'.$entry);
            }
        }

        @rmdir($partial);

        return $this->refuse($headline, $detail);
    }

    private static function directoryBytes(string $path): int
    {
        $bytes = 0;

        foreach (self::walk($path) as $file) {
            $bytes += (int) $file->getSize();
        }

        return $bytes;
    }

    private static function directoryFileCount(string $path): int
    {
        $count = 0;

        foreach (self::walk($path) as $ignored) {
            $count++;
        }

        return $count;
    }

    private static function walk(string $path): iterable
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            // Symlinks are excluded on purpose, so that the count on this side
            // and the count ArchiveIntegrity takes on the archive side are the
            // same unit. `isFile()` follows a symlink and says "yes, a file";
            // tar does not follow it and stores a `2` record carrying no data.
            // Counting one as a file on the disk and not in the archive would
            // fail an honest run every night the disk had a symlink in it.
            if ($file->isFile() && ! $file->isLink()) {
                yield $file;
            }
        }
    }

    /** @param  array<string, mixed>  $coverage */
    private static function describeCoverage(array $coverage): string
    {
        $status = (string) ($coverage['status'] ?? '');

        if ($status === 'unknown') {
            return sprintf('UNKNOWN — the media rows could not be read (%s)', $coverage['reason'] ?? 'no reason given');
        }

        if ($status !== 'checked') {
            return sprintf('not checked (%s)', $coverage['reason'] ?? 'no reason given');
        }

        $uncovered = (array) ($coverage['uncovered'] ?? []);

        if ($uncovered === []) {
            return sprintf('[%s] — and no media row names any other disk', (string) ($coverage['archived'] ?? '?'));
        }

        return sprintf(
            '[%s] ONLY — rows also name %s',
            (string) ($coverage['archived'] ?? '?'),
            implode(', ', array_map(
                fn (array $row) => sprintf('[%s] (%d row(s))', ($row['disk'] ?? '') === '' ? '(empty)' : $row['disk'], (int) ($row['rows'] ?? 0)),
                $uncovered,
            )),
        );
    }

    private static function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            return $info['name'] ?? 'this user';
        }

        return get_current_user() ?: 'this user';
    }

    private static function gitCommit(): ?string
    {
        $head = base_path('.git/HEAD');

        if (! is_file($head)) {
            return null;
        }

        $contents = trim((string) file_get_contents($head));

        if (str_starts_with($contents, 'ref: ')) {
            $ref = base_path('.git/'.substr($contents, 5));

            return is_file($ref) ? substr(trim((string) file_get_contents($ref)), 0, 12) : null;
        }

        return substr($contents, 0, 12) ?: null;
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return sprintf($index === 0 ? '%d %s' : '%.1f %s', $value, $units[$index]);
    }
}
