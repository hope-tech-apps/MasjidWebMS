<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * POST /api/admin/studio/domains/check: may this host be given to a new
 * organisation? SuperAdmin-only (StudioAccessTest covers the refusals), and in
 * S3 it answers from our own table alone, with no Cloudflare call.
 */
class StudioDomainCheckTest extends TestCase
{
    use FakesCloudflare;
    use MakesStudioDomains;
    use RefreshDatabase;

    private const URL = '/api/admin/studio/domains/check';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh());
        config(['cloudflare.studio_token' => null]);
        Http::preventStrayRequests();
    }

    #[Test]
    public function a_free_managed_subdomain_is_available(): void
    {
        $this->postJson(self::URL, ['kind' => 'managed_subdomain', 'label' => ' Al-Noor '])
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'data' => [
                'host' => 'al-noor.manara.hopetechapps.com',
                'available' => true,
                'taken_by_masjid_id' => null,
                'case' => 'managed_subdomain',
                'token_configured' => false,
                'pages_domains_used' => null,
                'pages_domains_ceiling' => 100,
            ]]);
    }

    #[Test]
    public function a_reserved_host_is_unavailable_and_names_who_holds_it(): void
    {
        $mec = $this->makeOrg();
        $this->makeDomain($mec, 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['zone_apex' => 'meccharlotte.org']);
        $this->makeDomain($mec, 'mec.manara.hopetechapps.com', MasjidDomain::STATUS_MANUAL, [
            'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN, 'zone_apex' => 'hopetechapps.com',
        ]);

        $custom = $this->postJson(self::URL, ['kind' => 'custom', 'host' => 'MECcharlotte.org.', 'zone_apex' => 'meccharlotte.org'])->assertOk();
        $this->assertSame('meccharlotte.org', $custom->json('data.host'));
        $this->assertFalse($custom->json('data.available'));
        $this->assertSame($mec->id, $custom->json('data.taken_by_masjid_id'));

        // `mec` is on the reserved-label list as well, so the managed form of
        // the same question is refused before it reaches the table.
        $this->postJson(self::URL, ['kind' => 'managed_subdomain', 'label' => 'mec'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function with_the_token_blank_a_custom_hosts_case_is_unknown(): void
    {
        $response = $this->postJson(self::URL, ['kind' => 'custom', 'host' => 'www.new-masjid.org', 'zone_apex' => 'new-masjid.org'])->assertOk();

        $this->assertSame('unknown', $response->json('data.case'));
        $this->assertFalse($response->json('data.token_configured'));
        $this->assertTrue($response->json('data.available'));
        $this->assertNull($response->json('data.pages_domains_used'));
        Http::assertNothingSent();
    }

    #[Test]
    public function single_label_and_internal_suffix_hosts_are_refused(): void
    {
        foreach ([
            'intranet', 'localhost', 'app.localhost', 'printer.local', 'db.internal',
            'mec-web.pages.dev', 'pages.dev', 'api.workers.dev', '127.0.0.1', 'bücher.example',
        ] as $host) {
            $response = $this->postJson(self::URL, ['kind' => 'custom', 'host' => $host, 'zone_apex' => $host]);

            $this->assertSame(422, $response->status(), "{$host} was accepted");
            $this->assertArrayHasKey('host', $response->json('data'), "{$host} was refused for the wrong reason");
        }
    }

    #[Test]
    public function a_custom_host_must_sit_in_its_stated_zone_and_outside_our_managed_suffix(): void
    {
        $this->postJson(self::URL, ['kind' => 'custom', 'host' => 'www.example.org', 'zone_apex' => 'example.com'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['zone_apex']]);

        $this->postJson(self::URL, ['kind' => 'custom', 'host' => 'wwwexample.org', 'zone_apex' => 'example.org'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['zone_apex']]);

        $this->postJson(self::URL, ['kind' => 'custom', 'host' => 'x.manara.hopetechapps.com', 'zone_apex' => 'hopetechapps.com'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['host']]);

        // Our managed suffix itself is not a custom host either.
        $this->postJson(self::URL, ['kind' => 'custom', 'host' => 'manara.hopetechapps.com', 'zone_apex' => 'hopetechapps.com'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['host']]);

        // A zone the write-side rule refuses is refused as the zone, even when
        // the host is fine: "org" would make every later step about the TLD.
        $this->postJson(self::URL, ['kind' => 'custom', 'host' => 'www.example.org', 'zone_apex' => 'org'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['zone_apex']]);

        $this->postJson(self::URL, ['kind' => 'custom', 'host' => 'a.localhost', 'zone_apex' => 'localhost'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['zone_apex']]);

        $this->postJson(self::URL, ['kind' => 'custom', 'host' => 'example.org', 'zone_apex' => 'example.org'])->assertOk();
    }

    #[Test]
    public function a_managed_label_must_be_one_dns_label_and_not_reserved(): void
    {
        foreach (['-bad', 'bad-', 'two.labels', str_repeat('a', 64), 'under_score', '', 'www', 'api', 'alrazi', 'preview', 'PREVIEW'] as $label) {
            $this->postJson(self::URL, ['kind' => 'managed_subdomain', 'label' => $label])
                ->assertStatus(422)->assertJsonStructure(['data' => ['label']]);
        }

        $this->postJson(self::URL, ['kind' => 'nameservers', 'label' => 'ok'])->assertStatus(422)->assertJsonStructure(['data' => ['kind']]);
    }

    #[Test]
    public function with_the_token_each_zone_case_is_read_from_cloudflare_by_gets_only(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            'GET /zones/859eddb9bce48f4f35e6197f6c0b8e15' => $this->cfOk($this->zoneBody('hopetechapps.com', 'active', '859eddb9bce48f4f35e6197f6c0b8e15')),
            'GET /zones?name=on-cloudflare.org*' => $this->cfOk([$this->zoneBody('on-cloudflare.org', 'pending', 'zone-on')]),
            'GET /zones?name=elsewhere.org*' => $this->cfOk([]),
            'GET /zones?name=broken.org*' => $this->cfError(503, 10000, 'Service unavailable'),
            'GET /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk([], ['total_count' => 7]),
        ]);

        $cases = [
            [['kind' => 'managed_subdomain', 'label' => 'al-noor'], 'managed_subdomain', 'active'],
            [['kind' => 'custom', 'host' => 'www.on-cloudflare.org', 'zone_apex' => 'on-cloudflare.org'], 'zone_in_account', 'pending'],
            [['kind' => 'custom', 'host' => 'www.elsewhere.org', 'zone_apex' => 'elsewhere.org'], 'zone_not_in_account', null],
            [['kind' => 'custom', 'host' => 'www.broken.org', 'zone_apex' => 'broken.org'], 'unknown', null],
        ];

        foreach ($cases as [$body, $case, $zoneStatus]) {
            $data = $this->postJson(self::URL, $body)->assertOk()->json('data');

            $this->assertSame($case, $data['case'], json_encode($body));
            $this->assertSame($zoneStatus, $data['zone_status'], json_encode($body));
            $this->assertTrue($data['token_configured']);
            $this->assertSame(7, $data['pages_domains_used']);
            $this->assertTrue($data['available']);
        }

        $this->assertSame(['GET'], array_values(array_unique(array_map(fn ($line) => strtok($line, ' '), $this->sent()))));
    }
}
