<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Domains\DomainAttacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * The attach state machine (S7). Cloudflare is faked route by route and the
 * probe's host resolves to an address the test chooses, so nothing here
 * touches a network; any request a test did not declare throws.
 */
class DomainAttacherTest extends TestCase
{
    use FakesCloudflare;
    use MakesStudioDomains;
    use RefreshDatabase;

    private const MANAGED = 'al-noor.manara.hopetechapps.com';

    private const PAGES = 'GET /accounts/*/pages/projects/manara-renderer/domains/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolveTo(['93.184.216.34']);
    }

    private function attacher(): DomainAttacher
    {
        return $this->app->make(DomainAttacher::class);
    }

    private function managed(Masjid $org, string $status = MasjidDomain::STATUS_PENDING, array $attributes = []): MasjidDomain
    {
        return $this->makeDomain($org, self::MANAGED, $status, array_merge([
            'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN,
            'zone_apex' => 'hopetechapps.com',
        ], $attributes));
    }

    private function servesAs(string $host, int|string $masjidId): array
    {
        return ["GET https://{$host}/api/tenant" => Http::response('{}', 200, ['x-manara-tenant' => (string) $masjidId])];
    }

    /** @return list<string> the verbs sent to Cloudflare */
    private function cloudflareVerbs(): array
    {
        return array_values(array_map(fn (string $line) => strtok($line, ' '), $this->sentToCloudflare()));
    }

    #[Test]
    public function the_managed_happy_path_goes_live_across_two_ticks(): void
    {
        $this->withStudioToken();
        $org = $this->makeOrg();
        $domain = $this->managed($org);

        $this->fakeCloudflare(array_merge([
            'GET /zones/859eddb9bce48f4f35e6197f6c0b8e15/dns_records?*' => $this->cfOk([]),
            'POST /zones/859eddb9bce48f4f35e6197f6c0b8e15/dns_records' => $this->cfOk($this->dnsRecord(self::MANAGED, 'CNAME', 'manara-renderer.pages.dev', 'rec-new')),
            self::PAGES . self::MANAGED => Http::sequence()
                ->push(['success' => false, 'errors' => [['code' => 8000007, 'message' => 'Domain not found.']], 'result' => null], 404)
                ->push(['success' => true, 'errors' => [], 'result' => $this->pagesDomainBody(self::MANAGED, 'active')]),
            'GET /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk([], ['total_count' => 5]),
            'POST /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk($this->pagesDomainBody(self::MANAGED, 'initializing')),
        ], $this->servesAs(self::MANAGED, $org->id)));

        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $domain->status);
        $this->assertSame('certificate', $domain->waiting_on);
        $this->assertSame('859eddb9bce48f4f35e6197f6c0b8e15', $domain->cf_zone_id);
        $this->assertSame('rec-new', $domain->cf_dns_record_id);
        $this->assertSame('pd-' . md5(self::MANAGED), $domain->cf_pages_domain_id);
        $this->assertNotNull($domain->stage_started_at);
        $this->assertNull($domain->liveUrl(), 'a host is not live until it has been seen serving');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/tenant'));

        $this->travel(5)->minutes();
        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $domain->status);
        $this->assertSame(MasjidDomain::VERIFIED_BY_CLOUDFLARE, $domain->verified_by);
        $this->assertNotNull($domain->verified_at);
        $this->assertNull($domain->waiting_on);
        $this->assertNotNull($domain->serving_confirmed_at);
        $this->assertSame('https://' . self::MANAGED, $domain->liveUrl());
        $this->assertNotContains('DELETE', $this->cloudflareVerbs());
    }

    #[Test]
    public function a_pages_domain_still_pending_stays_provisioning(): void
    {
        $this->withStudioToken();
        $domain = $this->managed($this->makeOrg(), MasjidDomain::STATUS_PROVISIONING, ['stage_started_at' => now()->subHours(2)]);
        $this->fakeCloudflare([self::PAGES . self::MANAGED => $this->cfOk($this->pagesDomainBody(self::MANAGED, 'pending'))]);

        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $domain->status);
        $this->assertSame('certificate', $domain->waiting_on);
        $this->assertNull($domain->last_error);
        $this->assertSame(['GET'], $this->cloudflareVerbs());
    }

    #[Test]
    public function an_unrecognised_pages_status_stays_provisioning_and_fails_at_72_hours(): void
    {
        $this->withStudioToken();
        $domain = $this->managed($this->makeOrg(), MasjidDomain::STATUS_PROVISIONING, ['stage_started_at' => now()]);
        $this->fakeCloudflare([
            self::PAGES . self::MANAGED => $this->cfOk($this->pagesDomainBody(self::MANAGED, 'queued_for_review')),
            'PATCH /accounts/*/pages/projects/manara-renderer/domains/' . self::MANAGED => $this->cfOk($this->pagesDomainBody(self::MANAGED, 'queued_for_review')),
        ]);

        $this->attacher()->advance($domain);
        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $domain->fresh()->status);
        $this->assertStringContainsString('unrecognised status "queued_for_review"', (string) $domain->fresh()->last_error);

        // Retried once after a day, and only once.
        $this->travel(25)->hours();
        $this->attacher()->advance($domain);
        $this->travel(1)->hours();
        $this->attacher()->advance($domain);
        $this->assertSame(1, count(array_filter($this->cloudflareVerbs(), fn ($verb) => $verb === 'PATCH')));
        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $domain->fresh()->status);

        $this->travel(47)->hours();
        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_FAILED, $domain->status);
        $this->assertStringContainsString('72 hours', (string) $domain->last_error);
        $this->assertNull($domain->next_check_at);
    }

    #[Test]
    public function a_pages_error_fails_the_row_with_cloudflares_own_message(): void
    {
        $this->withStudioToken();
        $domain = $this->managed($this->makeOrg(), MasjidDomain::STATUS_PROVISIONING, ['stage_started_at' => now()]);
        $this->fakeCloudflare([
            self::PAGES . self::MANAGED => $this->cfOk($this->pagesDomainBody(self::MANAGED, 'error', [
                'validation_data' => ['status' => 'error', 'error_message' => 'CAA records block certificate issuance for this host.'],
            ])),
        ]);

        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_FAILED, $domain->status);
        $this->assertStringContainsString('CAA records block certificate issuance for this host.', (string) $domain->last_error);
        $this->assertNull($domain->liveUrl());
    }

    #[Test]
    public function a_dns_record_studio_did_not_create_fails_the_row_with_no_write(): void
    {
        $this->withStudioToken();
        $domain = $this->managed($this->makeOrg());
        $this->fakeCloudflare([
            'GET /zones/*/dns_records?*' => $this->cfOk([$this->dnsRecord(self::MANAGED, 'A', '192.0.2.10')]),
        ]);

        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_FAILED, $domain->status);
        $this->assertStringContainsString('already has a DNS record (A 192.0.2.10)', (string) $domain->last_error);
        $this->assertStringContainsString('will not overwrite', (string) $domain->last_error);
        $this->assertSame(['GET'], $this->cloudflareVerbs());

        // Nothing of Cloudflare's was recorded, so Studio may still remove it.
        $this->assertNull($domain->cf_zone_id);
        $this->assertTrue($domain->deletableThroughStudio());
    }

    #[Test]
    public function without_a_token_nothing_is_sent_and_the_row_waits_on_the_token(): void
    {
        config(['cloudflare.studio_token' => null]);
        $this->resolveTo([]);
        Http::preventStrayRequests();
        Http::fake();

        $domain = $this->managed($this->makeOrg());
        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_PENDING, $domain->status);
        $this->assertSame('token', $domain->waiting_on);
        $this->assertNotNull($domain->last_checked_at);
        $this->assertNotNull($domain->next_check_at);
        $this->assertNull($domain->serving_confirmed_at);
        Http::assertNothingSent();
    }

    #[Test]
    public function without_a_token_a_probe_match_confirms_a_pending_row_as_manual_and_serving(): void
    {
        config(['cloudflare.studio_token' => null]);
        $org = $this->makeOrg();
        $domain = $this->managed($org);
        $this->fakeCloudflare($this->servesAs(self::MANAGED, $org->id));

        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_MANUAL, $domain->status);
        $this->assertSame(MasjidDomain::VERIFIED_BY_PROBE, $domain->verified_by);
        $this->assertNotNull($domain->verified_at);
        $this->assertNotNull($domain->serving_confirmed_at);
        $this->assertNull($domain->waiting_on);
        $this->assertNull($domain->next_check_at);
        $this->assertSame('https://' . self::MANAGED, $domain->liveUrl());
        $this->assertSame([], $domain->manualSteps());
        $this->assertSame([], $this->sentToCloudflare());
        $this->assertSame(['GET https://' . self::MANAGED . '/api/tenant'], $this->sent());
    }

    #[Test]
    public function without_a_token_a_probe_mismatch_leaves_the_row_pending_and_unconfirmed(): void
    {
        config(['cloudflare.studio_token' => null]);
        $org = $this->makeOrg();
        $domain = $this->managed($org);
        $this->fakeCloudflare($this->servesAs(self::MANAGED, $org->id + 1000));

        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_PENDING, $domain->status);
        $this->assertSame('token', $domain->waiting_on);
        $this->assertNull($domain->verified_at);
        $this->assertNull($domain->serving_confirmed_at);
        $this->assertNull($domain->liveUrl());
        $this->assertStringContainsString('Not serving this organisation yet', (string) $domain->last_error);
        $this->assertSame([], $this->sentToCloudflare());

        // The steps a person takes instead, in full, ending in the probe.
        $steps = $domain->manualSteps();
        $this->assertStringContainsString('add a CNAME record named al-noor.manara pointing to manara-renderer.pages.dev', $steps[0]);
        $this->assertStringContainsString('Custom domains, and add ' . self::MANAGED, $steps[1]);
        $this->assertStringContainsString('Press Check now', $steps[2]);
    }

    #[Test]
    public function a_domain_not_yet_on_cloudflare_ends_in_awaiting_nameservers(): void
    {
        $this->withStudioToken();
        $domain = $this->makeDomain($this->makeOrg(), 'www.new-masjid.org', MasjidDomain::STATUS_PENDING, ['zone_apex' => 'new-masjid.org']);
        $this->fakeCloudflare([
            'GET /zones?*' => $this->cfOk([]),
            'POST /zones' => $this->cfOk($this->zoneBody('new-masjid.org', 'pending', 'zone-new')),
        ]);

        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_AWAITING_NAMESERVERS, $domain->status);
        $this->assertSame('nameservers', $domain->waiting_on);
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $domain->nameservers);
        $this->assertSame('zone-new', $domain->cf_zone_id);
        $this->assertTrue($domain->cf_zone_created);
        $this->assertNotNull($domain->stage_started_at);
        $this->assertSame(['GET', 'POST'], $this->cloudflareVerbs());
        $this->assertStringContainsString('ada.ns.cloudflare.com, bob.ns.cloudflare.com', $domain->manualSteps()[0]);
    }

    #[Test]
    public function a_custom_host_in_a_zone_already_active_on_cloudflare_is_attached_without_creating_the_zone(): void
    {
        $this->withStudioToken();
        $domain = $this->makeDomain($this->makeOrg(), 'www.on-cloudflare.org', MasjidDomain::STATUS_PENDING, ['zone_apex' => 'on-cloudflare.org']);
        $this->fakeCloudflare([
            'GET /zones?*' => $this->cfOk([$this->zoneBody('on-cloudflare.org', 'active', 'zone-on')]),
            'GET /zones/zone-on/dns_records?*' => $this->cfOk([]),
            'POST /zones/zone-on/dns_records' => $this->cfOk($this->dnsRecord('www.on-cloudflare.org', 'CNAME', 'manara-renderer.pages.dev', 'rec-on')),
            self::PAGES . 'www.on-cloudflare.org' => $this->cfError(404, 8000007, 'Domain not found.'),
            'GET /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk([], ['total_count' => 5]),
            'POST /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk($this->pagesDomainBody('www.on-cloudflare.org', 'initializing')),
        ]);

        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $domain->status);
        $this->assertSame('zone-on', $domain->cf_zone_id);
        $this->assertFalse($domain->cf_zone_created);
        $this->assertSame('rec-on', $domain->cf_dns_record_id);
        $this->assertNotContains('POST https://api.cloudflare.com/client/v4/zones', $this->sent());
    }

    #[Test]
    public function a_zone_still_pending_after_28_days_fails_and_the_activation_check_is_rate_limited(): void
    {
        $this->withStudioToken();
        $domain = $this->makeDomain($this->makeOrg(), 'www.new-masjid.org', MasjidDomain::STATUS_AWAITING_NAMESERVERS, [
            'zone_apex' => 'new-masjid.org', 'cf_zone_id' => 'zone-new', 'cf_zone_created' => true,
            'waiting_on' => 'nameservers', 'stage_started_at' => now(),
        ]);
        $this->fakeCloudflare([
            'GET /zones/zone-new' => $this->cfOk($this->zoneBody('new-masjid.org', 'pending', 'zone-new')),
            'PUT /zones/zone-new/activation_check' => $this->cfOk(['id' => 'zone-new']),
        ]);

        $this->attacher()->advance($domain);
        $this->travel(30)->minutes();
        $this->attacher()->advance($domain);
        $this->assertSame(1, count(array_filter($this->cloudflareVerbs(), fn ($verb) => $verb === 'PUT')), 'the activation check went more than once in six hours');

        $this->travel(28)->days();
        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_FAILED, $domain->status);
        $this->assertStringContainsString('28 days', (string) $domain->last_error);
    }

    #[Test]
    public function a_refused_token_waits_on_token_scope_and_keeps_the_status(): void
    {
        $this->withStudioToken();
        $domain = $this->managed($this->makeOrg());
        $this->fakeCloudflare(['GET /zones/*/dns_records?*' => $this->cfError(403, 10000, 'Authentication error')]);

        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_PENDING, $domain->status);
        $this->assertSame('token_scope', $domain->waiting_on);
        $this->assertStringContainsString('Authentication error', (string) $domain->last_error);
        $this->assertStringContainsString('Zone › DNS: Edit', $domain->manualSteps()[0]);
    }

    #[Test]
    public function an_imported_row_is_promoted_by_gets_only_and_never_failed(): void
    {
        $this->withStudioToken();
        $org = $this->makeOrg();
        $confirmed = now()->subDay();
        $live = $this->makeDomain($org, 'mec.manara.hopetechapps.com', MasjidDomain::STATUS_MANUAL, [
            'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN, 'zone_apex' => 'hopetechapps.com',
            'source' => MasjidDomain::SOURCE_IMPORTED, 'verified_by' => MasjidDomain::VERIFIED_BY_PROBE,
            'verified_at' => $confirmed, 'serving_confirmed_at' => $confirmed,
        ]);
        $broken = $this->makeDomain($org, 'www.burlingtonmasjid.com', MasjidDomain::STATUS_MANUAL, [
            'source' => MasjidDomain::SOURCE_IMPORTED, 'verified_by' => MasjidDomain::VERIFIED_BY_PROBE,
            'verified_at' => $confirmed, 'serving_confirmed_at' => $confirmed,
        ]);

        $this->fakeCloudflare([
            self::PAGES . 'mec.manara.hopetechapps.com' => $this->cfOk($this->pagesDomainBody('mec.manara.hopetechapps.com', 'active')),
            self::PAGES . 'www.burlingtonmasjid.com' => $this->cfOk($this->pagesDomainBody('www.burlingtonmasjid.com', 'error', [
                'verification_data' => ['status' => 'error', 'error_message' => 'Something Cloudflare says'],
            ])),
        ]);

        $this->attacher()->advance($live);
        $this->attacher()->advance($broken);
        $live->refresh();
        $broken->refresh();

        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $live->status);
        $this->assertSame(MasjidDomain::VERIFIED_BY_CLOUDFLARE, $live->verified_by);
        $this->assertSame('pd-' . md5('mec.manara.hopetechapps.com'), $live->cf_pages_domain_id);
        $this->assertSame($confirmed->toDateTimeString(), $live->serving_confirmed_at->toDateTimeString(), 'the earlier confirmation stands');
        $this->assertSame(MasjidDomain::SOURCE_IMPORTED, $live->source);

        $this->assertSame(MasjidDomain::STATUS_MANUAL, $broken->status, 'an imported row is never failed by what Cloudflare says');
        $this->assertNull($broken->last_error);

        $this->assertSame(['GET', 'GET'], $this->cloudflareVerbs());
        $this->assertSame([], array_values(array_filter($this->sent(), fn ($line) => ! str_starts_with($line, 'GET '))));
    }

    #[Test]
    public function a_reserved_row_is_never_advanced_with_or_without_a_token(): void
    {
        $org = $this->makeOrg();
        $reserved = $this->makeDomain($org, 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, [
            'zone_apex' => 'meccharlotte.org', 'source' => MasjidDomain::SOURCE_IMPORTED,
        ]);
        $before = $reserved->fresh()->getAttributes();

        // The host answering as its organisation must not move it either.
        $this->fakeCloudflare($this->servesAs('meccharlotte.org', $org->id));

        config(['cloudflare.studio_token' => null]);
        $this->attacher()->advance($reserved);
        $this->withStudioToken();
        $this->attacher()->advance($reserved);

        $this->assertSame($before, $reserved->fresh()->getAttributes());
        $this->assertSame([], $this->sent());
    }

    #[Test]
    public function an_active_row_is_probed_and_a_match_confirms_serving_without_calling_cloudflare(): void
    {
        $this->withStudioToken();
        $org = $this->makeOrg();
        $domain = $this->managed($org, MasjidDomain::STATUS_ACTIVE, [
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now(),
        ]);
        $this->fakeCloudflare($this->servesAs(self::MANAGED, $org->id));

        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $domain->status);
        $this->assertNotNull($domain->serving_confirmed_at);
        $this->assertNull($domain->next_check_at);
        $this->assertSame([], $this->sentToCloudflare());

        // A later miss does not take the confirmation back (plan §6): "Check
        // now" without a token probes again, and the host answers for someone
        // else this time.
        $stamp = $domain->serving_confirmed_at->toDateTimeString();
        config(['cloudflare.studio_token' => null]);
        $this->fakeCloudflare($this->servesAs(self::MANAGED, $org->id + 1000));
        $this->travel(1)->hours();
        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame($stamp, $domain->serving_confirmed_at->toDateTimeString());
        $this->assertSame('https://' . self::MANAGED, $domain->liveUrl());
        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $domain->status);
    }

    #[Test]
    public function a_held_lock_makes_advance_a_no_op(): void
    {
        $this->withStudioToken();
        $domain = $this->managed($this->makeOrg());
        $before = $domain->fresh()->getAttributes();
        $this->fakeCloudflare([]);

        $held = Cache::lock('masjid-domain:' . $domain->id, 120);
        $this->assertTrue($held->get());

        $this->attacher()->advance($domain);

        $this->assertSame($before, $domain->fresh()->getAttributes());
        $this->assertSame([], $this->sent());

        $held->release();
    }

    #[Test]
    public function stage_started_at_is_a_nullable_timestamp(): void
    {
        $this->assertContains(Schema::getColumnType('masjid_domains', 'stage_started_at'), ['datetime', 'timestamp']);
        $this->assertNull($this->managed($this->makeOrg())->fresh()->stage_started_at);
    }
}
