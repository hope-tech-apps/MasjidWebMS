<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
 * Plus the promise that makes log-only safe to ship: the report path cannot
 * itself fail the request.
 */
class TrustedHostsLogOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const ENV_KEYS = ['TRUSTED_HOSTS', 'TRUSTED_HOSTS_ENFORCE', 'TRUSTED_HOSTS_LOG_INTERVAL'];

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

    #[Test]
    public function the_shipped_default_is_log_only(): void
    {
        $defaults = $this->trustedHostsConfigWith([]);

        $this->assertFalse($defaults['enforce'], 'with no TRUSTED_HOSTS_ENFORCE in the environment the middleware must only observe');
        $this->assertSame([], $defaults['extra']);
        $this->assertSame(3600, $defaults['log_interval']);

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
        config(['trusted_hosts.enforce' => false, 'trusted_hosts.log_interval' => 3600]);

        // Under /api/ so the SPA catch-all in routes/web.php does not take it,
        // and with no middleware group, so nothing but the global stack runs
        // and the cache mock below sees only the middleware's own call.
        Route::get('/api/__trusted-hosts-probe', fn () => response()->json(['ok' => true]));

        Cache::shouldReceive('add')->once()->andThrow(new \RuntimeException('cache store unavailable'));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => ($context['host'] ?? null) === 'unlisted.example');

        $this->getJson('https://unlisted.example/api/__trusted-hosts-probe')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function a_listed_host_never_touches_the_report_path(): void
    {
        // The other half of the cost argument: the hosts we serve pay nothing.
        config(['trusted_hosts.enforce' => false, 'trusted_hosts.extra' => ['manara.test']]);

        Route::get('/api/__trusted-hosts-probe', fn () => response()->json(['ok' => true]));

        Cache::shouldReceive('add')->never();
        Log::shouldReceive('warning')->never();

        foreach (['masjid.test', 'portal.school.test', 'manara.test', 'MANARA.TEST', 'manara.test.'] as $host) {
            $this->getJson('https://'.$host.'/api/__trusted-hosts-probe')->assertOk();
        }
    }

    #[Test]
    public function an_ipv6_literal_is_logged_as_itself(): void
    {
        // Splitting on the first colon to drop a port turned `[2001:db8::1]`
        // into `[2001` — a log line naming a host nobody can look up.
        config(['trusted_hosts.enforce' => false]);

        Route::get('/api/__trusted-hosts-probe', fn () => response()->json(['ok' => true]));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => ($context['host'] ?? null) === '[2001:db8::1]');

        $this->getJson('https://[2001:db8::1]/api/__trusted-hosts-probe')->assertOk();
    }
}
