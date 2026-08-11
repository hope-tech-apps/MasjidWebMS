<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Models\RegistrationPayment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for the registration data layer (T-006a):
 * Registration + Registrant + RegistrationAdjustment + RegistrationPayment.
 *
 * These rows are money AND minors' rosters, and MySQL has no row-level
 * security — App\Models\Concerns\BelongsToMasjid plus this test are the
 * isolation guarantee (.claude/rules/tenant-scoping.md; mirrors
 * GroupTenantIsolationTest).
 *
 * It also pins the design's uuid rule: the registration uuid is an opaque
 * public handle and lookups are ALWAYS masjid-filtered
 * (Registration::findByUuidForMasjid) because the public endpoints run
 * UNBOUND, where the global scope adds no constraint — masjid A must never
 * resolve masjid B's registration from a stolen uuid.
 */
class RegistrationTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private Offering $offeringA;
    private Offering $offeringB;

    private Registration $registrationA;
    private Registration $registrationB;

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

        // Seeded while UNBOUND so the explicit masjid_id is honored.
        $this->offeringA = Offering::factory()->forMasjid($this->masjidA)->create();
        $this->offeringB = Offering::factory()->forMasjid($this->masjidB)->create();

        $this->registrationA = $this->makeRegistration($this->masjidA, $this->offeringA);
        $this->registrationB = $this->makeRegistration($this->masjidB, $this->offeringB);
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

    private function makeRegistration(Masjid $masjid, Offering $offering): Registration
    {
        return Registration::factory()->create([
            'masjid_id' => $masjid->id,
            'offering_id' => $offering->id,
        ]);
    }

    // ---------- Registration ----------

    #[Test]
    public function registration_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, Registration::count());
        $this->assertSame($this->registrationA->id, Registration::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_registration(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(Registration::find($this->registrationB->id));
    }

    #[Test]
    public function registration_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $registration = $this->makeRegistration($this->masjidB, $this->offeringA);

        $this->assertSame($this->masjidA->id, $registration->masjid_id);
    }

    #[Test]
    public function an_unbound_context_sees_every_organizations_registrations(): void
    {
        $this->assertSame(2, Registration::count());
    }

    // ---------- uuid lookups are masjid-filtered ----------

    #[Test]
    public function uuid_lookup_resolves_within_the_named_masjid(): void
    {
        $found = Registration::findByUuidForMasjid(
            $this->registrationA->uuid, $this->masjidA->id
        );

        $this->assertNotNull($found);
        $this->assertSame($this->registrationA->id, $found->id);
    }

    #[Test]
    public function uuid_lookup_misses_across_tenants_even_while_unbound(): void
    {
        // The public paths run exactly like this: no tenant bound, uuid from
        // the client. Masjid A presenting masjid B's (stolen) uuid is a miss.
        $this->assertNull(Registration::findByUuidForMasjid(
            $this->registrationB->uuid, $this->masjidA->id
        ));
    }

    #[Test]
    public function a_bound_tenant_cannot_resolve_a_foreign_uuid_at_all(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(
            Registration::where('uuid', $this->registrationB->uuid)->first()
        );
    }

    // ---------- Registrant ----------

    #[Test]
    public function registrant_queries_are_scoped_to_the_bound_tenant(): void
    {
        $rowA = $this->makeRegistrant($this->masjidA, $this->registrationA);
        $this->makeRegistrant($this->masjidB, $this->registrationB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, Registrant::count());
        $this->assertSame($rowA->id, Registrant::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_registrant(): void
    {
        $foreign = $this->makeRegistrant($this->masjidB, $this->registrationB);

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(Registrant::find($foreign->id));
    }

    #[Test]
    public function registrant_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $contact = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);

        $this->tenant->set($this->masjidA->id);

        $row = Registrant::create([
            'masjid_id' => $this->masjidB->id,
            'registration_id' => $this->registrationA->id,
            'contact_id' => $contact->id,
        ]);

        $this->assertSame($this->masjidA->id, $row->masjid_id);
    }

    // ---------- RegistrationAdjustment ----------

    #[Test]
    public function adjustment_queries_are_scoped_to_the_bound_tenant(): void
    {
        $rowA = $this->makeAdjustment($this->masjidA, $this->registrationA);
        $this->makeAdjustment($this->masjidB, $this->registrationB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, RegistrationAdjustment::count());
        $this->assertSame($rowA->id, RegistrationAdjustment::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_adjustment(): void
    {
        $foreign = $this->makeAdjustment($this->masjidB, $this->registrationB);

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(RegistrationAdjustment::find($foreign->id));
    }

    #[Test]
    public function adjustment_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $row = RegistrationAdjustment::create([
            'masjid_id' => $this->masjidB->id,
            'registration_id' => $this->registrationA->id,
            'kind' => RegistrationAdjustment::KIND_AID,
            'amount_minor' => 1000,
        ]);

        $this->assertSame($this->masjidA->id, $row->masjid_id);
    }

    // ---------- RegistrationPayment ----------

    #[Test]
    public function payment_queries_are_scoped_to_the_bound_tenant(): void
    {
        $rowA = $this->makePayment($this->masjidA, $this->registrationA);
        $this->makePayment($this->masjidB, $this->registrationB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, RegistrationPayment::count());
        $this->assertSame($rowA->id, RegistrationPayment::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_payment(): void
    {
        $foreign = $this->makePayment($this->masjidB, $this->registrationB);

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(RegistrationPayment::find($foreign->id));
    }

    #[Test]
    public function payment_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $row = RegistrationPayment::create([
            'masjid_id' => $this->masjidB->id,
            'registration_id' => $this->registrationA->id,
            'amount_minor' => 15000,
        ]);

        $this->assertSame($this->masjidA->id, $row->masjid_id);
    }

    #[Test]
    public function without_masjid_scope_bypasses_the_filter_for_reporting(): void
    {
        $this->makePayment($this->masjidA, $this->registrationA);
        $this->makePayment($this->masjidB, $this->registrationB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, RegistrationPayment::withoutMasjidScope()->count());
    }

    // ---------- helpers ----------

    private function makeRegistrant(Masjid $masjid, Registration $registration): Registrant
    {
        return Registrant::factory()->create([
            'masjid_id' => $masjid->id,
            'registration_id' => $registration->id,
        ]);
    }

    private function makeAdjustment(Masjid $masjid, Registration $registration): RegistrationAdjustment
    {
        return RegistrationAdjustment::factory()->create([
            'masjid_id' => $masjid->id,
            'registration_id' => $registration->id,
        ]);
    }

    private function makePayment(Masjid $masjid, Registration $registration): RegistrationPayment
    {
        return RegistrationPayment::factory()->create([
            'masjid_id' => $masjid->id,
            'registration_id' => $registration->id,
        ]);
    }
}
