<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Member-directory (Contact CRUD) endpoint tests for
 * /api/admin/masjids/{masjid_id}/contacts.
 *
 * These exercise the tenant guardrail END TO END over HTTP: as a MasjidAdmin of
 * masjid A, every method works and is scoped to A, while A can never reach B's
 * data. Two paths keep A out of B:
 *   - targeting B's masjid in the route  -> 403 (ResolveMasjidTenant confines a
 *     MasjidAdmin to their own masjid);
 *   - B's contact id under A's own route -> 404 (the BelongsToMasjid scope makes
 *     findOrFail miss the row).
 *
 * Sqlite-in-memory is forced in setUp (mirrors TenantIsolationTest) so CI never
 * needs a network DB. Contacts for each masjid are seeded while the tenant
 * context is UNBOUND, so the explicit masjid_id is honored.
 */
class ContactCrudTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

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

        // Seed the additive spatie roles/permissions BEFORE creating the admins,
        // so each MasjidAdmin is bridged to the masjid-admin role (with the full
        // CRM permission set) on save — exactly as production does after the
        // seeder runs. The new `permission:` gate on these routes then passes for
        // them, preserving the access they have today.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // Seeded while the context is UNBOUND (no request yet), so the explicit
        // masjid_id is honored rather than overridden by the creating hook.
        Contact::factory()->count(3)->create(['masjid_id' => $this->masjidA->id]);
        Contact::factory()->count(2)->create(['masjid_id' => $this->masjidB->id]);
    }

    /** Create a Masjid row with the minimum columns the schema requires. */
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
            // CRM is OFF by default; these end-to-end contact tests assume access,
            // so enable the gate for the test masjid. The default-off + gate
            // behavior is covered separately in CrmFeatureGateTest.
            'crm_enabled' => true,
        ]);
    }

    /**
     * Create a MasjidAdmin owning $masjid (masjids.user_id -> User::masjid()),
     * which is how ResolveMasjidTenant resolves the tenant in a real request.
     */
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

    /** Id of a contact belonging to $masjid (read without the tenant scope). */
    private function contactIdFor(Masjid $masjid): int
    {
        return Contact::withoutMasjidScope()->where('masjid_id', $masjid->id)->value('id');
    }

    // ---------- auth ----------

    #[Test]
    public function index_rejects_unauthenticated_requests(): void
    {
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/contacts")
            ->assertStatus(401);
    }

    // ---------- index ----------

    #[Test]
    public function index_returns_only_the_admins_own_masjid_contacts(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/contacts")
            ->assertOk();

        $this->assertSame(3, $response->json('data.total'));
    }

    #[Test]
    public function index_search_filters_by_name(): void
    {
        Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'first_name' => 'Zainab',
            'last_name' => 'Uniquename',
        ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/contacts?search=Uniquename")
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    // ---------- store ----------

    #[Test]
    public function store_creates_a_contact_scoped_to_the_admins_masjid(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/contacts", [
            'first_name' => 'Aisha',
            'last_name' => 'Rahman',
            'email' => 'aisha@example.com',
            'phone' => '+15551234567',
        ])->assertStatus(201);

        $this->assertDatabaseHas('contacts', [
            'first_name' => 'Aisha',
            'last_name' => 'Rahman',
            'masjid_id' => $this->masjidA->id,
        ]);
    }

    #[Test]
    public function store_requires_first_and_last_name(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/contacts", [
            'email' => 'no-name@example.com',
        ])->assertStatus(422);
    }

    #[Test]
    public function store_rejects_a_malformed_email(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/contacts", [
            'first_name' => 'Bad',
            'last_name' => 'Email',
            'email' => 'not-an-email',
        ])->assertStatus(422);
    }

    #[Test]
    public function store_ignores_a_client_supplied_masjid_id(): void
    {
        Sanctum::actingAs($this->adminA);

        // The client tries to plant the row in masjid B; the trait stamps A.
        $response = $this->postJson("/api/admin/masjids/{$this->masjidA->id}/contacts", [
            'masjid_id' => $this->masjidB->id,
            'first_name' => 'Malicious',
            'last_name' => 'Payload',
        ])->assertStatus(201);

        $this->assertSame($this->masjidA->id, $response->json('data.masjid_id'));
        $this->assertDatabaseHas('contacts', [
            'first_name' => 'Malicious',
            'masjid_id' => $this->masjidA->id,
        ]);
        $this->assertDatabaseMissing('contacts', [
            'first_name' => 'Malicious',
            'masjid_id' => $this->masjidB->id,
        ]);
    }

    // ---------- show ----------

    #[Test]
    public function show_returns_the_admins_own_contact(): void
    {
        $ownId = $this->contactIdFor($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$ownId}")
            ->assertOk()
            ->assertJsonPath('data.id', $ownId);
    }

    #[Test]
    public function show_cannot_read_another_masjids_contact_via_own_route(): void
    {
        $otherId = $this->contactIdFor($this->masjidB);

        Sanctum::actingAs($this->adminA);

        // Own masjid in the URL, but the id belongs to B -> scoped miss -> 404.
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$otherId}")
            ->assertStatus(404);
    }

    #[Test]
    public function admin_cannot_target_another_masjid_in_the_route(): void
    {
        $otherId = $this->contactIdFor($this->masjidB);

        Sanctum::actingAs($this->adminA);

        // Targeting B's masjid in the URL is forbidden by ResolveMasjidTenant.
        $this->getJson("/api/admin/masjids/{$this->masjidB->id}/contacts/{$otherId}")
            ->assertStatus(403);
    }

    // ---------- update ----------

    #[Test]
    public function update_changes_the_admins_own_contact(): void
    {
        $ownId = $this->contactIdFor($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->putJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$ownId}", [
            'first_name' => 'Updated',
            'last_name' => 'Name',
        ])->assertOk();

        $this->assertDatabaseHas('contacts', [
            'id' => $ownId,
            'first_name' => 'Updated',
            'last_name' => 'Name',
        ]);
    }

    #[Test]
    public function update_cannot_touch_another_masjids_contact(): void
    {
        $otherId = $this->contactIdFor($this->masjidB);

        Sanctum::actingAs($this->adminA);

        // Own masjid route + B's id -> scoped miss -> 404, and B is untouched.
        $this->putJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$otherId}", [
            'first_name' => 'HIJACKED',
            'last_name' => 'Name',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('contacts', [
            'id' => $otherId,
            'first_name' => 'HIJACKED',
        ]);
    }

    // ---------- destroy ----------

    #[Test]
    public function destroy_soft_deletes_the_admins_own_contact(): void
    {
        $ownId = $this->contactIdFor($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$ownId}")
            ->assertOk();

        $this->assertSoftDeleted('contacts', ['id' => $ownId]);
    }

    #[Test]
    public function destroy_cannot_delete_another_masjids_contact(): void
    {
        $otherId = $this->contactIdFor($this->masjidB);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$otherId}")
            ->assertStatus(404);

        // B's row is still live (not soft-deleted).
        $this->assertDatabaseHas('contacts', [
            'id' => $otherId,
            'deleted_at' => null,
        ]);
    }
}
