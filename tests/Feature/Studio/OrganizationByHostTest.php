<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * GET /api/v1/organizations/by-host: the renderer's question "which
 * organisation serves this host?", answered for anyone, with no credential.
 */
class OrganizationByHostTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    private const MISS = ['status' => 'error', 'message' => 'No organisation serves this host.'];

    private function lookup(?string $host): TestResponse
    {
        return $this->getJson('/api/v1/organizations/by-host' . ($host === null ? '' : '?host=' . rawurlencode($host)));
    }

    private function assertMiss(TestResponse $response, string $why): void
    {
        $this->assertSame(404, $response->status(), "{$why} answered {$response->status()}");
        $response->assertExactJson(self::MISS);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), "{$why} is cacheable");
    }

    #[Test]
    public function the_answer_carries_exactly_the_allowlisted_keys(): void
    {
        $org = $this->makeOrg(['description' => 'A masjid in the heart of town.']);
        $this->makeDomain($org, 'www.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);

        $response = $this->lookup('www.example.org')->assertOk();

        $data = $response->json('data');
        $keys = array_keys($data);
        sort($keys);

        $this->assertSame(['description', 'favicon_url', 'host', 'masjid_id', 'name', 'share_image_url'], $keys);
        $this->assertSame('success', $response->json('status'));
        $this->assertSame('OK', $response->json('message'));
        $this->assertSame([
            'host' => 'www.example.org',
            'masjid_id' => $org->id,
            'name' => $org->name,
            'description' => 'A masjid in the heart of town.',
            'favicon_url' => null,
            'share_image_url' => null,
        ], $data);
    }

    #[Test]
    public function no_contact_detail_credential_or_actor_id_reaches_the_caller(): void
    {
        $org = $this->makeOrg(['email' => 'office@private.test', 'phone' => '+15551234567']);
        $org->forceFill([
            'google_maps_key' => 'AIzaSyLOOKUPKEY',
            'stripe_account_id' => 'acct_LOOKUPSTRIPE',
            'user_id' => User::factory()->create(['phone' => '+15559876543'])->id,
        ])->save();
        $this->makeDomain($org, 'www.example.org');

        $response = $this->lookup('www.example.org')->assertOk();
        $body = $response->getContent();

        foreach (['email', 'phone', 'google_maps_key', 'stripe_account_id', 'user_id'] as $key) {
            $this->assertArrayNotHasKey($key, $response->json('data'));
        }

        foreach (['office@private.test', '+15551234567', 'AIzaSyLOOKUPKEY', 'acct_LOOKUPSTRIPE'] as $value) {
            $this->assertStringNotContainsString($value, $body);
        }
    }

    #[Test]
    public function a_host_is_found_however_the_caller_spells_it(): void
    {
        $org = $this->makeOrg();
        $this->makeDomain($org, 'www.example.org');

        $response = $this->lookup('WWW.Example.ORG.:443')->assertOk();

        $this->assertSame('www.example.org', $response->json('data.host'));
        $this->assertSame($org->id, $response->json('data.masjid_id'));
    }

    #[Test]
    public function a_host_no_live_organisation_serves_is_a_404(): void
    {
        $org = $this->makeOrg();
        $this->makeDomain($org, 'failed.example.org', MasjidDomain::STATUS_FAILED);
        $this->makeDomain($org, 'reserved.example.org', MasjidDomain::STATUS_RESERVED);

        $gone = $this->makeOrg();
        $this->makeDomain($gone, 'gone.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);
        $gone->delete();

        $this->assertMiss($this->lookup('unknown.example.org'), 'an unknown host');
        $this->assertMiss($this->lookup(null), 'no host at all');
        $this->assertMiss($this->lookup(''), 'an empty host');
        $this->assertMiss($this->lookup(str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 62)), 'a 254-character host');
        $this->assertMiss($this->lookup('127.0.0.1'), 'an IP literal');
        $this->assertMiss($this->lookup('[::1]'), 'an IPv6 literal');
        $this->assertMiss($this->lookup('failed.example.org'), 'a failed host');
        $this->assertMiss($this->lookup('reserved.example.org'), 'a reserved host');
        $this->assertMiss($this->lookup('gone.example.org'), "a trashed organisation's host");

        $this->assertMiss($this->getJson('/api/v1/organizations/by-host?host[]=www.example.org'), 'a host sent as an array');
    }

    #[Test]
    public function with_two_organisations_no_host_ever_answers_with_the_other(): void
    {
        $a = $this->makeOrg();
        $b = $this->makeOrg();

        $hosts = [
            'a.example.org' => $a, 'www.a-masjid.org' => $a, 'a-masjid.org' => $a,
            'b.example.org' => $b, 'www.b-masjid.org' => $b, 'b-masjid.org' => $b,
        ];

        foreach ($hosts as $host => $org) {
            $this->makeDomain($org, $host);
        }

        foreach ($hosts as $host => $org) {
            $response = $this->lookup(strtoupper($host))->assertOk();

            $this->assertSame($org->id, $response->json('data.masjid_id'), "{$host} answered with the other organisation");
            $this->assertSame($org->name, $response->json('data.name'));
            $this->assertSame($host, $response->json('data.host'));
        }
    }

    /**
     * Production's cache is the database, and the host is the caller's choice,
     * so anything cached per host is a row an attacker can mint. A miss must add
     * none: after one warm-up request, misses for new hosts leave the cache
     * exactly as it was, and no row ever mentions a host that was asked about.
     */
    #[Test]
    public function a_miss_writes_no_cache_row(): void
    {
        config(['cache.default' => 'database']);

        $this->assertMiss($this->lookup('warm-up.example.org'), 'the warm-up');
        $before = DB::table('cache')->orderBy('key')->pluck('key')->all();

        foreach (['one', 'two', 'three'] as $label) {
            $this->assertMiss($this->lookup("{$label}.example.org"), "{$label}.example.org");
        }

        $this->assertSame($before, DB::table('cache')->orderBy('key')->pluck('key')->all());

        foreach (DB::table('cache')->get() as $row) {
            $this->assertStringNotContainsString('example.org', $row->key . $row->value);
        }
    }

    #[Test]
    public function every_answer_is_no_store(): void
    {
        $this->makeDomain($this->makeOrg(), 'www.example.org');

        $found = $this->lookup('www.example.org')->assertOk();
        $this->assertStringContainsString('no-store', (string) $found->headers->get('Cache-Control'));

        $this->assertMiss($this->lookup('nobody.example.org'), 'a miss');
    }

    #[Test]
    public function the_route_is_throttled_by_its_own_limiter_and_needs_no_tenant_or_login(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'api/v1/organizations/by-host' && in_array('GET', $route->methods(), true)
        );

        $this->assertNotNull($route, 'GET api/v1/organizations/by-host is not registered');

        $middleware = $route->gatherMiddleware();

        $this->assertContains('throttle:tenant-host', $middleware);
        $this->assertNotContains('tenant', $middleware);
        $this->assertNotContains('auth:sanctum', $middleware);

        foreach ($middleware as $name) {
            $this->assertStringStartsNotWith('auth', (string) $name, "the lookup runs {$name}");
        }
    }
}
