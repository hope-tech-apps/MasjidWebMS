<?php

namespace Tests\Feature\Studio;

use App\Exceptions\DomainsStillAttached;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesDetachableDomains;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * `domains:release {masjid_id}` (W2 S3): every Studio web address of one
 * organisation, trashed or not, detached. A dry run unless --execute.
 */
class DomainsReleaseCommandTest extends TestCase
{
    use FakesCloudflare;
    use MakesDetachableDomains;
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withStudioToken();
        $this->resolveTo([]);
    }

    /** @return array<string, mixed> */
    private function release(Masjid $org, bool $execute = false): array
    {
        // An executed run is ledgered and, for a live organisation, forced
        // (review follow-ups 10); the tests below that are about those
        // refusals call the command themselves.
        $this->assertSame(0, Artisan::call('domains:release', array_filter([
            'masjid_id' => $org->id,
            '--execute' => $execute,
            '--operator' => $execute ? 'owner' : null,
            '--reason' => $execute ? 'the organisation left' : null,
            '--force' => $execute,
            '--json' => true,
        ])));

        return json_decode(Artisan::output(), true);
    }

    /** A trashed organisation with a Studio row it created everything for and an imported one. */
    private function departedOrg(): array
    {
        $org = $this->makeOrg();
        $studio = $this->attachedRow($org, ['cf_zone_created' => true]);
        $imported = $this->makeDomain($org, 'mec.manara.hopetechapps.com', MasjidDomain::STATUS_MANUAL, [
            'source' => MasjidDomain::SOURCE_IMPORTED, 'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN, 'zone_apex' => 'hopetechapps.com',
            'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(), 'serving_confirmed_at' => now(),
        ]);
        $org->delete();

        return [$org, $studio, $imported];
    }

    #[Test]
    public function the_dry_run_sends_nothing_changes_nothing_and_says_what_it_would_do(): void
    {
        [$org, $studio, $imported] = $this->departedOrg();
        $this->fakeCloudflare([]);
        $before = MasjidDomain::query()->orderBy('id')->get()->map->getAttributes()->all();

        $out = $this->release($org);

        $this->assertFalse($out['executed']);
        $this->assertTrue($out['organisation_trashed']);
        $rows = collect($out['rows'])->keyBy('id');
        $this->assertSame('would_detach', $rows[$studio->id]['outcome']);
        $this->assertCount(2, $rows[$studio->id]['would_remove']);
        $this->assertStringContainsString('Studio added the new-masjid.org zone', $rows[$studio->id]['manual_steps'][0]);
        $this->assertSame('left_alone', $rows[$imported->id]['outcome']);

        $this->assertSame([], $this->sent());
        $this->assertSame($before, MasjidDomain::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    #[Test]
    public function execute_detaches_the_studio_rows_and_leaves_the_imported_ones(): void
    {
        [$org, $studio, $imported] = $this->departedOrg();
        $this->fakeCloudflare($this->cloudflareAsStudioLeftIt());

        $out = $this->release($org, true);

        $rows = collect($out['rows'])->keyBy('id');
        $this->assertSame('detached', $rows[$studio->id]['outcome']);
        $this->assertSame('left_alone', $rows[$imported->id]['outcome']);
        $this->assertNull(MasjidDomain::find($studio->id));
        $this->assertNotNull(MasjidDomain::find($imported->id));
        $this->assertCount(2, $this->deletes());
    }

    #[Test]
    public function after_a_release_the_force_delete_guard_names_only_what_is_left(): void
    {
        $org = $this->makeOrg();
        $this->attachedRow($org);
        $this->fakeCloudflare($this->cloudflareAsStudioLeftIt());

        try {
            $org->forceDelete();
            $this->fail('the force-delete went through with Studio objects still in Cloudflare');
        } catch (DomainsStillAttached $refused) {
            $this->assertStringContainsString("php artisan domains:release {$org->id}", $refused->getMessage());
        }

        $this->release($org, true);

        $this->assertTrue($org->fresh()->forceDelete());
        $this->assertNull(Masjid::withTrashed()->find($org->id));
    }

    #[Test]
    public function execute_without_a_token_refuses_and_changes_nothing(): void
    {
        [$org, $studio] = $this->departedOrg();
        config(['cloudflare.studio_token' => null]);
        $this->fakeCloudflare([]);

        $this->assertSame(1, Artisan::call('domains:release', ['masjid_id' => $org->id, '--execute' => true, '--operator' => 'owner', '--reason' => 'test']));
        $this->assertStringContainsString('CLOUDFLARE_STUDIO_TOKEN', Artisan::output());
        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $studio->fresh()->status);
        $this->assertSame([], $this->sent());
    }

    #[Test]
    public function an_unknown_organisation_is_an_error(): void
    {
        $this->assertSame(1, Artisan::call('domains:release', ['masjid_id' => 999999]));
    }
}
