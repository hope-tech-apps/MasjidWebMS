<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupSet;
use App\Support\Backup\MediaTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Restore a backup SET — both halves, or neither.
 *
 * THE REFUSAL IS THE FEATURE
 *
 * The four dumps in /root/backups are `mysqldump` output. Restoring one puts
 * 226 media rows back, pointing at 226 files that are not on this server, which
 * is the state that blanked every masjid logo and withheld every announcement
 * with an image from the feed. That is not a partial recovery; it is the outage,
 * re-applied, by someone who believes they are fixing it.
 *
 * So this command has no `--database-only`. It restores a set whose manifest
 * says both halves are present, whose files are the size and the sha256 the
 * manifest recorded, and nothing else. Every refusal is a sentence saying which
 * of those failed. Pointed at one of the legacy dumps it says so by name.
 *
 * BY DEFAULT IT RESTORES NOTHING. A bare run is a preflight: it prints what it
 * would do and every reason it would not, and exits. `--force` is what performs
 * it, and in production `--force` is also what ConfirmableTrait requires.
 *
 * ORDER: MEDIA FIRST, THEN THE DATABASE. A file with no row is inert. A row with
 * no file is the failure. If a restore dies halfway, it should die on the side
 * that does not break the application.
 *
 * MEDIA GOES WHERE THE APPLICATION READS FROM TODAY, not where the backup came
 * from — the archive is unpacked into the disk `config('media-library.disk_name')`
 * currently resolves to. Both paths are printed, because if they differ that is
 * something the operator has to see.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHICH HALF OF THIS COMMAND HAS BEEN EXERCISED, AND WHICH HAS ONLY BEEN READ
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * An untested restore path is a backup nobody has proven, and an operator is
 * entitled to know which half has been run and which has been reasoned about.
 *
 * THE FILE HALF IS EXECUTED. A real `tar` unpacks a real `media.tar.gz` into a
 * real directory and the files are asserted to be on the disk afterwards, in
 * BackupRestoreDatabaseHalfTest. Every refusal in this command is executed too.
 *
 * THE DATABASE HALF HAS NEVER BEEN APPLIED TO A REAL MySQL OR MariaDB SERVER by
 * anything in this repository, and it cannot be from here. The droplet is the
 * production application host: it carries the CLIENT only (`/usr/bin/mysql`,
 * `/usr/bin/mariadb`) and no server binary at all — there is no `/usr/sbin/
 * mysqld` and no `/usr/sbin/mariadbd`, and both units read `inactive` — while
 * the database itself is a DigitalOcean managed instance that is off limits.
 * Installing a server to test a backup would be changing production to check
 * that production is safe.
 *
 * What IS executed, short of the server: the set is verified, the dump is
 * decompressed by a real `gzip`, and a stand-in executable standing exactly
 * where `mysql` stands receives the dump ON ITS STDIN — the whole file,
 * asserted byte-for-byte — and its exit status decides whether this command
 * reports success. So the plumbing is proven and the client's own behaviour is
 * documented-and-assumed. tests/Feature/Backup/BackupRestoreDatabaseHalfTest
 * states that assumption in one place, in its own docblock.
 *
 * This paragraph stops being true the day somebody restores a set onto a
 * scratch database and writes down that they did. Until then it is repeated in
 * deploy/README.md, where an operator looks first.
 */
class BackupRestore extends Command
{
    use ConfirmableTrait;

    protected $signature = 'backup:restore
                            {--set= : Set id, or an absolute path. Default: the newest set in the destination}
                            {--accept-dangling : Proceed even though the set records media rows whose files were already missing when it was taken}
                            {--accept-fewer-rows : Proceed even though the set holds far fewer media rows than the live table}
                            {--force : Actually restore. Without it this is a preflight that writes nothing}';

    protected $description = 'Restore a backup set — the database and the media files together, or not at all.';

    private const EXIT_OK = 0;

    private const EXIT_REFUSED = 1;

