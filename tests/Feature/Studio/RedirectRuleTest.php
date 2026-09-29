<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareResult;
use App\Services\Cloudflare\CloudflareService;
use App\Services\Domains\DomainAttacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesRedirectDomains;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * W2 S5: a redirect host gets a proxied placeholder record and one Single
 * Redirect rule, APPENDED to the zone's entry point and never replacing it,
 * and only in a zone Studio created or the owner listed.
 */
class RedirectRuleTest extends TestCase
{
    use FakesCloudflare;
    use MakesRedirectDomains;
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withStudioToken();
        $this->resolveTo(['93.184.216.34']);
        config(['cloudflare.redirect_zones' => [self::APEX]]);
    }

    private function attacher(): DomainAttacher
    {
        return $this->app->make(DomainAttacher::class);
    }

    /** @return list<string> the verbs sent to Cloudflare */
    private function verbs(): array
    {
        return array_values(array_map(fn (string $line) => strtok($line, ' '), $this->sentToCloudflare()));
    }

    #[Test]
    public function an_existing_ruleset_gets_one_rule_appended_never_replaced(): void
    {
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org);
        $apex = $this->redirectRow($org, $www);

        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([$this->clientRule()])),
            'GET /zones/zone-pair/dns_records?*' => $this->cfOk([]),
            'POST /zones/zone-pair/dns_records' => $this->cfOk($this->dnsRecord(self::APEX, 'A', '192.0.2.1', 'rec-apex')),
            'POST /zones/zone-pair/rulesets/rs-1/rules' => $this->cfOk($this->ruleset([$this->clientRule(), $this->studioRule($apex)])),
        ]);

        $this->attacher()->advance($apex);
        $apex->refresh();

        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $apex->status);
        $this->assertSame('rule-studio', $apex->cf_redirect_rule_id);
        $this->assertSame('rec-apex', $apex->cf_dns_record_id);
        $this->assertTrue($apex->cf_dns_record_created);
        $this->assertSame(self::PAIR_ZONE, $apex->cf_zone_id);

        $this->assertNotContains('PUT', $this->verbs(), 'the ruleset is never replaced');
        $this->assertNotContains('DELETE', $this->verbs());
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/zones/zone-pair/dns_records')
            && $r['type'] === 'A' && $r['content'] === '192.0.2.1' && $r['proxied'] === true && $r['name'] === self::APEX);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/rulesets/rs-1/rules')
            && $r['ref'] === 'manara-studio-redirect-' . $apex->id
            && $r['expression'] === 'http.host eq "pair-masjid.org"'
            && $r['action'] === 'redirect'
            && $r['action_parameters']['from_value']['status_code'] === 301
            && $r['action_parameters']['from_value']['target_url']['expression'] === 'concat("https://www.pair-masjid.org", http.request.uri.path)'
            && $r['action_parameters']['from_value']['preserve_query_string'] === true
            && ! isset($r['rules']));
    }

    #[Test]
    public function a_missing_entry_point_is_created_with_one_rule(): void
    {
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org);
        $apex = $this->redirectRow($org, $www);

        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => $this->cfError(404, 10003, 'Not found'),
            'GET /zones/zone-pair/dns_records?*' => $this->cfOk([]),
            'POST /zones/zone-pair/dns_records' => $this->cfOk($this->dnsRecord(self::APEX, 'A', '192.0.2.1', 'rec-apex')),
            'POST /zones/zone-pair/rulesets' => $this->cfOk($this->ruleset([$this->studioRule($apex)], 'rs-new')),
        ]);

        $this->attacher()->advance($apex);

        $this->assertSame('rule-studio', $apex->fresh()->cf_redirect_rule_id);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/zones/zone-pair/rulesets')
            && $r['phase'] === 'http_request_dynamic_redirect'
            && $r['kind'] === 'zone'
            && count($r['rules']) === 1
            && $r['rules'][0]['ref'] === 'manara-studio-redirect-' . $apex->id);
        $this->assertNotContains('PUT', $this->verbs());
    }

    #[Test]
    public function a_zone_neither_studio_created_nor_allowlisted_is_refused_before_any_request(): void
    {
        config(['cloudflare.redirect_zones' => []]);
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org);
        $apex = $this->redirectRow($org, $www);
        $this->fakeCloudflare([]);

        $this->attacher()->advance($apex);
        $apex->refresh();

        $this->assertSame([], $this->sent());
        $this->assertSame(MasjidDomain::STATUS_FAILED, $apex->status);
        $this->assertStringContainsString('cloudflare.redirect_zones', (string) $apex->last_error);
        $this->assertStringContainsString('create a Single Redirect', implode(' ', $apex->manualSteps()));
    }

    #[Test]
    public function a_zone_studio_created_is_allowed_without_the_allowlist(): void
    {
        config(['cloudflare.redirect_zones' => []]);
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org, ['cf_zone_created' => true]);
        $apex = $this->redirectRow($org, $www);
        $this->assertTrue($apex->redirectZoneAllowed());

        $this->assertFalse($this->redirectRow($this->makeOrg(), $this->makeDomain($this->makeOrg(), 'www.burlingtonmasjid.com', MasjidDomain::STATUS_MANUAL, ['source' => MasjidDomain::SOURCE_IMPORTED]), MasjidDomain::STATUS_PENDING, [
            'host' => 'burlingtonmasjid.com', 'zone_apex' => 'burlingtonmasjid.com',
        ])->redirectZoneAllowed(), 'a live zone Studio did not create');
    }

    #[Test]
    public function the_allowlist_ships_empty(): void
    {
        $this->assertSame([], (require base_path('config/cloudflare.php'))['redirect_zones']);
    }

    #[Test]
    public function without_the_scope_the_row_waits_on_token_scope_with_manual_steps(): void
    {
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org);
        $apex = $this->redirectRow($org, $www);
        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => $this->cfError(403, 10000, 'Authentication error'),
        ]);

        $this->attacher()->advance($apex);
        $apex->refresh();

        $this->assertSame(['GET'], $this->verbs(), 'no placeholder record for a rule that cannot follow');
        $this->assertSame(MasjidDomain::STATUS_PENDING, $apex->status);
        $this->assertSame('token_scope', $apex->waiting_on);
        $steps = implode(' ', $apex->manualSteps());
        $this->assertStringContainsString('Zone › Single Redirect: Edit', $steps);
        $this->assertStringContainsString('create a Single Redirect: when the hostname equals pair-masjid.org', $steps);
        $this->assertStringContainsString('concat("https://www.pair-masjid.org", http.request.uri.path)', $steps);
    }

    #[Test]
    public function a_rule_studio_already_wrote_is_adopted_and_a_foreign_record_is_never_overwritten(): void
    {
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org);
        $apex = $this->redirectRow($org, $www);
        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([$this->studioRule($apex, 'rule-old')])),
            'GET /zones/zone-pair/dns_records?*' => $this->cfOk([$this->dnsRecord(self::APEX, 'A', '192.0.2.1', 'rec-old')]),
        ]);

        $this->attacher()->advance($apex);
        $apex->refresh();

        $this->assertSame('rule-old', $apex->cf_redirect_rule_id);
        $this->assertSame('rec-old', $apex->cf_dns_record_id);
        $this->assertFalse($apex->cf_dns_record_created, 'adopted, so never deleted by detach');
        $this->assertSame(['GET', 'GET', 'GET'], $this->verbs());

        // An apex that already points somewhere is left alone and the row fails.
        $other = $this->redirectRow($this->makeOrg(), $this->canonicalRow($this->makeOrg(), ['host' => 'www.other-pair.org', 'zone_apex' => 'other-pair.org', 'cf_zone_id' => 'zone-other']), MasjidDomain::STATUS_PENDING, [
            'host' => 'other-pair.org', 'zone_apex' => 'other-pair.org',
        ]);
        config(['cloudflare.redirect_zones' => ['other-pair.org']]);
        $this->fakeCloudflare([
            'GET /zones/zone-other/rulesets/phases/http_request_dynamic_redirect/entrypoint' => $this->cfOk($this->ruleset([])),
            'GET /zones/zone-other/dns_records?*' => $this->cfOk([$this->dnsRecord('other-pair.org', 'A', '198.51.100.9', 'rec-theirs')]),
        ]);
        $this->attacher()->advance($other);

        $this->assertSame(MasjidDomain::STATUS_FAILED, $other->fresh()->status);
        $this->assertStringContainsString('will not overwrite', (string) $other->fresh()->last_error);
        $this->assertNotContains('POST', $this->verbs());
    }

    #[Test]
    public function a_redirect_waits_for_its_canonical_host_to_have_a_zone(): void
    {
        $org = $this->makeOrg();
        $www = $this->makeDomain($org, self::WWW, MasjidDomain::STATUS_PENDING, ['zone_apex' => self::APEX]);
        $apex = $this->redirectRow($org, $www);
        $this->fakeCloudflare([]);

        $this->attacher()->advance($apex);

        $this->assertSame('canonical', $apex->fresh()->waiting_on);
        $this->assertSame([], $this->sent());

        // A zone id is recorded while a new zone still waits on its
        // nameservers (up to 28 days): still not ready.
        $www->forceFill(['status' => MasjidDomain::STATUS_AWAITING_NAMESERVERS, 'cf_zone_id' => self::PAIR_ZONE, 'waiting_on' => 'nameservers'])->save();
        $this->attacher()->advance($apex);

        $this->assertSame('canonical', $apex->fresh()->waiting_on);
        $this->assertSame(MasjidDomain::STATUS_PENDING, $apex->fresh()->status);
        $this->assertSame([], $this->sent());
    }

    #[Test]
    public function the_new_service_methods_send_nothing_without_a_token(): void
    {
        config(['cloudflare.studio_token' => null]);
        $this->fakeCloudflare([]);
        $service = $this->app->make(CloudflareService::class);

        foreach ([
            $service->ensureProxiedPlaceholder('z', self::APEX),
            $service->getRedirectEntrypoint('z'),
            $service->ensureRedirectRule('z', 'manara-studio-redirect-1', self::APEX, self::WWW),
        ] as $result) {
            $this->assertSame(CloudflareResult::NOT_CONFIGURED, $result->outcome);
        }

        $this->assertSame([], $this->sent());
    }
}
