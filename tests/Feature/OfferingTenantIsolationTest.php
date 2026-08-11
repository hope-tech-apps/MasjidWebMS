<?php

namespace Tests\Feature;

use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\Offering;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for the offering data layer (T-006a):
 * Offering + FeePlan.
 *
 * MySQL has NO row-level security, so App\Models\Concerns\BelongsToMasjid is
 * the only thing keeping one organization's catalog (and its pricing) out of
 * another's queries. .claude/rules/tenant-scoping.md makes a cross-tenant test
 * mandatory for every new tenant-scoped model; this is it for both models, at
 * the model layer, binding TenantContext directly the way ResolveMasjidTenant
 * does per request. Mirrors GroupTenantIsolationTest.
 */
class OfferingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private Offering $offeringA;
    private Offering $offeringB;

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

        $this->tenant = app(TenantContext::class);
        // Every test starts UNBOUND; each binds explicitly when it needs to.
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        // Seeded while UNBOUND so the explicit masjid_id is honored (the
        // creating hook only overrides when a tenant is bound). Same slug in
        // both organizations: proves the unique index is per-masjid.
        $this->offeringA = Offering::factory()->forMasjid($this->masjidA)->create([
            'name' => 'Weekend School 2026–27',
            'slug' => 'weekend-school',
        ]);
        $this->offeringB = Offering::factory()->forMasjid($this->masjidB)->create([
            'name' => 'Weekend School 2026–27',
            'slug' => 'weekend-school',
        ]);
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    // ---------- Offering ----------

    #[Test]
    public function offering_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, Offering::count());
        $this->assertSame($this->offeringA->id, Offering::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_offering(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(Offering::find($this->offeringB->id));
    }

    #[Test]
    public function offering_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $form = Form::factory()->create(['masjid_id' => $this->masjidA->id]);

        $this->tenant->set($this->masjidA->id);

        $offering = Offering::create([
            // The caller tries to plant the offering in masjid B.
            'masjid_id' => $this->masjidB->id,
            'name' => 'Planted',
            'slug' => 'planted',
            'intake_form_id' => $form->id,
        ]);

        $this->assertSame($this->masjidA->id, $offering->masjid_id);
    }

    #[Test]
    public function an_unbound_context_sees_every_organizations_offerings(): void
    {
        $this->assertSame(2, Offering::count());
    }

    #[Test]
    public function without_masjid_scope_bypasses_the_filter(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, Offering::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_same_slug_is_allowed_in_two_organizations(): void
    {
        $this->assertSame(
            2,
            Offering::withoutMasjidScope()->where('slug', 'weekend-school')->count()
        );
    }

    // ---------- FeePlan ----------

    #[Test]
    public function fee_plan_queries_are_scoped_to_the_bound_tenant(): void
    {
        $planA = $this->makePlan($this->masjidA, $this->offeringA);
        $this->makePlan($this->masjidB, $this->offeringB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, FeePlan::count());
        $this->assertSame($planA->id, FeePlan::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_fee_plan(): void
    {
        $foreign = $this->makePlan($this->masjidB, $this->offeringB);

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(FeePlan::find($foreign->id));
    }

    #[Test]
    public function fee_plan_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $plan = FeePlan::create([
            'masjid_id' => $this->masjidB->id,
            'offering_id' => $this->offeringA->id,
            'kind' => FeePlan::KIND_ONE_TIME,
            'amount_minor' => 5000,
            'label' => 'Standard',
        ]);

        $this->assertSame($this->masjidA->id, $plan->masjid_id);
    }

    private function makePlan(Masjid $masjid, Offering $offering): FeePlan
    {
        return FeePlan::factory()->create([
            'masjid_id' => $masjid->id,
            'offering_id' => $offering->id,
        ]);
    }
}
