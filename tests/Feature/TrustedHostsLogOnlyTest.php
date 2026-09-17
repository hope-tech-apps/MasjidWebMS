<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The mode App\Http\Middleware\TrustedHosts SHIPS in: observe, never refuse.
 *
 * The owner's decision (2026-09-17) was "ship log-only": fix the URL poisoning
 * now, log unknown hosts without blocking them, and decide on enforcement
 * separately after reading the log. Three things have to be true for that to
 * be what production actually gets, and TrustedHostsMiddlewareTest proves none
 * of them — it sets `enforce` to true in its setUp and to false by hand where it
 * needs to:
 *
 *   1. the DEFAULT, with no TRUSTED_HOSTS_* variable in the environment at all
 *      (production's .env has none), is log-only;
 *   2. in that mode a forged Host is served AND poisons nothing — the request
 *      goes through, so the payload builders are the only protection left;
 *   3. the log line is written at a level production keeps. Production runs
 *      LOG_LEVEL=warning; a line below that is discarded before it reaches
 *      laravel.log, and an observing mode that records nothing looks exactly
 *      like one that has nothing to record.
 *
 * Plus the promises that make log-only safe to ship: the report path cannot
 * itself fail the request, and a client inventing Host names cannot make it
 * write without limit.
 */
class TrustedHostsLogOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const ENV_KEYS = ['TRUSTED_HOSTS', 'TRUSTED_HOSTS_ENFORCE', 'TRUSTED_HOSTS_LOG_INTERVAL', 'TRUSTED_HOSTS_LOG_BUDGET'];

    private const PROBE = '/api/__trusted-hosts-probe';

    /** @var array<int, string> */
    private array $logFiles = [];

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

        config([
            'app.url' => 'https://masjid.test',
            'portal.hosts' => ['portal.school.test' => 14],
        ]);

        // The report path writes its rate-limit marker through the cache.
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->logFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * config/trusted_hosts.php evaluated against an environment holding exactly
     * `$env` for the TRUSTED_HOSTS_* keys — nothing inherited from the box the
     * suite happens to run on. The real environment is put back afterwards.
     *
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function trustedHostsConfigWith(array $env = []): array
    {
        $saved = [];

        foreach (self::ENV_KEYS as $key) {
            $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        foreach ($env as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            return require base_path('config/trusted_hosts.php');
        } finally {
            foreach ($saved as $key => [$getenv, $envConst, $serverConst]) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                if ($getenv !== false) {
                    putenv("{$key}={$getenv}");
                }
                if ($envConst !== null) {
                    $_ENV[$key] = $envConst;
                }
                if ($serverConst !== null) {
                    $_SERVER[$key] = $serverConst;
                }
            }
        }
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Org '.uniqid(),
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    /** A single-file log channel at `$level`, made the default. Returns its path. */
    private function logToFileAt(string $level): string
    {
        $path = sys_get_temp_dir().'/trusted-hosts-'.$level.'-'.uniqid('', true).'.log';
        $this->logFiles[] = $path;
        $channel = 'trusted_hosts_probe_'.str_replace('.', '', uniqid('', true));

        config([
            "logging.channels.{$channel}" => [
                'driver' => 'single',
                'path' => $path,
                'level' => $level,
                'replace_placeholders' => true,
            ],
            'logging.default' => $channel,
        ]);

        return $path;
    }

    /**
     * Make the database store the default, as production has it
     * (CACHE_STORE=database), backed by `$table` on the in-memory connection.
     */
    private function useDatabaseCache(string $table = 'cache'): void
    {
        config([
            'cache.default' => 'database',
            'cache.stores.database' => [
                'driver' => 'database',
                'connection' => null,
                'table' => $table,
                'lock_connection' => null,
                'lock_table' => 'cache_locks',
            ],
        ]);
    }

    /** A route that runs only the global middleware stack. */
    private function registerProbe(): void
    {
        // Under /api/ so the SPA catch-all in routes/web.php does not take it,
        // and with no middleware group, so nothing but the global stack runs.
        Route::get(self::PROBE, fn () => response()->json(['ok' => true]));
    }

    /**
     * Record every warning instead of writing it.
     *
     * @param  array<int, array{0: string, 1: array<string, mixed>}>  $lines
     */
    private function captureWarnings(array &$lines): void
    {
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$lines): void {
            $lines[] = [$message, $context];
        });
    }

    /**
     * @param  array<int, array{0: string, 1: array<string, mixed>}>  $lines
     * @return array<int, string>
     */
    private function hostsNamed(array $lines): array
    {
        return array_values(array_map(
            static fn (array $line): string => (string) $line[1]['host'],
            array_filter($lines, static fn (array $line): bool => str_contains($line[0], 'Host header this deployment does not serve')),
        ));
    }

    /** @param  array<int, array{0: string, 1: array<string, mixed>}>  $lines */
    private function pausedLines(array $lines): int
    {
        return count(array_filter($lines, static fn (array $line): bool => str_starts_with($line[0], 'Unknown-Host logging paused')));
    }

    #[Test]
    public function the_shipped_default_is_log_only(): void
    {
        $defaults = $this->trustedHostsConfigWith([]);

        $this->assertFalse($defaults['enforce'], 'with no TRUSTED_HOSTS_ENFORCE in the environment the middleware must only observe');
        $this->assertSame([], $defaults['extra']);
        $this->assertSame(3600, $defaults['log_interval']);
        $this->assertSame(200, $defaults['log_budget']);

        // The control: the same file DOES read the environment, so the
        // assertions above are about the default and not about a constant.
        $configured = $this->trustedHostsConfigWith([
            'TRUSTED_HOSTS' => ' manara.example , other.example ,',
            'TRUSTED_HOSTS_ENFORCE' => 'true',
        ]);

        $this->assertTrue($configured['enforce']);
        $this->assertSame(['manara.example', 'other.example'], $configured['extra']);
    }

    #[Test]
    public function the_example_env_does_not_switch_enforcement_on(): void
    {
        // .env.example is what a new box is built from. Enforcement is a
        // separate owner decision, so a copied example must not make it.
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertSame(1, preg_match('/^TRUSTED_HOSTS_ENFORCE=(.*)$/m', $example, $m), '.env.example no longer names TRUSTED_HOSTS_ENFORCE');
        $this->assertSame('false', trim($m[1]));
    }

    #[Test]
    public function with_the_shipped_defaults_a_forged_host_is_served_and_poisons_nothing(): void
    {
        config(['trusted_hosts' => $this->trustedHostsConfigWith([])]);
        Log::spy();

        $masjid = $this->makeMasjid();

        // No image row: the placeholder path, which is the reachable one.
        Announcement::create([
            'masjid_id' => $masjid->id,
            'title' => 'Imageless notice',
            'summary' => 'summary',
            'details' => 'details',
            'text' => 'text',
            'start_date' => '2026-08-28',
            'end_date' => '2026-09-28',
        ]);
        MobileCache::flushMasjid($masjid->id, MobileCache::ANNOUNCEMENTS);

        // Served — log-only means log-only. The absolute URI is what actually
        // delivers the forged host (see TrustedHostsMiddlewareTest::asHost).
        $this->getJson("https://evil.example/api/mobile/masjids/{$masjid->id}/announcements")
            ->assertOk();
        $this->assertSame('evil.example', app('request')->getHost(), 'the request did not arrive on the forged host');

        $entry = Cache::get(MobileCache::masjidKey($masjid->id, MobileCache::ANNOUNCEMENTS));
        $this->assertNotNull($entry, 'the forged request should still have warmed the cache — it was admitted');

        $stored = str_replace('\/', '/', (string) json_encode($entry));
        $this->assertStringContainsString('https://masjid.test/mobile-assets/placeholder', $stored);
        $this->assertStringNotContainsString('evil.example', $stored, 'an admitted forged Host reached the shared cache entry');

        // The delete-account form, the page a member types an address into.
        $html = $this->get('https://evil.example/account-deletion')->assertOk()->getContent();
        $this->assertStringContainsString('action="https://masjid.test/account-deletion"', $html);
        $this->assertStringNotContainsString('evil.example', $html);

        // Seen, and said so: one line for the host, not one per request.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Host header this deployment does not serve')
                && ($context['host'] ?? null) === 'evil.example'
                && ($context['enforced'] ?? null) === false);
    }

    #[Test]
    public function the_warning_is_written_at_the_level_production_keeps(): void
    {
        config(['trusted_hosts.enforce' => false, 'trusted_hosts.log_interval' => 3600]);

        // Production: LOG_CHANNEL=stack, LOG_STACK=single, LOG_LEVEL=warning.
        $kept = $this->logToFileAt('warning');

        $this->get('https://unknown.example/account-deletion')->assertOk();

        $this->assertFileExists($kept, 'nothing was written to a warning-level log');
        $line = (string) file_get_contents($kept);
        $this->assertStringContainsString('.WARNING: Request carried a Host header this deployment does not serve.', $line);
        $this->assertStringContainsString('"host":"unknown.example"', $line);
        $this->assertStringContainsString('"enforced":false', $line);

        // The control: the level filter is live in this harness, so the line
        // above landed because of its level and not because every line lands.
        // A channel one step stricter than production keeps nothing.
        $stricter = $this->logToFileAt('error');

        $this->get('https://another-unknown.example/account-deletion')->assertOk();

        $this->assertFalse(
            is_file($stricter) && str_contains((string) file_get_contents($stricter), 'another-unknown.example'),
            'an error-level channel kept a warning — the level assertion above proves nothing'
        );
    }

    #[Test]
    public function a_cache_failure_while_reporting_does_not_fail_the_request(): void
    {
        // The rate-limit marker is a convenience. In observing mode the
        // middleware has promised to pass the request; a cache that throws must
        // not turn that promise into a 500 on every request with an unlisted
        // Host — which, until TRUSTED_HOSTS is set, includes a hostname we
        // serve on purpose.
        //
        // Production's store (database), pointed at a table that does not
        // exist, so every cache call the middleware makes throws, in whatever
        // order it makes them.
        config(['trusted_hosts.enforce' => false, 'trusted_hosts.log_interval' => 3600]);
        $this->useDatabaseCache('no_such_cache_table');
        $this->registerProbe();

        // The control: the store really is broken.
        try {
            Cache::get('trusted-hosts-probe');
            $this->fail('the cache store answered, so this case would prove nothing');
        } catch (QueryException) {
            // expected
        }

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => ($context['host'] ?? null) === 'unlisted.example');

        $this->getJson('https://unlisted.example'.self::PROBE)
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function a_flood_of_invented_hosts_leaves_a_bounded_trace(): void
    {
        // The Host is the caller's choice. Production caches in the shared
        // MySQL, whose database store only deletes an expired row when that key
        // is read again, so a marker per host name meant one permanent row and
        // one log line for every invented name. The trace must stay bounded
        // however many names a client invents.
        config([
            'trusted_hosts.enforce' => false,
            'trusted_hosts.log_interval' => 3600,
            'trusted_hosts.log_budget' => 5,
        ]);
        $this->useDatabaseCache();
        $this->registerProbe();

        $lines = [];
        $this->captureWarnings($lines);

        foreach (range(1, 40) as $i) {
            $this->getJson("https://flood-{$i}.example".self::PROBE)->assertOk();
        }

        // Five names, then one line saying the log is now incomplete.
        $this->assertSame(
            ['flood-1.example', 'flood-2.example', 'flood-3.example', 'flood-4.example', 'flood-5.example'],
            $this->hostsNamed($lines)
        );
        $this->assertSame(1, $this->pausedLines($lines));
        $this->assertCount(6, $lines);

        $paused = array_values(array_filter($lines, static fn (array $line): bool => str_starts_with($line[0], 'Unknown-Host logging paused')))[0][1];
        $this->assertSame('flood-6.example', $paused['first_unlogged_host']);

        // Five markers and one counter, not forty rows. The control is the
        // lower bound: the rows are really in this table.
        $keys = DB::table('cache')->where('key', 'like', '%trusted-hosts%')->pluck('key')->all();
        $this->assertCount(6, $keys, 'the flood left '.count($keys).' cache rows');
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/trusted-hosts:(seen:[0-9a-f]{4}|logged)$/', $key, 'a marker key is not from the fixed key space');
        }

        // A host already named this interval stays silent.
        $this->getJson('https://flood-1.example'.self::PROBE)->assertOk();
        $this->assertCount(6, $lines);

        // A new interval: the budget is back, for new names and for old ones.
        $this->travel(3601)->seconds();

        $this->getJson('https://flood-41.example'.self::PROBE)->assertOk();
        $this->getJson('https://flood-1.example'.self::PROBE)->assertOk();

        $this->assertSame(['flood-41.example', 'flood-1.example'], array_slice($this->hostsNamed($lines), 5));
        $this->assertSame(1, $this->pausedLines($lines));
    }

    #[Test]
    public function two_hosts_that_share_a_marker_slot_are_both_logged(): void
    {
        // The marker key space is fixed, so two names can land in one slot. A
        // collision may repeat a line; it must never hide a host.
        config(['trusted_hosts.enforce' => false, 'trusted_hosts.log_interval' => 3600]);
        $this->useDatabaseCache();
        $this->registerProbe();

        $first = 'slot-a.example';
        $prefix = substr(sha1($first), 0, 4);
        $second = null;

        for ($i = 0; $i < 2_000_000 && $second === null; $i++) {
            if (substr(sha1("slot-{$i}.example"), 0, 4) === $prefix) {
                $second = "slot-{$i}.example";
            }
        }

        $this->assertNotNull($second, 'no colliding name found; the slot width changed');

        $lines = [];
        $this->captureWarnings($lines);

        $this->getJson('https://'.$first.self::PROBE)->assertOk();
        $this->getJson('https://'.$second.self::PROBE)->assertOk();
        $this->getJson('https://'.$second.self::PROBE)->assertOk();

        $this->assertSame([$first, $second], $this->hostsNamed($lines));
    }

    #[Test]
    public function a_long_invented_host_leaves_a_fixed_size_marker_and_a_capped_line(): void
    {
        // The bounds above count rows and lines. The bytes in each are the
        // caller's too: production nginx sets no large_client_header_buffers,
        // so a Host or a path of about 8 KB reaches PHP, and a marker that
        // stored the host (or a line that carried it whole) would take that
        // much per request.
        config(['trusted_hosts.enforce' => false, 'trusted_hosts.log_interval' => 3600]);
        $this->useDatabaseCache();
        Route::get('/api/__trusted-hosts-long/{rest}', fn () => response()->json(['ok' => true]))->where('rest', '.*');

        $host = str_repeat('a', 4000).'.example';
        $path = 'api/__trusted-hosts-long/'.str_repeat('p', 4000);

        // The control: Symfony accepts this name, so it reaches the report
        // path as itself rather than as a rejected Host.
        $this->assertSame($host, Request::create('https://'.$host.'/')->getHost());

        $lines = [];
        $this->captureWarnings($lines);

        $this->get('https://'.$host.'/'.$path)->assertOk();

        $this->assertCount(1, $lines);
        $context = $lines[0][1];

        $this->assertSame(substr($host, 0, 253).'...[4008 bytes]', $context['host']);
        $this->assertSame(substr($path, 0, 253).'...[4025 bytes]', $context['path']);

        $rows = DB::table('cache')->where('key', 'like', '%trusted-hosts:seen:%')->pluck('value')->all();
        $this->assertCount(1, $rows);
        $this->assertLessThan(100, strlen((string) $rows[0]), 'the marker row grows with the Host the caller chose');
        $this->assertStringNotContainsString('aaaa', (string) $rows[0]);

        // Still one line per host: the fingerprint recognises it next time.
        $this->get('https://'.$host.'/'.$path)->assertOk();
        $this->assertCount(1, $lines);
    }

    #[Test]
    public function a_log_that_cannot_be_written_does_not_fail_the_request(): void
    {
        // The same promise as the cache case, one step later. Production's
        // laravel.log is www-data-owned today, but a log file root re-created,
        // a full disk or a bad path would otherwise turn every request with an
        // unlisted Host into a 500 in a mode whose whole claim is that it
        // refuses nobody.
        config(['trusted_hosts.enforce' => false, 'trusted_hosts.log_interval' => 3600]);

        // A log path whose parent is a regular file. Nobody can create it, root
        // included (mkdir answers ENOTDIR), and the suite runs as root.
        $notADirectory = (string) tempnam(sys_get_temp_dir(), 'trusted-hosts-notadir-');
        $this->logFiles[] = $notADirectory;

        config([
            'logging.channels.trusted_hosts_unwritable' => [
                'driver' => 'single',
                'path' => $notADirectory.'/laravel.log',
                'level' => 'debug',
            ],
            'logging.default' => 'trusted_hosts_unwritable',
        ]);

        // The control: this channel really does throw when written to, so the
        // request below passes because the middleware absorbed the failure and
        // not because nothing was ever written.
        try {
            Log::warning('trusted-hosts probe: this channel must be unwritable');
            $this->fail('the log channel accepted a write, so this case would prove nothing');
        } catch (\UnexpectedValueException) {
            // expected
        }

        $this->registerProbe();

        $this->getJson('https://unlisted.example/api/__trusted-hosts-probe')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function a_listed_host_never_touches_the_report_path(): void
    {
        // The other half of the cost argument: the hosts we serve pay nothing.
        config(['trusted_hosts.enforce' => false, 'trusted_hosts.extra' => ['manara.test']]);

        $this->registerProbe();

        Cache::shouldReceive('get', 'add', 'put', 'increment')->never();
        Log::shouldReceive('warning')->never();

        foreach (['masjid.test', 'portal.school.test', 'manara.test', 'MANARA.TEST', 'manara.test.'] as $host) {
            $this->getJson('https://'.$host.self::PROBE)->assertOk();
        }
    }

    #[Test]
    public function an_ipv6_literal_is_logged_as_itself(): void
    {
        // Splitting on the first colon to drop a port turned `[2001:db8::1]`
        // into `[2001` — a log line naming a host nobody can look up.
        config(['trusted_hosts.enforce' => false]);

        $this->registerProbe();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => ($context['host'] ?? null) === '[2001:db8::1]');

        $this->getJson('https://[2001:db8::1]/api/__trusted-hosts-probe')->assertOk();
    }
}
