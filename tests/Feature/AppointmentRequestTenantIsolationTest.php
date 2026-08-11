<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestNote;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for appointment requests (PLAN T-021).
 *
 * MySQL has NO row-level security, so App\Models\Concerns\BelongsToMasjid is
 * the only thing keeping one clinic's patient intake out of another
 * organization's queries. .claude/rules/tenant-scoping.md makes a cross-tenant
 * test mandatory for every new tenant-scoped model; this is it for BOTH
 * AppointmentRequest and AppointmentRequestNote, at the model layer, binding
 * TenantContext directly the way ResolveMasjidTenant does per request.
 *
 * The note-cleanup invariant lives here too — it belongs to the model, so it
 * must hold for every caller, not just the controller.
 */
class AppointmentRequestTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private AppointmentRequest $requestA;
    private AppointmentRequest $requestB;

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
        // creating hook only overrides when a tenant is bound).
        $this->requestA = AppointmentRequest::factory()->create([
            'masjid_id' => $this->masjidA->id,
        ]);
        $this->requestB = AppointmentRequest::factory()->create([
            'masjid_id' => $this->masjidB->id,
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

    private function makeNote(AppointmentRequest $request, string $body = 'A note'): AppointmentRequestNote
    {
        return AppointmentRequestNote::create([
            'masjid_id' => $request->masjid_id,
            'appointment_request_id' => $request->id,
            'user_id' => User::factory()->create([
                'phone' => '+1' . random_int(1000000000, 9999999999),
            ])->id,
            'body' => $body,
        ]);
    }

    // ---------- AppointmentRequest ----------

    #[Test]
    public function request_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, AppointmentRequest::count());
        $this->assertSame($this->requestA->id, AppointmentRequest::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_request(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(AppointmentRequest::find($this->requestB->id));
    }

    #[Test]
    public function request_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $request = AppointmentRequest::factory()->create([
            // The caller tries to plant the request in masjid B.
            'masjid_id' => $this->masjidB->id,
        ]);

        $this->assertSame($this->masjidA->id, $request->masjid_id);
    }

    #[Test]
    public function an_unbound_context_sees_every_organizations_requests(): void
    {
        $this->assertSame(2, AppointmentRequest::count());
    }

    #[Test]
    public function without_masjid_scope_bypasses_the_filter(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, AppointmentRequest::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_bound_tenant_cannot_update_another_organizations_request(): void
    {
        $this->tenant->set($this->masjidA->id);

        // The scoped query misses B's row entirely, so nothing changes.
        $affected = AppointmentRequest::whereKey($this->requestB->id)
            ->update(['status' => AppointmentRequest::STATUS_CLOSED]);

        $this->assertSame(0, $affected);
        $this->assertSame(
            AppointmentRequest::STATUS_NEW,
            $this->requestB->fresh()->status
        );
    }

    // ---------- AppointmentRequestNote ----------

    #[Test]
    public function note_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->makeNote($this->requestA);
        $this->makeNote($this->requestB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, AppointmentRequestNote::count());
        $this->assertSame($this->requestA->id, AppointmentRequestNote::first()->appointment_request_id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_note(): void
    {
        $foreign = $this->makeNote($this->requestB);

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(AppointmentRequestNote::find($foreign->id));
    }

    #[Test]
    public function note_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $note = AppointmentRequestNote::create([
            'masjid_id' => $this->masjidB->id,
            'appointment_request_id' => $this->requestA->id,
            'user_id' => null,
            'body' => 'Planted',
        ]);

        $this->assertSame($this->masjidA->id, $note->masjid_id);
    }

    // ---------- deletion ----------

    #[Test]
    public function deleting_a_request_removes_its_notes_through_the_model(): void
    {
        $noteOne = $this->makeNote($this->requestA);
        $noteTwo = $this->makeNote($this->requestA);
        $unrelated = $this->makeNote($this->requestB);

        // Model-layer delete: the `deleting` hook must remove the notes (the
        // DB cascade is only a backstop, and it fires no model events).
        $this->requestA->delete();

        $this->assertDatabaseMissing('appointment_request_notes', ['id' => $noteOne->id]);
        $this->assertDatabaseMissing('appointment_request_notes', ['id' => $noteTwo->id]);
        // Another request's notes are untouched.
        $this->assertDatabaseHas('appointment_request_notes', ['id' => $unrelated->id]);
    }
}
