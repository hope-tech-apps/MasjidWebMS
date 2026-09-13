<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `backup:drill` — a restore nobody has practised is not a backup.
 *
 * WHAT IS EXERCISED HERE AND WHAT IS NOT
 *
 * `gzip` and `tar` run FOR REAL: the set is a real set, the dump is really
 * decompressed and the media archive is really unpacked, so the dump scan and
 * the media cross-check operate on genuine bytes. The database client is faked,
 * for the reason stated in BackupRestoreDatabaseHalfTest: there is no MySQL
 * server this suite is permitted to touch. What the fake stands in for is
 * narrow — the client's exit status and its `--batch` output — and every
 * assertion below is about what the DRILL does, not about what MySQL does.
 *
 * The tests that matter most are not the happy path. They are the five that pin
 * the safety argument: this command creates and drops databases on the
 * production server, and every one of those pins is a way it could have dropped
 * the wrong one.
 */
class BackupDrillTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private string $mediaRoot;

    private string $destination;

    /** @var list<string> */
    private array $ran = [];

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

        if (! $this->hasBinary('tar') || ! $this->hasBinary('gzip')) {
            $this->markTestSkipped('tar and gzip are required: the drill really unpacks and really decompresses.');
        }

        $this->base = sys_get_temp_dir().'/manara-drill-test-'.bin2hex(random_bytes(6));
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
            'backup.offsite.enabled' => false,

            'backup.drill.log_channel' => 'null',
            'backup.drill.scratch_prefix' => 'manara_drill_',
            'backup.drill.minimum_tables' => 20,
            'backup.drill.max_rows' => 25000,

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

    private function plausibleDump(string $extra = ''): string
    {
        $sql = "-- MySQL dump 10.19\n".$extra."CREATE TABLE `media` (`id` bigint unsigned NOT NULL);\n";

        for ($i = 0; $i < 400; $i++) {
            $sql .= sprintf("INSERT INTO `media` VALUES (%d,'%s');\n", $i, bin2hex(random_bytes(32)));
        }

        return $sql."-- Dump completed on 2026-09-12  2:40:00\n";
    }

    private function writeSet(string $extraSql = ''): BackupSet
    {
        DB::table('media')->insert([
            'id' => 7,
            'model_type' => 'App\\Models\\Masjid',
            'model_id' => 1,
            'collection_name' => 'announcements',
            'name' => 'poster',
            'file_name' => 'poster.jpg',
            'disk' => 'testmedia',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
        ]);

        Process::fake([
            '*mysqldump*' => function ($process) use ($extraSql) {
                foreach ((array) $process->command as $argument) {
                    if (str_starts_with((string) $argument, '--result-file=')) {
                        file_put_contents(substr((string) $argument, strlen('--result-file=')), $this->plausibleDump($extraSql));
                    }
                }

                return Process::result('', '', 0);
            },
        ]);

        $this->artisan('backup:run')->assertExitCode(0);

        return BackupSet::latest($this->destination);
    }

    /** A schema the restored dump is pretended to have produced. */
    private function restoredTables(): array
    {
        $tables = ['media'];

        for ($i = 0; $i < 30; $i++) {
            $tables[] = 'table_'.$i;
        }

        return $tables;
    }

    /**
     * Stand where the mysql client stands. `mysqldump` is deliberately NOT
     * matched by this pattern, so the set-building fake above is untouched.
     *
     * @param  array<string, mixed>  $overrides  substring of the command => Process::result to answer with
     * @param  list<string>  $existingScratchSchemas
     */
    private function fakeMysql(array $overrides = [], array $existingScratchSchemas = []): void
    {
        $this->ran = [];

        Process::fake([
            '*mysql*' => function ($process) use ($overrides, $existingScratchSchemas) {
                $command = implode(' ', (array) $process->command);
                $this->ran[] = $command;

                foreach ($overrides as $needle => $result) {
                    if (str_contains($command, $needle)) {
                        return $result;
                    }
                }

                if (str_contains($command, 'SHOW DATABASES')) {
                    return Process::result(implode("\n", $existingScratchSchemas), '', 0);
                }

                if (str_contains($command, 'SHOW TABLES')) {
                    return Process::result(implode("\n", $this->restoredTables()), '', 0);
                }

                if (str_contains($command, 'FROM media')) {
                    return Process::result("7\tposter.jpg", '', 0);
                }

                return Process::result('', '', 0);
            },
        ]);
    }

    /** @return list<string> */
    private function commandsMatching(string $needle): array
    {
        return array_values(array_filter($this->ran, fn (string $command) => str_contains($command, $needle)));
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

    // -------------------------------------------------------- the happy path

    #[Test]
    public function it_restores_the_newest_set_and_checks_the_rows_against_the_files(): void
    {
        // The pairing is the point. A database-only restore is the outage, so a
        // drill that only proved the dump applied would certify exactly the
        // artefact this tooling exists to never produce.
        $this->writeSet();
        $this->fakeMysql();

        $this->artisan('backup:drill')->assertExitCode(0);

        $this->assertCount(1, $this->commandsMatching('CREATE DATABASE'));
        $this->assertCount(1, $this->commandsMatching('DROP DATABASE'));
    }

    // ------------------------------------------------- the safety argument

    #[Test]
    public function the_drill_cannot_be_pointed_at_the_live_database(): void
    {
        // There is no --database option, so the only route to the live schema is
        // a configuration in which the drill's own prefix matches it. That
        // configuration refuses to drill AT ALL rather than proceeding carefully,
        // because a DROP of `<prefix>%` could not then be told apart from
        // dropping production.
        $this->writeSet();
        config(['backup.drill.scratch_prefix' => 'manara_']);

        $this->fakeMysql();
        Process::preventStrayProcesses();

        $this->artisan('backup:drill')->assertExitCode(2);

        $this->assertSame([], $this->ran, 'not one statement may be sent under an unsafe prefix');
    }

    #[Test]
    public function no_statement_the_drill_sends_names_the_live_database(): void
    {
        $this->writeSet();
        $this->fakeMysql();

        $this->artisan('backup:drill')->assertExitCode(0);

        $this->assertNotSame([], $this->ran);

        foreach ($this->ran as $command) {
            // The credentials file carries host, port, user and password; the
            // database name is never in it and never on a command line, so the
            // live schema's name must appear nowhere in what was run.
            $this->assertStringNotContainsString('manara_production', $command);
        }
    }

    #[Test]
    public function a_dump_that_names_a_database_is_refused_before_a_byte_reaches_the_client(): void
    {
        // A dump taken with --databases carries `USE `live`;`, which MOVES THE
        // CLIENT out of the scratch schema and into the live one — so restoring
        // it here would overwrite production with a month-old copy of itself.
        // `backup:run` never writes such a dump; a set on disk may have come
        // from anywhere.
        $this->writeSet(extraSql: "USE `manara_production`;\n");
        $this->fakeMysql();

        $this->artisan('backup:drill')->assertExitCode(2);

        $this->assertSame([], $this->commandsMatching('CREATE DATABASE'));
        $this->assertSame([], $this->commandsMatching('--database=manara_drill_'));
    }

    #[Test]
    public function a_dump_that_creates_a_database_is_refused_too(): void
    {
        $this->writeSet(extraSql: "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `manara_production`;\n");
        $this->fakeMysql();

        $this->artisan('backup:drill')->assertExitCode(2);

        $this->assertSame([], $this->commandsMatching('CREATE DATABASE `manara_drill_'));
    }

    // ------------------------------------------------------ no leaked schemas

    #[Test]
    public function the_drill_leaves_no_scratch_database_behind_on_success(): void
    {
        $this->writeSet();
        $this->fakeMysql();

        $this->artisan('backup:drill')->assertExitCode(0);

        $created = $this->commandsMatching('CREATE DATABASE');
        $dropped = $this->commandsMatching('DROP DATABASE');

        $this->assertCount(1, $created);
        $this->assertCount(1, $dropped);

        preg_match('/CREATE DATABASE `([a-z0-9_]+)`/', $created[0], $match);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `'.$match[1].'`', $dropped[0]);
    }

    #[Test]
    public function the_drill_leaves_no_scratch_database_behind_when_the_restore_fails(): void
    {
        // The path that actually matters. A drill that leaks a schema on failure
        // fills the managed instance with full, unguarded copies of the
        // production database — every one of them holding children's records —
        // which is a worse outcome than never drilling.
        $this->writeSet();
        $this->fakeMysql(overrides: ['--default-character-set=utf8mb4' => Process::result('', 'ERROR 1064 at line 812', 1)]);

        $this->artisan('backup:drill')->assertExitCode(1);

        $this->assertCount(1, $this->commandsMatching('CREATE DATABASE'));
        $this->assertCount(1, $this->commandsMatching('DROP DATABASE'));
    }

    #[Test]
    public function the_drill_sweeps_a_scratch_schema_a_killed_run_left_behind(): void
    {
        // `finally` and the shutdown hook cannot survive a SIGKILL or a power
        // loss, so the guarantee that no scratch schema outlives a drill is
        // carried by the NEXT drill.
        $this->writeSet();
        $this->fakeMysql(existingScratchSchemas: ['manara_drill_20260901_041500_abc123']);

        $this->artisan('backup:drill')->assertExitCode(0);

        $dropped = $this->commandsMatching('DROP DATABASE');

        $this->assertCount(2, $dropped, 'the leftover and this run\'s own scratch schema');
        $this->assertStringContainsString('manara_drill_20260901_041500_abc123', $dropped[0]);
    }

    #[Test]
    public function the_sweep_will_not_drop_a_schema_that_does_not_match_the_prefix(): void
    {
        // `SHOW DATABASES LIKE` output is input, and input about to be
        // interpolated into a DROP is not trusted because of where it came from.
        $this->writeSet();
        $this->fakeMysql(existingScratchSchemas: ['manara_production', 'mysql', 'manara_drill_ok_aaaaaa']);

        $this->artisan('backup:drill')->assertExitCode(0);

        foreach ($this->commandsMatching('DROP DATABASE') as $command) {
            $this->assertStringContainsString('DROP DATABASE IF EXISTS `manara_drill_', $command);
        }

        $this->assertSame([], $this->commandsMatching('DROP DATABASE IF EXISTS `manara_production`'));
    }

    // --------------------------------------------------- what the drill grades

    #[Test]
    public function a_restore_that_produces_almost_no_tables_is_a_failed_drill(): void
    {
        // The client exited 0 and almost nothing arrived. A surface announcing
        // success about something it never read back is the recurring defect in
        // this codebase.
        $this->writeSet();
        $this->fakeMysql(overrides: ['SHOW TABLES' => Process::result("media\nusers", '', 0)]);

        $this->artisan('backup:drill')->assertExitCode(1);
        $this->assertCount(1, $this->commandsMatching('DROP DATABASE'));
    }

    #[Test]
    public function a_restore_whose_media_rows_have_no_files_in_the_archive_is_a_failed_drill(): void
    {
        // The 2026-08-17 outage in a box: rows that come back pointing at files
        // the set never held. The manifest says one row with its file; the
        // restore produces a row whose file is not in the archive.
        $this->writeSet();
        $this->fakeMysql(overrides: ['FROM media' => Process::result("9\tvanished.jpg", '', 0)]);

        $this->artisan('backup:drill')->assertExitCode(1);
        $this->assertCount(1, $this->commandsMatching('DROP DATABASE'));
    }

    #[Test]
    public function a_set_that_does_not_verify_is_never_restored_at_all(): void
    {
        $set = $this->writeSet();
        $this->fakeMysql();
        Process::preventStrayProcesses();

        unlink($set->mediaPath());

        $this->artisan('backup:drill')->assertExitCode(1);

        $this->assertSame([], $this->ran, 'a set that is not a set is not worth a scratch schema');
    }

    #[Test]
    public function no_set_at_all_is_blocked_rather_than_failed(): void
    {
        // "The restore is broken" and "there is nothing to restore" are
        // different facts and want different sentences, even though both page.
        $this->fakeMysql();
        Process::preventStrayProcesses();

        $this->artisan('backup:drill')->assertExitCode(2);

        $this->assertSame([], $this->ran);
    }

    #[Test]
    public function a_scratch_schema_that_cannot_be_created_is_blocked_and_names_the_grant(): void
    {
        $this->writeSet();
        $this->fakeMysql(overrides: ['CREATE DATABASE' => Process::result('', 'ERROR 1044 (42000): Access denied for user', 1)]);

        $this->artisan('backup:drill')
            ->expectsOutputToContain('GRANT ALL PRIVILEGES')
            ->assertExitCode(2);
    }
}