    public function handle(): int
    {
        $config = (array) config('backup');
        $destination = rtrim((string) ($config['destination'] ?? ''), '/');
        $selector = trim((string) ($this->option('set') ?? ''));

        $set = $this->resolveSet($destination, $selector);

        if ($set === null) {
            return $this->refuse('No backup set to restore.', [
                $selector === ''
                    ? sprintf('There are no finished sets in %s. `backup:run` writes them; nothing has written one here yet.', $destination)
                    : sprintf('Nothing at %s or %s.', $selector, $destination.'/'.$selector),
                sprintf(
                    'Note: any *.sql.gz in /root/backups is a database-only dump from before this tooling. It is not a set and this command will not restore one — see the class docblock for why.',
                ),
            ]);
        }

        $this->line(sprintf('Set: %s', $set->path));

        $problems = $set->problems();

        if ($problems !== []) {
            return $this->refuse('This set cannot be restored.', $problems);
        }

        $manifest = (array) $set->manifest();

        // Where the files go: the disk the application reads from NOW.
        $media = MediaTarget::resolve(
            $config,
            (array) config('filesystems.disks', []),
            config('media-library.disk_name'),
            config('media-library.prefix'),
        );

        if (! $media->isSupported() || $media->strategy !== MediaTarget::STRATEGY_TAR) {
            return $this->refuse('The media half cannot be unpacked, so nothing will be restored.', [
                $media->reason ?? sprintf('Media is on disk [%s] with strategy [%s]; this command unpacks a tar into a local disk only.', $media->disk, (string) $media->strategy),
                'Restoring the database alone would re-create rows pointing at files that are not there.',
            ]);
        }

        $connection = $config['database']['connection'] ?? null;
        $connection = ($connection === null || $connection === '') ? (string) config('database.default') : (string) $connection;
        $db = (array) config("database.connections.{$connection}");

        if (($db['driver'] ?? null) !== 'mysql' && ($db['driver'] ?? null) !== 'mariadb') {
            return $this->refuse('The database half cannot be restored, so nothing will be restored.', [
                sprintf('Connection [%s] uses driver [%s]; this command restores mysql/mariadb only.', $connection, $db['driver'] ?? 'null'),
            ]);
        }

        $integrity = (array) ($manifest['media_integrity'] ?? []);
        $dangling = (int) ($integrity['rows_without_file'] ?? 0);

        // Not a defect in the SET — the set is intact. It is a statement about
        // what was true when it was taken, and it is the one thing an operator
        // restoring months later cannot see from the outside.
        if ($dangling > 0 && ! $this->option('accept-dangling')) {
            return $this->refuse('This set is intact, and what it captured is not.', [
                sprintf(
                    'When it was taken, %d of %d media rows already had no file on the disk. Restoring it restores that state: those rows come back pointing at nothing.',
                    $dangling,
                    (int) ($integrity['rows'] ?? 0),
                ),
                'If that is understood and wanted, re-run with --accept-dangling.',
            ]);
        }

        // RESTORING BACKWARDS OVER A HEALTHIER TABLE.
        //
        // A restore always goes back in time, so holding fewer rows than the
        // live table is normal and not by itself interesting. What is NOT normal
        // is a set that captured almost nothing being laid over a populated
        // estate — and that is exactly the shape this platform produced: for an
        // unknown window the `media` table read 0 rows while the application was
        // serving three organisations, so any set taken in that window records
        // 0 and restoring it would wipe the table it is supposed to protect.
        //
        // The ratio is deliberately loose (half, not the retention guard's 0.9):
        // a month-old set legitimately misses everything added since, and a
        // restore that nags about ordinary staleness is one an operator learns
        // to `--force` past without reading. A set holding ZERO rows against a
        // populated table is refused whatever the ratio says, because that is
        // not staleness, it is the incident.
        if (($refusal = $this->rowsRegression($integrity, $config)) !== null && ! $this->option('accept-fewer-rows')) {
            return $this->refuse('This set holds far fewer media rows than the table it would replace.', $refusal);
        }

        $this->reportPlan($set, $manifest, $media, $connection, $db, $dangling);

        if (! $this->option('force')) {
            $this->newLine();
            $this->info('Preflight only — nothing was restored. Re-run with --force to perform it.');

            return self::EXIT_OK;
        }

        // Production also demands --force through ConfirmableTrait; this keeps
        // the same gate on every other environment.
        if (! $this->confirmToProceed()) {
            return self::EXIT_REFUSED;
        }

        return $this->perform($set, $media, $connection, $db, $config);
    }

