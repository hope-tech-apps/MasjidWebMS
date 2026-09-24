<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use App\Services\Domains\DomainProbe;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * The probe asks a host whether it serves OUR site for THIS organisation, and
 * it does so from our own server on a host a person typed. Every test decides
 * the DNS answer (MakesStudioDomains::resolveTo) and fakes the HTTP, so nothing
 * here touches a network.
 */
class DomainProbeTest extends TestCase
{
    use MakesStudioDomains;

    private const PUBLIC_ADDRESS = '93.184.216.34';

    private function row(string $host = 'www.example.org', int $masjidId = 13, string $status = MasjidDomain::STATUS_PENDING, array $extra = []): MasjidDomain
    {
        return new MasjidDomain(array_merge([
            'masjid_id' => $masjidId,
            'host' => $host,
            'kind' => MasjidDomain::KIND_CUSTOM,
            'zone_apex' => 'example.org',
            'status' => $status,
        ], $extra));
    }

    private function probe(): DomainProbe
    {
        return $this->app->make(DomainProbe::class);
    }

    #[Test]
    public function a_200_carrying_the_rows_own_masjid_id_confirms_the_host_as_manual(): void
    {
        $this->resolveTo([self::PUBLIC_ADDRESS]);
        Http::fake(['https://www.example.org/api/tenant' => Http::response(['ok' => true], 200, ['x-manara-tenant' => '13'])]);

        $domain = $this->row();
        $result = $this->probe()->confirm($domain);

        $this->assertTrue($result['matched']);
        $this->assertSame(MasjidDomain::STATUS_MANUAL, $domain->status);
        $this->assertSame(MasjidDomain::VERIFIED_BY_PROBE, $domain->verified_by);
        $this->assertNotNull($domain->verified_at);
        $this->assertNotNull($domain->serving_confirmed_at);
        $this->assertSame('https://www.example.org', $domain->liveUrl());
    }

    #[Test]
    public function another_organisations_id_does_not_match(): void
    {
        $this->resolveTo([self::PUBLIC_ADDRESS]);

        $header = null;
        Http::fake(function () use (&$header) {
            return Http::response('', 200, ['x-manara-tenant' => $header]);
        });

        // Another organisation, and near misses for 13 that a prefix, a loose
        // `==` or an (int) cast would take for it: organisation 130's site
        // must never confirm organisation 13's host.
        foreach (['14', '130', '1', '013', '+13', '13.0', '13,14'] as $header) {
            $domain = $this->row();
            $result = $this->probe()->confirm($domain);

            $this->assertFalse($result['matched'], "header {$header} matched masjid 13");
            $this->assertStringContainsString($header, $result['seen']);
            $this->assertSame(MasjidDomain::STATUS_PENDING, $domain->status);
            $this->assertNull($domain->serving_confirmed_at);
            $this->assertNull($domain->liveUrl());
        }
    }

