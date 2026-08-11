<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactCredential;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Volunteer credentials over HTTP (T-023):
 * /api/admin/masjids/{masjid_id}/contacts/{contact_id}/credentials.
 *
 * Mirrors GroupCrudTest — the same two paths keep organization A out of B:
 *   - targeting B's masjid in the route  -> 403 (ResolveMasjidTenant);
 *   - B's id under A's own route         -> 404 (the BelongsToMasjid scope
 *     makes the findOrFail chain miss).
 *
 * On top of that this suite pins what the credentials slice adds: the license
 * number encrypted at rest but readable in the admin payload, status derived
 * from expires_at, the ?expiring_within_days filter, and the private document —
 * config-driven allowlist/ceiling on the way in, authenticated
 * chain-re-resolving download on the way out, bytes gone when the credential
 * goes.
 */
class ContactCredentialCrudTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    private Contact $contactA;
    private Contact $contactB;

    private ContactCredential $credentialA;
    private ContactCredential $credentialB;

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

        // Nothing here may write into storage/app/private for real.
        Storage::fake($this->disk());

        // Credentials reuse the CONTACTS permissions (see routes/admin.php), so
        // the bridged masjid-admin role must be seeded before the admins exist.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->contactA = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);
        $this->contactB = Contact::factory()->create(['masjid_id' => $this->masjidB->id]);

        // Seeded while the context is UNBOUND (no request yet), so the explicit
        // masjid_id is honored rather than overridden by the creating hook.
        $this->credentialA = ContactCredential::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
        ]);
        $this->credentialB = ContactCredential::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'contact_id' => $this->contactB->id,
        ]);
    }

    private function disk(): string
    {
        return (string) config('credentials.document.disk', 'local');
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
            // Credentials live inside the CRM route group, which is gated by
            // masjids.crm_enabled (default false; covered by CrmFeatureGateTest).
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

    private function url(?Masjid $masjid = null, ?Contact $contact = null): string
    {
        $masjid = $masjid ?? $this->masjidA;
        $contact = $contact ?? $this->contactA;

        return "/api/admin/masjids/{$masjid->id}/contacts/{$contact->id}/credentials";
    }

    private function pdf(string $name = 'license.pdf', int $kilobytes = 64): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    // ---------- auth ----------

    #[Test]
    public function index_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->url())->assertStatus(401);
    }

    #[Test]
    public function an_unauthenticated_caller_cannot_download_a_document(): void
    {
        $this->getJson($this->url() . "/{$this->credentialA->id}/document")->assertStatus(401);
    }

    // ---------- index ----------

    #[Test]
    public function index_lists_only_this_contacts_credentials(): void
    {
        // A second credential on ANOTHER contact in the same org must not leak
        // into this contact's card.
        $otherContact = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);
        ContactCredential::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $otherContact->id,
        ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->credentialA->id, $response->json('data.0.id'));
        $this->assertSame(ContactCredential::KINDS, $response->json('meta.kinds'));
    }

    #[Test]
    public function index_on_another_organizations_contact_is_a_404(): void
    {
        Sanctum::actingAs($this->adminA);

        // A's own masjid in the route, B's contact id -> scoped miss.
        $this->getJson($this->url($this->masjidA, $this->contactB))->assertStatus(404);
    }

    #[Test]
    public function an_admin_cannot_target_another_masjid_in_the_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->url($this->masjidB, $this->contactB))->assertStatus(403);
    }

    #[Test]
    public function index_filters_to_credentials_expiring_within_the_window(): void
    {
        $soon = ContactCredential::factory()->expiringInDays(10)->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
        ]);
        ContactCredential::factory()->expired(3)->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
        ]);
        ContactCredential::factory()->nonExpiring()->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
        ]);
        // credentialA from setUp expires in a year — outside a 30-day window.

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url() . '?expiring_within_days=30')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($soon->id, $response->json('data.0.id'));
        $this->assertSame(ContactCredential::STATUS_EXPIRING, $response->json('data.0.status'));
    }

    #[Test]
    public function a_garbage_expiring_window_is_rejected(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->url() . '?expiring_within_days=soon')->assertStatus(422);
        $this->getJson($this->url() . '?expiring_within_days=0')->assertStatus(422);
    }

    #[Test]
    public function the_status_in_the_payload_is_derived_from_expires_at(): void
    {
        $expired = ContactCredential::factory()->expired(5)->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
        ]);
        $forever = ContactCredential::factory()->nonExpiring()->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
        ]);

        Sanctum::actingAs($this->adminA);

        $byId = collect($this->getJson($this->url())->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame(ContactCredential::STATUS_EXPIRED, $byId[$expired->id]['status']);
        $this->assertSame(ContactCredential::STATUS_VALID, $byId[$forever->id]['status']);
        $this->assertSame(ContactCredential::STATUS_VALID, $byId[$this->credentialA->id]['status']);
    }

    // ---------- store ----------

    #[Test]
    public function store_creates_a_credential_with_an_identifier_encrypted_at_rest(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->url(), [
            'kind' => ContactCredential::KIND_MEDICAL_LICENSE,
            'issuing_body' => 'NC Medical Board',
            'identifier' => 'MD-9876543',
            'issued_at' => '2024-01-15',
            'expires_at' => '2027-01-15',
        ])->assertStatus(201);

        // The admin who stored it reads it back in the clear…
        $this->assertSame('MD-9876543', $response->json('data.identifier'));

        // …but the COLUMN holds ciphertext — the point of the encrypted cast.
        $raw = DB::table('contact_credentials')
            ->where('id', $response->json('data.id'))
            ->value('identifier');

        $this->assertNotSame('MD-9876543', $raw);
        $this->assertStringNotContainsString('MD-9876543', (string) $raw);
    }

    #[Test]
    public function store_stamps_the_tenant_and_contact_server_side(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->url(), [
            // The payload tries to re-parent the credential; neither field is
            // even validated, so neither can reach the row.
            'masjid_id' => $this->masjidB->id,
            'contact_id' => $this->contactB->id,
            'kind' => ContactCredential::KIND_BACKGROUND_CHECK,
        ])->assertStatus(201);

        $this->assertSame($this->masjidA->id, $response->json('data.masjid_id'));
        $this->assertSame($this->contactA->id, $response->json('data.contact_id'));
    }

    #[Test]
    public function store_on_another_organizations_contact_is_a_404(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url($this->masjidA, $this->contactB), [
            'kind' => ContactCredential::KIND_BACKGROUND_CHECK,
        ])->assertStatus(404);
    }

    #[Test]
    public function store_rejects_an_unknown_kind(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url(), ['kind' => 'notary_public'])->assertStatus(422);
    }

    #[Test]
    public function kind_other_requires_a_label(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url(), ['kind' => ContactCredential::KIND_OTHER])
            ->assertStatus(422);

        $this->postJson($this->url(), [
            'kind' => ContactCredential::KIND_OTHER,
            'label' => 'HIPAA Training Certificate',
        ])->assertStatus(201);
    }

    #[Test]
    public function an_expiry_before_the_issue_date_is_rejected(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url(), [
            'kind' => ContactCredential::KIND_MEDICAL_LICENSE,
            'issued_at' => '2026-01-01',
            'expires_at' => '2025-01-01',
        ])->assertStatus(422);
    }

    // ---------- document upload ----------

    #[Test]
    public function store_accepts_a_document_and_keeps_it_private_and_unguessable(): void
    {
        Sanctum::actingAs($this->adminA);

        // Multipart like a real browser, not JSON.
        $response = $this->post($this->url(), [
            'kind' => ContactCredential::KIND_MEDICAL_LICENSE,
            'document' => $this->pdf('Dr Amal License.pdf'),
        ])->assertStatus(201);

        $credential = ContactCredential::withoutMasjidScope()
            ->findOrFail($response->json('data.id'));

        // Bytes exist, under a random name in this tenant's subtree.
        Storage::disk($this->disk())->assertExists($credential->document_path);
        $this->assertStringNotContainsString('Dr Amal License', $credential->document_path);
        $this->assertStringContainsString("/{$this->masjidA->id}/", $credential->document_path);

        // The payload carries metadata + the authenticated download link ONLY —
        // no disk, no path, no public URL.
        $document = $response->json('data.document');
        $this->assertSame('Dr Amal License.pdf', $document['file_name']);
        $this->assertSame('application/pdf', $document['mime_type']);
        $this->assertStringContainsString(
            "/credentials/{$credential->id}/document",
            $document['download_url']
        );
        $this->assertArrayNotHasKey('document_path', $response->json('data'));
        $this->assertArrayNotHasKey('document_disk', $response->json('data'));
    }

    #[Test]
    public function a_disallowed_document_type_is_rejected_and_nothing_is_stored(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->post($this->url(), [
            'kind' => ContactCredential::KIND_MEDICAL_LICENSE,
            'document' => UploadedFile::fake()->create('shell.php', 4, 'application/x-httpd-php'),
        ])->assertStatus(422);

        $this->assertEmpty(Storage::disk($this->disk())->allFiles());
        // The 422 happened at the boundary: no half-created credential either.
        $this->assertSame(1, ContactCredential::withoutMasjidScope()
            ->where('contact_id', $this->contactA->id)->count());
    }

    #[Test]
    public function an_oversized_document_is_rejected(): void
    {
        Sanctum::actingAs($this->adminA);

        $maxKb = (int) config('credentials.document.max_size_kb');

        $this->post($this->url(), [
            'kind' => ContactCredential::KIND_MEDICAL_LICENSE,
            'document' => $this->pdf('huge.pdf', $maxKb + 1),
        ])->assertStatus(422);

        $this->assertEmpty(Storage::disk($this->disk())->allFiles());
    }

    #[Test]
    public function replacing_the_document_removes_the_old_bytes(): void
    {
        Sanctum::actingAs($this->adminA);

        $created = $this->post($this->url(), [
            'kind' => ContactCredential::KIND_BLS_CERTIFICATION,
            'document' => $this->pdf('bls-2025.pdf'),
        ])->assertStatus(201);

        $credential = ContactCredential::withoutMasjidScope()
            ->findOrFail($created->json('data.id'));
        $oldPath = $credential->document_path;

        // A renewal: new scan replaces the old on the SAME credential.
        $this->put($this->url() . "/{$credential->id}", [
            'expires_at' => '2028-06-30',
            'document' => $this->pdf('bls-2027.pdf'),
        ])->assertOk();

        $credential->refresh();

        $this->assertSame('bls-2027.pdf', $credential->document_original_name);
        Storage::disk($this->disk())->assertExists($credential->document_path);
        // One document per credential means ONE: the old bytes are gone.
        Storage::disk($this->disk())->assertMissing($oldPath);
        $this->assertCount(1, Storage::disk($this->disk())->allFiles());
    }

    // ---------- show / update ----------

    #[Test]
    public function show_returns_the_admins_own_credential(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->url() . "/{$this->credentialA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->credentialA->id);
    }

    #[Test]
    public function show_cannot_read_another_organizations_credential_via_own_route(): void
    {
        Sanctum::actingAs($this->adminA);

        // A's own masjid AND contact in the path, B's credential id -> miss.
        $this->getJson($this->url() . "/{$this->credentialB->id}")->assertStatus(404);
    }

    #[Test]
    public function update_changes_the_admins_own_credential(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->url() . "/{$this->credentialA->id}", [
            'notes' => 'Renewed at the January board meeting.',
            'expires_at' => '2028-01-31',
        ])->assertOk()
            ->assertJsonPath('data.notes', 'Renewed at the January board meeting.');
    }

    #[Test]
    public function update_cannot_touch_another_organizations_credential(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->url() . "/{$this->credentialB->id}", ['notes' => 'HIJACKED'])
            ->assertStatus(404);

        $this->assertDatabaseMissing('contact_credentials', [
            'id' => $this->credentialB->id,
            'notes' => 'HIJACKED',
        ]);
    }

    // ---------- destroy ----------

    #[Test]
    public function destroy_removes_the_credential_and_its_document_bytes(): void
    {
        Sanctum::actingAs($this->adminA);

        $created = $this->post($this->url(), [
            'kind' => ContactCredential::KIND_LIABILITY_INSURANCE,
            'document' => $this->pdf('policy.pdf'),
        ])->assertStatus(201);

        $credential = ContactCredential::withoutMasjidScope()
            ->findOrFail($created->json('data.id'));
        $path = $credential->document_path;

        $this->deleteJson($this->url() . "/{$credential->id}")->assertOk();

        $this->assertDatabaseMissing('contact_credentials', ['id' => $credential->id]);
        // The bytes went with the row — model-layer deletion, not a cascade.
        Storage::disk($this->disk())->assertMissing($path);
    }

    #[Test]
    public function destroy_cannot_delete_another_organizations_credential(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->url() . "/{$this->credentialB->id}")->assertStatus(404);

        $this->assertDatabaseHas('contact_credentials', ['id' => $this->credentialB->id]);
    }

    // ---------- download ----------

    #[Test]
    public function an_admin_can_download_their_own_credential_document(): void
    {
        Sanctum::actingAs($this->adminA);

        $created = $this->post($this->url(), [
            'kind' => ContactCredential::KIND_MEDICAL_LICENSE,
            'document' => $this->pdf('license.pdf'),
        ])->assertStatus(201);

        $download = $this->get($created->json('data.document.download_url'));

        $download->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        // The DOWNLOAD name is the admin's original, not the random stored one.
        $this->assertStringContainsString('license.pdf', $download->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        // Never cacheable by an intermediary.
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $download->headers->get('Cache-Control'));
    }

    #[Test]
    public function another_organizations_admin_cannot_download_the_document(): void
    {
        // Give A's credential a real document…
        Sanctum::actingAs($this->adminA);
        $created = $this->post($this->url(), [
            'kind' => ContactCredential::KIND_MEDICAL_LICENSE,
            'document' => $this->pdf(),
        ])->assertStatus(201);
        $credentialId = $created->json('data.id');

        // …then come at it as B, through B's OWN route, with A's foreign ids in
        // the chain. The scoped contact findOrFail misses -> 404, no bytes.
        Sanctum::actingAs(User::where('id', $this->masjidB->fresh()->user_id)->firstOrFail());

        $this->get("/api/admin/masjids/{$this->masjidB->id}/contacts/{$this->contactA->id}"
            . "/credentials/{$credentialId}/document")
            ->assertStatus(404);
    }

    #[Test]
    public function a_foreign_credential_id_under_the_admins_own_contact_is_a_404(): void
    {
        Sanctum::actingAs($this->adminA);

        // Own masjid, own contact — B's credential id as the last link.
        $this->get($this->url() . "/{$this->credentialB->id}/document")->assertStatus(404);
    }

    #[Test]
    public function a_credential_without_a_document_downloads_nothing(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->get($this->url() . "/{$this->credentialA->id}/document")->assertStatus(404);
    }
}
