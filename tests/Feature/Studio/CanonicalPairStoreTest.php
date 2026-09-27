<?php

namespace Tests\Feature\Studio;

use App\Jobs\AttachMasjidDomain;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * POST .../domains with `canonical` (W2 S5): the apex/www pair in one request,
 * the canonical host serving and the other redirecting to it. Without
 * `canonical` the request is W1's, one host.
 */
class CanonicalPairStoreTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        config(['cloudflare.studio_token' => null]);
        Http::preventStrayRequests();
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000001'])->fresh());
    }

    private function store(Masjid $org, array $body)
    {
        // Form-encoded, as the SPA sends it.
        return $this->post("/api/admin/masjids/{$org->id}/domains", $body, ['Accept' => 'application/json']);
    }

    #[Test]
    public function www_is_canonical_and_the_apex_redirects_to_it(): void
    {
        $org = $this->makeOrg();

        $response = $this->store($org, ['kind' => 'custom', 'host' => 'pair-masjid.org', 'zone_apex' => 'pair-masjid.org', 'canonical' => 'www'])
            ->assertStatus(201)
            ->assertJsonPath('data.domain.host', 'pair-masjid.org')
            ->assertJsonPath('data.domain.role', MasjidDomain::ROLE_REDIRECT)
            ->assertJsonCount(2, 'data.domains');

        $www = MasjidDomain::query()->where('host', 'www.pair-masjid.org')->firstOrFail();
        $apex = MasjidDomain::query()->where('host', 'pair-masjid.org')->firstOrFail();
        $this->assertSame(MasjidDomain::ROLE_SERVING, $www->role);
        $this->assertSame(MasjidDomain::ROLE_REDIRECT, $apex->role);
        $this->assertSame($www->id, (int) $apex->redirect_to_id);
        $this->assertSame($www->id, $response->json('data.domain.redirect_to_id'));
        Queue::assertPushed(AttachMasjidDomain::class, 2);
    }

    #[Test]
    public function apex_can_be_canonical(): void
    {
        $org = $this->makeOrg();

        $this->store($org, ['kind' => 'custom', 'host' => 'www.pair-masjid.org', 'zone_apex' => 'pair-masjid.org', 'canonical' => 'apex'])
            ->assertStatus(201);

        $this->assertSame(MasjidDomain::ROLE_SERVING, MasjidDomain::query()->where('host', 'pair-masjid.org')->value('role'));
        $this->assertSame(MasjidDomain::ROLE_REDIRECT, MasjidDomain::query()->where('host', 'www.pair-masjid.org')->value('role'));
    }

    #[Test]
    public function without_canonical_it_is_one_host_as_before(): void
    {
        $org = $this->makeOrg();

        $this->store($org, ['kind' => 'custom', 'host' => 'www.pair-masjid.org', 'zone_apex' => 'pair-masjid.org'])
            ->assertStatus(201)
            ->assertJsonPath('data.domain.role', MasjidDomain::ROLE_SERVING);

        $this->assertSame(1, MasjidDomain::count());
        Queue::assertPushed(AttachMasjidDomain::class, 1);
    }

    #[Test]
    public function a_canonical_for_a_deeper_host_or_a_taken_sibling_is_refused(): void
    {
        $org = $this->makeOrg();

        $this->store($org, ['kind' => 'custom', 'host' => 'school.pair-masjid.org', 'zone_apex' => 'pair-masjid.org', 'canonical' => 'www'])
            ->assertStatus(422)
            ->assertJsonPath('data.canonical.0', 'A canonical host applies only to pair-masjid.org and www.pair-masjid.org.');

        $other = $this->makeOrg();
        $this->makeDomain($other, 'pair-masjid.org');
        $this->store($org, ['kind' => 'custom', 'host' => 'www.pair-masjid.org', 'zone_apex' => 'pair-masjid.org', 'canonical' => 'www'])
            ->assertStatus(422)
            ->assertJsonPath('data.canonical.0', "pair-masjid.org is already recorded for organisation #{$other->id}.");

        $this->store($org, ['kind' => 'custom', 'host' => 'www.pair-masjid.org', 'zone_apex' => 'pair-masjid.org', 'canonical' => 'sideways'])
            ->assertStatus(422);

        $this->assertSame(1, MasjidDomain::count());
        Queue::assertNothingPushed();
    }
}
