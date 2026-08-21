<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `backup:restore` — both halves or neither.
 *
 * The four dumps in /root/backups can restore 226 media rows and not one of the
 * files those rows point at. Restoring one is not a partial recovery: it is the
 * outage that blanked every masjid logo and withheld every announcement with an
 * image, re-applied by someone who believes they are fixing it. So the refusals
 * ARE the feature, and each one is pinned here.
 *
 * Every test in this file runs with stray processes PREVENTED: a preflight that
 * quietly shelled out to `mysql` would be the bug.
 */
class BackupRestoreTest extends TestCase
{
    private string $base;

    private string $mediaRoot;

    private string $destination;

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

        $this->base = sys_get_temp_dir().'/manara-restore-'.bin2hex(random_bytes(6));
        $this->mediaRoot = $this->base.'/media';
        $this->destination = $this->base.'/backups';

        mkdir($this->mediaRoot, 0777, true);
        mkdir($this->destination, 0777, true);

        config([
            'filesystems.disks.testmedia' => ['driver' => 'local', 'root' => $this->mediaRoot, 'throw' => false],
            'media-library.disk_name' => 'testmedia',
            'media-library.prefix' => '',

            'backup.destination' => $this->destination,
            'backup.database.connection' => 'backup_mysql',
            'database.connections.backup_mysql' => [
                'driver' => 'mysql',
                'host' => 'db.example.internal',
                'database' => 'manara_production',
                'username' => 'manara_user',
                'password' => 'correct-horse-battery-staple',
                'options' => [],
            ],
        ]);

        // Nothing in a preflight may execute anything.
        Process::fake();
        Process::preventStrayProcesses();
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
     * A structurally valid set: two real files and a manifest whose sizes and
     * checksums match them.
     */
    private function makeSet(string $id = '20260821-024000', array $manifestOverrides = [], bool $withMediaHalf = true): BackupSet
    {
        $path = $this->destination.'/'.$id;
        mkdir($path, 0777, true);

        $database = $path.'/'.BackupSet::DATABASE_FILE;
        file_put_contents($database, gzencode(str_repeat("INSERT INTO `media` VALUES (1);\n", 200)."-- Dump completed\n"));

        $halves = [
            'database' => [
                'file' => BackupSet::DATABASE_FILE,
                'bytes' => filesize($database),
                'sha256' => hash_file('sha256', $database),
            ],
        ];

        if ($withMediaHalf) {
            $media = $path.'/'.BackupSet::MEDIA_FILE;
            file_put_contents($media, gzencode(str_repeat('media-archive', 100)));

            $halves['media'] = [
                'file' => BackupSet::MEDIA_FILE,
                'bytes' => filesize($media),
                'sha256' => hash_file('sha256', $media),
                'disk' => 'testmedia',
                'driver' => 'local',
                'strategy' => 'tar',
                'root' => $this->mediaRoot,
                'entries' => 3,
            ];
        }

        $manifest = array_replace_recursive([
            'manifest_version' => BackupSet::MANIFEST_VERSION,
            'set' => $id,
            'created_at' => '2026-08-21T02:40:00+00:00',
            'complete' => true,
            'app' => ['env' => 'production', 'commit' => '0c46b03abcd1'],
            'halves' => $halves,
            'media_integrity' => ['status' => 'checked', 'rows' => 226, 'rows_with_file' => 226, 'rows_without_file' => 0],
        ], $manifestOverrides);

        file_put_contents($path.'/'.BackupSet::MANIFEST_FILE, json_encode($manifest, JSON_PRETTY_PRINT));

        return BackupSet::at($path);
    }

    /**
     * A minimal live `media` table holding $rows rows.
     *
     * This class deliberately does NOT use RefreshDatabase — the restore path
     * needs no database — so the table the shrinkage guard compares against has
     * to be built by the tests that care about it. Only the columns the guard
     * reads are created; it counts rows and nothing else.
     */
    private function withLiveMediaRows(int $rows): void
    {
        Schema::dropIfExists('media');
        Schema::create('media', function ($table) {
            $table->id();
            $table->string('collection_name')->nullable();
        });

        for ($i = 1; $i <= $rows; $i++) {
            DB::table('media')->insert(['collection_name' => 'logos']);
        }
    }

