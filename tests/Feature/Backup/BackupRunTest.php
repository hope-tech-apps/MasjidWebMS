<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\ArchiveIntegrity;
use App\Support\Backup\BackupSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `backup:run` — the database and the media files its rows point at, together.
 *
 * WHAT IS FAKED AND WHAT IS NOT. Only `mysqldump` is faked (the handler writes a
 * plausible dump at the `--result-file` path it was given); `gzip` and `tar` run
 * for real, so the archives these tests verify are real archives and the
 * verification is real verification. Laravel runs unmatched processes for real
 * unless `preventStrayProcesses()` is called, which is exactly what that buys.
 *
 * The behaviours pinned here are the ones the outage of 2026-08-17 needed:
 *   - a set is BOTH halves or it is nothing (no database-only fallback, ever);
 *   - a dump that captured nothing is not a backup, however healthy `gzip -t`
 *     says it is (see the 20-byte file in /root/backups);
 *   - a failed run leaves no set and no half-set behind;
 *   - retention is bounded and never eats the run that just succeeded.
 */
class BackupRunTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private string $mediaRoot;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        if (! $this->hasBinary('tar') || ! $this->hasBinary('gzip')) {
            $this->markTestSkipped('tar and gzip are required: this command archives with them and these tests run them for real.');
        }

        $this->base = sys_get_temp_dir().'/manara-backup-'.bin2hex(random_bytes(6));
        $this->mediaRoot = $this->base.'/media';
        $this->destination = $this->base.'/backups';

        mkdir($this->mediaRoot.'/7', 0777, true);
        mkdir($this->destination, 0777, true);
        file_put_contents($this->mediaRoot.'/7/poster.jpg', str_repeat('poster-bytes', 64));

        config([
            'filesystems.disks.testmedia' => ['driver' => 'local', 'root' => $this->mediaRoot, 'throw' => false],
            'media-library.disk_name' => 'testmedia',
            'media-library.prefix' => '',

            'backup.destination' => $this->destination,
            'backup.keep_sets' => 14,
            'backup.headroom_multiple' => 1.0,
            'backup.database.connection' => 'backup_mysql',

            // A mysql-SHAPED connection that is never opened: mysqldump is faked,
            // and the media-row check reads the application's own connection.
            'database.connections.backup_mysql' => [
                'driver' => 'mysql',
                'host' => 'db.example.internal',
                'port' => '25060',
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

    private function hasBinary(string $binary): bool
    {
        $which = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }

    /** A dump big enough to clear the 4096-byte floor and ending the way a finished one does. */
    private function plausibleDump(bool $complete = true): string
    {
        $sql = "-- MySQL dump 10.19\nCREATE TABLE `media` (`id` bigint unsigned NOT NULL);\n";

        for ($i = 0; $i < 400; $i++) {
            $sql .= sprintf("INSERT INTO `media` VALUES (%d,'%s');\n", $i, bin2hex(random_bytes(32)));
        }

        return $complete ? $sql."-- Dump completed on 2026-08-21  2:40:00\n" : $sql;
    }

    private function fakeDumpWriting(?string $contents): void
    {
        Process::fake([
            '*mysqldump*' => function ($process) use ($contents) {
                foreach ((array) $process->command as $argument) {
                    if (str_starts_with((string) $argument, '--result-file=')) {
                        file_put_contents(substr((string) $argument, strlen('--result-file=')), $contents ?? '');
                    }
                }

                return Process::result('', '', 0);
            },
        ]);
    }

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

    private function setsIn(string $destination): array
    {
        return array_values(array_filter(
            (array) scandir($destination),
            fn ($entry) => $entry !== '.' && $entry !== '..',
        ));
    }

    private function insertMediaRow(int $id, string $fileName, string $disk = 'testmedia', ?string $conversionsDisk = null): void
    {
        DB::table('media')->insert([
            'id' => $id,
            'model_type' => 'App\\Models\\Masjid',
            'model_id' => 1,
            'collection_name' => 'announcements',
            'name' => 'poster',
            'file_name' => $fileName,
            'disk' => $disk,
            'conversions_disk' => $conversionsDisk,
            'size' => 1024,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
        ]);
    }

    /** A real gzipped tar built from a tree this test controls. */
    private function buildArchive(string $from): string
    {
        $path = $this->base.'/built-'.bin2hex(random_bytes(4)).'.tar.gz';

        exec(sprintf('tar -czf %s -C %s .', escapeshellarg($path), escapeshellarg($from)), $ignored, $status);

        $this->assertSame(0, $status, 'the fixture archive itself had to be built by a real tar');

        return $path;
    }

    /** Let mysqldump write a plausible dump, and make `tar` produce $archive instead of a real one. */
    private function fakeTarProducing(string $archive): void
    {
        Process::fake([
            '*mysqldump*' => function ($process) {
                foreach ((array) $process->command as $argument) {
                    if (str_starts_with((string) $argument, '--result-file=')) {
                        file_put_contents(substr((string) $argument, strlen('--result-file=')), $this->plausibleDump());
                    }
                }

                return Process::result('', '', 0);
            },
            '*tar*' => function ($process) use ($archive) {
                $command = array_values((array) $process->command);
                $target = $command[array_search('-czf', $command, true) + 1] ?? null;

                if (is_string($target)) {
                    copy($archive, $target);
                }

                return Process::result('', '', 0);
            },
        ]);
    }

    // ------------------------------------------------------------------ tests

    #[Test]
    public function it_writes_one_set_holding_both_halves_and_a_manifest_that_binds_them(): void
    {
        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run')->assertExitCode(0);

        $sets = $this->setsIn($this->destination);
        $this->assertCount(1, $sets, 'exactly one set should have been written');

        $set = BackupSet::at($this->destination.'/'.$sets[0]);

        $this->assertFileExists($set->databasePath());
        $this->assertFileExists($set->mediaPath());
        $this->assertFileExists($set->manifestPath());

        $manifest = (array) $set->manifest();
        $this->assertTrue($manifest['complete']);
        $this->assertSame(hash_file('sha256', $set->databasePath()), $manifest['halves']['database']['sha256']);
        $this->assertSame(hash_file('sha256', $set->mediaPath()), $manifest['halves']['media']['sha256']);
        $this->assertSame('testmedia', $manifest['halves']['media']['disk']);
        $this->assertSame($this->mediaRoot, $manifest['halves']['media']['root']);

        // The whole point: the set is restorable as a pair, and says so.
        $this->assertSame([], $set->problems());
    }

    #[Test]
    public function the_media_archive_really_contains_the_files_that_were_on_the_disk(): void
    {
        mkdir($this->mediaRoot.'/8', 0777, true);
        file_put_contents($this->mediaRoot.'/8/logo.png', str_repeat('logo', 128));

        $this->fakeDumpWriting($this->plausibleDump());
        $this->artisan('backup:run')->assertExitCode(0);

        $set = BackupSet::latest($this->destination);
        $manifest = (array) $set->manifest();

        $this->assertSame(2, $manifest['halves']['media']['files_on_disk_at_start']);
        $this->assertGreaterThanOrEqual(2, $manifest['halves']['media']['entries']);

        $listing = [];
        exec('tar -tzf '.escapeshellarg($set->mediaPath()), $listing);
        $listing = implode("\n", $listing);

        $this->assertStringContainsString('7/poster.jpg', $listing);
        $this->assertStringContainsString('8/logo.png', $listing);
    }

    #[Test]
    public function it_refuses_the_whole_run_when_the_media_disk_cannot_be_archived(): void
    {
        // This is the central refusal. Media on a disk this build cannot reach
        // must NOT produce a database-only backup — restoring one of those is
        // what re-creates rows pointing at files that are gone.
        config(['media-library.disk_name' => 's3']);
        config(['filesystems.disks.s3' => ['driver' => 's3', 'bucket' => 'manara-media']]);

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run')->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertSame([], $this->setsIn($this->destination), 'nothing at all may be written');
    }

    #[Test]
    public function it_refuses_when_the_media_root_does_not_exist(): void
    {
        $this->deleteTree($this->mediaRoot);

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run')->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertSame([], $this->setsIn($this->destination));
    }

    #[Test]
    public function a_dump_that_captured_nothing_is_not_a_backup(): void
    {
        // /root/backups/pre-forms-migration-20260728-233129.sql.gz is 20 bytes,
        // decompresses to zero, and `gzip -t` calls it healthy. It sat beside
        // four real dumps for 24 days.
        $this->fakeDumpWriting('');

        $this->artisan('backup:run')->assertExitCode(1);

        $this->assertSame([], $this->setsIn($this->destination), 'no set, and no half-set, may survive a failed run');
    }

    #[Test]
    public function a_truncated_dump_is_not_a_backup(): void
    {
        // Large enough to clear the size floor, but missing the line mysqldump
        // writes only when it finished.
        $this->fakeDumpWriting($this->plausibleDump(complete: false));

        $this->artisan('backup:run')->assertExitCode(1);

        $this->assertSame([], $this->setsIn($this->destination));
    }

    #[Test]
    public function a_failed_dump_leaves_no_partial_directory_behind(): void
    {
        Process::fake(['*mysqldump*' => Process::result('', 'mysqldump: Got error 1045: Access denied', 1)]);

        $this->artisan('backup:run')->assertExitCode(1);

        $this->assertSame([], $this->setsIn($this->destination));
    }

    #[Test]
    public function the_database_password_never_reaches_a_command_line(): void
    {
        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run')->assertExitCode(0);

        Process::assertRan(function ($process) {
            $line = implode(' ', (array) $process->command);

            if (! str_contains($line, 'mysqldump')) {
                return false;
            }

            // A command line is readable by every process on the host.
            $this->assertStringNotContainsString('correct-horse-battery-staple', $line);
            $this->assertStringContainsString('--defaults-extra-file=', $line);

            return true;
        });
    }

    #[Test]
    public function the_credentials_file_is_not_left_inside_the_finished_set(): void
    {
        $this->fakeDumpWriting($this->plausibleDump());
        $this->artisan('backup:run')->assertExitCode(0);

        $set = BackupSet::latest($this->destination);

        $this->assertFileDoesNotExist($set->path.'/.my.cnf');
    }

    #[Test]
    public function it_records_how_many_media_rows_have_their_file_and_flags_the_ones_that_do_not(): void
    {
        // Row 7 has its file. Row 9 does not — the shape that emptied the feed.
        $this->insertMediaRow(7, 'poster.jpg');
        $this->insertMediaRow(9, 'missing.jpg');

        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run')->assertExitCode(2);

        $manifest = (array) BackupSet::latest($this->destination)->manifest();

        $this->assertSame('checked', $manifest['media_integrity']['status']);
        $this->assertSame(2, $manifest['media_integrity']['rows']);
        $this->assertSame(1, $manifest['media_integrity']['rows_with_file']);
        $this->assertSame(1, $manifest['media_integrity']['rows_without_file']);
        $this->assertSame([9], $manifest['media_integrity']['first_rows_without_file']);
    }

    #[Test]
    public function a_set_whose_rows_all_have_files_is_a_clean_run(): void
    {
        $this->insertMediaRow(7, 'poster.jpg');

        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run')->assertExitCode(0);

        $manifest = (array) BackupSet::latest($this->destination)->manifest();
        $this->assertSame(0, $manifest['media_integrity']['rows_without_file']);
    }

    #[Test]
    public function files_on_the_disk_with_no_row_do_not_downgrade_a_run(): void
    {
        // 68 directories of stock seed art on the production disk will never
        // have rows. An amber that burns every ordinary night gets silenced.
        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run')->assertExitCode(0);
    }

    #[Test]
    public function it_refuses_before_writing_anything_when_the_volume_cannot_take_another_set(): void
    {
        config(['backup.headroom_multiple' => 1.0e9]);

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run')->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertSame([], $this->setsIn($this->destination), 'a refusal must not part-write');
    }

    #[Test]
    public function retention_keeps_the_newest_sets_and_never_the_one_just_written(): void
    {
        foreach (['20260101-000000', '20260102-000000'] as $old) {
            mkdir($this->destination.'/'.$old, 0777, true);
            file_put_contents($this->destination.'/'.$old.'/'.BackupSet::MANIFEST_FILE, json_encode(['manifest_version' => 1, 'set' => $old]));
        }

        config(['backup.keep_sets' => 2]);

        $this->fakeDumpWriting($this->plausibleDump());
        $this->artisan('backup:run')->assertExitCode(0);

        $sets = $this->setsIn($this->destination);
        sort($sets);

        $this->assertCount(2, $sets);
        $this->assertSame('20260102-000000', $sets[0], 'the newest of the old sets stays');
        $this->assertNotSame('20260101-000000', $sets[1]);
        $this->assertTrue(BackupSet::latest($this->destination)->isRestorable(), 'the set just written must survive its own pruning');
    }

    #[Test]
    public function retention_never_empties_the_destination_when_a_run_fails(): void
    {
        mkdir($this->destination.'/20260101-000000', 0777, true);
        file_put_contents($this->destination.'/20260101-000000/'.BackupSet::MANIFEST_FILE, json_encode(['manifest_version' => 1]));

        config(['backup.keep_sets' => 1]);

        // The run fails, so pruning never happens and the old set is still here.
        Process::fake(['*mysqldump*' => Process::result('', 'connection refused', 2)]);

        $this->artisan('backup:run')->assertExitCode(1);

        $this->assertSame(['20260101-000000'], $this->setsIn($this->destination));
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run', ['--dry-run' => true])->assertExitCode(0);

        Process::assertNothingRan();
        $this->assertSame([], $this->setsIn($this->destination));
    }

    #[Test]
    public function a_label_names_the_set(): void
    {
        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run', ['--label' => 'pre-r9'])->assertExitCode(0);

        $this->assertStringStartsWith('pre-r9-', BackupSet::latest($this->destination)->id());
    }

    #[Test]
    public function it_refuses_a_connection_it_cannot_dump(): void
    {
        config(['backup.database.connection' => 'sqlite']);

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run')->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertSame([], $this->setsIn($this->destination));
    }

    #[Test]
    public function it_refuses_when_the_destination_does_not_exist(): void
    {
        config(['backup.destination' => $this->base.'/nowhere']);

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run')->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function a_dry_run_reports_the_plan_even_when_the_destination_is_missing(): void
    {
        // --dry-run is the first thing an operator runs, and on a fresh server
        // the destination is exactly what is missing. Stopping at that line
        // would hide the rest of the plan behind the one problem it is meant to
        // surface.
        config(['backup.destination' => $this->base.'/nowhere']);

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run', ['--dry-run' => true])
            ->expectsOutputToContain('A real run would REFUSE')
            ->assertExitCode(0);

        Process::assertNothingRan();
    }

    // -------------------------------------- the file half has to be the WHOLE
    // -------------------------------------- file half, in files and in disks

    #[Test]
    public function an_archive_whose_headers_are_directories_is_still_a_short_archive(): void
    {
        // THE SHAPE THIS EXISTS FOR. The completeness check compared a count of
        // TAR HEADERS against a count of FILES, and a tar header is not a file:
        // directories, symlinks and long-name records all get one. Here the disk
        // holds three files and the archive holds one file inside eight headers,
        // so the old comparison (8 >= 3) called it complete and wrote the set.
        mkdir($this->mediaRoot.'/8', 0777, true);
        mkdir($this->mediaRoot.'/9', 0777, true);
        file_put_contents($this->mediaRoot.'/8/logo.png', str_repeat('logo', 128));
        file_put_contents($this->mediaRoot.'/9/flyer.jpg', str_repeat('flyer', 128));

        $short = $this->base.'/short';
        mkdir($short.'/a/b/c/d/e/f', 0777, true);
        file_put_contents($short.'/a/one.jpg', str_repeat('one', 64));

        $archive = $this->buildArchive($short);

        $counted = ArchiveIntegrity::countTarEntries($archive);
        $this->assertGreaterThan(3, $counted['entries'], 'the fixture must have MORE headers than the disk has files');
        $this->assertSame(1, $counted['files'], 'and far fewer files');

        $this->fakeTarProducing($archive);

        $this->artisan('backup:run')->assertExitCode(1);

        $this->assertSame([], $this->setsIn($this->destination), 'an archive missing two thirds of the files is not a backup');
    }

    #[Test]
    public function an_archive_that_really_holds_the_files_still_passes(): void
    {
        // The other side of the same check: the honest archive must not be
        // refused just because the count is now stricter.
        mkdir($this->mediaRoot.'/8', 0777, true);
        file_put_contents($this->mediaRoot.'/8/logo.png', str_repeat('logo', 128));

        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run')->assertExitCode(0);

        $manifest = (array) BackupSet::latest($this->destination)->manifest();

        $this->assertSame(2, $manifest['halves']['media']['files']);
        $this->assertGreaterThan(2, $manifest['halves']['media']['entries'], 'the directory headers are still entries');
    }

    #[Test]
    public function it_refuses_when_a_media_row_names_a_disk_this_set_does_not_archive(): void
    {
        // A set covers ONE disk. Every row is on `public` today, which is a fact
        // about today's configuration and not a property of the design: one row
        // on another disk and the archive stops being the file half of the
        // database it is bound to, while the manifest goes on saying complete.
        $this->insertMediaRow(7, 'poster.jpg');
        $this->insertMediaRow(11, 'logo.png', disk: 's3');

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run')
            ->expectsOutputToContain('[s3]')
            ->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertSame([], $this->setsIn($this->destination), 'a set claiming a completeness it does not have must not exist');
    }

    #[Test]
    public function a_conversions_disk_the_set_does_not_cover_is_a_refusal_too(): void
    {
        $this->insertMediaRow(7, 'poster.jpg', conversionsDisk: 's3');

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run')
            ->expectsOutputToContain('conversions_disk')
            ->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertSame([], $this->setsIn($this->destination));
    }

    #[Test]
    public function a_finished_set_records_which_disks_its_rows_named(): void
    {
        $this->insertMediaRow(7, 'poster.jpg');

        $this->fakeDumpWriting($this->plausibleDump());
        $this->artisan('backup:run')->assertExitCode(0);

        $manifest = (array) BackupSet::latest($this->destination)->manifest();

        $this->assertSame('checked', $manifest['media_disks']['status']);
        $this->assertSame('testmedia', $manifest['media_disks']['archived']);
        $this->assertSame([], $manifest['media_disks']['uncovered']);
        $this->assertSame(1, $manifest['media_disks']['named_by_rows']['disk']['testmedia']);
    }

    #[Test]
    public function it_refuses_when_it_cannot_tell_which_disks_the_rows_name(): void
    {
        // Not "assume it is fine". `complete: true` is a claim, and a claim
        // nobody could check is not one this command is entitled to write.
        Schema::table('media', function ($table) {
            $table->dropColumn('disk');
        });

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run')->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertSame([], $this->setsIn($this->destination));
    }

    // ------------------------------------------------ the media-half floor and
    // ------------------------------------------------ what retention may eat

    #[Test]
    public function a_media_root_that_has_lost_its_files_is_not_a_backup(): void
    {
        // The 2026-08-17 order of events: the FILES went first. A nightly run in
        // that window dumps a healthy database, tars an empty directory, and
        // every check passes honestly — and then retention prunes the sets that
        // still held the files.
        unlink($this->mediaRoot.'/7/poster.jpg');

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run')->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertSame([], $this->setsIn($this->destination));
    }

    #[Test]
    public function a_set_that_captured_far_fewer_files_than_the_one_before_it_does_not_prune_it(): void
    {
        $older = '20260820-024000';
        mkdir($this->destination.'/'.$older, 0777, true);
        file_put_contents($this->destination.'/'.$older.'/'.BackupSet::MANIFEST_FILE, json_encode([
            'manifest_version' => 1,
            'set' => $older,
            'created_at' => '2026-08-20T02:40:00+00:00',
            'complete' => true,
            'halves' => ['media' => ['files' => 100]],
        ]));

        // Keep one set: without the guard, the set holding a hundred files is
        // deleted by the run that captured one.
        config(['backup.keep_sets' => 1]);

        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run')
            ->expectsOutputToContain('Retention was SKIPPED')
            ->assertExitCode(3);

        $sets = $this->setsIn($this->destination);
        sort($sets);

        $this->assertCount(2, $sets, 'the richer set is the only copy of the files this run no longer holds');
        $this->assertContains($older, $sets);
    }

    #[Test]
    public function a_set_that_captured_far_fewer_media_rows_than_the_one_before_it_does_not_prune_it(): void
    {
        // THE CHAIN A REVIEWER WALKED, and the reason the guard now watches both
        // halves. A run taken while the `media` table is empty captures 0 ROWS
        // while its FILE count is unchanged — the files are still on disk. The
        // files-only guard therefore saw no regression and pruned the last set
        // that still recorded what had been lost: the incident's aftermath,
        // reproduced, destroying the evidence of it in the same breath.
        $older = '20260820-024000';
        mkdir($this->destination.'/'.$older, 0777, true);
        file_put_contents($this->destination.'/'.$older.'/'.BackupSet::MANIFEST_FILE, json_encode([
            'manifest_version' => 1,
            'set' => $older,
            'created_at' => '2026-08-20T02:40:00+00:00',
            'complete' => true,
            // Same file count this run will capture, so the FILE half sees
            // nothing wrong. Only the row half can catch this.
            'halves' => ['media' => ['files' => 1]],
            'media_integrity' => ['status' => 'checked', 'rows' => 226, 'rows_without_file' => 0],
        ]));

        // The live table is empty — exactly the window this platform sat in.
        DB::table('media')->delete();

        config(['backup.keep_sets' => 1]);

        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run')
            ->expectsOutputToContain('Retention was SKIPPED')
            ->assertExitCode(3);

        $sets = $this->setsIn($this->destination);

        $this->assertContains($older, $sets,
            'the set recording 226 rows was pruned by the run that captured none');
    }

    #[Test]
    public function a_set_whose_row_count_held_still_prunes(): void
    {
        // The other direction: the row guard must not become a permanent brake
        // either. A run capturing the same rows prunes as before.
        $older = '20260820-024000';
        mkdir($this->destination.'/'.$older, 0777, true);
        file_put_contents($this->destination.'/'.$older.'/'.BackupSet::MANIFEST_FILE, json_encode([
            'manifest_version' => 1,
            'set' => $older,
            'created_at' => '2026-08-20T02:40:00+00:00',
            'complete' => true,
            'halves' => ['media' => ['files' => 1]],
            'media_integrity' => ['status' => 'checked', 'rows' => 1, 'rows_without_file' => 0],
        ]));

        // The base fixture creates the FILE but no row, so the row has to exist
        // for "the count held" to be the thing under test rather than a second
        // copy of the regression case.
        $this->insertMediaRow(7, 'poster.jpg');

        config(['backup.keep_sets' => 1]);

        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run')->assertExitCode(0);

        $this->assertNotContains($older, $this->setsIn($this->destination));
    }

    #[Test]
    public function a_set_written_before_the_row_field_existed_does_not_block_retention(): void
    {
        // An older manifest records no `media_integrity`. That comparison goes
        // inert rather than guessing — the file half still applies.
        $older = '20260820-024000';
        mkdir($this->destination.'/'.$older, 0777, true);
        file_put_contents($this->destination.'/'.$older.'/'.BackupSet::MANIFEST_FILE, json_encode([
            'manifest_version' => 1,
            'set' => $older,
            'created_at' => '2026-08-20T02:40:00+00:00',
            'complete' => true,
            'halves' => ['media' => ['files' => 1]],
        ]));

        config(['backup.keep_sets' => 1]);

        $this->fakeDumpWriting($this->plausibleDump());

        $this->artisan('backup:run')->assertExitCode(0);

        $this->assertNotContains($older, $this->setsIn($this->destination));
    }

    #[Test]
    public function a_set_that_captured_what_the_one_before_it_captured_still_prunes(): void
    {
        // The guard must not be a permanent brake: ordinary churn still prunes,
        // or the destination grows until the free-space refusal stops backups.
        $older = '20260820-024000';
        mkdir($this->destination.'/'.$older, 0777, true);
        file_put_contents($this->destination.'/'.$older.'/'.BackupSet::MANIFEST_FILE, json_encode([
            'manifest_version' => 1,
            'set' => $older,
            'created_at' => '2026-08-20T02:40:00+00:00',
            'complete' => true,
            'halves' => ['media' => ['files' => 1]],
        ]));

        config(['backup.keep_sets' => 1]);

        $this->fakeDumpWriting($this->plausibleDump());
        $this->artisan('backup:run')->assertExitCode(0);

        $this->assertSame([BackupSet::latest($this->destination)->id()], $this->setsIn($this->destination));
    }

    #[Test]
    public function the_regression_guard_never_compares_files_against_tar_headers(): void
    {
        // A set taken before `files` existed recorded `entries` — tar headers —
        // and comparing files to headers is the very mistake this area was
        // fixed for. An older set makes the guard inert rather than make it
        // guess: 91 files against 160 headers would look like a 43% collapse.
        $older = '20260820-024000';
        mkdir($this->destination.'/'.$older, 0777, true);
        file_put_contents($this->destination.'/'.$older.'/'.BackupSet::MANIFEST_FILE, json_encode([
            'manifest_version' => 1,
            'set' => $older,
            'created_at' => '2026-08-20T02:40:00+00:00',
            'complete' => true,
            'halves' => ['media' => ['entries' => 100]],
        ]));

        config(['backup.keep_sets' => 1]);

        $this->fakeDumpWriting($this->plausibleDump());
        $this->artisan('backup:run')->assertExitCode(0);

        $this->assertSame([BackupSet::latest($this->destination)->id()], $this->setsIn($this->destination));
    }

    #[Test]
    public function a_dry_run_says_which_disks_the_rows_name(): void
    {
        $this->insertMediaRow(7, 'poster.jpg');
        $this->insertMediaRow(11, 'logo.png', disk: 's3');

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:run', ['--dry-run' => true])
            ->expectsOutputToContain('disks covered')
            ->expectsOutputToContain('A real run would REFUSE')
            ->assertExitCode(0);

        Process::assertNothingRan();
        $this->assertSame([], $this->setsIn($this->destination));
    }
}
