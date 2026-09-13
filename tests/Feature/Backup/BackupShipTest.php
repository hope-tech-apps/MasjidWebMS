<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `backup:ship` — one verified set to the off-site store, BOTH HALVES OR NOTHING.
 *
 * The failure being designed against is not "the upload broke". It is a bucket
 * containing a database and no media: the exact artefact of 2026-08-17, where
 * four backups could restore 226 rows and not one held a file, and restoring any
 * of them RE-CREATES the outage. Reproduced off-site, that artefact is worse,
 * because the bucket is what somebody reaches for during the worst hour of the
 * platform's life and nobody audits what they find there.
 *
 * NOTHING HERE TOUCHES A NETWORK. `Http::preventStrayRequests()` is on for every
 * test, so a request that escapes the fake fails the suite rather than leaving
 * the machine. The sets are real, written by `backup:run` with only `mysqldump`
 * faked, so `gzip` and `tar` produce genuine archives.
 */
class BackupShipTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private string $mediaRoot;

    private string $destination;

    /** @var list<array{method: string, url: string, content_type: list<string>}> */
    private array $sent = [];

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

        $this->base = sys_get_temp_dir().'/manara-ship-'.bin2hex(random_bytes(6));
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
            'backup.check.log_channel' => 'null',

            'backup.offsite.enabled' => false,

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

    private function configureOffsite(): void
    {
        config([
            'backup.offsite.enabled' => true,
            'backup.offsite.bucket' => 'manara-backups',
            'backup.offsite.region' => 'nyc3',
            'backup.offsite.endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'backup.offsite.prefix' => 'manara',
            'backup.offsite.key' => 'DO00TESTKEY',
            'backup.offsite.secret' => 'test-secret',
        ]);
    }

    /**
     * A store that accepts everything and remembers what it holds, so a HEAD
     * after a PUT answers the way a real one would.
     *
     * @param  array<string, int>  $rejectPuts  object file name => status to answer with
     */
    private function fakeStore(array $rejectPuts = []): void
    {
        $held = [];
        $this->sent = [];

        Http::fake(function ($request) use (&$held, $rejectPuts) {
            $url = $request->url();
            $file = basename((string) parse_url($url, PHP_URL_PATH));
            $method = strtoupper($request->method());

            $this->sent[] = ['method' => $method, 'url' => $url, 'content_type' => $this->contentTypesOf($request)];

            if ($method === 'PUT') {
                if (isset($rejectPuts[$file])) {
                    return Http::response('<Error><Code>InternalError</Code></Error>', $rejectPuts[$file]);
                }

                $held[$url] = strlen((string) $request->body());

                return Http::response('', 200);
            }

            if ($method === 'DELETE') {
                unset($held[$url]);

                return Http::response('', 204);
            }

            return isset($held[$url])
                ? Http::response('', 200, ['Content-Length' => (string) $held[$url]])
                : Http::response('', 404);
        });
    }

    /**
     * Every value under a `content-type` header, whatever case it was spelled
     * in. PSR-7 combines two case-variant spellings of one header rather than
     * replacing, so this returns more than one entry exactly when that has
     * happened.
     *
     * @return list<string>
     */
    private function contentTypesOf($request): array
    {
        $values = [];

        foreach ($request->headers() as $name => $value) {
            if (strtolower((string) $name) === 'content-type') {
                $values = array_merge($values, array_map('strval', (array) $value));
            }
        }

        return array_values($values);
    }

    /** @return list<string> */
    private function puts(): array
    {
        return array_values(array_map(
            fn (array $row) => basename((string) parse_url($row['url'], PHP_URL_PATH)),
            array_filter($this->sent, fn (array $row) => $row['method'] === 'PUT'),
        ));
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
    public function an_unconfigured_offsite_ships_nothing_says_so_and_does_not_throw(): void
    {
        // The state of this platform today, and the one this must handle without
        // drama: no credentials, so a clean no-op with a sentence naming the
        // variables that turn it on.
        $this->writeSet();

        $this->artisan('backup:ship')
            ->expectsOutputToContain('BACKUP_OFFSITE_ENABLED')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_refuses_a_half_set_and_uploads_nothing_at_all(): void
    {
        // Shipping the database and losing the media is the exact shape of the
        // 2026-08-17 outage. A set missing a half never reaches the wire.
        $set = $this->writeSet();
        $this->configureOffsite();
        $this->fakeStore();

        unlink($set->mediaPath());

        $this->artisan('backup:ship')->assertExitCode(1);

        $this->assertSame([], $this->puts(), 'not one byte of a half set may be uploaded');
    }

    #[Test]
    public function it_refuses_a_set_whose_half_no_longer_matches_its_checksum(): void
    {
        $set = $this->writeSet();
        $this->configureOffsite();
        $this->fakeStore();

        file_put_contents($set->databasePath(), 'tampered', FILE_APPEND);

        $this->artisan('backup:ship')->assertExitCode(1);

        $this->assertSame([], $this->puts());
    }

    #[Test]
    public function it_uploads_both_halves_and_writes_the_manifest_last(): void
    {
        // THE ATOMICITY. An object store has no transaction, so the manifest
        // going last is the whole of it: `BackupSet::all()` skips a directory
        // with no manifest, which makes a remote prefix holding one half the
        // bucket's spelling of a local `*.partial` — litter, not a backup.
        $this->writeSet();
        $this->configureOffsite();
        $this->fakeStore();

        $this->artisan('backup:ship')->assertExitCode(0);

        $this->assertSame(
            [BackupSet::DATABASE_FILE, BackupSet::MEDIA_FILE, BackupSet::MANIFEST_FILE],
            $this->puts(),
        );
    }

    #[Test]
    public function every_upload_carries_the_sha256_the_manifest_recorded(): void
    {
        // The store recomputes it and rejects a body truncated in flight, so the
        // number that proves a local set is intact is the same number that gates
        // the upload. There is no second definition of "the right bytes".
        $set = $this->writeSet();
        $this->configureOffsite();
        $this->fakeStore();

        $this->artisan('backup:ship')->assertExitCode(0);

        $expected = (array) $set->manifest();

        Http::assertSent(function ($request) use ($expected) {
            return str_ends_with($request->url(), '/'.BackupSet::DATABASE_FILE)
                && strtoupper($request->method()) === 'PUT'
                && $request->header('x-amz-content-sha256')[0] === $expected['halves']['database']['sha256'];
        });
    }

    #[Test]
    public function an_upload_carries_exactly_one_content_type_and_it_is_the_one_that_was_signed(): void
    {
        // A bug with no symptom except silence. `Http::withBody()` sets a
        // `Content-Type` of its own and Laravel MERGES header arrays, so sending
        // our signed lowercase `content-type` alongside it leaves PSR-7
        // combining the two into `application/gzip, application/gzip` — while
        // the signature covers the single value. Every upload would be rejected
        // as a bad signature, at 02:41, into a log nobody reads, and the store
        // would simply never have a backup in it.
        $this->writeSet();
        $this->configureOffsite();
        $this->fakeStore();

        $this->artisan('backup:ship')->assertExitCode(0);

        $puts = array_values(array_filter($this->sent, fn (array $row) => $row['method'] === 'PUT'));

        $this->assertCount(3, $puts);

        foreach ($puts as $put) {
            $this->assertCount(1, $put['content_type'], 'a header spelled twice is a signature that cannot match');
            $this->assertContains($put['content_type'][0], ['application/gzip', 'application/json']);
        }
    }

    #[Test]
    public function a_failed_media_upload_takes_back_the_database_half_and_never_writes_a_manifest(): void
    {
        // The one window in which a remote set could read as complete while
        // holding half of itself. It must close by removing what went up, and
        // above all by never putting the manifest there.
        $this->writeSet();
        $this->configureOffsite();
        $this->fakeStore(rejectPuts: [BackupSet::MEDIA_FILE => 500]);

        $this->artisan('backup:ship')->assertExitCode(1);

        $this->assertSame([BackupSet::DATABASE_FILE, BackupSet::MEDIA_FILE], $this->puts());
        $this->assertNotContains(BackupSet::MANIFEST_FILE, $this->puts(), 'a manifest must never follow a failed half');

        $deleted = array_values(array_filter($this->sent, fn (array $row) => $row['method'] === 'DELETE'));
        $this->assertCount(1, $deleted);
        $this->assertStringEndsWith('/'.BackupSet::DATABASE_FILE, $deleted[0]['url']);
    }

    #[Test]
    public function a_store_that_accepts_an_upload_and_then_does_not_have_it_is_a_failure(): void
    {
        // A 200 is the uploader's own report. The recurring defect in this
        // codebase is a surface announcing success about something it never read
        // back, so every half is HEADed from the store's side before the
        // manifest follows.
        $this->writeSet();
        $this->configureOffsite();

        $this->sent = [];

        Http::fake(function ($request) {
            $this->sent[] = ['method' => strtoupper($request->method()), 'url' => $request->url(), 'content_type' => $this->contentTypesOf($request)];

            // Accepts everything, holds nothing.
            return strtoupper($request->method()) === 'PUT'
                ? Http::response('', 200)
                : Http::response('', 404);
        });

        $this->artisan('backup:ship')->assertExitCode(1);

        $this->assertNotContains(BackupSet::MANIFEST_FILE, $this->puts());
    }

    #[Test]
    public function a_set_that_is_already_off_site_in_one_piece_is_not_uploaded_again(): void
    {
        $this->writeSet();
        $this->configureOffsite();
        $this->fakeStore();

        $this->artisan('backup:ship')->assertExitCode(0);
        $this->assertCount(3, $this->puts());

        $this->sent = [];
        $this->artisan('backup:ship')->assertExitCode(0);
        $this->assertSame([], $this->puts(), 'shipping twice must be free, so an operator can loop over the whole destination');
    }

    #[Test]
    public function a_dry_run_sends_nothing(): void
    {
        $this->writeSet();
        $this->configureOffsite();
        $this->fakeStore();

        $this->artisan('backup:ship', ['--dry-run' => true])->assertExitCode(0);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_failing_store_never_fails_the_backup_that_already_succeeded(): void
    {
        // `backup:run` ships the set it has just written. A shipper able to turn
        // a verified backup into a failed run is a shipper somebody eventually
        // switches off, and the local backup goes with it.
        $this->configureOffsite();

        Http::fake(fn () => Http::response('<Error><Code>AccessDenied</Code></Error>', 403));

        $set = $this->writeSet();

        $this->assertSame([], $set->problems(), 'the local set is complete and verified whatever the store said');
    }
}
