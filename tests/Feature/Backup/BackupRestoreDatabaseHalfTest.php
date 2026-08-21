<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupSet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `backup:restore --force`, actually performed — with real processes.
 *
 * WHY THIS FILE IS SEPARATE FROM BackupRestoreTest
 *
 * That file pins the REFUSALS, and every test in it runs with stray processes
 * prevented because a preflight that quietly shelled out to `mysql` would be the
 * bug. This one is the opposite: nothing is faked. A real `tar` unpacks a real
 * archive onto a real disk, a real `gzip` decompresses a real dump, and a real
 * child process stands where the database client stands.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS PROVEN HERE AND WHAT IS ASSUMED — read this before trusting a restore
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * PROVEN BY EXECUTION: the media half really lands on the disk, and it lands
 * BEFORE the database is touched; the dump is really decompressed; the whole
 * decompressed dump really arrives on the client's STDIN, asserted byte for
 * byte; the client's exit status really decides whether this command reports
 * success; and no argument resembling `--execute=source` is passed any more.
 *
 * ASSUMED, AND THIS IS THE ONLY ASSUMPTION: that the real `mysql`/`mariadb`
 * client behaves as its manual says — that `--execute='source dump.sql'` runs
 * the client's own SOURCE command, which prints a failed statement and CARRIES
 * ON, leaving the client to exit 0; and that the same dump fed on stdin runs in
 * batch mode, where the first error aborts and the exit status is non-zero
 * (which is exactly what `--force` exists to switch off). The stand-in below
 * encodes those two behaviours and nothing else.
 *
 * WHY IT IS ASSUMED RATHER THAN MEASURED. There is nowhere permitted to measure
 * it. The droplet is the production application host and carries the client
 * only — `/usr/bin/mysql` and `/usr/bin/mariadb` exist, `/usr/sbin/mysqld` and
 * `/usr/sbin/mariadbd` do not, and both units read `inactive` — while the
 * database is a DigitalOcean managed instance that is off limits. Installing a
 * server on the production host to prove a backup is safe would be changing
 * production to check that production is safe. So the database half of a
 * restore has never been applied to a real server by anything in this
 * repository, that sentence is in the command's own docblock and in
 * deploy/README.md, and it stops being true the day somebody restores a set
 * onto a scratch database and writes down that they did.
 */
class BackupRestoreDatabaseHalfTest extends TestCase
{
    private string $base;

    private string $mediaRoot;

    private string $destination;

    private string $client;

    /** The line the stand-in treats as a statement the server rejected. */
    private const POISON = 'INSERT INTO `media` VALUES (DELIBERATE SYNTAX ERROR);';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        foreach (['tar', 'gzip', 'bash'] as $binary) {
            $which = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

            if (! is_string($which) || trim($which) === '') {
                $this->markTestSkipped('tar, gzip and bash are required: this test runs all three for real.');
            }
        }

        $this->base = sys_get_temp_dir().'/manara-restore-real-'.bin2hex(random_bytes(6));
        $this->mediaRoot = $this->base.'/media';
        $this->destination = $this->base.'/backups';

        mkdir($this->mediaRoot, 0777, true);
        mkdir($this->destination, 0777, true);

        $this->client = $this->writeStandInClient();

