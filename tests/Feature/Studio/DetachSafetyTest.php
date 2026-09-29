<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use App\Models\User;
use App\Services\Domains\DetachResult;
use App\Services\Domains\DomainAttacher;
use App\Services\Domains\DomainDetacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesDetachableDomains;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * Review findings on W2 S3, pinned: a Pages domain that was replaced is not
 * Studio's to delete, and a detach that cannot remove what Studio made does
 * not start.
 */
class DetachSafetyTest extends TestCase
{
    use FakesCloudflare;
    use MakesDetachableDomains;
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolveTo(['93.184.216.34']);
    }

    #[Test]
    public function a_pages_domain_read_back_with_another_id_loses_the_created_flag(): void
    {
        // Studio added the domain, then someone removed and re-added the host
        // on the project: the domain the read finds is not the one Studio made.
        $this->withStudioToken();
        $row = $this->attachedRow($this->makeOrg(), [
            'status' => MasjidDomain::STATUS_PROVISIONING,
            'verified_by' => null, 'verified_at' => null, 'serving_confirmed_at' => null,
            'stage_started_at' => now(),
        ]);
        $this->fakeCloudflare([
            'GET ' . $this->pagesPath() => $this->cfOk($this->pagesDomainBody(self::HOST, 'active', ['id' => 'pd-replacement'])),
            'GET https://' . self::HOST . '/api/tenant' => Http::response('', 404),
        ]);

        $this->app->make(DomainAttacher::class)->advance($row);
        $row->refresh();

        $this->assertSame('pd-replacement', $row->cf_pages_domain_id);
        $this->assertFalse($row->cf_pages_domain_created);
        $this->assertTrue($row->cf_dns_record_created, 'the record Studio made is still Studio\'s');
    }

    #[Test]
    public function without_a_token_a_detach_with_something_to_remove_does_not_start(): void
    {
        config(['cloudflare.studio_token' => null]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000002'])->fresh());
        $org = $this->makeOrg();
        $row = $this->attachedRow($org);
        $this->fakeCloudflare([]);
        $before = $row->fresh()->getAttributes();

        $this->postJson("/api/admin/masjids/{$org->id}/domains/{$row->id}/detach")
            ->assertStatus(409)
            ->assertJsonPath('status', 'error');

        $this->assertSame($before, $row->fresh()->getAttributes(), 'still served: nothing changed');
        $this->assertTrue(MasjidDomain::query()->served()->whereKey($row->id)->exists());
        $this->assertSame([], $this->sent());

        // A row whose Cloudflare objects Studio did not make has nothing for
        // the token to do: it detaches, and names what is left.
        $found = $this->attachedRow($this->makeOrg(), [
            'host' => 'www.other-masjid.org', 'zone_apex' => 'other-masjid.org',
            'cf_dns_record_created' => false, 'cf_pages_domain_created' => false,
        ]);
        $result = $this->app->make(DomainDetacher::class)->detach($found);
        $this->assertSame(DetachResult::DETACHED, $result->outcome);
        $this->assertCount(2, $result->manualSteps);
        $this->assertSame([], $this->sent());
    }
}
