<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactCredential;
use App\Models\Masjid;
use App\Support\CredentialDocuments;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for volunteer credentials (T-023) — the mandatory
 * cross-tenant test for the new tenant-scoped model
 * (.claude/rules/tenant-scoping.md), mirroring GroupTenantIsolationTest at the
 * model layer.
 *
 * The model-owned behavior is pinned here too, because it holds for every
 * caller, not just the HTTP controller:
 *   - the license number is ENCRYPTED at rest (a DB dump must not read it);
 *   - status (valid/expiring/expired) is DERIVED from expires_at, never stored;
 *   - deleting a credential — or force-deleting its contact — removes the
 *     document bytes through the model, never via a DB cascade.
 */
class ContactCredentialTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private Contact $contactA;
    private Contact $contactB;

    private ContactCredential $credentialA;
    private ContactCredential $credentialB;

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

        // Nothing in this suite may write into storage/app/private for real.
        Storage::fake($this->disk());

        $this->tenant = app(TenantContext::class);
        // Every test starts UNBOUND; each binds explicitly when it needs to.
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->contactA = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);
        $this->contactB = Contact::factory()->create(['masjid_id' => $this->masjidB->id]);

        // Seeded while UNBOUND so the explicit masjid_id is honored (the
        // creating hook only overrides when a tenant is bound).
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

    /** Attach a fake scan through the real support class. */
    private function attachDocument(ContactCredential $credential, string $name = 'license.pdf'): void
    {
        CredentialDocuments::attach(
            $credential,
            UploadedFile::fake()->create($name, 64, 'application/pdf')
        );
    }

    // ---------------------------------------------------------------- isolation

    #[Test]
    public function credential_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, ContactCredential::count());
        $this->assertSame($this->credentialA->id, ContactCredential::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_credential(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(ContactCredential::find($this->credentialB->id));
    }

    #[Test]
    public function credential_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $credential = ContactCredential::create([
            // The caller tries to plant the credential in masjid B.
            'masjid_id' => $this->masjidB->id,
            'contact_id' => $this->contactA->id,
            'kind' => ContactCredential::KIND_BLS_CERTIFICATION,
        ]);

        $this->assertSame($this->masjidA->id, $credential->masjid_id);
    }

    #[Test]
    public function an_unbound_context_sees_every_organizations_credentials(): void
    {
        $this->assertSame(2, ContactCredential::count());
    }

    #[Test]
    public function without_masjid_scope_bypasses_the_filter(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, ContactCredential::withoutMasjidScope()->count());
    }

    // --------------------------------------------------------------- encryption

    #[Test]
    public function the_identifier_is_encrypted_at_rest(): void
    {
        $credential = ContactCredential::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
            'identifier' => 'MD-1234567',
        ]);

        $raw = DB::table('contact_credentials')
            ->where('id', $credential->id)
            ->value('identifier');

        // The whole point of the cast: what sits in the column is ciphertext.
        $this->assertNotSame('MD-1234567', $raw);
        $this->assertStringNotContainsString('MD-1234567', (string) $raw);

        // And it round-trips for the code that is allowed to read it.
        $this->assertSame('MD-1234567', $credential->fresh()->identifier);
    }

    // ----------------------------------------------------------- derived status

    #[Test]
    public function a_non_expiring_credential_is_valid(): void
    {
        $credential = ContactCredential::factory()->nonExpiring()->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
        ]);

        $this->assertSame(ContactCredential::STATUS_VALID, $credential->status);
    }

    #[Test]
    public function a_far_future_expiry_is_valid(): void
    {
        $credential = ContactCredential::factory()->expiringInDays(200)->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
        ]);

        $this->assertSame(ContactCredential::STATUS_VALID, $credential->status);
    }

    #[Test]
    public function an_expiry_inside_the_window_is_expiring(): void
    {
        $credential = ContactCredential::factory()
            ->expiringInDays(ContactCredential::expiringThresholdDays())
            ->create([
                'masjid_id' => $this->masjidA->id,
                'contact_id' => $this->contactA->id,
            ]);

        $this->assertSame(ContactCredential::STATUS_EXPIRING, $credential->status);
    }

    #[Test]
    public function a_credential_expiring_today_is_still_expiring_not_expired(): void
    {
        // A license dated today is still presentable today; expired begins the
        // day AFTER the expiry date.
        $credential = ContactCredential::factory()->expiringInDays(0)->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
        ]);

        $this->assertSame(ContactCredential::STATUS_EXPIRING, $credential->status);
    }

    #[Test]
    public function a_past_expiry_is_expired(): void
    {
        $credential = ContactCredential::factory()->expired(1)->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->contactA->id,
        ]);

        $this->assertSame(ContactCredential::STATUS_EXPIRED, $credential->status);
    }

    #[Test]
    public function status_is_never_a_column(): void
    {
        // Derived means derived: if someone adds a `status` column later, the
        // accessor and the column WILL drift apart at midnight. This pin makes
        // that a conscious decision instead of an accident.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('contact_credentials', 'status')
        );
    }

    // ---------------------------------------------------------------- the scopes

    #[Test]
    public function expiring_within_includes_the_window_and_excludes_everything_else(): void
    {
        $this->tenant->set($this->masjidA->id);

        $inside = ContactCredential::factory()->expiringInDays(5)
            ->create(['contact_id' => $this->contactA->id]);
        $boundary = ContactCredential::factory()->expiringInDays(30)
            ->create(['contact_id' => $this->contactA->id]);
        ContactCredential::factory()->expiringInDays(31)
            ->create(['contact_id' => $this->contactA->id]);
        ContactCredential::factory()->expired(3)
            ->create(['contact_id' => $this->contactA->id]);
        ContactCredential::factory()->nonExpiring()
            ->create(['contact_id' => $this->contactA->id]);

        $ids = ContactCredential::expiringWithin(30)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$inside->id, $boundary->id], $ids);
    }

    #[Test]
    public function expired_scope_returns_only_past_expiries(): void
    {
        $this->tenant->set($this->masjidA->id);

        $expired = ContactCredential::factory()->expired(3)
            ->create(['contact_id' => $this->contactA->id]);
        ContactCredential::factory()->expiringInDays(0)
            ->create(['contact_id' => $this->contactA->id]);
        ContactCredential::factory()->nonExpiring()
            ->create(['contact_id' => $this->contactA->id]);

        // credentialA (expires in a year) is also in scope for tenant A.
        $this->assertSame([$expired->id], ContactCredential::expired()->pluck('id')->all());
    }

    // ------------------------------------------------------- document lifecycle

    #[Test]
    public function deleting_a_credential_removes_its_document_bytes(): void
    {
        $this->attachDocument($this->credentialA);

        $path = $this->credentialA->document_path;
        Storage::disk($this->disk())->assertExists($path);

        $this->credentialA->delete();

        // Model-layer deletion reached the disk — nothing orphaned.
        Storage::disk($this->disk())->assertMissing($path);
        $this->assertDatabaseMissing('contact_credentials', ['id' => $this->credentialA->id]);
    }

    #[Test]
    public function the_document_path_is_random_and_tenant_scoped(): void
    {
        $this->attachDocument($this->credentialA, 'Dr Amal License.pdf');

        $path = $this->credentialA->document_path;

        // The admin's filename never reaches the filesystem…
        $this->assertStringNotContainsString('Dr Amal License', $path);
        $this->assertStringNotContainsString(' ', $path);
        // …and the bytes live under this tenant, never interleaved.
        $this->assertStringContainsString("/{$this->masjidA->id}/", $path);
        // On a disk with no public URL.
        $this->assertNull(config('filesystems.disks.' . $this->disk() . '.url'));
    }

    #[Test]
    public function force_deleting_a_contact_removes_credential_rows_and_bytes(): void
    {
        // The merge flow absorbs placeholder contacts with forceDelete(); the
        // DB cascade would silently take the credential rows with it and
        // orphan the scans, so Contact's deleting hook must get there first.
        $this->attachDocument($this->credentialA);
        $path = $this->credentialA->document_path;

        $this->contactA->forceDelete();

        Storage::disk($this->disk())->assertMissing($path);
        $this->assertDatabaseMissing('contact_credentials', ['id' => $this->credentialA->id]);
    }

    #[Test]
    public function soft_deleting_a_contact_keeps_credentials_and_bytes(): void
    {
        // The normal destroy path is recoverable, so a provider's credential
        // history must survive it.
        $this->attachDocument($this->credentialA);
        $path = $this->credentialA->document_path;

        $this->contactA->delete();

        Storage::disk($this->disk())->assertExists($path);
        $this->assertDatabaseHas('contact_credentials', ['id' => $this->credentialA->id]);
    }
}
