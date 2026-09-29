<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use App\Services\Domains\DetachResult;
use App\Services\Domains\DomainAttacher;
use App\Services\Domains\DomainDetacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesDetachableDomains;
use Tests\Feature\Studio\Concerns\MakesRedirectDomains;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * DomainDetacher (W2 S3): a detached host stops being served at once, Studio
 * removes what it created and nothing else, and a removal that stops part-way
 * is finished by the poller.
 */
class DomainDetacherTest extends TestCase
{
    use FakesCloudflare;
    use MakesDetachableDomains;
    use MakesRedirectDomains;
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withStudioToken();
        $this->resolveTo(['93.184.216.34']);
    }

    private function detacher(): DomainDetacher
    {
        return $this->app->make(DomainDetacher::class);
    }

    #[Test]
    public function a_detaching_row_is_not_served_by_the_lookup(): void
    {
        $org = $this->makeOrg();
        $row = $this->attachedRow($org);
        $this->assertSame([$row->id], MasjidDomain::query()->served()->pluck('id')->all(), 'the premise: served');
        $this->assertSame(['https://' . self::HOST], MasjidDomain::corsOrigins(), 'the premise: CORS-admitted');

        // Cloudflare is down: the removal stops before anything is deleted.
        $this->fakeCloudflare(['GET ' . $this->pagesPath() => $this->cfError(503, 0, 'Service unavailable')]);

        $result = $this->detacher()->detach($row, 7);

        $this->assertSame(DetachResult::PENDING, $result->outcome);
        $this->assertSame(MasjidDomain::STATUS_DETACHING, $row->fresh()->status);
        $this->assertSame([], MasjidDomain::query()->served()->pluck('id')->all());
        $this->assertSame([], MasjidDomain::query()->corsAdmitted()->pluck('id')->all());
        $this->assertSame([], MasjidDomain::corsOrigins(), 'the save forgot the cached CORS list');
        $this->getJson('/api/v1/organizations/by-host?host=' . self::HOST)->assertNotFound();
    }

    #[Test]
    public function full_success_deletes_the_row_and_forgets_the_cors_key(): void
    {
        $row = $this->attachedRow($this->makeOrg());
        MasjidDomain::corsOrigins();
        $this->assertTrue(Cache::has(MasjidDomain::CORS_ORIGINS_CACHE_KEY), 'the premise: the list is cached');
        $this->fakeCloudflare($this->cloudflareAsStudioLeftIt());
        Log::spy();

        $result = $this->detacher()->detach($row, 7);

        $this->assertSame(DetachResult::DETACHED, $result->outcome);
        $this->assertCount(2, $result->removed);
        $this->assertSame([], $result->manualSteps);
        $this->assertNull(MasjidDomain::find($row->id));
        $this->assertFalse(Cache::has(MasjidDomain::CORS_ORIGINS_CACHE_KEY));

        // The Pages domain first, then the record: one read and one delete each.
        $this->assertCount(4, $this->cloudflareCalls());
        $this->assertCount(2, $this->deletes());
        $this->assertStringContainsString('/pages/projects/manara-renderer/domains/' . self::HOST, $this->deletes()[0]);
        $this->assertSame('DELETE ' . $this->recordPath(), $this->deletes()[1]);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Studio detached a web address.'
            && $context['host'] === self::HOST && $context['actor_user_id'] === 7)->once();
    }

    #[Test]
    public function a_partial_failure_keeps_the_row_detaching_and_reconcile_retries_it(): void
    {
        $row = $this->attachedRow($this->makeOrg());
        $routes = $this->cloudflareAsStudioLeftIt();
        $this->fakeCloudflare(['GET ' . $this->recordPath() => $this->cfError(503, 0, 'Service unavailable')] + $routes);

        $this->assertSame(DetachResult::PENDING, $this->detacher()->detach($row)->outcome);
        $row->refresh();

        $this->assertSame(MasjidDomain::STATUS_DETACHING, $row->status);
        $this->assertNull($row->cf_pages_domain_id, 'the Pages domain went, and the row forgot it');
        $this->assertFalse($row->cf_pages_domain_created);
        $this->assertSame(self::RECORD, $row->cf_dns_record_id);
        $this->assertStringContainsString('Service unavailable', (string) $row->last_error);
        $this->assertNotNull($row->next_check_at);
        $this->assertStringContainsString('press Detach to try now', implode(' ', $row->manualSteps()));

        // Not due yet: the poller leaves it.
        $this->artisan('domains:reconcile')->assertExitCode(0);
        $this->assertCount(1, $this->deletes());

        $this->travel(6)->minutes();
        $this->fakeCloudflare($routes);
        $this->artisan('domains:reconcile')->assertExitCode(0);

        $this->assertNull(MasjidDomain::find($row->id));
        $this->assertSame(1, count(array_filter($this->deletes(), fn (string $line) => str_contains($line, '/pages/projects/'))),
            'the Pages domain was not deleted twice');
        $this->assertContains('DELETE ' . $this->recordPath(), $this->deletes());
    }

    #[Test]
    public function a_trashed_organisations_detach_is_still_finished_by_the_poller(): void
    {
        $org = $this->makeOrg();
        $row = $this->attachedRow($org, ['status' => MasjidDomain::STATUS_DETACHING]);
        $org->delete();
        $this->fakeCloudflare($this->cloudflareAsStudioLeftIt());

        $this->artisan('domains:reconcile')->assertExitCode(0);

        $this->assertNull(MasjidDomain::find($row->id));
        $this->assertCount(2, $this->deletes());
    }

    #[Test]
    public function what_studio_did_not_create_is_left_and_named(): void
    {
        $row = $this->attachedRow($this->makeOrg(), [
            'cf_pages_domain_created' => false,
            'cf_zone_created' => true,
        ]);
        $this->fakeCloudflare($this->cloudflareAsStudioLeftIt());

        $result = $this->detacher()->detach($row);

        $this->assertSame(DetachResult::DETACHED, $result->outcome);
        $this->assertCount(1, $result->removed, 'the CNAME, which Studio made');
        $this->assertSame(['DELETE ' . $this->recordPath()], $this->deletes());
        $steps = implode(' ', $result->manualSteps);
        $this->assertStringContainsString('Custom domains, and remove ' . self::HOST, $steps);
        $this->assertStringContainsString('Studio added the new-masjid.org zone', $steps);
        $this->assertNotContains('DELETE /zones/' . self::ZONE, $this->cloudflareCalls(), 'never the zone');
        $this->assertNull(MasjidDomain::find($row->id));
    }

    #[Test]
    public function an_object_changed_since_studio_made_it_is_left_and_named(): void
    {
        $row = $this->attachedRow($this->makeOrg());
        $this->fakeCloudflare([
            'GET ' . $this->recordPath() => $this->cfOk($this->dnsRecord(self::HOST, 'CNAME', 'shop.example.com', self::RECORD)),
        ] + $this->cloudflareAsStudioLeftIt());

        $result = $this->detacher()->detach($row);

        $this->assertSame(DetachResult::DETACHED, $result->outcome);
        $this->assertCount(1, $this->deletes(), 'the Pages domain only');
        $this->assertStringContainsString('has changed since Studio created it', implode(' ', $result->manualSteps));
    }

    #[Test]
    public function a_held_lock_is_a_409(): void
    {
        $row = $this->attachedRow($this->makeOrg());
        $this->fakeCloudflare([]);
        $lock = DomainAttacher::lockFor($row->id);
        $this->assertTrue($lock->get());
        $before = $row->fresh()->getAttributes();

        $result = $this->detacher()->detach($row);

        $this->assertSame(DetachResult::BUSY, $result->outcome);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame([], $this->sent());
        $lock->release();
    }

    #[Test]
    public function the_attacher_never_advances_a_detaching_row(): void
    {
        $row = $this->attachedRow($this->makeOrg(), ['status' => MasjidDomain::STATUS_DETACHING, 'serving_confirmed_at' => null]);
        $this->fakeCloudflare([]);
        $before = $row->fresh()->getAttributes();

        $this->app->make(DomainAttacher::class)->advance($row);
        $this->assertTrue($this->app->make(DomainAttacher::class)->checkNow($row));

        $this->assertSame([], $this->sent());
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    #[Test]
    public function an_imported_or_adopted_row_is_refused(): void
    {
        $org = $this->makeOrg();
        $imported = $this->attachedRow($org, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $this->fakeCloudflare([]);

        $result = $this->detacher()->detach($imported);
        $this->assertSame(DetachResult::REFUSED, $result->outcome);
        $this->assertStringContainsString('imported from the live host map', implode(' ', $result->manualSteps));

        // As `domains:imported adopt` leaves a row (W2 S6), written past the
        // model, which refuses that change anywhere but in the tool.
        MasjidDomain::query()->whereKey($imported->id)->update(['source' => MasjidDomain::SOURCE_STUDIO, 'adopted_from_import_at' => now()]);
        $imported->refresh();
        $this->assertSame(DetachResult::REFUSED, $this->detacher()->detach($imported)->outcome);

        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $imported->fresh()->status);
        $this->assertSame([], $this->sent());
    }

    #[Test]
    public function a_redirect_row_takes_its_rule_then_its_placeholder_record(): void
    {
        // W2 S5: the rule first, so the host stops answering 301; then the
        // proxied A record by its own shape. The canonical host is untouched.
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org);
        $apex = $this->redirectRow($org, $www, MasjidDomain::STATUS_MANUAL, [
            'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(),
            'cf_zone_id' => self::PAIR_ZONE, 'cf_redirect_rule_id' => 'rule-studio',
            'cf_dns_record_id' => 'rec-apex', 'cf_dns_record_created' => true,
        ]);
        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([$this->clientRule(), $this->studioRule($apex)])),
            'DELETE /zones/zone-pair/rulesets/rs-1/rules/rule-studio' => $this->cfOk($this->ruleset([$this->clientRule()])),
            'GET /zones/zone-pair/dns_records/rec-apex' => $this->cfOk($this->dnsRecord(self::APEX, 'A', '192.0.2.1', 'rec-apex')),
            'DELETE /zones/zone-pair/dns_records/rec-apex' => $this->cfOk(['id' => 'rec-apex']),
        ]);

        $result = $this->detacher()->detach($apex);

        $this->assertSame(DetachResult::DETACHED, $result->outcome);
        $this->assertSame([
            'DELETE /zones/zone-pair/rulesets/rs-1/rules/rule-studio',
            'DELETE /zones/zone-pair/dns_records/rec-apex',
        ], $this->deletes());
        $this->assertNull(MasjidDomain::find($apex->id));
        $this->assertNotNull($www->fresh());
    }
}
