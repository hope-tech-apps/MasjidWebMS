<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Domains\DomainAttacher;
use GuzzleHttp\Exception\ConnectException;
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

        // A minute short of 72 hours the row is still waiting: a certificate
        // that is merely slow is not failed early.
        $this->travel(45 * 60 + 59)->minutes();
        $this->attacher()->advance($domain);
        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $domain->fresh()->status, 'failed before 72 hours');
        $this->assertStringNotContainsString('72 hours', (string) $domain->fresh()->last_error);

        $this->travel(2)->minutes();
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

        // An hour short of 28 days the zone is still waited for: a registrar
        // that takes days to switch nameservers is not failed early.
        $this->travel(27 * 24 * 60 + 23 * 60 - 30)->minutes();
        $this->attacher()->advance($domain);
        $this->assertSame(MasjidDomain::STATUS_AWAITING_NAMESERVERS, $domain->fresh()->status, 'failed before 28 days');
        $this->assertSame('nameservers', $domain->fresh()->waiting_on);

        $this->travel(2)->hours();
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
    public function a_custom_host_that_waited_for_its_nameservers_and_meets_a_dns_conflict_is_sent_to_check_now(): void
    {
        $this->withStudioToken();
        $domain = $this->makeDomain($this->makeOrg(), 'www.new-masjid.org', MasjidDomain::STATUS_AWAITING_NAMESERVERS, [
            'zone_apex' => 'new-masjid.org', 'cf_zone_id' => 'zone-new', 'cf_zone_created' => true,
            'waiting_on' => 'nameservers', 'stage_started_at' => now()->subDay(),
        ]);
        $this->fakeCloudflare([
            'GET /zones/zone-new' => $this->cfOk($this->zoneBody('new-masjid.org', 'active', 'zone-new')),
            'GET /zones/zone-new/dns_records?*' => $this->cfOk([$this->dnsRecord('www.new-masjid.org', 'A', '192.0.2.10')]),
        ]);

        $this->attacher()->advance($domain);
        $domain->refresh();

        // The zone it waited for is still recorded, so Studio cannot delete
        // the row and cannot take the host again: remove-and-add is no way out.
        $this->assertSame(MasjidDomain::STATUS_FAILED, $domain->status);
        $this->assertSame('zone-new', $domain->cf_zone_id);
        $this->assertFalse($domain->deletableThroughStudio());
        $this->assertStringContainsString('already has a DNS record (A 192.0.2.10)', (string) $domain->last_error);
        $this->assertStringNotContainsString('add the domain again', (string) $domain->last_error);

        $steps = $domain->manualSteps();
        $this->assertStringContainsString('press Check now', $steps[1]);
        $this->assertStringNotContainsString('add it again', implode(' ', $steps));
        $this->assertSame(['GET', 'GET'], $this->cloudflareVerbs());
    }

    #[Test]
    public function a_zone_whose_create_answer_was_lost_is_still_recorded_as_one_studio_added(): void
    {
        $this->withStudioToken();
        $domain = $this->makeDomain($this->makeOrg(), 'www.new-masjid.org', MasjidDomain::STATUS_PENDING, ['zone_apex' => 'new-masjid.org']);
        $made = array_replace($this->zoneBody('new-masjid.org', 'pending', 'zone-new'), ['created_on' => now()->utc()->format('Y-m-d\TH:i:s\Z')]);
        $this->fakeCloudflare([
            'GET /zones?*' => Http::sequence()->pushResponse($this->cfOk([]))->pushResponse($this->cfOk([$made])),
            // Cloudflare makes the zone, and the answer never arrives.
            'POST /zones' => fn (Request $r) => throw new ConnectException('cURL error 28: Operation timed out', $r->toPsrRequest()),
        ]);

        $this->attacher()->advance($domain);
        $domain->refresh();
        $this->assertSame(MasjidDomain::STATUS_PENDING, $domain->status);
        $this->assertStringContainsString('did not answer', (string) $domain->last_error);

        $this->travel(5)->minutes();
        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame(MasjidDomain::STATUS_AWAITING_NAMESERVERS, $domain->status);
        $this->assertSame('zone-new', $domain->cf_zone_id);
        $this->assertTrue($domain->cf_zone_created, 'the zone Studio added was recorded as one it only found');
        $this->assertStringContainsString('Studio added the new-masjid.org zone', implode(' ', $domain->removalSteps()));
        $this->assertSame(['GET', 'POST', 'GET'], $this->cloudflareVerbs());
    }

    #[Test]
    public function a_zone_found_after_a_lost_create_but_made_long_before_it_is_not_claimed(): void
    {
        $this->withStudioToken();
        $domain = $this->makeDomain($this->makeOrg(), 'www.new-masjid.org', MasjidDomain::STATUS_PENDING, ['zone_apex' => 'new-masjid.org']);
        $older = array_replace($this->zoneBody('new-masjid.org', 'pending', 'zone-old'), ['created_on' => now()->subDays(3)->utc()->format('Y-m-d\TH:i:s\Z')]);
        $this->fakeCloudflare([
            'GET /zones?*' => Http::sequence()->pushResponse($this->cfOk([]))->pushResponse($this->cfOk([$older])),
            'POST /zones' => $this->cfError(503, 10000, 'Service unavailable'),
        ]);

        $this->attacher()->advance($domain);
        $this->travel(5)->minutes();
        $this->attacher()->advance($domain);
        $domain->refresh();

        $this->assertSame('zone-old', $domain->cf_zone_id);
        $this->assertFalse($domain->cf_zone_created);
    }

    #[Test]
    public function without_a_token_an_apex_is_told_to_move_its_nameservers_not_to_add_an_alias(): void
    {
        config(['cloudflare.studio_token' => null]);
        $org = $this->makeOrg();
        $apex = $this->makeDomain($org, 'new-masjid.org', MasjidDomain::STATUS_PENDING, ['zone_apex' => 'new-masjid.org', 'waiting_on' => 'token']);

        $steps = implode(' ', $apex->manualSteps());

        // Pages serves an apex only from a zone on the account (see manualSteps()).
        $this->assertStringNotContainsString('ALIAS', $steps);
        $this->assertStringContainsString('add new-masjid.org as a domain', $steps);
        $this->assertStringContainsString('replace the nameservers', $steps);
        $this->assertStringContainsString('email (MX)', $steps);
        $this->assertStringContainsString('28 days', $steps);
        $this->assertStringContainsString('Custom domains, and add new-masjid.org', $steps);
        $this->assertStringContainsString('Press Check now', $steps);

        // A subdomain keeps its CNAME at whatever DNS provider it has.
        $www = $this->makeDomain($org, 'www.other-masjid.org', MasjidDomain::STATUS_PENDING, ['waiting_on' => 'token']);
        $this->assertStringContainsString('add a CNAME record for www.other-masjid.org pointing to manara-renderer.pages.dev', $www->manualSteps()[0]);
    }

    #[Test]
    public function a_row_changed_after_the_caller_loaded_it_is_judged_by_what_is_stored_now(): void
    {
        $this->withStudioToken();
        $stale = $this->managed($this->makeOrg());

        // The job failed the row while domains:reconcile still held its pending copy.
        MasjidDomain::query()->whereKey($stale->id)->update(['status' => MasjidDomain::STATUS_FAILED, 'last_error' => 'The job failed it first.']);
        $before = MasjidDomain::findOrFail($stale->id)->getAttributes();
        $this->assertSame(MasjidDomain::STATUS_PENDING, $stale->status, 'the premise: the caller holds a stale copy');

        $this->fakeCloudflare([
            'GET /zones/*/dns_records?*' => $this->cfOk([]),
            'POST /zones/*/dns_records' => $this->cfOk($this->dnsRecord(self::MANAGED, 'CNAME', 'manara-renderer.pages.dev', 'rec-new')),
            self::PAGES . self::MANAGED => $this->cfError(404, 8000007, 'Domain not found.'),
            'GET /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk([], ['total_count' => 5]),
            'POST /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk($this->pagesDomainBody(self::MANAGED, 'initializing')),
        ]);

        $this->attacher()->advance($stale);

        $this->assertSame($before, MasjidDomain::findOrFail($stale->id)->getAttributes());
        $this->assertSame([], $this->sent());
    }

    #[Test]
    public function check_now_restarts_only_a_row_that_is_still_failed_and_not_held(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([]);
        $org = $this->makeOrg();

        // Another Check now already moved the row on; this caller holds the failed copy.
        $stale = $this->managed($org, MasjidDomain::STATUS_FAILED, ['last_error' => 'The first attempt failed.']);
        MasjidDomain::query()->whereKey($stale->id)->update([
            'status' => MasjidDomain::STATUS_PROVISIONING, 'last_error' => null, 'cf_zone_id' => 'zone-managed',
            'cf_dns_record_id' => 'rec-1', 'cf_pages_domain_id' => 'pd-1', 'waiting_on' => 'certificate', 'stage_started_at' => now(),
        ]);
        $before = MasjidDomain::findOrFail($stale->id)->getAttributes();
        $this->assertSame(MasjidDomain::STATUS_FAILED, $stale->status, 'the premise: the caller holds a stale copy');

        $this->attacher()->restart($stale);
        $this->assertSame($before, MasjidDomain::findOrFail($stale->id)->getAttributes(), 'a stale failed copy reset a row that had moved on');

        // A row whose lock is held is left to the holder.
        $held = $this->makeDomain($org, 'www.held-masjid.org', MasjidDomain::STATUS_FAILED, ['last_error' => 'It failed.']);
        $lock = DomainAttacher::lockFor($held->id);
        $this->assertTrue($lock->get());
        $this->attacher()->restart($held);
        $this->assertSame(MasjidDomain::STATUS_FAILED, $held->fresh()->status, 'Check now reset a row while another writer held it');
        $lock->release();

        // Free and still failed: back to pending, clocks cleared.
        $this->attacher()->restart($held);
        $this->assertSame(MasjidDomain::STATUS_PENDING, $held->fresh()->status);
        $this->assertNull($held->fresh()->last_error);
        $this->assertSame([], $this->sent());
    }

    #[Test]
    public function an_imported_row_in_a_moving_status_is_still_only_read(): void
    {
        $this->withStudioToken();
        $org = $this->makeOrg();
        $pending = $this->makeDomain($org, 'www.imported-one.org', MasjidDomain::STATUS_PENDING, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $provisioning = $this->makeDomain($org, 'www.imported-two.org', MasjidDomain::STATUS_PROVISIONING, [
            'source' => MasjidDomain::SOURCE_IMPORTED, 'stage_started_at' => now()->subHours(80),
        ]);

        // Every write the attach path could make is answered, so a row sent
        // down it shows up as a write rather than as a stray request.
        $this->fakeCloudflare([
            self::PAGES . 'www.imported-one.org' => $this->cfOk($this->pagesDomainBody('www.imported-one.org', 'pending')),
            self::PAGES . 'www.imported-two.org' => $this->cfOk($this->pagesDomainBody('www.imported-two.org', 'pending')),
            'GET /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk([], ['total_count' => 5]),
            'GET /zones?*' => $this->cfOk([$this->zoneBody('imported-one.org', 'active', 'zone-imported')]),
            'GET /zones/*/dns_records?*' => $this->cfOk([]),
            'POST *' => $this->cfOk(['id' => 'made']),
            'PATCH *' => $this->cfOk(['id' => 'made']),
            'PUT *' => $this->cfOk(['id' => 'made']),
        ]);

        $this->attacher()->advance($pending);
        $this->attacher()->advance($provisioning);

        $this->assertSame(MasjidDomain::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $provisioning->fresh()->status, 'an imported row is never failed by the 72-hour clock');
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
    public function the_row_lock_outlives_the_slowest_step_cloudflare_can_make(): void
    {
        // The slowest step: a custom host whose zone the account lacks, made
        // and active at once, whose CNAME post loses an "already exists" race,
        // and whose Pages domain is new.
        $this->withStudioToken();
        $host = 'www.new-masjid.org';
        $domain = $this->makeDomain($this->makeOrg(), $host, MasjidDomain::STATUS_PENDING, ['zone_apex' => 'new-masjid.org']);
        $this->fakeCloudflare([
            'GET /zones?*' => $this->cfOk([]),
            'POST /zones' => $this->cfOk($this->zoneBody('new-masjid.org', 'active', 'zone-new')),
            'GET /zones/zone-new/dns_records?*' => Http::sequence()
                ->pushResponse($this->cfOk([]))
                ->pushResponse($this->cfOk([$this->dnsRecord($host, 'CNAME', 'manara-renderer.pages.dev', 'rec-race')])),
            'POST /zones/zone-new/dns_records' => $this->cfError(400, 81053, 'An A, AAAA, or CNAME record with that host already exists.'),
            self::PAGES . $host => $this->cfError(404, 8000007, 'Domain not found.'),
            'GET /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk([], ['total_count' => 5]),
            'POST /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk($this->pagesDomainBody($host, 'initializing')),
        ]);

        $this->attacher()->advance($domain);

        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $domain->fresh()->status, 'the premise: the step ran the whole way');
        $this->assertSame(['GET', 'POST', 'GET', 'POST', 'GET', 'GET', 'GET', 'POST'], $this->cloudflareVerbs());
        $this->assertCount(DomainAttacher::MAX_CLOUDFLARE_REQUESTS_PER_STEP, $this->sentToCloudflare());

        // Each of those requests may take its connect timeout and its total
        // timeout (CloudflareService::client()); the lock must outlast them all.
        $timeout = (int) config('cloudflare.timeout');
        $slowest = DomainAttacher::MAX_CLOUDFLARE_REQUESTS_PER_STEP * ($timeout + $timeout);
        $this->assertGreaterThanOrEqual($slowest, DomainAttacher::LOCK_SECONDS);

        // And lockFor() takes it for that long: a second writer is still kept
        // out once the slowest step's time has passed.
        $step = DomainAttacher::lockFor($domain->id);
        $this->assertTrue($step->get());
        $this->travel($slowest)->seconds();
        $this->assertFalse(DomainAttacher::lockFor($domain->id)->get(), 'the lock ran out while the slowest step could still be running');
        $step->release();
    }

    #[Test]
    public function stage_started_at_is_a_nullable_timestamp(): void
    {
        $this->assertContains(Schema::getColumnType('masjid_domains', 'stage_started_at'), ['datetime', 'timestamp']);
        $this->assertNull($this->managed($this->makeOrg())->fresh()->stage_started_at);
    }
}
