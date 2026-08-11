<?php

namespace Tests\Feature\Broadcasts;

use App\Enums\BroadcastChannel;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-tenant guarantee for the two models this slice adds (T-008).
 *
 * .claude/rules/tenant-scoping.md makes this mandatory, not optional: MySQL has
 * no row-level security, so the BelongsToMasjid global scope plus a test like
 * this one IS the isolation guarantee. Both layers are covered, the shape
 * GroupTenantIsolationTest + GroupCrudTest established:
 *
 *   - model layer: the scope filters, the creating hook stamps a
 *     server-derived masjid_id over anything supplied, and the documented
 *     bypass still crosses tenants for super/system code;
 *   - over HTTP: another tenant's broadcast id under your own route is a 404,
 *     their masjid in the route is a 403;
 *   - and the PUBLIC signage board — which runs unbound by design — hands each
 *     masjid only its own notices.
 */
class BroadcastTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    /** Seeded UNBOUND, so the explicit masjid_id is honoured. */
    private function makeBroadcast(Masjid $masjid, string $title, string $signageStatus = BroadcastDelivery::STATUS_SENT): Broadcast
    {
        $broadcast = Broadcast::create([
            'masjid_id' => $masjid->id,
            'title' => $title,
            'body' => 'Body of ' . $title,
            'status' => Broadcast::STATUS_SENT,
        ]);

        BroadcastDelivery::create([
            'broadcast_id' => $broadcast->id,
            'masjid_id' => $masjid->id,
            'channel' => BroadcastChannel::SIGNAGE->value,
            'status' => $signageStatus,
        ]);

        return $broadcast;
    }

    // ---------- model layer ----------

    #[Test]
    public function the_global_scope_hides_another_tenants_broadcasts(): void
    {
        $this->makeBroadcast($this->masjidA, 'A notice');
        $this->makeBroadcast($this->masjidB, 'B notice');

        app(TenantContext::class)->set($this->masjidA->id);

        $this->assertSame(['A notice'], Broadcast::pluck('title')->all());
        $this->assertSame(1, BroadcastDelivery::count());
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_over_client_input(): void
    {
        app(TenantContext::class)->set($this->masjidA->id);

        $broadcast = Broadcast::create([
            // A MasjidAdmin trying to plant a broadcast in another masjid.
            'masjid_id' => $this->masjidB->id,
            'title' => 'Attempted cross-tenant notice',
            'body' => 'Body',
        ]);

        $this->assertSame($this->masjidA->id, $broadcast->masjid_id);
    }

    #[Test]
    public function the_documented_bypass_still_crosses_tenants(): void
    {
        $this->makeBroadcast($this->masjidA, 'A notice');
        $this->makeBroadcast($this->masjidB, 'B notice');

        app(TenantContext::class)->set($this->masjidA->id);

        $this->assertSame(2, Broadcast::withoutMasjidScope()->count());
    }

    // ---------- over HTTP ----------

    #[Test]
    public function another_tenants_broadcast_id_under_your_own_route_is_a_404(): void
    {
        $foreign = $this->makeBroadcast($this->masjidB, 'B notice');

        Sanctum::actingAs($this->adminA);

        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/broadcasts/{$foreign->id}")
            ->assertStatus(404);
    }

    #[Test]
    public function targeting_another_masjid_in_the_route_is_a_403(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson("/api/admin/masjids/{$this->masjidB->id}/broadcasts")
            ->assertStatus(403);
    }

    #[Test]
    public function the_index_lists_only_your_own_tenants_sends(): void
    {
        $this->makeBroadcast($this->masjidA, 'A notice');
        $this->makeBroadcast($this->masjidB, 'B notice');

        Sanctum::actingAs($this->adminA);

        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/broadcasts")
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    // ---------- the public board ----------

    #[Test]
    public function the_public_signage_board_is_scoped_by_the_masjid_in_its_url(): void
    {
        $this->makeBroadcast($this->masjidA, 'A notice');
        $this->makeBroadcast($this->masjidB, 'B notice');

        // Unauthenticated, so TenantContext stays unbound and the explicit
        // masjid_id filter is the whole of the isolation here.
        $this->getJson("/api/mobile/masjids/{$this->masjidA->id}/signage")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'A notice')
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_public_board_omits_a_broadcast_whose_signage_delivery_failed(): void
    {
        $this->makeBroadcast($this->masjidA, 'Failed to publish', BroadcastDelivery::STATUS_FAILED);

        $this->getJson("/api/mobile/masjids/{$this->masjidA->id}/signage")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
