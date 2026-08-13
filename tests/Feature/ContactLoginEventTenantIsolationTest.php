<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-TENANT isolation for `ContactLoginEvent` — the audit trail of who
 * opened or closed a family sign-in (T-015d, admin half).
 *
 * `.claude/rules/tenant-scoping.md` requires a cross-tenant Feature test for
 * every new model using `BelongsToMasjid`, and this slice adds exactly one such
 * model. Hence the file name — the same `<Model>TenantIsolationTest` convention
 * as ContactLoginCodeTenantIsolationTest, GroupTenantIsolationTest and the
 * rest. MySQL has no row-level security, so the bound tenant plus this file are
 * the ONLY backstop; nothing in the database would catch a missing global
 * scope.
 *
 * The stakes are unusually specific here. An audit row names a FAMILY ADDRESS
 * against an ORGANISATION and a staff member against an act. Leaking one across
 * the boundary answers "which school does this parent's child attend?" — the
 * question the whole family realm is built to be unable to answer — and it does
 * so from the very table that exists to make the system accountable.
 *
 * Four properties, and the last two are the ones a model-layer-only test would
 * miss:
 *
 *  1. READ across the boundary is a MISS, not a filtered result.
 *  2. WRITE across the boundary affects ZERO rows — the scope constrains
 *     `update()`/`delete()`, not just `SELECT`.
 *  3. `create()` STAMPS the bound tenant over a client-supplied `masjid_id`, so
 *     a payload cannot plant a row in another organisation.
 *  4. Over HTTP, the trail an admin of A reads never contains B's acts, even
 *     for a contact id that exists in B.
 */
class ContactLoginEventTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $a;
    private Masjid $b;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->a = $this->makeMasjid();
        $this->b = $this->makeMasjid();
    }

    // ---------------------------------------------------------------- helpers

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

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** An audit row written UNBOUND, so the explicit masjid_id is honoured. */
    private function seedEvent(Masjid $masjid, Contact $contact): ContactLoginEvent
    {
        $this->tenant()->forgetTenant();

        return ContactLoginEvent::create([
            'masjid_id' => $masjid->id,
            'contact_id' => $contact->id,
            'action' => ContactLoginEvent::ACTION_ENABLED,
            'login_email' => 'parent-' . uniqid() . '@test.local',
            'actor_name' => 'Office Staff',
            'actor_email' => 'office-' . uniqid() . '@test.local',
        ]);
    }

    // -------------------------------------- 1. the model layer (what the rule demands)

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_contact_login_event(): void
    {
        $contactA = $this->makeGuardian($this->a);
        $contactB = $this->makeGuardian($this->b);

        $eventA = $this->seedEvent($this->a, $contactA);
        $eventB = $this->seedEvent($this->b, $contactB);

        $this->assertSame(2, ContactLoginEvent::withoutMasjidScope()->count());

        $this->tenant()->set($this->a->id);

        // READ — B's row is a MISS, not a filtered result.
        $this->assertSame(1, ContactLoginEvent::count());
        $this->assertNotNull(ContactLoginEvent::find($eventA->id));
        $this->assertNull(ContactLoginEvent::find($eventB->id));

        // WRITE — the scope constrains the write verbs too. These are BUILDER
        // operations, which fire no model events, so the append-only hooks on
        // the model never see them (the model docblock says so out loud): what
        // is being proven here is the TENANT boundary, on its own, with the
        // append-only guard deliberately out of the way.
        $this->assertSame(0, ContactLoginEvent::where('id', $eventB->id)
            ->update(['action' => ContactLoginEvent::ACTION_REVOKED]));
        $this->assertSame(0, ContactLoginEvent::where('id', $eventB->id)->delete());

        // …and B's row is untouched by either.
        $this->tenant()->forgetTenant();
        $survivor = ContactLoginEvent::withoutMasjidScope()->find($eventB->id);
        $this->assertNotNull($survivor);
        $this->assertSame(ContactLoginEvent::ACTION_ENABLED, $survivor->action);
    }

    #[Test]
    public function a_client_supplied_masjid_id_cannot_plant_an_audit_row_in_another_organization(): void
    {
        $contactA = $this->makeGuardian($this->a);

        $this->tenant()->set($this->a->id);

        // The `creating` hook stamps the BOUND tenant over whatever was passed.
        // An audit row is the last place a forged masjid_id should be able to
        // land: it would put one organisation's grant history inside another's
        // accountability record.
        $planted = ContactLoginEvent::create([
            'masjid_id' => $this->b->id,
            'contact_id' => $contactA->id,
            'action' => ContactLoginEvent::ACTION_ENABLED,
            'login_email' => 'forged@test.local',
        ]);

        $this->assertSame($this->a->id, (int) $planted->fresh()->masjid_id);

        $this->tenant()->set($this->b->id);
        $this->assertNull(ContactLoginEvent::find($planted->id));
        $this->assertCount(0, ContactLoginEvent::all());
    }

    // ------------------------------------------------------- 2. over HTTP

    #[Test]
    public function an_admin_never_reads_another_organizations_grant_history(): void
    {
        $adminA = $this->makeAdminFor($this->a);
        $adminB = $this->makeAdminFor($this->b);

        $contactA = $this->makeGuardian($this->a);
        $contactB = $this->makeGuardian($this->b);

        // Each organisation enables one of its own parents, through the real
        // endpoint, so both trails are genuinely written.
        $this->asANewRequest();
        Sanctum::actingAs($adminA);
        $this->postJson("/api/admin/masjids/{$this->a->id}/contacts/{$contactA->id}/family-login", [
            'login_email' => 'at-a@test.local',
        ])->assertOk();

        $this->asANewRequest();
        Sanctum::actingAs($adminB);
        $this->postJson("/api/admin/masjids/{$this->b->id}/contacts/{$contactB->id}/family-login", [
            'login_email' => 'at-b@test.local',
        ])->assertOk();

        // A reads its own trail: one act, its own.
        $this->asANewRequest();
        Sanctum::actingAs($adminA);
        $this->getJson("/api/admin/masjids/{$this->a->id}/contacts/{$contactA->id}/family-login")
            ->assertOk()
            ->assertJsonCount(1, 'data.events')
            ->assertJsonPath('data.events.0.login_email', 'at-a@test.local');

        // B's contact id under A's own route is a 404 — the row is invisible to
        // the bound tenant, so nothing confirms it exists.
        $this->asANewRequest();
        Sanctum::actingAs($adminA);
        $response = $this->getJson("/api/admin/masjids/{$this->a->id}/contacts/{$contactB->id}/family-login")
            ->assertStatus(404);

        // Not even in the error body: B's credential address must not appear
        // anywhere in a response A can see.
        $this->assertStringNotContainsString('at-b@test.local', $response->getContent());

        // Naming B's masjid in the path is a 403 from ResolveMasjidTenant.
        $this->asANewRequest();
        Sanctum::actingAs($adminA);
        $this->getJson("/api/admin/masjids/{$this->b->id}/contacts/{$contactB->id}/family-login")
            ->assertStatus(403);
    }

    /**
     * Put the process back into the state a genuinely NEW request starts from.
     * `RequestGuard::user()` memoizes and `TenantContext` is a scoped binding
     * nothing clears mid-process.
     */
    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        $this->tenant()->forgetTenant();
    }

    /**
     * A contact who is somebody's guardian in `$masjid`.
     *
     * Family sign-in is guardian-only (FamilyAccessService::ineligibilityReason,
     * GuardianOnlyLoginTest) because enabling a login on a child's own row is a
     * student login — it grants the whole class feed. These tests all enable a
     * login, so the subject has to be someone who may legally hold one.
     */
    private function makeGuardian(\App\Models\Masjid $masjid): Contact
    {
        $guardian = Contact::factory()->create(['masjid_id' => $masjid->id]);
        $ward = Contact::factory()->create(['masjid_id' => $masjid->id]);

        $name = 'Class '.uniqid();

        $group = \App\Models\Group::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'kind' => 'class',
        ]);

        \App\Models\GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $ward->id,
            'role' => \App\Models\GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        \App\Models\GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $guardian->id,
            'role' => \App\Models\GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $ward->id,
            'joined_at' => now(),
        ]);

        return $guardian;
    }
}