    // ------------------------------------------------------------------ tests

    #[Test]
    public function a_set_holding_no_media_rows_is_refused_over_a_populated_table(): void
    {
        // The incident, offered back as a restore point. For an unknown window
        // this platform's `media` table read 0 rows while three organisations
        // were being served, so any set taken then records 0 — and restoring it
        // would wipe the table it is supposed to protect.
        $this->makeSet('20260821-024000', [
            'media_integrity' => ['status' => 'checked', 'rows' => 0, 'rows_with_file' => 0, 'rows_without_file' => 0],
        ]);

        $this->withLiveMediaRows(1);

        $this->artisan('backup:restore --force')
            ->expectsOutputToContain('far fewer media rows')
            ->assertFailed();

        Process::assertNothingRan();
    }

    #[Test]
    public function an_empty_set_can_still_be_restored_deliberately(): void
    {
        // The refusal is a speed bump, not a wall — an operator who really does
        // want the empty state back says so explicitly.
        $this->makeSet('20260821-024000', [
            'media_integrity' => ['status' => 'checked', 'rows' => 0, 'rows_with_file' => 0, 'rows_without_file' => 0],
        ]);

        $this->withLiveMediaRows(1);

        $this->artisan('backup:restore --accept-fewer-rows')
            ->expectsOutputToContain('Preflight only')
            ->assertExitCode(0);
    }

    #[Test]
    public function an_empty_set_never_reads_as_all_with_files(): void
    {
        // `0, all with files` is true and reads as reassurance. The one number
        // an operator most needs to notice must not look like a clean bill.
        $this->makeSet('20260821-024000', [
            'media_integrity' => ['status' => 'checked', 'rows' => 0, 'rows_with_file' => 0, 'rows_without_file' => 0],
        ]);

        $this->artisan('backup:restore')
            ->expectsOutputToContain('NONE')
            ->assertExitCode(0);
    }

