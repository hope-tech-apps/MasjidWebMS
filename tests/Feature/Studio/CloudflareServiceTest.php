<?php

namespace Tests\Feature\Studio;

use App\Services\Cloudflare\CloudflareResult;
use App\Services\Cloudflare\CloudflareService;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\TestCase;

/**
 * Studio's Cloudflare client (S7). Every request here is faked and anything
 * unfaked throws (Http::preventStrayRequests), so nothing reaches Cloudflare.
 * The fixtures follow the API reference cited in CloudflareService (OQ3).
 */
class CloudflareServiceTest extends TestCase
{
    use FakesCloudflare;

    private const ZONE = 'zone-managed-1';

    private const RECORDS = 'GET /zones/zone-managed-1/dns_records?*';

    private function service(): CloudflareService
    {
        return $this->app->make(CloudflareService::class);
    }

    #[Test]
    public function a_blank_token_answers_not_configured_from_every_method_and_sends_nothing(): void
    {
        config(['cloudflare.studio_token' => null]);
        Http::preventStrayRequests();
        Http::fake();

        $service = $this->service();
        $this->assertFalse($service->isConfigured());

        foreach ([
            fn () => $service->findZone('example.org'),
            fn () => $service->createZone('example.org'),
            fn () => $service->getZone('z'),
            fn () => $service->requestActivationCheck('z'),
            fn () => $service->ensureCname('z', 'www.example.org'),
            fn () => $service->getPagesDomain('www.example.org'),
            fn () => $service->countPagesDomains(),
            fn () => $service->ensurePagesDomain('www.example.org'),
            fn () => $service->retryPagesDomain('www.example.org'),
        ] as $call) {
            $result = $call();
            $this->assertSame(CloudflareResult::NOT_CONFIGURED, $result->outcome);
            $this->assertFalse($result->ok);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function ensure_cname_creates_a_proxied_cname_to_the_pages_target_when_the_name_is_free(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            self::RECORDS => $this->cfOk([]),
            'POST /zones/zone-managed-1/dns_records' => $this->cfOk($this->dnsRecord('al-noor.manara.hopetechapps.com', 'CNAME', 'manara-renderer.pages.dev', 'rec-new')),
        ]);

        $result = $this->service()->ensureCname(self::ZONE, 'al-noor.manara.hopetechapps.com');

        $this->assertSame(CloudflareResult::CREATED, $result->outcome);
        $this->assertSame('rec-new', $result->data['id']);

        // The lookup uses the documented exact-name filter.
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), 'name.exact=al-noor.manara.hopetechapps.com'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['type'] === 'CNAME'
            && $r['name'] === 'al-noor.manara.hopetechapps.com'
            && $r['content'] === 'manara-renderer.pages.dev'
            && $r['proxied'] === true
            && $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN));
    }

    #[Test]
    public function ensure_cname_adopts_a_cname_that_already_points_at_the_target_without_writing(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            self::RECORDS => $this->cfOk([
                $this->dnsRecord('al-noor.manara.hopetechapps.com', 'CNAME', 'Manara-Renderer.pages.dev.', 'rec-old'),
                // A TXT record at the name does not decide web traffic.
                $this->dnsRecord('al-noor.manara.hopetechapps.com', 'TXT', 'v=spf1 -all', 'rec-txt'),
            ]),
        ]);

        $result = $this->service()->ensureCname(self::ZONE, 'al-noor.manara.hopetechapps.com');

        $this->assertSame(CloudflareResult::ADOPTED, $result->outcome);
        $this->assertSame('rec-old', $result->data['id']);
        $this->assertSame(['GET'], array_values(array_unique(array_map(fn ($l) => strtok($l, ' '), $this->sent()))));
    }

    #[Test]
    public function ensure_cname_calls_an_a_record_a_conflict_and_never_writes_over_it(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            self::RECORDS => $this->cfOk([$this->dnsRecord('www.burlingtonmasjid.com', 'A', '192.0.2.10')]),
        ]);

        $result = $this->service()->ensureCname(self::ZONE, 'www.burlingtonmasjid.com');

        $this->assertSame(CloudflareResult::CONFLICT, $result->outcome);
        $this->assertFalse($result->ok);
        $this->assertSame(['id' => 'rec-1', 'type' => 'A', 'content' => '192.0.2.10'], $result->data);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    #[Test]
    public function ensure_cname_calls_a_cname_to_somewhere_else_a_conflict(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            self::RECORDS => $this->cfOk([$this->dnsRecord('www.example.org', 'CNAME', 'example.netlify.app')]),
        ]);

        $this->assertSame(CloudflareResult::CONFLICT, $this->service()->ensureCname(self::ZONE, 'www.example.org')->outcome);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    #[Test]
    public function ensure_cname_that_loses_the_already_exists_race_reads_again_and_adopts(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            self::RECORDS => Http::sequence()
                ->push(['success' => true, 'errors' => [], 'messages' => [], 'result' => []])
                ->push(['success' => true, 'errors' => [], 'messages' => [], 'result' => [
                    $this->dnsRecord('www.example.org', 'CNAME', 'manara-renderer.pages.dev', 'rec-race'),
                ]]),
            'POST /zones/zone-managed-1/dns_records' => $this->cfError(400, 81053, 'An A, AAAA, or CNAME record with that host already exists.'),
        ]);

        $result = $this->service()->ensureCname(self::ZONE, 'www.example.org');

        $this->assertSame(CloudflareResult::ADOPTED, $result->outcome);
        $this->assertSame('rec-race', $result->data['id']);
        Http::assertSentCount(3);
    }

    #[Test]
    public function ensure_cname_judges_only_records_at_exactly_the_host_whatever_the_filter_returned(): void
    {
        $this->withStudioToken();
        $post = 'POST /zones/zone-managed-1/dns_records';
        $made = $this->cfOk($this->dnsRecord('www.example.org', 'CNAME', 'manara-renderer.pages.dev', 'rec-new'));

        // Another host's CNAME to the renderer is not this host's to adopt.
        $this->fakeCloudflare([
            self::RECORDS => $this->cfOk([$this->dnsRecord('mec.example.org', 'CNAME', 'manara-renderer.pages.dev', 'rec-other')]),
            $post => $made,
        ]);
        $other = $this->service()->ensureCname(self::ZONE, 'www.example.org');
        $this->assertSame(CloudflareResult::CREATED, $other->outcome);
        $this->assertSame('rec-new', $other->data['id']);

        // The apex's own A record is not a conflict for www.
        $this->fakeCloudflare([
            self::RECORDS => $this->cfOk([$this->dnsRecord('example.org', 'A', '192.0.2.10', 'rec-apex')]),
            $post => $made,
        ]);
        $apex = $this->service()->ensureCname(self::ZONE, 'www.example.org');
        $this->assertSame(CloudflareResult::CREATED, $apex->outcome);

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['name'] === 'www.example.org');
    }

    #[Test]
    public function ensure_pages_domain_adds_an_absent_host(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            'GET /accounts/*/pages/projects/manara-renderer/domains/new.example.org' => $this->cfError(404, 8000007, 'Domain not found.'),
            'GET /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk([], ['count' => 5, 'page' => 1, 'per_page' => 20, 'total_count' => 5, 'total_pages' => 1]),
            'POST /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk($this->pagesDomainBody('new.example.org', 'initializing')),
        ]);

        $result = $this->service()->ensurePagesDomain('new.example.org');

        $this->assertSame(CloudflareResult::CREATED, $result->outcome);
        $this->assertSame('initializing', $result->data['status']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['name' => 'new.example.org']);
    }

    #[Test]
    public function ensure_pages_domain_adopts_a_present_host_without_posting(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            'GET /accounts/*/pages/projects/manara-renderer/domains/new.example.org' => $this->cfOk($this->pagesDomainBody('new.example.org', 'pending')),
        ]);

        $result = $this->service()->ensurePagesDomain('new.example.org');

        $this->assertSame(CloudflareResult::ADOPTED, $result->outcome);
        $this->assertSame('pd-' . md5('new.example.org'), $result->data['id']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function ensure_pages_domain_at_the_ceiling_is_a_capacity_conflict_and_adds_nothing(): void
    {
        $this->withStudioToken();
        config(['cloudflare.pages_domain_ceiling' => 100]);
        $this->fakeCloudflare([
            'GET /accounts/*/pages/projects/manara-renderer/domains/new.example.org' => $this->cfError(404, 8000007, 'Domain not found.'),
            'GET /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk([], ['count' => 20, 'page' => 1, 'per_page' => 20, 'total_count' => 100, 'total_pages' => 5]),
        ]);

        $result = $this->service()->ensurePagesDomain('new.example.org');

        $this->assertSame(CloudflareResult::CONFLICT, $result->outcome);
        $this->assertSame('capacity', $result->data['reason']);
        $this->assertSame(100, $result->data['count']);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    #[Test]
    public function create_zone_adopts_an_existing_zone_and_posts_only_for_an_absent_one(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            'GET /zones?name=existing.org*' => $this->cfOk([$this->zoneBody('existing.org', 'active', 'zone-existing')]),
            'GET /zones?name=new-masjid.org*' => $this->cfOk([]),
            'POST /zones' => $this->cfOk($this->zoneBody('new-masjid.org', 'pending', 'zone-new')),
        ]);

        $existing = $this->service()->createZone('existing.org');
        $this->assertSame(CloudflareResult::ADOPTED, $existing->outcome);
        $this->assertSame('zone-existing', $existing->data['id']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        $created = $this->service()->createZone('new-masjid.org');
        $this->assertSame(CloudflareResult::CREATED, $created->outcome);
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $created->data['name_servers']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->data() === ['name' => 'new-masjid.org', 'account' => ['id' => config('cloudflare.account_id')], 'type' => 'full']);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), 'account.id=' . config('cloudflare.account_id')));
    }

    #[Test]
    public function status_codes_map_onto_outcomes(): void
    {
        $this->withStudioToken();

        foreach ([
            [401, CloudflareResult::UNAUTHORIZED],
            [403, CloudflareResult::UNAUTHORIZED],
            [429, CloudflareResult::RATE_LIMITED],
            [500, CloudflareResult::TRANSIENT],
            [503, CloudflareResult::TRANSIENT],
            [400, CloudflareResult::REJECTED],
        ] as [$status, $outcome]) {
            $this->fakeCloudflare(['GET /zones/zone-x' => $this->cfError($status, 9109, "Answer {$status}")]);

            $result = $this->service()->getZone('zone-x');

            $this->assertSame($outcome, $result->outcome, "HTTP {$status}");
            $this->assertSame($status, $result->http_status);
            $this->assertFalse($result->ok);
        }

        // A 200 whose envelope says success:false is a rejection too.
        $this->fakeCloudflare(['GET /zones/zone-x' => Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Nope']], 'result' => null], 200)]);
        $rejected = $this->service()->getZone('zone-x');
        $this->assertSame(CloudflareResult::REJECTED, $rejected->outcome);
        $this->assertStringContainsString('Nope', (string) $rejected->error);

        // No answer at all (a timeout) is transient.
        $this->fakeCloudflare(['GET /zones/zone-x' => fn (Request $r) => throw new ConnectException('cURL error 28: Operation timed out', $r->toPsrRequest())]);
        $this->assertSame(CloudflareResult::TRANSIENT, $this->service()->getZone('zone-x')->outcome);
    }

    #[Test]
    public function the_delete_verb_is_never_used_and_cannot_be(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            'GET /zones?*' => $this->cfOk([]),
            'POST /zones' => $this->cfOk($this->zoneBody('new-masjid.org', 'pending', 'zone-new')),
            'GET /zones/*/dns_records?*' => $this->cfOk([]),
            'POST /zones/*/dns_records' => $this->cfOk($this->dnsRecord('www.new-masjid.org', 'CNAME', 'manara-renderer.pages.dev')),
            'PUT /zones/*/activation_check' => $this->cfOk(['id' => 'zone-new']),
            'GET /zones/*' => $this->cfOk($this->zoneBody('new-masjid.org', 'pending', 'zone-new')),
            'GET /accounts/*/domains/*' => $this->cfError(404, 8000007, 'Domain not found.'),
            'GET /accounts/*/domains' => $this->cfOk([], ['total_count' => 0]),
            'POST /accounts/*/domains' => $this->cfOk($this->pagesDomainBody('www.new-masjid.org', 'initializing')),
            'PATCH /accounts/*/domains/*' => $this->cfOk($this->pagesDomainBody('www.new-masjid.org', 'pending')),
        ]);

        $service = $this->service();
        $service->createZone('new-masjid.org');
        $service->getZone('zone-new');
        $service->requestActivationCheck('zone-new');
        $service->ensureCname('zone-new', 'www.new-masjid.org');
        $service->ensurePagesDomain('www.new-masjid.org');
        $service->retryPagesDomain('www.new-masjid.org');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
        $this->assertNotEmpty($this->sent());

        // And there is no way to ask for one: no public method deletes, and
        // the one place requests are built refuses the verb.
        $public = array_map(fn (ReflectionMethod $m) => strtolower($m->getName()), (new ReflectionClass(CloudflareService::class))->getMethods(ReflectionMethod::IS_PUBLIC));
        foreach ($public as $name) {
            $this->assertStringNotContainsString('delete', $name);
            $this->assertStringNotContainsString('remove', $name);
        }
        $this->assertNotContains('DELETE', CloudflareService::VERBS);

        $request = new ReflectionMethod(CloudflareService::class, 'request');
        $this->expectException(\LogicException::class);
        $request->invoke($service, 'DELETE', '/zones/zone-new');
    }

    #[Test]
    public function the_token_never_appears_in_a_log_line_or_an_error(): void
    {
        $this->withStudioToken();
        Log::spy();

        // Cloudflare echoing the credential back is the worst case: the error
        // text must still arrive without it.
        $this->fakeCloudflare([
            'GET /zones/zone-x' => $this->cfError(403, 10000, 'Authentication error for token ' . self::TOKEN),
            'GET /zones/zone-y' => fn (Request $r) => throw new ConnectException('Could not connect with Bearer ' . self::TOKEN, $r->toPsrRequest()),
        ]);

        $refused = $this->service()->getZone('zone-x');
        $unreachable = $this->service()->getZone('zone-y');

        $this->assertSame(CloudflareResult::UNAUTHORIZED, $refused->outcome);
        $this->assertStringNotContainsString(self::TOKEN, (string) $refused->error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $unreachable->error);
        $this->assertStringContainsString('[redacted]', (string) $refused->error);

        // Two warnings in all, and both of them without the token anywhere.
        Log::shouldHaveReceived('warning')->times(2);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ! str_contains($message . json_encode($context), self::TOKEN))
            ->times(2);
    }
}
