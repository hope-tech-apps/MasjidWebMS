<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The door: App\Http\Middleware\TrustedHosts.
 *
 * Production nginx is `default_server` on :80 and :443, so a request naming any
 * Host at all reaches this application, and it reaches it without passing
 * through Cloudflare. App\Support\SiteUrl makes the cached payloads indifferent
 * to that; this middleware stops the request instead.
 *
 * WHY THIS SUITE EXISTS RATHER THAN A `trustHosts()` LINE IN bootstrap/app.php.
 * Laravel's own Illuminate\Http\Middleware\TrustHosts disables itself under
 * tests — `shouldSpecifyTrustedHosts()` is `! environment('local') &&
 * ! runningUnitTests()` — so every assertion below would pass against an
 * application that had never registered it. A protection whose test cannot fail
 * is not a protection. See the class docblock for the other two reasons.
 */
class TrustedHostsMiddlewareTest extends TestCase
{
    use RefreshDatabase;

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
            'trusted_hosts.extra' => ['manara.test'],
            'trusted_hosts.enforce' => true,
            'trusted_hosts.log_interval' => 3600,
        ]);

        // The report path writes a marker through the cache; a shared entry
        // between cases would silence the log assertions.
        Cache::flush();
    }

    /**
     * A request that genuinely arrives carrying `$host`.
     *
     * An ABSOLUTE URL, not a `Host:` header set on a relative path:
     * `MakesHttpRequests::prepareUrlForRequest()` rewrites a relative path
     * through `url()`, and Symfony's `Request::create()` then overwrites
     * HTTP_HOST from that URI — so the header is discarded, and every case below
     * would pass against an application with no middleware at all. The host has
     * to be in the URI to reach it.
     */
    private function asHost(string $host, string $path = '/account-deletion'): \Illuminate\Testing\TestResponse
    {
        return $this->get('https://'.$host.$path);
    }

    /**
     * The belt to the absolute-URI braces: the request the application handled
     * arrived on `$expected`. Without this, a future middleware that normalised
     * the host back would leave the "admitted" cases passing for the wrong
     * reason. Only the admitted cases can use it — a refused request is rejected
     * before anything worth inspecting is bound.
     */
    private function assertArrivedOnHost(string $expected): void
    {
        $this->assertSame(
            $expected,
            app('request')->getHost(),
            'the request the app handled did not arrive on '.$expected
        );
    }

    #[Test]
    public function a_forged_host_is_refused(): void
    {
        $this->asHost('evil.example')
            ->assertStatus(400);
    }

    #[Test]
    public function the_configured_host_is_admitted(): void
    {
        $this->asHost('masjid.test')
            ->assertOk();

        $this->assertArrivedOnHost('masjid.test');
    }

    #[Test]
    public function an_organisation_host_from_portal_hosts_is_admitted(): void
    {
        // PORTAL_HOSTS names the domains a school has pointed at this app. They
        // are already trusted enough to select an organisation for the portal;
        // restating them in a second setting is how the two drift apart.
        $this->asHost('portal.school.test')
            ->assertOk();

        $this->assertArrivedOnHost('portal.school.test');
    }

    #[Test]
    public function an_extra_host_from_the_env_list_is_admitted(): void
    {
        // manara.hopetechapps.com is served off the default vhost and appears in
        // no other configuration, which is exactly what TRUSTED_HOSTS is for.
        $this->asHost('manara.test')
            ->assertOk();

        $this->assertArrivedOnHost('manara.test');
    }

    #[Test]
    public function the_comparison_ignores_case_a_port_and_a_trailing_dot(): void
    {
        // All three are the same name to DNS and three different strings to
        // `in_array`. Symfony's `getHost()` already lower-cases and strips the
        // port, so those two are here to pin behaviour rather than to exercise
        // our own normalising; the TRAILING DOT is the one Symfony hands through
        // unchanged, and it is among the first things a scanner tries.
        foreach (['MASJID.TEST', 'masjid.test:443', 'masjid.test.'] as $host) {
            $this->asHost($host)
                ->assertOk();
        }
    }

    #[Test]
    public function a_subdomain_of_a_trusted_host_is_not_itself_trusted(): void
    {
        // Laravel's TrustHosts defaults to `^(.+\.)?masjid\.test$` — every
        // subdomain that exists now or ever will, including one an attacker
        // gets pointed at this origin. The list here is literal.
        $this->asHost('anything.masjid.test')
            ->assertStatus(400);
    }

    #[Test]
    public function a_forged_host_never_reaches_a_cached_payload(): void
    {
        // The refusal has to happen before the controller, not after: a 400 that
        // still warmed the entry would leave the poisoned copy behind for every
        // later caller. The endpoint is chosen for being one of the cached five.
        //
        // The organisation is REAL. Against a nonexistent id the endpoint would
        // 404 out of `findOrFail` and cache nothing, so the assertion below would
        // hold on an application with no middleware — a test that cannot fail.
        $masjid = Masjid::create([
            'name' => 'Org '.uniqid(),
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $this->asHost('evil.example', "/api/mobile/masjids/{$masjid->id}/announcements")
            ->assertStatus(400);

        $this->assertNull(
            Cache::get(MobileCache::masjidKey($masjid->id, MobileCache::ANNOUNCEMENTS)),
            'a refused request still left a cache entry behind'
        );

        // ...and the control: the same request on a trusted host DOES warm it,
        // so the assertion above is measuring the refusal and not an endpoint
        // that never caches.
        $this->asHost('masjid.test', "/api/mobile/masjids/{$masjid->id}/announcements")
            ->assertOk();

        $this->assertNotNull(
            Cache::get(MobileCache::masjidKey($masjid->id, MobileCache::ANNOUNCEMENTS)),
            'the endpoint did not cache at all — the refusal assertion proves nothing'
        );
    }

    #[Test]
    public function report_only_mode_admits_the_request_and_logs_it(): void
    {
        // The shipping default. It has to be genuinely non-blocking — the whole
        // reason for it is that the host list cannot be fully known from the
        // code, and a health check reaching the origin by IP must not 400.
        config(['trusted_hosts.enforce' => false]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'Host header')
                    && $context['host'] === 'unknown.example'
                    && $context['enforced'] === false;
            });

        $this->asHost('unknown.example')
            ->assertOk();
    }

    #[Test]
    public function the_same_unknown_host_is_logged_once_per_interval(): void
    {
        // This IP already takes unsolicited scanner traffic. A line per request
        // buries the thing an operator reads the log FOR — a legitimate hostname
        // nobody put on the list.
        config(['trusted_hosts.enforce' => false]);

        Log::shouldReceive('warning')->once();

        foreach (range(1, 4) as $ignored) {
            $this->asHost('noisy.example')
                ->assertOk();
        }
    }

    #[Test]
    public function an_empty_allowlist_admits_everything(): void
    {
        // The unconfigured case — a fresh checkout with no APP_URL. A security
        // middleware that bricks one gets deleted rather than configured.
        config([
            'app.url' => '',
            'portal.hosts' => [],
            'trusted_hosts.extra' => [],
            'trusted_hosts.enforce' => true,
        ]);

        $this->asHost('anything.example')
            ->assertOk();
    }
}
