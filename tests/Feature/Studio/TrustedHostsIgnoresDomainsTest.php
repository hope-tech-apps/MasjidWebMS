<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * S9 widens CORS, and ONLY CORS. A client's hostname reaches Laravel as an
 * `Origin` (the browser on the client's site calling our API), never as the
 * `Host` (the renderer serves the client's site; docs/tenant-host-map.md), so
 * App\Http\Middleware\TrustedHosts stays exactly as it was: a confirmed
 * masjid_domains row is not a host this deployment answers to, and the door
 * never reads the table.
 */
class TrustedHostsIgnoresDomainsTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://masjid.test',
            'portal.hosts' => [],
            'trusted_hosts.extra' => [],
            'trusted_hosts.enforce' => true,
            'cors.allowed_origins' => ['https://burlingtonmasjid.com', 'https://www.burlingtonmasjid.com'],
        ]);

        Cache::flush();
    }

    #[Test]
    public function a_confirmed_domain_is_admitted_as_an_origin_but_never_as_a_host(): void
    {
        $this->makeDomain($this->makeOrg(), 'client.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        // As a Host (an absolute URL, so the header really arrives): refused at the door.
        $this->get('https://client.example.org/api/v1/organizations/by-host?host=client.example.org')->assertStatus(400);

        $this->assertSame(
            0,
            collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'masjid_domains'))->count(),
            'TrustedHosts read masjid_domains to decide a Host'
        );

        // As an Origin on our own host: admitted by CORS.
        $response = $this->call('OPTIONS', 'https://masjid.test/api/v1/organizations/by-host', [], [], [], [
            'HTTP_ORIGIN' => 'https://client.example.org',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertSame('https://client.example.org', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
