<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Donations-ledger (read-only) endpoint tests for
 * /api/admin/masjids/{masjid_id}/donations.
 *
 * Donations are created and advanced ONLY by Stripe webhooks, so the admin API
 * exposes just index + show. These tests prove:
 *   - index is scoped to the admin's masjid and honors the status + fund filters;
 *   - show returns a single donation (with fund + receipt) scoped to the masjid;
 *   - cross-masjid access is blocked (404 via the scope, 403 via the tenant
 *     middleware when targeting another masjid in the route);
 *   - there is deliberately NO store / update / destroy route (405).
 *
 * Sqlite-in-memory is forced in setUp (mirrors ContactCrudTest). Rows are seeded
 * while the tenant context is UNBOUND so the explicit masjid_id is honored.
 */
class DonationReadTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    private Fund $fundA;
    private Fund $fundA2;
    private Fund $fundB;

    private int $succeededDonationAId;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Seed roles/permissions BEFORE the admins so each MasjidAdmin is bridged
        // to the masjid-admin role (full CRM permission set) on save.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // A fund per masjid (+ a second fund in A to prove the fund filter).
        $this->fundA = Fund::factory()->create(['masjid_id' => $this->masjidA->id]);
        $this->fundA2 = Fund::factory()->create(['masjid_id' => $this->masjidA->id]);
        $this->fundB = Fund::factory()->create(['masjid_id' => $this->masjidB->id]);

        // Masjid A: 2 succeeded gifts to fundA + 1 pending gift to fundA2 (= 3).
        $succeeded = Donation::factory()->count(2)->succeeded()->create([
            'masjid_id' => $this->masjidA->id,
            'fund_id' => $this->fundA->id,
        ]);
        Donation::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'fund_id' => $this->fundA2->id,
            'status' => 'pending',
        ]);

        // One of the succeeded gifts has an issued tax receipt (for the show test).
        $this->succeededDonationAId = $succeeded->first()->id;
        DonationReceipt::create([
            'masjid_id' => $this->masjidA->id,
            'donation_id' => $this->succeededDonationAId,
            'serial_number' => 1,
            'issue_date' => now()->toDateString(),
            'gross_amount' => 5000,
            'advantage_amount' => 0,
            'eligible_amount' => 5000,
            'currency' => 'usd',
            'jurisdiction' => 'US',
            'status' => 'issued',
        ]);

        // Masjid B: 2 pending gifts to fundB.
        Donation::factory()->count(2)->create([
            'masjid_id' => $this->masjidB->id,
            'fund_id' => $this->fundB->id,
            'status' => 'pending',
        ]);
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
            'crm_enabled' => true,
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

    /** Id of a donation belonging to $masjid (read without the tenant scope). */
    private function donationIdFor(Masjid $masjid): int
    {
        return Donation::withoutMasjidScope()->where('masjid_id', $masjid->id)->value('id');
    }

    // ---------- auth ----------

    #[Test]
    public function index_rejects_unauthenticated_requests(): void
    {
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations")
            ->assertStatus(401);
    }

    // ---------- index ----------

    #[Test]
    public function index_returns_only_the_admins_own_masjid_donations(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations")
            ->assertOk();

        $this->assertSame(3, $response->json('data.total'));
    }

    #[Test]
    public function index_filters_by_status(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations?status=succeeded")
            ->assertOk();

        $this->assertSame(2, $response->json('data.total'));
    }

    #[Test]
    public function index_filters_by_fund(): void
    {
        Sanctum::actingAs($this->adminA);

        // fundA has the 2 succeeded gifts; fundA2's single pending gift is excluded.
        $response = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations?fund_id={$this->fundA->id}")
            ->assertOk();

        $this->assertSame(2, $response->json('data.total'));
    }

    // ---------- show ----------

    #[Test]
    public function show_returns_the_admins_own_donation_with_fund_and_receipt(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$this->succeededDonationAId}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->succeededDonationAId)
            ->assertJsonPath('data.fund.id', $this->fundA->id)
            ->assertJsonPath('data.receipt.serial_number', 1)
            ->assertJsonPath('data.receipt.eligible_amount', 5000);
    }

    #[Test]
    public function show_cannot_read_another_masjids_donation_via_own_route(): void
    {
        $otherId = $this->donationIdFor($this->masjidB);

        Sanctum::actingAs($this->adminA);

        // Own masjid in the URL, but the id belongs to B -> scoped miss -> 404.
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$otherId}")
            ->assertStatus(404);
    }

    #[Test]
    public function admin_cannot_target_another_masjid_in_the_route(): void
    {
        $otherId = $this->donationIdFor($this->masjidB);

        Sanctum::actingAs($this->adminA);

        // Targeting B's masjid in the URL is forbidden by ResolveMasjidTenant.
        $this->getJson("/api/admin/masjids/{$this->masjidB->id}/donations/{$otherId}")
            ->assertStatus(403);
    }

    // ---------- read-only: no write routes exist ----------

    #[Test]
    public function donations_have_no_store_route(): void
    {
        Sanctum::actingAs($this->adminA);

        // POST is not registered on the donations collection -> 405.
        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/donations", [
            'intended_amount' => 5000,
            'fund_id' => $this->fundA->id,
        ])->assertStatus(405);
    }

    #[Test]
    public function donations_have_no_update_route(): void
    {
        $ownId = $this->donationIdFor($this->masjidA);

        Sanctum::actingAs($this->adminA);

        // PUT is not registered on a single donation -> 405.
        $this->putJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$ownId}", [
            'status' => 'succeeded',
        ])->assertStatus(405);
    }

    #[Test]
    public function donations_have_no_destroy_route(): void
    {
        $ownId = $this->donationIdFor($this->masjidA);

        Sanctum::actingAs($this->adminA);

        // DELETE is not registered on a single donation -> 405.
        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/donations/{$ownId}")
            ->assertStatus(405);
    }
}
