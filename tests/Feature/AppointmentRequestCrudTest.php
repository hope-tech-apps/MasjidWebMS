<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestNote;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Appointment-request triage over HTTP:
 * /api/admin/masjids/{masjid_id}/appointment-requests (T-021).
 *
 * Mirrors GroupCrudTest — the same two paths keep organization A out of B:
 *   - targeting B's masjid in the route  -> 403 (ResolveMasjidTenant);
 *   - B's id under A's own route         -> 404 (the BelongsToMasjid scope
 *     makes findOrFail miss the row).
 *
 * On top of that this suite pins what the appointments slice adds: the status
 * vocabulary is the PHP constant set (an unknown status is a 422, not a DB
 * error), and internal notes carry a server-derived author and tenant.
 */
class AppointmentRequestCrudTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    private AppointmentRequest $requestA;
    private AppointmentRequest $requestB;

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

        // Appointment requests reuse the CONTACTS permissions (see
        // routes/admin.php), so the bridged masjid-admin role must be seeded
        // before the admins exist.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // Seeded while the context is UNBOUND (no request yet), so the explicit
        // masjid_id is honored rather than overridden by the creating hook.
        $this->requestA = AppointmentRequest::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'applicant_name' => 'Amal Yusuf',
            'status' => AppointmentRequest::STATUS_NEW,
        ]);
        AppointmentRequest::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'status' => AppointmentRequest::STATUS_CONTACTED,
        ]);
        $this->requestB = AppointmentRequest::factory()->create([
            'masjid_id' => $this->masjidB->id,
        ]);
    }

    /** Create a Masjid row with the minimum columns the schema requires. */
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
            // Appointment requests live inside the CRM route group, which is
            // gated by masjids.crm_enabled (default false; CrmFeatureGateTest).
            'crm_enabled' => true,
        ], $overrides));
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

    private function url(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id . '/appointment-requests';
    }

    /** Add a note directly (unbound), for the cases that need one to exist. */
    private function seedNote(AppointmentRequest $request, User $author, string $body): AppointmentRequestNote
    {
        return AppointmentRequestNote::create([
            'masjid_id' => $request->masjid_id,
            'appointment_request_id' => $request->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);
    }

    // ---------- auth ----------

    #[Test]
    public function index_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->url())->assertStatus(401);
    }

    // ---------- index ----------

    #[Test]
    public function index_returns_only_the_admins_own_requests(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url())->assertOk();

        $this->assertSame(2, $response->json('data.total'));
    }

    #[Test]
    public function index_filters_by_status(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url() . '?status=' . AppointmentRequest::STATUS_CONTACTED)
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    #[Test]
    public function index_serves_the_status_vocabulary_in_meta(): void
    {
        Sanctum::actingAs($this->adminA);

        // The SPA reads the triage set from here rather than hardcoding it.
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('meta.statuses', AppointmentRequest::STATUSES);
    }

    // ---------- show ----------

    #[Test]
    public function show_returns_the_admins_own_request_with_its_notes(): void
    {
        $this->seedNote($this->requestA, $this->adminA, 'Called back, no answer.');

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url() . '/' . $this->requestA->id)->assertOk();

        $this->assertSame($this->requestA->id, $response->json('data.id'));
        $this->assertCount(1, $response->json('data.notes'));
        // The note body decrypts for the staff reading it.
        $this->assertSame('Called back, no answer.', $response->json('data.notes.0.body'));
        $this->assertSame($this->adminA->id, $response->json('data.notes.0.author.id'));
    }

    #[Test]
    public function show_cannot_read_another_organizations_request_via_own_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->url() . '/' . $this->requestB->id)->assertStatus(404);
    }

    #[Test]
    public function admin_cannot_target_another_masjid_in_the_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->url($this->masjidB) . '/' . $this->requestB->id)
            ->assertStatus(403);
    }

    // ---------- status transition ----------

    #[Test]
    public function status_can_be_moved_through_triage(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->patchJson($this->url() . '/' . $this->requestA->id . '/status', [
            'status' => AppointmentRequest::STATUS_SCHEDULED,
        ])->assertOk()
            ->assertJsonPath('data.status', AppointmentRequest::STATUS_SCHEDULED);

        $this->assertSame(
            AppointmentRequest::STATUS_SCHEDULED,
            $this->requestA->fresh()->status
        );
    }

    #[Test]
    public function an_unknown_status_is_rejected(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->patchJson($this->url() . '/' . $this->requestA->id . '/status', [
            'status' => 'triaged',
        ])->assertStatus(422);

        $this->assertSame(AppointmentRequest::STATUS_NEW, $this->requestA->fresh()->status);
    }

    #[Test]
    public function status_cannot_be_changed_on_another_organizations_request(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->patchJson($this->url() . '/' . $this->requestB->id . '/status', [
            'status' => AppointmentRequest::STATUS_CLOSED,
        ])->assertStatus(404);

        $this->assertSame(AppointmentRequest::STATUS_NEW, $this->requestB->fresh()->status);
    }

    // ---------- notes ----------

    #[Test]
    public function a_note_is_stored_with_a_server_derived_author_and_tenant(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->requestA->id . '/notes', [
            'body' => 'Needs an interpreter.',
            // Both must be ignored: author and tenant are never client input.
            'user_id' => 999999,
            'masjid_id' => $this->masjidB->id,
        ])->assertStatus(201);

        $note = AppointmentRequestNote::withoutMasjidScope()->latest('id')->first();
        $this->assertSame('Needs an interpreter.', $note->body);
        $this->assertSame($this->adminA->id, $note->user_id);
        $this->assertSame($this->masjidA->id, $note->masjid_id);
        $this->assertSame($this->requestA->id, $note->appointment_request_id);
    }

    #[Test]
    public function a_note_cannot_be_added_to_another_organizations_request(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->requestB->id . '/notes', [
            'body' => 'HIJACKED',
        ])->assertStatus(404);

        $this->assertDatabaseCount('appointment_request_notes', 0);
    }

    #[Test]
    public function a_note_requires_a_body(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url() . '/' . $this->requestA->id . '/notes', [])
            ->assertStatus(422);
    }
}
