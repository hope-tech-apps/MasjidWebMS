<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for the Groups primitive (PLAN T-005).
 *
 * MySQL has NO row-level security, so App\Models\Concerns\BelongsToMasjid is the
 * only thing keeping one organization's rosters out of another's queries — and
 * these rosters hold children. .claude/rules/tenant-scoping.md makes a
 * cross-tenant test mandatory for every new tenant-scoped model; this is it for
 * BOTH Group and GroupMembership, at the model layer, binding TenantContext
 * directly the way ResolveMasjidTenant does per request.
 *
 * The guardianship invariants that the models (rather than the HTTP boundary)
 * own are pinned here too, because they hold for every caller.
 */
class GroupTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private Group $groupA;
    private Group $groupB;

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
        $this->groupA = Group::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3',
        ]);
        $this->groupB = Group::factory()->create([
            'masjid_id' => $this->masjidB->id,
            // Same slug in a different organization: proves the unique index is
            // per-masjid, not global.
            'name' => 'Grade 3',
            'slug' => 'grade-3',
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

    private function makeContact(Masjid $masjid): Contact
    {
        return Contact::factory()->create(['masjid_id' => $masjid->id]);
    }

    private function makeMembership(Masjid $masjid, Group $group, Contact $contact, string $role, ?Contact $ward = null): GroupMembership
    {
        return GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);
    }

    // ---------- Group ----------

    #[Test]
    public function group_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, Group::count());
        $this->assertSame($this->groupA->id, Group::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_group(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(Group::find($this->groupB->id));
    }

    #[Test]
    public function group_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $group = Group::create([
            // The caller tries to plant the group in masjid B.
            'masjid_id' => $this->masjidB->id,
            'name' => 'Planted',
            'slug' => 'planted',
        ]);

        $this->assertSame($this->masjidA->id, $group->masjid_id);
    }

    #[Test]
    public function an_unbound_context_sees_every_organizations_groups(): void
    {
        $this->assertSame(2, Group::count());
    }

    #[Test]
    public function without_masjid_scope_bypasses_the_filter(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, Group::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_same_slug_is_allowed_in_two_organizations(): void
    {
        $this->assertSame(
            2,
            Group::withoutMasjidScope()->where('slug', 'grade-3')->count()
        );
    }

    // ---------- GroupMembership ----------

    #[Test]
    public function membership_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->makeMembership($this->masjidA, $this->groupA, $this->makeContact($this->masjidA), GroupMembership::ROLE_MEMBER);
        $this->makeMembership($this->masjidB, $this->groupB, $this->makeContact($this->masjidB), GroupMembership::ROLE_MEMBER);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, GroupMembership::count());
        $this->assertSame($this->groupA->id, GroupMembership::first()->group_id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_membership(): void
    {
        $foreign = $this->makeMembership(
            $this->masjidB, $this->groupB, $this->makeContact($this->masjidB), GroupMembership::ROLE_MEMBER
        );

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(GroupMembership::find($foreign->id));
    }

    #[Test]
    public function membership_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $contact = $this->makeContact($this->masjidA);

        $this->tenant->set($this->masjidA->id);

        $membership = GroupMembership::create([
            'masjid_id' => $this->masjidB->id,
            'group_id' => $this->groupA->id,
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_LEADER,
        ]);

        $this->assertSame($this->masjidA->id, $membership->masjid_id);
    }

    // ---------- guardianship ----------

    #[Test]
    public function a_guardian_edge_names_the_member_it_is_attached_to(): void
    {
        $child = $this->makeContact($this->masjidA);
        $parent = $this->makeContact($this->masjidA);

        $this->makeMembership($this->masjidA, $this->groupA, $child, GroupMembership::ROLE_MEMBER);
        $edge = $this->makeMembership($this->masjidA, $this->groupA, $parent, GroupMembership::ROLE_GUARDIAN, $child);

        $this->assertTrue($edge->isGuardian());
        $this->assertSame($child->id, $edge->guardianOf->id);
        $this->assertSame($parent->id, $edge->contact->id);
    }

    #[Test]
    public function one_guardian_can_hold_an_edge_per_child_in_the_same_group(): void
    {
        $childOne = $this->makeContact($this->masjidA);
        $childTwo = $this->makeContact($this->masjidA);
        $parent = $this->makeContact($this->masjidA);

        $this->makeMembership($this->masjidA, $this->groupA, $childOne, GroupMembership::ROLE_MEMBER);
        $this->makeMembership($this->masjidA, $this->groupA, $childTwo, GroupMembership::ROLE_MEMBER);
        $this->makeMembership($this->masjidA, $this->groupA, $parent, GroupMembership::ROLE_GUARDIAN, $childOne);
        $this->makeMembership($this->masjidA, $this->groupA, $parent, GroupMembership::ROLE_GUARDIAN, $childTwo);

        // This is the case a bare `role = guardian` could not express: which of
        // the two children each edge is about.
        $this->assertSame(2, GroupMembership::withoutMasjidScope()
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->count());
    }

    #[Test]
    public function removing_a_member_removes_the_guardian_edges_pointing_at_them(): void
    {
        $child = $this->makeContact($this->masjidA);
        $parent = $this->makeContact($this->masjidA);

        $childMembership = $this->makeMembership($this->masjidA, $this->groupA, $child, GroupMembership::ROLE_MEMBER);
        $edge = $this->makeMembership($this->masjidA, $this->groupA, $parent, GroupMembership::ROLE_GUARDIAN, $child);

        $childMembership->delete();

        // A guardian edge must never outlive the membership it was granted over.
        $this->assertDatabaseMissing('group_memberships', ['id' => $edge->id]);
    }

    #[Test]
    public function removing_a_guardian_leaves_the_member_in_place(): void
    {
        $child = $this->makeContact($this->masjidA);
        $parent = $this->makeContact($this->masjidA);

        $childMembership = $this->makeMembership($this->masjidA, $this->groupA, $child, GroupMembership::ROLE_MEMBER);
        $edge = $this->makeMembership($this->masjidA, $this->groupA, $parent, GroupMembership::ROLE_GUARDIAN, $child);

        $edge->delete();

        $this->assertDatabaseHas('group_memberships', ['id' => $childMembership->id]);
    }
}
