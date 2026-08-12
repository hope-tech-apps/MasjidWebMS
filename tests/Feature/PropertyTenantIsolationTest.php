<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for the property/rent component: Property +
 * RentPayment.
 *
 * .claude/rules/tenant-scoping.md makes a cross-tenant Feature test MANDATORY
 * for every model carrying BelongsToMasjid, and these two shipped without one.
 * MySQL has no row-level security, so the global scope plus a suite like this
 * IS the isolation guarantee — nothing underneath it will catch a regression.
 *
 * Two layers, because a model scope that exists is not the same claim as a
 * route that refuses:
 *
 *   - the MODEL layer, binding TenantContext directly the way
 *     ResolveMasjidTenant does per request (mirrors OfferingTenantIsolationTest);
 *   - the HTTP layer, end to end as an authenticated MasjidAdmin of masjid A
 *     (mirrors ContactCrudTest). B's id under A's own route is a 404; B's
 *     masjid in the route is a 403.
 *
 * RentPayment is MONEY, so its write paths are covered too and not only its
 * reads: PropertiesController::storeRent records a payment and destroyRent
 * removes one, and neither had a test of any kind before this file. Every
 * refusal asserts on the DATABASE as well as on the status code — a 404 that
 * still wrote (or still deleted) the row would otherwise pass.
 */
class PropertyTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private User $adminA;

    private Property $propertyA;
    private Property $propertyB;

    private RentPayment $paymentA;
    private RentPayment $paymentB;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml — this suite must
        // never need a network DB to run.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // The properties routes sit behind the granular `permission:` gate, so
        // the MasjidAdmins created below must be bridged to the masjid-admin
        // role before the first request. Without this every assertion in the
        // HTTP half would pass for the wrong reason (403, not 404).
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->tenant = app(TenantContext::class);
        // Every test starts UNBOUND; each binds explicitly when it needs to.
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // Seeded while UNBOUND so the explicit masjid_id is honored (the
        // creating hook only overrides when a tenant is bound). Identical names
        // in both organizations: nothing here is globally unique, so a leak can
        // only be caught by the tenant column, never by a name clash.
        $this->propertyA = $this->makeProperty($this->masjidA, 'Brick House 2', 120000);
        $this->propertyB = $this->makeProperty($this->masjidB, 'Brick House 2', 95000);

        $this->paymentA = $this->makeRentPayment($this->propertyA, '2026-01-05', 120000);
        $this->paymentB = $this->makeRentPayment($this->propertyB, '2026-01-05', 95000);
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
            // The properties routes sit inside the `crm` group; without this a
            // 403 from the feature gate would mask every isolation claim.
            'crm_enabled' => true,
        ]);
    }

    /**
     * A MasjidAdmin owning $masjid (masjids.user_id -> User::masjid()), which is
     * how ResolveMasjidTenant resolves the tenant in a real request.
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

    private function makeProperty(Masjid $masjid, string $name, ?int $monthlyRentCents = null): Property
    {
        return Property::create([
            'masjid_id' => $masjid->id,
            'name' => $name,
            'address' => '1910 S Mebane St',
            'tenant_name' => 'A Tenant',
            'monthly_rent' => $monthlyRentCents,
            'is_active' => true,
        ]);
    }

    private function makeRentPayment(Property $property, string $paidOn, int $cents): RentPayment
    {
        return RentPayment::create([
            'masjid_id' => $property->masjid_id,
            'property_id' => $property->id,
            'paid_on' => $paidOn,
            'amount' => $cents,
            'payment_method' => 'check',
        ]);
    }

    private function propertiesUrl(Masjid $masjid): string
    {
        return "/api/admin/masjids/{$masjid->id}/properties";
    }

    // =============================================================== model layer

    #[Test]
    public function property_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, Property::count());
        $this->assertSame($this->propertyA->id, Property::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_property(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(Property::find($this->propertyB->id));
    }

    #[Test]
    public function property_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $property = Property::create([
            // The caller tries to plant the property in masjid B.
            'masjid_id' => $this->masjidB->id,
            'name' => 'Planted',
        ]);

        $this->assertSame($this->masjidA->id, $property->masjid_id);
    }

    #[Test]
    public function an_unbound_context_sees_every_organizations_properties(): void
    {
        $this->assertSame(2, Property::count());
    }

    #[Test]
    public function without_masjid_scope_bypasses_the_property_filter(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, Property::withoutMasjidScope()->count());
    }

    #[Test]
    public function rent_payment_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, RentPayment::count());
        $this->assertSame($this->paymentA->id, RentPayment::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_rent_payment(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(RentPayment::find($this->paymentB->id));
    }

    #[Test]
    public function rent_payment_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $payment = RentPayment::create([
            // The caller tries to book the money into masjid B.
            'masjid_id' => $this->masjidB->id,
            'property_id' => $this->propertyA->id,
            'paid_on' => '2026-02-01',
            'amount' => 50000,
        ]);

        $this->assertSame($this->masjidA->id, $payment->masjid_id);
    }

    #[Test]
    public function without_masjid_scope_bypasses_the_rent_payment_filter(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, RentPayment::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_properties_rent_history_never_includes_another_tenants_money(): void
    {
        // The relation is what index/show aggregate over, so it carries the
        // isolation claim for every rent total an admin is shown.
        $this->tenant->set($this->masjidA->id);

        $this->assertSame([$this->paymentA->id], $this->propertyA->rentPayments()->pluck('id')->all());
    }

    // ================================================================ HTTP: read

    #[Test]
    public function index_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->propertiesUrl($this->masjidA))->assertStatus(401);
    }

    #[Test]
    public function index_lists_only_the_admins_own_properties_and_only_their_rent(): void
    {
        // A second payment for A, and a second for B, so a leak shows up in the
        // SUM as well as in the row count.
        $this->makeRentPayment($this->propertyA, '2026-02-05', 120000);
        $this->makeRentPayment($this->propertyB, '2026-02-05', 95000);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->propertiesUrl($this->masjidA))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->propertyA->id, $response->json('data.0.id'));
        $this->assertSame(2, $response->json('data.0.rent_payments_count'));
        // 2 x $1,200.00 — B's identically-priced payments are not in the total.
        $this->assertSame(240000, (int) $response->json('data.0.rent_payments_sum_amount'));
    }

    #[Test]
    public function show_returns_the_admins_own_property(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->propertyA->id)
            ->assertJsonCount(1, 'data.rent_payments');
    }

    #[Test]
    public function show_cannot_read_another_masjids_property_via_own_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyB->id}")
            ->assertStatus(404);
    }

    #[Test]
    public function admin_cannot_target_another_masjid_in_the_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->propertiesUrl($this->masjidB) . "/{$this->propertyB->id}")
            ->assertStatus(403);
    }

    // =============================================================== HTTP: write

    #[Test]
    public function store_ignores_a_client_supplied_masjid_id(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->propertiesUrl($this->masjidA), [
            'masjid_id' => $this->masjidB->id,
            'name' => 'Planted House',
            'monthly_rent' => 1500.00,
        ])->assertStatus(201);

        $this->assertSame($this->masjidA->id, $response->json('data.masjid_id'));
        $this->assertDatabaseHas('properties', [
            'name' => 'Planted House',
            'masjid_id' => $this->masjidA->id,
            'monthly_rent' => 150000,
        ]);
        $this->assertDatabaseMissing('properties', [
            'name' => 'Planted House',
            'masjid_id' => $this->masjidB->id,
        ]);
    }

    #[Test]
    public function update_cannot_touch_another_masjids_property(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyB->id}", [
            'name' => 'HIJACKED',
            'tenant_name' => 'HIJACKED',
        ])->assertStatus(404);

        $this->assertDatabaseHas('properties', [
            'id' => $this->propertyB->id,
            'name' => 'Brick House 2',
        ]);
        $this->assertDatabaseMissing('properties', ['name' => 'HIJACKED']);
    }

    #[Test]
    public function destroy_cannot_archive_another_masjids_property(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyB->id}")
            ->assertStatus(404);

        // B's property is still live — not soft-deleted out from under them.
        $this->assertDatabaseHas('properties', [
            'id' => $this->propertyB->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function destroy_archives_the_admins_own_property_and_keeps_its_rent_history(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyA->id}")
            ->assertOk();

        $this->assertSoftDeleted('properties', ['id' => $this->propertyA->id]);
        // Archiving a property must not destroy the money booked against it.
        $this->assertDatabaseHas('rent_payments', ['id' => $this->paymentA->id]);
    }

    // ========================================================== HTTP: money in

    #[Test]
    public function store_rent_records_money_against_the_admins_own_property(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyA->id}/rent", [
            'paid_on' => '2026-03-01',
            'amount' => 1200.50,
            'payment_method' => 'check',
            'check_number' => '1189',
        ])->assertStatus(201)
            ->assertJsonPath('data.amount', 120050)
            ->assertJsonPath('data.masjid_id', $this->masjidA->id)
            ->assertJsonPath('data.property_id', $this->propertyA->id);

        $this->assertDatabaseHas('rent_payments', [
            'property_id' => $this->propertyA->id,
            'masjid_id' => $this->masjidA->id,
            'amount' => 120050,
            'check_number' => '1189',
        ]);
    }

    #[Test]
    public function store_rent_accepts_a_negative_vacancy_adjustment(): void
    {
        // The ledger records a vacancy as negative money; the cents conversion
        // has to preserve the sign or a vacancy silently becomes income.
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyA->id}/rent", [
            'paid_on' => '2026-03-01',
            'amount' => -350.25,
            'note' => 'Vacant half the month',
        ])->assertStatus(201)
            ->assertJsonPath('data.amount', -35025);

        $this->assertDatabaseHas('rent_payments', [
            'property_id' => $this->propertyA->id,
            'amount' => -35025,
        ]);
    }

    #[Test]
    public function store_rent_validates_before_it_writes(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyA->id}/rent", [
            'note' => 'no date, no amount',
        ])->assertStatus(422);

        $this->assertSame(1, RentPayment::withoutMasjidScope()->where('property_id', $this->propertyA->id)->count());
    }

    #[Test]
    public function store_rent_cannot_book_money_against_another_masjids_property(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyB->id}/rent", [
            'paid_on' => '2026-03-01',
            'amount' => 9999.00,
        ])->assertStatus(404);

        // Nothing was written anywhere — not into B, and not into A either.
        $this->assertSame(2, RentPayment::withoutMasjidScope()->count());
        $this->assertDatabaseMissing('rent_payments', ['amount' => 999900]);
    }

    #[Test]
    public function store_rent_in_another_masjids_route_is_a_403(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->propertiesUrl($this->masjidB) . "/{$this->propertyB->id}/rent", [
            'paid_on' => '2026-03-01',
            'amount' => 9999.00,
        ])->assertStatus(403);

        $this->assertSame(2, RentPayment::withoutMasjidScope()->count());
    }

    // ========================================================= HTTP: money out

    #[Test]
    public function destroy_rent_removes_the_admins_own_payment(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->deleteJson(
            $this->propertiesUrl($this->masjidA) . "/{$this->propertyA->id}/rent/{$this->paymentA->id}"
        )->assertOk();

        $this->assertDatabaseMissing('rent_payments', ['id' => $this->paymentA->id]);
    }

    #[Test]
    public function destroy_rent_cannot_delete_another_masjids_payment(): void
    {
        Sanctum::actingAs($this->adminA);

        // A's own property in the path, B's payment id at the end.
        $this->deleteJson(
            $this->propertiesUrl($this->masjidA) . "/{$this->propertyA->id}/rent/{$this->paymentB->id}"
        )->assertStatus(404);

        $this->assertDatabaseHas('rent_payments', ['id' => $this->paymentB->id]);
    }

    #[Test]
    public function destroy_rent_cannot_delete_another_masjids_payment_under_its_own_property(): void
    {
        Sanctum::actingAs($this->adminA);

        // B's property AND B's payment, addressed through A's masjid route: the
        // property lookup is the first thing that must miss.
        $this->deleteJson(
            $this->propertiesUrl($this->masjidA) . "/{$this->propertyB->id}/rent/{$this->paymentB->id}"
        )->assertStatus(404);

        $this->assertDatabaseHas('rent_payments', ['id' => $this->paymentB->id]);
    }

    // ========================================================= the other actors

    #[Test]
    public function a_super_admin_is_confined_to_the_masjid_the_route_names(): void
    {
        // A SuperAdmin may act on any masjid but only ONE at a time — the route
        // binds them. A security review once concluded the opposite from a stale
        // docblock and had to be settled by the HTTP stack
        // (SuperAdminExportScopeTest); this is the same claim for money.
        $super = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        Sanctum::actingAs($super);

        $response = $this->getJson($this->propertiesUrl($this->masjidA))->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->propertyA->id, $response->json('data.0.id'));

        // B's property is invisible under A's route even to an operator.
        $this->getJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyB->id}")
            ->assertStatus(404);

        // And rent booked under A's route lands in A, never in the property's
        // own masjid, because the property itself could not be resolved.
        $this->postJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyB->id}/rent", [
            'paid_on' => '2026-03-01',
            'amount' => 100.00,
        ])->assertStatus(404);

        $this->assertSame(2, RentPayment::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_properties_surface_is_behind_the_per_masjid_crm_gate(): void
    {
        // The whole component sits inside the `crm` group. If it ever drifted
        // out, an organization that never bought the CRM would still have a
        // rent ledger — and every isolation test above would keep passing.
        $this->masjidA->forceFill(['crm_enabled' => false])->save();

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->propertiesUrl($this->masjidA))->assertStatus(403);
        $this->postJson($this->propertiesUrl($this->masjidA) . "/{$this->propertyA->id}/rent", [
            'paid_on' => '2026-03-01',
            'amount' => 100.00,
        ])->assertStatus(403);

        $this->assertSame(2, RentPayment::withoutMasjidScope()->count());
    }

    #[Test]
    public function destroy_rent_refuses_a_payment_that_belongs_to_a_different_property(): void
    {
        // Same tenant, wrong parent. The controller scopes the payment THROUGH
        // the property on purpose; without that, /properties/1/rent/{id} would
        // delete any of the masjid's payments regardless of the id in the path.
        $second = $this->makeProperty($this->masjidA, 'Duplex 4');

        Sanctum::actingAs($this->adminA);

        $this->deleteJson(
            $this->propertiesUrl($this->masjidA) . "/{$second->id}/rent/{$this->paymentA->id}"
        )->assertStatus(404);

        $this->assertDatabaseHas('rent_payments', ['id' => $this->paymentA->id]);
    }
}
