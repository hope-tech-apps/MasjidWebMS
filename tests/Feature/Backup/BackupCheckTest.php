<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `backup:check` — the thing that was missing.
 *
 * On 2026-09-12 this platform was found never to have taken a backup. Not a
 * stale one: none, ever. `backup:run` had been scheduled nightly for weeks,
 * /var/backups/manara did not exist, and the command refused correctly every
 * single night into `>> /dev/null 2>&1`. Every check worked. Nobody was told.
 *
 * Each test below is one of the ways that silence can happen again.
 *
 * The sets these tests examine are REAL: they are written by `backup:run` with
 * only `mysqldump` faked, so `gzip` and `tar` produce genuine archives and the
 * verification the check performs is genuine verification. `Http` is faked and
 * stray requests are prevented throughout — nothing here touches a network or a
 * bucket.
 */
class BackupCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private string $mediaRoot;

    private string $destination;

    private string $heartbeat;

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
            $this->markTestSkipped('tar and gzip are required: these tests build real archives.');
        }

        $this->base = sys_get_temp_dir().'/manara-check-'.bin2hex(random_bytes(6));
        $this->mediaRoot = $this->base.'/media';
        $this->destination = $this->base.'/backups';
        $this->heartbeat = $this->base.'/state/last-run.json';

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

            'backup.check.log_channel' => 'null',
            'backup.check.max_age_hours' => 36,
            'backup.check.heartbeat_path' => $this->heartbeat,
            'backup.check.heartbeat_url' => null,
            'backup.check.self_gap_hours' => 36,

            'backup.offsite.enabled' => false,
            'backup.offsite.required' => false,

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

        // No test in this file may reach a network or a real bucket.
        Http::preventStrayRequests();
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

    private function plausibleDump(): string
    {
        $sql = "-- MySQL dump 10.19\nCREATE TABLE `media` (`id` bigint unsigned NOT NULL);\n";

        for ($i = 0; $i < 400; $i++) {
            $sql .= sprintf("INSERT INTO `media` VALUES (%d,'%s');\n", $i, bin2hex(random_bytes(32)));
        }

        return $sql."-- Dump completed on 2026-09-12  2:40:00\n";
    }

    /** A genuine set, written by the real command with only mysqldump faked. */
    private function writeSet(): BackupSet
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
        ]);

        $this->artisan('backup:run')->assertExitCode(0);

        return BackupSet::latest($this->destination);
    }

    /** Re-date a set's manifest. The manifest is not itself checksummed, so this is the honest way to age one. */
    private function age(BackupSet $set, string $iso): void
    {
        $manifest = (array) $set->manifest();
        $manifest['created_at'] = $iso;

        file_put_contents($set->manifestPath(), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
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

    // ------------------------------------------------------------------ tests

    #[Test]
    public function a_recent_verified_set_passes(): void
    {
        $this->writeSet();

        $this->artisan('backup:check')->assertExitCode(0);
    }

    #[Test]
    public function a_destination_that_does_not_exist_fails_the_check(): void
    {
        // THE ACTUAL INCIDENT. /var/backups/manara was never created, so every
        // nightly run refused into /dev/null for weeks and this platform had no
        // backup at all. This is the test that would have caught it on night one.
        $this->deleteTree($this->destination);

        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:check')
            ->expectsOutputToContain('sudo bin/backup --install')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_destination_holding_no_set_at_all_fails_the_check(): void
    {
        // Distinct from "the newest set is old": nothing has ever succeeded here.
        Process::fake();
        Process::preventStrayProcesses();

        $this->artisan('backup:check')
            ->expectsOutputToContain('NOTHING CAN BE RESTORED')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_set_older_than_the_threshold_fails_the_check(): void
    {
        $set = $this->writeSet();
        $this->age($set, now()->utc()->subHours(40)->toIso8601String());

        $this->artisan('backup:check')->assertExitCode(1);
    }

    #[Test]
    public function a_set_one_late_night_old_still_passes(): void
    {
        // 36 hours and not 24, deliberately: a nightly run at 02:40 against a
        // 24-hour bar pages whenever cron is late, and an alarm that fires on an
        // ordinary Tuesday is an alarm that gets silenced.
        $set = $this->writeSet();
        $this->age($set, now()->utc()->subHours(30)->toIso8601String());

        $this->artisan('backup:check')->assertExitCode(0);
    }

    #[Test]
    public function a_set_whose_bytes_changed_after_it_was_verified_fails_the_check(): void
    {
        $set = $this->writeSet();

        file_put_contents($set->mediaPath(), 'x', FILE_APPEND);

        $this->artisan('backup:check')->assertExitCode(1);
    }

    #[Test]
    public function a_set_that_is_intact_on_paper_and_empty_inside_fails_the_check(): void
    {
        // The 20-byte file in /root/backups is a VALID gzip stream that
        // decompresses to zero and `gzip -t` calls it healthy. A check that
        // only compared sizes and checksums against the manifest would pass this
        // set forever, because the manifest here is perfectly consistent with
        // the bytes. This is why `backup:check` re-runs the inside-the-bytes
        // checks `backup:run` applies, out of the same shared code.
        $set = $this->writeSet();

        $hollow = gzencode('');
        file_put_contents($set->databasePath(), $hollow);

        $manifest = (array) $set->manifest();
        $manifest['halves']['database']['bytes'] = strlen($hollow);
        $manifest['halves']['database']['sha256'] = hash('sha256', $hollow);
        file_put_contents($set->manifestPath(), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->assertSame([], $set->problems(), 'the manifest and the bytes agree — only opening the file catches this');

        $this->artisan('backup:check')->assertExitCode(1);
    }

    #[Test]
    public function an_unconfigured_offsite_no_ops_and_says_so_rather_than_throwing(): void
    {
        // The state of this platform today. It must be a clean pass that STATES
        // the gap in a sentence an operator does not have to read code to
        // understand — not an error (which would burn every night and get
        // silenced) and not a silence.
        $this->writeSet();

        $this->artisan('backup:check')
            ->expectsOutputToContain('off-site: NONE CONFIGURED')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    #[Test]
    public function an_offsite_that_is_configured_and_required_but_empty_fails_the_check(): void
    {
        $this->writeSet();
        $this->configureOffsite();

        // The store answers "no such object" for everything.
        Http::fake(fn () => Http::response('<Error><Code>NoSuchKey</Code></Error>', 404));

        $this->artisan('backup:check')->assertExitCode(1);
    }

    #[Test]
    public function an_offsite_that_cannot_be_reached_is_graded_the_same_as_one_that_is_empty(): void
    {
        // "I could not confirm there is an off-site copy" is operationally the
        // same position as not having one — the rule BackupRun already follows
        // when it refuses to write a completeness claim nobody could check.
        $this->writeSet();
        $this->configureOffsite();

        Http::fake(fn () => Http::response('gateway is unwell', 503));

        $this->artisan('backup:check')->assertExitCode(1);
    }

    #[Test]
    public function a_set_that_is_off_site_in_one_piece_passes(): void
    {
        $set = $this->writeSet();
        $this->configureOffsite();

        $sizes = [
            BackupSet::DATABASE_FILE => filesize($set->databasePath()),
            BackupSet::MEDIA_FILE => filesize($set->mediaPath()),
            BackupSet::MANIFEST_FILE => filesize($set->manifestPath()),
        ];

        Http::fake(function ($request) use ($sizes) {
            foreach ($sizes as $file => $bytes) {
                if (str_ends_with($request->url(), '/'.$file)) {
                    return Http::response('', 200, ['Content-Length' => (string) $bytes]);
                }
            }

            return Http::response('', 404);
        });

        $this->artisan('backup:check')->assertExitCode(0);
    }

    #[Test]
    public function a_crashed_run_left_behind_is_a_ticket_and_not_a_page(): void
    {
        // A `*.partial` is litter that nothing will ever restore. It must be
        // visible and it must not ring at the volume of a platform with no
        // backups, or the volume stops meaning anything.
        $this->writeSet();

        $partial = $this->destination.'/20260901-024000.partial';
        mkdir($partial, 0777, true);
        touch($partial, time() - 172800);

        $this->artisan('backup:check')->assertExitCode(3);
    }

    #[Test]
    public function it_records_its_own_run_so_a_checker_that_went_dark_can_be_seen(): void
    {
        // A checker whose own failure is invisible reproduces the bug it exists
        // to catch. This is the in-band half of that: the run writes its own
        // history and reads it back.
        $this->writeSet();

        $this->artisan('backup:check')->assertExitCode(0);

        $this->assertFileExists($this->heartbeat);
        $this->assertNotNull(json_decode((string) file_get_contents($this->heartbeat), true)['ran_at'] ?? null);
    }

    #[Test]
    public function a_checker_that_had_not_run_for_a_week_says_so_when_it_wakes(): void
    {
        $this->writeSet();

        mkdir(dirname($this->heartbeat), 0777, true);
        file_put_contents($this->heartbeat, json_encode([
            'ran_at' => now()->utc()->subDays(7)->toIso8601String(),
            'status' => 'pass',
        ]));

        $this->artisan('backup:check')
            ->expectsOutputToContain('nothing was watching the backups')
            ->assertExitCode(3);
    }

    #[Test]
    public function a_first_run_with_no_history_is_not_graded_as_a_problem(): void
    {
        // The heartbeat is absent on the first run after this command exists and
        // again after any deploy that lands a fresh tree. Grading that would put
        // an amber on an ordinary release.
        $this->writeSet();
        $this->assertFileDoesNotExist($this->heartbeat);

        $this->artisan('backup:check')->assertExitCode(0);
    }

    #[Test]
    public function the_dead_mans_switch_is_pinged_only_after_a_clean_run(): void
    {
        // The one defence that survives this host going away: an outside
        // observer that alerts when it is NOT called. A failing check must be
        // indistinguishable from a dead one, so a failure never pings.
        config(['backup.check.heartbeat_url' => 'https://hc.example.test/ping/abc']);

        Http::fake(['hc.example.test/*' => Http::response('OK', 200)]);

        // No stray-process guard here on purpose: `writeSet()` below needs the
        // real gzip and tar, and the guard would still be armed when it ran.
        // `backup:check` runs no child processes at all.
        // Deliberately run BEFORE any set exists and again after one is written,
        // in the same process: that ordering is what caught PHP's cached
        // negative stat hiding a real set from BackupSet::all().
        $this->artisan('backup:check')->assertExitCode(1);
        Http::assertNothingSent();

        $this->writeSet();
        $this->artisan('backup:check')->assertExitCode(0);
        Http::assertSent(fn ($request) => $request->url() === 'https://hc.example.test/ping/abc');
    }

    private function configureOffsite(): void
    {
        config([
            'backup.offsite.enabled' => true,
            'backup.offsite.required' => true,
            'backup.offsite.bucket' => 'manara-backups',
            'backup.offsite.region' => 'nyc3',
            'backup.offsite.endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'backup.offsite.prefix' => 'manara',
            'backup.offsite.key' => 'DO00TESTKEY',
            'backup.offsite.secret' => 'test-secret',
        ]);
    }
}