        config([
            'filesystems.disks.testmedia' => ['driver' => 'local', 'root' => $this->mediaRoot, 'throw' => false],
            'media-library.disk_name' => 'testmedia',
            'media-library.prefix' => '',

            'backup.destination' => $this->destination,
            'backup.database.connection' => 'backup_mysql',
            'backup.database.restore_binary' => $this->client,
            'database.connections.backup_mysql' => [
                'driver' => 'mysql',
                'host' => 'db.example.internal',
                'database' => 'manara_production',
                'username' => 'manara_user',
                'password' => 'correct-horse-battery-staple',
                'options' => [],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->base);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->deleteTree($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }

    /**
     * An executable standing exactly where the database client stands, doing
     * the two things the client's manual says it does and nothing else. It also
     * records what it was given, so the test can assert on the real invocation
     * rather than on a mock's recollection of one.
     */
    private function writeStandInClient(): string
    {
        $directory = $this->base.'/client';
        mkdir($directory, 0777, true);

        $path = $directory.'/mysql';

        file_put_contents($path, <<<'BASH'
#!/bin/bash
# Stand-in for the mysql/mariadb client. See the class docblock of
# BackupRestoreDatabaseHalfTest for exactly which documented behaviours this
# encodes and why they are not measured here.
here="$(dirname "$0")"
printf '%s\n' "$@" > "$here/argv.txt"

for arg in "$@"; do
    case "$arg" in
        --execute=*)
            # `--execute='source dump.sql'` runs the client's own SOURCE, which
            # reports a failed statement and carries on. The client exits 0 —
            # which is how a restore that died on statement 812 of 4000 tells
            # the operator it succeeded.
            printf 'source\n' > "$here/mode.txt"
            ls -R "$here/../media" > "$here/media-at-db-time.txt" 2>/dev/null
            echo "ERROR 1064 (42000) at line 812: You have an error in your SQL syntax" >&2
            exit 0
            ;;
    esac
done

# Fed on stdin the client runs in batch mode: the first error aborts and the
# exit status is non-zero.
printf 'stdin\n' > "$here/mode.txt"
ls -R "$here/../media" > "$here/media-at-db-time.txt" 2>/dev/null
cat > "$here/stdin.sql"

if grep -q 'DELIBERATE SYNTAX ERROR' "$here/stdin.sql"; then
    echo "ERROR 1064 (42000) at line 812: You have an error in your SQL syntax" >&2
    exit 1
fi

exit 0
BASH);

        chmod($path, 0755);

        return $path;
    }

    /** A dump big enough to be worth streaming, optionally carrying a statement the server would reject. */
    private function dump(bool $poisoned = false): string
    {
        $sql = "-- MySQL dump 10.19\nCREATE TABLE `media` (`id` bigint unsigned NOT NULL);\n";

        for ($i = 0; $i < 400; $i++) {
            $sql .= sprintf("INSERT INTO `media` VALUES (%d,'%s');\n", $i, bin2hex(random_bytes(32)));

            if ($poisoned && $i === 200) {
                $sql .= self::POISON."\n";
            }
        }

        return $sql."-- Dump completed on 2026-08-21  2:40:00\n";
    }

    /**
     * A real set: a real gzipped tar of two real files, a real gzipped dump, and
     * a manifest whose sizes and checksums are the ones on disk.
     */
    private function makeSet(bool $poisoned = false, string $id = '20260821-024000'): BackupSet
    {
        $payload = $this->base.'/payload';
        mkdir($payload.'/7', 0777, true);
        mkdir($payload.'/8', 0777, true);
        file_put_contents($payload.'/7/poster.jpg', str_repeat('poster-bytes', 64));
        file_put_contents($payload.'/8/logo.png', str_repeat('logo-bytes', 64));

        $path = $this->destination.'/'.$id;
        mkdir($path, 0777, true);

        $media = $path.'/'.BackupSet::MEDIA_FILE;
        exec(sprintf('tar -czf %s -C %s .', escapeshellarg($media), escapeshellarg($payload)), $ignored, $status);
        $this->assertSame(0, $status, 'the fixture archive itself had to be built by a real tar');

        $database = $path.'/'.BackupSet::DATABASE_FILE;
        file_put_contents($database, gzencode($this->dump($poisoned)));

        file_put_contents($path.'/'.BackupSet::MANIFEST_FILE, json_encode([
            'manifest_version' => BackupSet::MANIFEST_VERSION,
            'set' => $id,
            'created_at' => '2026-08-21T02:40:00+00:00',
            'complete' => true,
            'app' => ['env' => 'production', 'commit' => '0c46b03abcd1'],
            'halves' => [
                'database' => [
                    'file' => BackupSet::DATABASE_FILE,
                    'bytes' => filesize($database),
                    'sha256' => hash_file('sha256', $database),
                ],
                'media' => [
                    'file' => BackupSet::MEDIA_FILE,
                    'bytes' => filesize($media),
                    'sha256' => hash_file('sha256', $media),
                    'disk' => 'testmedia',
                    'driver' => 'local',
                    'strategy' => 'tar',
                    'root' => $this->mediaRoot,
                    'entries' => 5,
                    'files' => 2,
                ],
            ],
            'media_integrity' => ['status' => 'checked', 'rows' => 2, 'rows_with_file' => 2, 'rows_without_file' => 0],
            'media_disks' => ['status' => 'checked', 'archived' => 'testmedia', 'named_by_rows' => ['disk' => ['testmedia' => 2]], 'uncovered' => []],
        ], JSON_PRETTY_PRINT));

        $this->deleteTree($payload);

        return BackupSet::at($path);
    }

    private function clientSaw(string $file): string
    {
        $path = dirname($this->client).'/'.$file;

        $this->assertFileExists($path, sprintf('the stand-in client never recorded %s, so it was never run', $file));

        return (string) file_get_contents($path);
    }

    // ------------------------------------------------------------------ tests

    #[Test]
    public function the_whole_dump_is_delivered_to_the_client_on_stdin(): void
    {
        $set = $this->makeSet();

        $this->artisan('backup:restore', ['--force' => true])->assertExitCode(0);

        $this->assertSame('stdin', trim($this->clientSaw('mode.txt')));

        // Byte for byte: this is the difference between "we handed it a path"
        // and "the bytes actually reached the process that applies them".
        $this->assertSame(
            (string) gzdecode((string) file_get_contents($set->databasePath())),
            $this->clientSaw('stdin.sql'),
        );
    }

    #[Test]
    public function no_argument_asks_the_client_to_source_the_dump(): void
    {
        // `source` is the client's own command and it does not abort on error.
        // The restore must never reach for it again, whatever else changes.
        $this->makeSet();

        $this->artisan('backup:restore', ['--force' => true])->assertExitCode(0);

        $argv = $this->clientSaw('argv.txt');

        $this->assertStringNotContainsString('--execute=', $argv);
        $this->assertStringNotContainsString('source ', $argv);
        $this->assertStringContainsString('--defaults-extra-file=', $argv);
        $this->assertStringNotContainsString('correct-horse-battery-staple', $argv, 'and the password still never reaches an argument vector');
    }

    #[Test]
    public function a_client_that_fails_partway_makes_this_command_report_failure(): void
    {
        // THE SHAPE THIS EXISTS FOR. Through `--execute=source` the stand-in —
        // like the real client — prints the rejected statement and exits 0, and
        // this command printed "Restored — both halves." over a database that
        // stopped applying at statement 812.
        $this->makeSet(poisoned: true);

        $this->artisan('backup:restore', ['--force' => true])
            ->expectsOutputToContain('The database half failed to apply')
            ->assertExitCode(1);

        $this->assertSame('stdin', trim($this->clientSaw('mode.txt')));
    }

    #[Test]
    public function the_media_half_is_on_the_disk_before_the_database_is_touched(): void
    {
        // A file with no row is inert; a row with no file is the outage. If a
        // restore dies halfway it must die on the harmless side — so the client
        // records the media root as it found it, and the files are already
        // there.
        $this->makeSet(poisoned: true);

        $this->artisan('backup:restore', ['--force' => true])->assertExitCode(1);

        $listing = $this->clientSaw('media-at-db-time.txt');

        $this->assertStringContainsString('poster.jpg', $listing);
        $this->assertStringContainsString('logo.png', $listing);

        // And they survive the failed database half, because that is what makes
        // re-running the restore the right next move.
        $this->assertFileExists($this->mediaRoot.'/7/poster.jpg');
        $this->assertFileExists($this->mediaRoot.'/8/logo.png');
    }

    #[Test]
    public function a_clean_restore_puts_both_halves_where_they_belong(): void
    {
        $this->makeSet();

        $this->artisan('backup:restore', ['--force' => true])
            ->expectsOutputToContain('both halves')
            ->assertExitCode(0);

        $this->assertSame(str_repeat('poster-bytes', 64), (string) file_get_contents($this->mediaRoot.'/7/poster.jpg'));
        $this->assertSame(str_repeat('logo-bytes', 64), (string) file_get_contents($this->mediaRoot.'/8/logo.png'));
    }

    #[Test]
    public function the_credentials_file_does_not_outlive_the_restore(): void
    {
        $this->makeSet();

        $this->artisan('backup:restore', ['--force' => true])->assertExitCode(0);

        $argv = $this->clientSaw('argv.txt');
        preg_match('/--defaults-extra-file=(\S+)/', $argv, $matches);

        $this->assertNotEmpty($matches, 'the client was given a defaults file');
        $this->assertFileDoesNotExist($matches[1], 'and it is gone once the restore is over');
    }
}
