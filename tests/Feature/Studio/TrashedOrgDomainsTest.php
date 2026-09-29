<?php

namespace Tests\Feature\Studio;

use App\Console\Commands\ReconcileDomains;
use App\Exceptions\DomainsStillAttached;
use App\Jobs\AttachMasjidDomain;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Domains\DomainAttacher;
use App\Support\DemoSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * W2 S2: a trashed organisation's web addresses are left exactly where they
 * stopped, and one that Cloudflare still holds records for cannot be
 * force-deleted out from under them.
 */
class TrashedOrgDomainsTest extends TestCase
{
    use FakesCloudflare;
    use MakesStudioDomains;
    use RefreshDatabase;

    private const MANAGED = 'al-noor.manara.hopetechapps.com';

    private const MANAGED_ZONE = '859eddb9bce48f4f35e6197f6c0b8e15';

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

    /** @return array<string, mixed> every column, as stored */
    private function stored(MasjidDomain $row): array
    {
        return MasjidDomain::query()->whereKey($row->id)->firstOrFail()->getAttributes();
    }

    #[Test]
    public function reconcile_never_selects_a_trashed_orgs_rows(): void
    {
        $live = $this->makeOrg();
        $trashed = $this->makeOrg();
        $probed = ['verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(), 'serving_confirmed_at' => now()];

        $kept = $this->makeDomain($live, 'a.example.org');
        $this->makeDomain($trashed, 'b.example.org');
        $this->makeDomain($trashed, 'c.example.org', MasjidDomain::STATUS_PROVISIONING);
        $this->makeDomain($trashed, 'd.example.org', MasjidDomain::STATUS_ACTIVE, ['verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now()]);
        $this->makeDomain($trashed, 'e.example.org', MasjidDomain::STATUS_MANUAL, $probed + ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $trashed->delete();

        $this->assertSame([$kept->id], ReconcileDomains::selection(false)->pluck('id')->all());
        $this->assertSame([$kept->id], ReconcileDomains::selection(true)->pluck('id')->all());

        // --id asks for rows by name, and still gets none of a trashed organisation's.
        $all = MasjidDomain::query()->pluck('id')->all();
        $this->assertSame([$kept->id], ReconcileDomains::selection(true, $all)->pluck('id')->all());

        // And the command, run for real with a token, sends nothing about them.
        $this->withStudioToken();
        $this->fakeCloudflare([
            'GET /zones?*' => $this->cfOk([$this->zoneBody('example.org', 'pending')]),
            'GET https://a.example.org/api/tenant' => Http::response('', 404),
        ]);
        $this->artisan('domains:reconcile', ['--json' => true])->assertExitCode(0);

        foreach ($this->sent() as $line) {
            $this->assertDoesNotMatchRegularExpression('/[bcde]\.example\.org/', $line);
        }
    }

    #[Test]
    public function check_now_on_a_trashed_orgs_row_changes_nothing(): void
    {
        $this->withStudioToken();
        $org = $this->makeOrg();
        $pending = $this->managed($org);
        $failed = $this->makeDomain($org, 'www.new-masjid.org', MasjidDomain::STATUS_FAILED, [
            'zone_apex' => 'new-masjid.org',
            'last_error' => 'Cloudflare refused the request.',
        ]);
        $org->delete();

        $this->fakeCloudflare([]);
        $before = [$this->stored($pending), $this->stored($failed)];

        $this->assertTrue($this->attacher()->checkNow($pending));
        $this->assertTrue($this->attacher()->checkNow($failed));
        $this->attacher()->advance($pending);
        $this->attacher()->restart($failed);

        $this->assertSame([], $this->sent(), 'nothing is sent, to Cloudflare or to the host');
        $this->assertSame($before, [$this->stored($pending), $this->stored($failed)]);
        $this->assertSame(MasjidDomain::STATUS_FAILED, $failed->fresh()->status, 'Check now did not restart it');
    }

    #[Test]
    public function the_attach_job_on_a_trashed_orgs_row_changes_nothing(): void
    {
        $this->withStudioToken();
        $org = $this->makeOrg();
        $row = $this->managed($org);
        $org->delete();
        $this->fakeCloudflare([]);
        $before = $this->stored($row);

        (new AttachMasjidDomain($row->id))->handle($this->attacher());

        $this->assertSame([], $this->sent());
        $this->assertSame($before, $this->stored($row));
    }

    #[Test]
    public function a_restored_org_resumes_where_it_stopped(): void
    {
        $this->withStudioToken();
        $org = $this->makeOrg();
        $row = $this->managed($org);
        $this->fakeCloudflare([]);

        $org->delete();
        $this->artisan('domains:reconcile')->assertExitCode(0);
        $this->assertSame([], $this->sent());
        $this->assertSame(MasjidDomain::STATUS_PENDING, $row->fresh()->status, 'trashing cleared nothing');

        $org->restore();
        $this->fakeCloudflare([
            'GET /zones/' . self::MANAGED_ZONE . '/dns_records?*' => $this->cfOk([]),
            'POST /zones/' . self::MANAGED_ZONE . '/dns_records' => $this->cfOk($this->dnsRecord(self::MANAGED, 'CNAME', 'manara-renderer.pages.dev', 'rec-new')),
            'GET /accounts/*/pages/projects/manara-renderer/domains/' . self::MANAGED => $this->cfError(404, 8000007, 'Domain not found.'),
            'GET /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk([], ['total_count' => 5]),
            'POST /accounts/*/pages/projects/manara-renderer/domains' => $this->cfOk($this->pagesDomainBody(self::MANAGED, 'initializing')),
        ]);

        $this->artisan('domains:reconcile')->assertExitCode(0);
        $row->refresh();

        $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $row->status);
        $this->assertSame('rec-new', $row->cf_dns_record_id);
        $this->assertSame('pd-' . md5(self::MANAGED), $row->cf_pages_domain_id);
    }

    #[Test]
    public function force_delete_is_refused_while_cloudflare_state_is_recorded(): void
    {
        $cases = [
            'a DNS record id' => ['cf_zone_id' => self::MANAGED_ZONE, 'cf_dns_record_id' => 'rec-1'],
            'a Pages domain id' => ['cf_pages_domain_id' => 'pd-1'],
            'only a zone Studio created' => ['cf_zone_created' => true],
        ];

        foreach ($cases as $case => $state) {
            $org = $this->makeOrg();
            $this->makeDomain($org, 'plain-' . $org->id . '.example.org');
            $row = $this->makeDomain($org, 'www.org' . $org->id . '.example.org', MasjidDomain::STATUS_FAILED, $state);

            try {
                $org->forceDelete();
                $this->fail("{$case}: the force-delete went through");
            } catch (DomainsStillAttached $refused) {
                $this->assertSame($org->id, $refused->masjidId, $case);
                $this->assertSame([$row->id], $refused->domains->pluck('id')->all(), "{$case}: names only the rows Cloudflare holds records for");
                $this->assertStringContainsString($row->host, $refused->getMessage());
                // W2 S3: a Studio row is released with the command that removes
                // only what Studio created.
                $this->assertStringContainsString("php artisan domains:release {$org->id}", $refused->getMessage(), "{$case}: names the release command");
            }

            $this->assertNotNull(Masjid::withTrashed()->find($org->id), "{$case}: the organisation is still there");
            $this->assertSame(2, MasjidDomain::query()->where('masjid_id', $org->id)->count(), "{$case}: and so are its rows");
        }

        // A trashed organisation is guarded the same way.
        $org = $this->makeOrg();
        $this->makeDomain($org, 'trashed.example.org', MasjidDomain::STATUS_PENDING, ['cf_zone_id' => 'zone-1']);
        $org->delete();
        $this->expectException(DomainsStillAttached::class);
        Masjid::withTrashed()->findOrFail($org->id)->forceDelete();
    }

    #[Test]
    public function force_delete_proceeds_when_no_row_carries_cloudflare_state(): void
    {
        $org = $this->makeOrg();
        $this->makeDomain($org, 'a.example.org');
        $this->makeDomain($org, 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $bare = $this->makeOrg();

        $this->assertTrue($org->forceDelete());
        $this->assertTrue($bare->forceDelete());

        $this->assertNull(Masjid::withTrashed()->find($org->id));
        $this->assertNull(Masjid::withTrashed()->find($bare->id));
        $this->assertSame(0, MasjidDomain::query()->where('masjid_id', $org->id)->count());
    }

    #[Test]
    public function the_demo_school_rollback_still_force_deletes(): void
    {
        // The only application code that force-deletes an organisation. Its
        // tenant has no web addresses, so the guard lets it through.
        $demo = $this->makeOrg(['email' => DemoSchool::TENANT_EMAIL]);

        $this->artisan('demo:seed-school --rollback')->assertSuccessful();

        $this->assertNull(Masjid::withTrashed()->find($demo->id));
    }
}