    #[Test]
    public function a_page_that_is_not_ours_does_not_match(): void
    {
        $this->resolveTo([self::PUBLIC_ADDRESS]);
        Http::fakeSequence('*')
            ->push('<html>parked</html>', 200)
            ->push('', 404, ['x-manara-tenant' => '13']);

        $this->assertFalse($this->probe()->probe($this->row())['matched'], 'a page with no tenant header matched');
        $this->assertFalse($this->probe()->probe($this->row())['matched'], 'a 404 carrying the header is not a site serving');
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_redirect_is_not_followed_and_does_not_match(): void
    {
        $this->resolveTo([self::PUBLIC_ADDRESS]);
        Http::fake([
            'https://example.org/api/tenant' => Http::response('', 307, ['Location' => 'https://www.example.org/api/tenant']),
            'https://www.example.org/*' => Http::response('', 200, ['x-manara-tenant' => '13']),
        ]);

        $result = $this->probe()->probe($this->row('example.org'));

        $this->assertFalse($result['matched']);
        $this->assertStringContainsString('307', $result['seen']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_timeout_does_not_match(): void
    {
        $this->resolveTo([self::PUBLIC_ADDRESS]);
        Http::fake(fn (Request $request) => throw new ConnectException('cURL error 28: Operation timed out', $request->toPsrRequest()));

        $domain = $this->row();
        $result = $this->probe()->confirm($domain);

        $this->assertFalse($result['matched']);
        $this->assertStringContainsString('timed out', $result['seen']);
        $this->assertSame(MasjidDomain::STATUS_PENDING, $domain->status);
    }

    #[Test]
    public function the_probe_never_produces_active(): void
    {
        $this->resolveTo([self::PUBLIC_ADDRESS]);
        Http::fake(['*' => Http::response('', 200, ['x-manara-tenant' => '13'])]);

        foreach (MasjidDomain::STATUSES as $status) {
            if ($status === MasjidDomain::STATUS_ACTIVE) {
                continue;
            }

            $domain = $this->row(status: $status);
            $this->probe()->confirm($domain);

            // Every matched row Cloudflare has not verified is `manual`, a
            // failed one included, except `reserved`, which nothing advances.
            $expected = $status === MasjidDomain::STATUS_RESERVED ? MasjidDomain::STATUS_RESERVED : MasjidDomain::STATUS_MANUAL;
            $this->assertSame($expected, $domain->status, "a matched {$status} row became {$domain->status}");
        }

        // A row Cloudflare already verified keeps Cloudflare's word for it; the
        // probe adds only that it was seen serving.
        $active = $this->row(status: MasjidDomain::STATUS_ACTIVE, extra: [
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now()->subDay(),
        ]);
        $this->probe()->confirm($active);

        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $active->status);
        $this->assertSame(MasjidDomain::VERIFIED_BY_CLOUDFLARE, $active->verified_by);
        $this->assertNotNull($active->serving_confirmed_at);
    }

    #[Test]
    public function a_reserved_row_is_never_advanced_even_when_it_answers(): void
    {
        $this->resolveTo([self::PUBLIC_ADDRESS]);
        Http::fake(['*' => Http::response('', 200, ['x-manara-tenant' => '13'])]);

        $domain = $this->row(status: MasjidDomain::STATUS_RESERVED);
        $result = $this->probe()->confirm($domain);

        // The answer is still reported, so an operator can see the host is
        // live, but the row is only stamped as checked.
        $this->assertTrue($result['matched']);
        $this->assertSame(MasjidDomain::STATUS_RESERVED, $domain->status);
        $this->assertNull($domain->serving_confirmed_at);
        $this->assertNull($domain->verified_by);
        $this->assertNull($domain->verified_at);
        $this->assertNotNull($domain->last_checked_at);
        $this->assertNull($domain->liveUrl());
    }

    #[Test]
    public function an_ipv4_mapped_address_is_judged_by_the_address_it_carries(): void
    {
        $this->assertTrue(DomainProbe::isPublicAddress('::ffff:' . self::PUBLIC_ADDRESS));
        $this->assertTrue(DomainProbe::isPublicAddress('::FFFF:' . self::PUBLIC_ADDRESS));
        $this->assertFalse(DomainProbe::isPublicAddress('::ffff:169.254.169.254'));
        $this->assertFalse(DomainProbe::isPublicAddress('::ffff:10.0.0.1'));
    }

    #[Test]
    public function a_host_resolving_to_a_loopback_or_private_address_is_never_fetched(): void
    {
        Http::fake();

        $answers = [
            'loopback' => ['127.0.0.1'],
            'loopback, IPv6' => ['::1'],
            'RFC 1918' => ['10.1.2.3'],
            'RFC 1918, 192.168' => ['192.168.1.10'],
            'RFC 1918, 172.16' => ['172.16.5.4'],
            'link-local, the cloud metadata address' => ['169.254.169.254'],
            'unique local IPv6' => ['fd00::1'],
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'carrier-grade NAT' => ['100.64.0.1'],
            'unspecified' => ['0.0.0.0'],
            'one public and one private answer' => [self::PUBLIC_ADDRESS, '10.0.0.1'],
        ];

        foreach ($answers as $case => $addresses) {
            $this->resolveTo($addresses);

            $result = $this->probe()->probe($this->row());

            $this->assertFalse($result['matched'], "{$case} matched");
            $this->assertStringStartsWith('refused', $result['seen'], "{$case} was not refused");
        }

        $this->resolveTo([]);
        $this->assertStringStartsWith('not fetched', $this->probe()->probe($this->row())['seen']);

        Http::assertNothingSent();
    }

    #[Test]
    public function the_connection_is_pinned_to_the_address_that_was_checked(): void
    {
        $this->resolveTo(['www.example.org' => [self::PUBLIC_ADDRESS, '2606:2800:220:1::1']]);

        $options = null;
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response('', 200, ['x-manara-tenant' => '13']);
        });

        $this->assertTrue($this->probe()->probe($this->row())['matched']);

        $this->assertSame(['www.example.org:443:' . self::PUBLIC_ADDRESS], $options['curl'][CURLOPT_RESOLVE] ?? null);
        $this->assertFalse($options['allow_redirects'] ?? null, 'redirects must not be followed');
        $this->assertNotFalse($options['verify'] ?? true, 'TLS must be verified');
        $this->assertSame(DomainProbe::TIMEOUT_SECONDS, (int) ($options['timeout'] ?? 0));
    }

    #[Test]
    public function an_ipv6_only_host_is_pinned_in_brackets(): void
    {
        $this->resolveTo(['2606:2800:220:1::1']);

        $options = null;
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response('', 200, ['x-manara-tenant' => '13']);
        });

        $this->probe()->probe($this->row());

        $this->assertSame(['www.example.org:443:[2606:2800:220:1::1]'], $options['curl'][CURLOPT_RESOLVE] ?? null);
    }
}
