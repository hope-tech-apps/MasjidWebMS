<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Domains\DomainAttacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesDetachableDomains;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * POST /api/admin/masjids/{masjid_id}/domains/{domain_id}/detach (W2 S3):
 * SuperAdmin only, never for a row from the live host map, and working in the
 * encoding the SPA actually sends.
 */
class MasjidDomainsDetachRouteTest extends TestCase
{
    use FakesCloudflare;
    use MakesDetachableDomains;
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->withStudioToken();
        $this->resolveTo([]);
    }

    private function actAsSuper(): User
    {
        $user = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(Masjid $org, MasjidDomain $row): string
    {
        return "/api/admin/masjids/{$org->id}/domains/{$row->id}/detach";
    }

    #[Test]
    public function a_masjid_admin_and_a_teacher_are_refused_with_401(): void
    {
        $org = $this->makeOrg();
        $row = $this->attachedRow($org);
        $this->fakeCloudflare([]);

        foreach (['MasjidAdmin' => 'masjid-admin', 'Teacher' => 'teacher'] as $type => $role) {
            $user = User::factory()->create(['type' => $type, 'phone' => '+1' . random_int(1000000000, 9999999999)]);
            MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'is_default' => true]);
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($user->fresh());

            $this->postJson($this->url($org, $row))->assertStatus(401);
        }

        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $row->fresh()->status);
        $this->assertSame([], $this->sent());
    }

    #[Test]
    public function a_form_encoded_post_detaches_and_answers_202_with_what_was_removed(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $row = $this->attachedRow($org, ['cf_zone_created' => true]);
        $this->fakeCloudflare($this->cloudflareAsStudioLeftIt());

        // The SPA posts form-encoded (ApiService's global default), with no body.
        $this->post($this->url($org, $row), [], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.result.outcome', 'detached')
            ->assertJsonPath('data.result.host', self::HOST)
            ->assertJsonCount(2, 'data.result.removed')
            ->assertJsonPath('data.domain', null);

        $this->assertNull(MasjidDomain::find($row->id));
        $this->assertCount(2, $this->deletes());
    }

    #[Test]
    public function a_stopped_detach_answers_202_with_the_row_still_listed_as_detaching(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $row = $this->attachedRow($org);
        $this->fakeCloudflare(['GET ' . $this->pagesPath() => $this->cfError(403, 10000, 'Authentication error')]);

        $this->postJson($this->url($org, $row))
            ->assertStatus(202)
            ->assertJsonPath('data.result.outcome', 'pending')
            ->assertJsonPath('data.domain.status', MasjidDomain::STATUS_DETACHING)
            ->assertJsonPath('data.domain.waiting_on', 'token_scope')
            ->assertJsonPath('data.domain.detachable', true);
    }

    #[Test]
    public function an_imported_or_adopted_row_is_a_409_and_nothing_is_sent(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $imported = $this->makeDomain($org, 'mec.manara.hopetechapps.com', MasjidDomain::STATUS_MANUAL, [
            'source' => MasjidDomain::SOURCE_IMPORTED, 'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN, 'zone_apex' => 'hopetechapps.com',
            'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(), 'serving_confirmed_at' => now(),
            'cf_pages_domain_id' => 'pd-mec', 'cf_zone_id' => 'zone-mec',
        ]);
        $adopted = $this->attachedRow($org, ['adopted_from_import_at' => now()]);
        $this->fakeCloudflare([]);

        foreach ([$imported, $adopted] as $row) {
            $before = $row->fresh()->getAttributes();

            $this->postJson($this->url($org, $row))
                ->assertStatus(409)
                ->assertJsonPath('status', 'error');

            $this->assertSame($before, $row->fresh()->getAttributes());
            $this->assertFalse($row->fresh()->toAdminArray()['detachable']);
        }

        $this->assertSame([], $this->sent());
    }

    #[Test]
    public function a_held_lock_is_a_409(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $row = $this->attachedRow($org);
        $this->fakeCloudflare([]);
        $lock = DomainAttacher::lockFor($row->id);
        $this->assertTrue($lock->get());

        $this->postJson($this->url($org, $row))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Studio is already working on ' . self::HOST . '. Try again in a minute or two.');

        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $row->fresh()->status);
        $lock->release();
    }

    #[Test]
    public function another_organisations_row_is_not_found_through_this_one(): void
    {
        $this->actAsSuper();
        $mine = $this->makeOrg();
        $theirs = $this->attachedRow($this->makeOrg());
        $this->fakeCloudflare([]);

        $this->postJson($this->url($mine, $theirs))->assertNotFound();
        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $theirs->fresh()->status);
    }

    #[Test]
    public function the_list_offers_detach_with_the_servers_plan_only_where_remove_is_refused(): void
    {
        $this->actAsSuper();
        $org = $this->makeOrg();
        $this->attachedRow($org, ['cf_pages_domain_created' => false]);
        $this->makeDomain($org, 'plain.example.org');
        config(['cloudflare.studio_token' => null]);
        $this->fakeCloudflare([]);

        $rows = collect($this->getJson("/api/admin/masjids/{$org->id}/domains")->assertOk()->json('data.domains'))->keyBy('host');

        $attached = $rows[self::HOST];
        $this->assertTrue($attached['detachable']);
        $this->assertFalse($attached['deletable']);
        $this->assertSame(['the ' . self::HOST . ' DNS record in the new-masjid.org zone, if unchanged'], $attached['detach_plan']['would_remove']);
        $this->assertStringContainsString('Custom domains, and remove ' . self::HOST, $attached['detach_plan']['manual_steps'][0]);

        $plain = $rows['plain.example.org'];
        $this->assertFalse($plain['detachable']);
        $this->assertTrue($plain['deletable']);
        $this->assertNull($plain['detach_plan']);
    }
}