    private function perform(BackupSet $set, MediaTarget $media, string $connection, array $db, array $config): int
    {
        $timeout = (int) ($config['process_timeout'] ?? 1800);
        $root = (string) $media->archiveRoot();
        $credentials = null;
        $staging = null;

        try {
            if (! is_dir($root) && ! @mkdir($root, 0775, true)) {
                return $this->refuse(sprintf('Could not create the media root %s.', $root));
            }

            // 1. Files first. A file with no row is inert; a row with no file is
            //    the outage. tar OVERWRITES what the archive names and leaves
            //    everything else alone — it is not a mirror, so anything added
            //    since the backup survives.
            $this->line('Unpacking media...');

            $untar = Process::timeout($timeout)->run([
                $config['media']['tar_binary'] ?? 'tar',
                '-xzf', $set->mediaPath(),
                '-C', $root,
            ]);

            if (! $untar->successful()) {
                return $this->refuse('The media half failed to unpack; the database was NOT touched.', [
                    trim($untar->errorOutput()) ?: sprintf('tar exited %d.', $untar->exitCode()),
                ]);
            }

            // 2. Then the rows those files belong to.
            $this->line('Restoring the database...');

            $staging = (string) tempnam(sys_get_temp_dir(), 'manara-restore-');
            @unlink($staging);

            if (! @mkdir($staging, 0700)) {
                return $this->refuse(sprintf('Could not create a staging directory at %s.', $staging));
            }

            copy($set->databasePath(), $staging.'/database.sql.gz');

            $gunzip = Process::timeout($timeout)->run([
                $config['database']['gzip_binary'] ?? 'gzip', '-d', $staging.'/database.sql.gz',
            ]);

            if (! $gunzip->successful() || ! is_file($staging.'/database.sql')) {
                return $this->refuse('The database half could not be decompressed. The media files ARE now restored; re-run once this is fixed.', [
                    trim($gunzip->errorOutput()) ?: sprintf('gzip exited %d.', $gunzip->exitCode()),
                ]);
            }

            $credentials = $this->writeCredentialsFile($staging, $db);

            // THE DUMP GOES IN ON STDIN, NOT THROUGH `--execute=source`.
            //
            // `mysql --execute='source dump.sql'` runs the client's own SOURCE
            // command, and SOURCE does not stop on an error: it prints the
            // failed statement to stderr, carries on with the next one, and the
            // client exits 0. A restore that dies on statement 812 of 4000
            // therefore reports SUCCESS, and the operator walks away from a
            // half-restored database believing the outage is over. It is the
            // same shape as `mysqldump | gzip`, which is why that pipe is
            // banned in BackupRun: a wrapper reporting its own health instead
            // of the thing it wrapped.
            //
            // Fed on stdin the client runs in batch mode, where the FIRST error
            // aborts and the exit status is non-zero — the behaviour `--force`
            // exists to turn OFF. The dump is handed over as a stream rather
            // than a string so a multi-gigabyte set does not have to fit in
            // PHP's memory limit.
            $sql = $staging.'/database.sql';
            $stream = fopen($sql, 'rb');

            if ($stream === false) {
                return $this->refuse(sprintf('Could not read the decompressed dump at %s.', $sql));
            }

            try {
                $restore = Process::timeout($timeout)->input($stream)->run(array_merge(
                    [$config['database']['restore_binary'] ?? 'mysql', '--defaults-extra-file='.$credentials],
                    $this->sslArguments($db),
                    ['--default-character-set=utf8mb4', (string) ($db['database'] ?? '')],
                ));
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $restore->successful()) {
                Log::error('backup:restore failed applying the database half', ['set' => $set->id()]);

                return $this->refuse('The database half failed to apply. The media files ARE now restored.', [
                    trim($restore->errorOutput()) ?: sprintf('mysql exited %d.', $restore->exitCode()),
                ]);
            }

            Log::info('backup:restore restored a set', ['set' => $set->id(), 'path' => $set->path, 'connection' => $connection]);

            $this->newLine();
            $this->info(sprintf('Restored %s — both halves.', $set->id()));
            $this->line('  Now: sudo systemctl restart masjid-queue.service, and php artisan config:clear && php artisan config:cache.');

            return self::EXIT_OK;
        } catch (Throwable $e) {
            return $this->refuse('The restore threw.', [$e->getMessage()]);
        } finally {
            if ($credentials !== null) {
                @unlink($credentials);
            }

            if ($staging !== null && is_dir($staging)) {
                foreach ((array) (@scandir($staging) ?: []) as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        @unlink($staging.'/'.$entry);
                    }
                }

                @rmdir($staging);
            }
        }
    }

    private function resolveSet(string $destination, string $selector): ?BackupSet
    {
        if ($selector === '') {
            return $destination === '' ? null : BackupSet::latest($destination);
        }

        foreach ([$selector, $destination.'/'.$selector] as $candidate) {
            if (is_dir($candidate) || (is_file($candidate) && str_ends_with($candidate, '.sql.gz'))) {
                return BackupSet::at($candidate);
            }
        }

        return null;
    }

    private function reportPlan(BackupSet $set, array $manifest, MediaTarget $media, string $connection, array $db, int $dangling): void
    {
        $recordedRoot = $manifest['halves']['media']['root'] ?? '(not recorded)';
        $currentRoot = (string) $media->archiveRoot();

        $this->newLine();
        $this->line(sprintf('  taken           %s', $manifest['created_at'] ?? '?'));
        $this->line(sprintf('  from            %s%s', $manifest['app']['env'] ?? '?', isset($manifest['app']['commit']) ? ' @ '.$manifest['app']['commit'] : ''));
        $this->line(sprintf('  database half   %s -> database [%s] on connection [%s]', $manifest['halves']['database']['file'], $db['database'] ?? '?', $connection));
        $this->line(sprintf('  media half      %s -> %s', $manifest['halves']['media']['file'], $currentRoot));

        if ($recordedRoot !== $currentRoot) {
            $this->warn(sprintf('  media was taken from %s and will be unpacked into %s.', $recordedRoot, $currentRoot));
        }

        $recordedRows = (int) ($manifest['media_integrity']['rows'] ?? 0);

        // `0, all with files` is true and reads as reassurance. An empty set is
        // the one number an operator most needs to notice, so it says so.
        $this->line(sprintf('  media rows      %s', match (true) {
            $recordedRows === 0 => 'NONE — this set records no media rows at all',
            $dangling > 0 => sprintf('%d, of which %d had no file when this was taken', $recordedRows, $dangling),
            default => sprintf('%d, all with files', $recordedRows),
        }));
        $this->newLine();
        $this->line('  Order: media first, then the database. Existing files not named by the archive are left alone.');
    }

    /**
     * Reasons this set holds materially fewer media rows than the live table,
     * or null when it does not.
     *
     * Returns the operator-facing lines rather than a boolean, because the two
     * cases it catches want different sentences: an empty set is an incident,
     * a merely-small one is a judgement call.
     *
     * Reads the live count defensively — no media table, or a database this
     * command cannot reach yet, means there is nothing to compare against and
     * therefore nothing to refuse. A guard that throws on a healthy estate is
     * worse than no guard.
     *
     * @param  array<string, mixed>  $integrity
     * @param  array<string, mixed>  $config
     * @return list<string>|null
     */
    private function rowsRegression(array $integrity, array $config): ?array
    {
        if (($integrity['status'] ?? null) === 'not_checked' || ! array_key_exists('rows', $integrity)) {
            return null;
        }

        $recorded = (int) $integrity['rows'];

        try {
            if (! Schema::hasTable('media')) {
                return null;
            }

            $live = (int) DB::table('media')->count();
        } catch (Throwable) {
            return null;
        }

        if ($live <= 0) {
            return null;
        }

        if ($recorded === 0) {
            return [
                sprintf('This set records NO media rows. The live table currently holds %d.', $live),
                'A set taken while the media table was empty is the failure this tooling exists to catch, not a restore point.',
                'If the empty table really is the state you want back, re-run with --accept-fewer-rows.',
            ];
        }

        $ratio = (float) ($config['media']['restore_shrinkage_ratio'] ?? 0.5);
        $floor = (int) ceil($live * $ratio);

        if ($recorded >= $floor) {
            return null;
        }

        return [
            sprintf('This set records %d media rows; the live table holds %d.', $recorded, $live),
            sprintf('That is below %d (%.0f%% of live), which is more than ordinary staleness accounts for.', $floor, $ratio * 100),
            'If this set really is the state you want back, re-run with --accept-fewer-rows.',
        ];
    }

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

    private function refuse(string $headline, array $detail = []): int
    {
        $this->error($headline);

        foreach ($detail as $line) {
            $this->line('  '.$line);
        }

        return self::EXIT_REFUSED;
    }
}