    #[Test]
    public function an_ordinarily_stale_set_still_restores_without_a_flag(): void
    {
        // The guard must not fire on the normal case: a set from last week
        // legitimately misses everything added since, and a command that nags
        // about that is one an operator learns to force past without reading.
        $this->makeSet('20260821-024000', [
            'media_integrity' => ['status' => 'checked', 'rows' => 9, 'rows_with_file' => 9, 'rows_without_file' => 0],
        ]);

        $this->withLiveMediaRows(10);

        $this->artisan('backup:restore')
            ->expectsOutputToContain('Preflight only')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_valid_set_preflights_without_restoring_anything(): void
    {
        $this->makeSet();

        $this->artisan('backup:restore')
            ->expectsOutputToContain('Preflight only')
            ->assertExitCode(0);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_refuses_a_database_only_dump_by_name(): void
    {
        // The exact shape of every backup that existed before this tooling.
        $legacy = $this->base.'/pre-r8-20260817-231513.sql.gz';
        file_put_contents($legacy, gzencode('-- MySQL dump'));

        $this->artisan('backup:restore', ['--set' => $legacy, '--force' => true])
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_refuses_a_directory_with_no_manifest(): void
    {
        $path = $this->destination.'/20260821-024000';
        mkdir($path, 0777, true);
        file_put_contents($path.'/'.BackupSet::DATABASE_FILE, gzencode('-- MySQL dump'));
        file_put_contents($path.'/'.BackupSet::MEDIA_FILE, gzencode('archive'));

        $this->artisan('backup:restore', ['--set' => '20260821-024000', '--force' => true])
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_refuses_a_set_whose_media_half_is_missing(): void
    {
        // Half a pair. Restoring the database alone re-creates rows pointing at
        // files that are not there — the whole reason this command exists.
        $this->makeSet('20260821-024000', ['halves' => ['media' => [
            'file' => BackupSet::MEDIA_FILE,
            'bytes' => 4096,
            'sha256' => str_repeat('0', 64),
        ]]], withMediaHalf: false);

        $this->artisan('backup:restore', ['--set' => '20260821-024000', '--force' => true])
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_refuses_a_set_the_manifest_marks_incomplete(): void
    {
        $this->makeSet('20260821-024000', ['complete' => false, 'incomplete_reason' => 'the media disk was unreachable']);

        $this->artisan('backup:restore', ['--set' => '20260821-024000', '--force' => true])
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_refuses_a_set_whose_bytes_changed_after_it_was_verified(): void
    {
        $set = $this->makeSet();

        file_put_contents($set->mediaPath(), gzencode('a different archive entirely'), FILE_APPEND);

        $this->artisan('backup:restore', ['--set' => $set->id(), '--force' => true])
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_refuses_a_set_whose_checksum_does_not_match(): void
    {
        // Same size, different bytes: only the sha256 can tell.
        $set = $this->makeSet();
        $original = (string) file_get_contents($set->mediaPath());
        file_put_contents($set->mediaPath(), strrev($original));

        $this->assertSame(strlen($original), (int) filesize($set->mediaPath()));

        $this->artisan('backup:restore', ['--set' => $set->id(), '--force' => true])
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_refuses_a_manifest_version_it_does_not_understand(): void
    {
        $this->makeSet('20260821-024000', ['manifest_version' => 99]);

        $this->artisan('backup:restore', ['--set' => '20260821-024000', '--force' => true])
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_refuses_a_set_that_already_had_media_rows_without_files_unless_that_is_accepted(): void
    {
        $this->makeSet('20260821-024000', ['media_integrity' => [
            'status' => 'checked',
            'rows' => 226,
            'rows_with_file' => 0,
            'rows_without_file' => 226,
        ]]);

        $this->artisan('backup:restore', ['--set' => '20260821-024000', '--force' => true])
            ->assertExitCode(1);

        Process::assertNothingRan();

        // The set is intact; it is the DATA that is not. So it is overridable,
        // knowingly, rather than impossible.
        $this->artisan('backup:restore', ['--set' => '20260821-024000', '--accept-dangling' => true])
            ->expectsOutputToContain('Preflight only')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_refuses_when_the_media_half_would_have_nowhere_to_go(): void
    {
        $this->makeSet();

        config(['media-library.disk_name' => 's3']);
        config(['filesystems.disks.s3' => ['driver' => 's3', 'bucket' => 'manara-media']]);

        $this->artisan('backup:restore', ['--force' => true])->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_refuses_a_connection_it_cannot_restore_into(): void
    {
        $this->makeSet();

        config(['backup.database.connection' => 'sqlite']);

        $this->artisan('backup:restore', ['--force' => true])->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_says_so_when_there_is_nothing_to_restore(): void
    {
        $this->artisan('backup:restore', ['--force' => true])->assertExitCode(1);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_defaults_to_the_newest_set(): void
    {
        $this->makeSet('20260101-000000');
        $this->makeSet('20260821-024000');

        $this->artisan('backup:restore')
            ->expectsOutputToContain('20260821-024000')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_labelled_set_does_not_outrank_a_newer_one_just_because_of_its_name(): void
    {
        // 'p' sorts above '2', so ordering on the name alone would make a
        // pre-deploy backup from January permanently "the newest set" — which
        // decides both what this restores by default and what retention deletes.
        $this->makeSet('pre-r8-20260101-000000', ['created_at' => '2026-01-01T00:00:00+00:00']);
        $this->makeSet('20260821-024000', ['created_at' => '2026-08-21T02:40:00+00:00']);

        $this->artisan('backup:restore')
            ->expectsOutputToContain('20260821-024000')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_partial_directory_left_by_a_killed_run_is_never_a_candidate(): void
    {
        $this->makeSet('20260101-000000');

        $partial = $this->destination.'/20260821-024000.partial';
        mkdir($partial, 0777, true);
        file_put_contents($partial.'/'.BackupSet::DATABASE_FILE, gzencode('-- MySQL dump'));

        $this->artisan('backup:restore')
            ->expectsOutputToContain('20260101-000000')
            ->assertExitCode(0);
    }
}
