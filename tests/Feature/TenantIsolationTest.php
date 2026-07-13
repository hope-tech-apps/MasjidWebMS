<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveMasjidTenant;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for the CRM (Phase 0).
 *
 * MySQL has NO row-level security, so App\Models\Concerns\BelongsToMasjid is the
 * only thing keeping one masjid's data out of another's queries. These tests are
 * the backstop that proves it, at the model layer, by binding TenantContext
 * directly (the same object the ResolveMasjidTenant middleware binds per request).
 *
 * Two masjids A and B are seeded, each with a MasjidAdmin and several Contacts.
 * See .claude/rules/tenant-scoping.md. Sqlite-in-memory + RefreshDatabase per
 * the testing convention.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

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

        $this->masjidA = $this->makeMasjid(['name' => 'Masjid A ' . uniqid()]);
        $this->masjidB = $this->makeMasjid(['name' => 'Masjid B ' . uniqid()]);

        $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // Seed while UNBOUND so the explicit masjid_id is honored (the creating
        // hook only overrides when a tenant is bound).
        Contact::factory()->count(3)->create(['masjid_id' => $this->masjidA->id]);
        Contact::factory()->count(2)->create(['masjid_id' => $this->masjidB->id]);
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
        ], $overrides));
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

    #[Test]
    public function bound_tenant_all_returns_only_its_own_contacts(): void
    {
        $this->tenant->set($this->masjidA->id);

        $contacts = Contact::all();

        $this->assertCount(3, $contacts);
        $this->assertTrue(
            $contacts->every(fn (Contact $c) => (int) $c->masjid_id === $this->masjidA->id),
            'Every row returned to masjid A must belong to masjid A.'
        );
    }

    #[Test]
    public function bound_tenant_count_only_counts_its_own_contacts(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(3, Contact::count());
    }

    #[Test]
    public function bound_tenant_find_returns_own_row(): void
    {
        $ownId = Contact::withoutMasjidScope()->where('masjid_id', $this->masjidA->id)->value('id');

        $this->tenant->set($this->masjidA->id);

        $this->assertNotNull(Contact::find($ownId));
    }

    #[Test]
    public function bound_tenant_find_returns_null_for_another_masjids_row(): void
    {
        $otherId = Contact::withoutMasjidScope()->where('masjid_id', $this->masjidB->id)->value('id');

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(Contact::find($otherId));
    }

    #[Test]
    public function bound_tenant_cannot_update_another_masjids_row(): void
    {
        $otherId = Contact::withoutMasjidScope()->where('masjid_id', $this->masjidB->id)->value('id');

        $this->tenant->set($this->masjidA->id);

        $affected = Contact::where('id', $otherId)->update(['first_name' => 'HIJACKED']);

        $this->assertSame(0, $affected, 'Masjid A must not be able to update masjid B rows.');

        // Verify B's row is untouched by reading it with the scope bypassed.
        $row = $this->tenant->runWithout(fn () => Contact::find($otherId));
        $this->assertNotSame('HIJACKED', $row->first_name);
    }

    #[Test]
    public function bound_tenant_cannot_delete_another_masjids_row(): void
    {
        $otherId = Contact::withoutMasjidScope()->where('masjid_id', $this->masjidB->id)->value('id');

        $this->tenant->set($this->masjidA->id);

        $affected = Contact::where('id', $otherId)->delete();

        $this->assertSame(0, $affected, 'Masjid A must not be able to delete masjid B rows.');

        // B's row still exists (not soft-deleted) when read across masjids.
        $stillThere = $this->tenant->runWithout(
            fn () => Contact::whereKey($otherId)->exists()
        );
        $this->assertTrue($stillThere);
    }

    #[Test]
    public function create_stamps_bound_tenant_when_payload_omits_masjid_id(): void
    {
        $this->tenant->set($this->masjidA->id);

        $contact = Contact::create([
            'first_name' => 'Aisha',
            'last_name' => 'Rahman',
        ]);

        $this->assertSame($this->masjidA->id, $contact->masjid_id);
    }

    #[Test]
    public function create_ignores_client_supplied_masjid_id_and_uses_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        // Client tries to plant the row in masjid B; the server-derived hook wins.
        $contact = Contact::create([
            'masjid_id' => $this->masjidB->id,
            'first_name' => 'Malicious',
            'last_name' => 'Payload',
        ]);

        $this->assertSame($this->masjidA->id, $contact->masjid_id);
        $this->assertNotSame($this->masjidB->id, $contact->masjid_id);
    }

    #[Test]
    public function unbound_context_sees_all_masjids_contacts(): void
    {
        // No binding: SuperAdmin / system / public behavior — no auto-filter.
        $this->assertFalse($this->tenant->hasTenant());

        $this->assertSame(5, Contact::count());
    }

    #[Test]
    public function without_masjid_scope_bypasses_filter_even_when_bound(): void
    {
        $this->tenant->set($this->masjidA->id);

        // Documented bypass for super/system/reporting code.
        $this->assertSame(5, Contact::withoutMasjidScope()->count());
    }

    #[Test]
    public function contact_registers_the_masjid_tenant_global_scope(): void
    {
        $scopes = (new Contact())->getGlobalScopes();

        $this->assertArrayHasKey(Contact::MASJID_TENANT_SCOPE, $scopes);
    }

    #[Test]
    public function middleware_binds_the_masjid_admins_own_masjid(): void
    {
        // Fresh admin owning masjid A (masjids.user_id -> User::masjid()).
        $admin = $this->makeAdminFor($this->masjidA);

        $this->handleThrough($this->makeAuthedRequest($admin));

        $this->assertTrue($this->tenant->hasTenant());
        $this->assertSame($this->masjidA->id, $this->tenant->get());
    }

    #[Test]
    public function middleware_leaves_super_admin_unbound(): void
    {
        $super = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $this->handleThrough($this->makeAuthedRequest($super));

        $this->assertFalse($this->tenant->hasTenant());
    }

    /** Build a request whose authenticated user is $user. */
    private function makeAuthedRequest(User $user): Request
    {
        $request = Request::create('/api/admin/contacts', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /** Run ResolveMasjidTenant (resolved with the shared TenantContext). */
    private function handleThrough(Request $request): void
    {
        app(ResolveMasjidTenant::class)->handle($request, fn () => response('ok'));
    }
}
