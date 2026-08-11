<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Groups + rosters over HTTP: /api/admin/masjids/{masjid_id}/groups[/{id}/members].
 *
 * Mirrors ContactCrudTest — the same two paths keep organization A out of B:
 *   - targeting B's masjid in the route  -> 403 (ResolveMasjidTenant);
 *   - B's id under A's own route         -> 404 (the BelongsToMasjid scope makes
 *     findOrFail miss the row).
 *
 * On top of that this suite pins the two things the Groups slice adds:
 * guardianship as an explicit edge (a guardian membership names the member it is
 * attached to, and the link is refused unless that member is actually in the
 * group), and vertical-aware labelling (what a group is CALLED comes from the
 * terminology pack, never a hardcoded "Classroom").
 */
class GroupCrudTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    private Group $groupA;
    private Group $groupB;

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

        // Groups reuse the CONTACTS permissions (see routes/admin.php), so the
        // bridged masjid-admin role must be seeded before the admins exist.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // Seeded while the context is UNBOUND (no request yet), so the explicit
        // masjid_id is honored rather than overridden by the creating hook.
        $this->groupA = Group::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3',
        ]);
        Group::factory()->create(['masjid_id' => $this->masjidA->id, 'name' => 'Grade 4', 'slug' => 'grade-4']);
        $this->groupB = Group::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3',
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
            // Groups live inside the CRM route group, which is gated by
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

    private function makeContact(Masjid $masjid): Contact
    {
        return Contact::factory()->create(['masjid_id' => $masjid->id]);
    }

    private function groupsUrl(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id . '/groups';
    }

    private function membersUrl(Group $group, ?Masjid $masjid = null): string
    {
        return $this->groupsUrl($masjid) . '/' . $group->id . '/members';
    }

    /** Add a roster row directly (unbound), for the cases that need one to exist. */
    private function seedMembership(Masjid $masjid, Group $group, Contact $contact, string $role, ?Contact $ward = null): GroupMembership
    {
        return GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);
    }

    // ---------- auth ----------

    #[Test]
    public function index_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->groupsUrl())->assertStatus(401);
    }

    // ---------- index ----------

    #[Test]
    public function index_returns_only_the_admins_own_groups(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->groupsUrl())->assertOk();

        $this->assertSame(2, $response->json('data.total'));
    }

    #[Test]
    public function index_search_filters_by_name(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->groupsUrl() . '?search=' . urlencode('Grade 4'))->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    #[Test]
    public function index_labels_groups_with_the_tenants_own_vocabulary(): void
    {
        Sanctum::actingAs($this->adminA);

        // Default vertical is masjid -> the masjid pack's word for groups.
        $this->getJson($this->groupsUrl())
            ->assertOk()
            ->assertJsonPath('meta.group_label', config('verticals.masjid.terminology.groups'));
    }

    #[Test]
    public function the_group_label_follows_the_tenants_vertical(): void
    {
        $this->masjidA->update(['org_type' => Masjid::ORG_TYPE_SCHOOL]);

        Sanctum::actingAs($this->adminA);

        // Same endpoint, same code path, different word — nothing hardcodes
        // "Classroom". See .claude/rules/verticals.md.
        $this->getJson($this->groupsUrl())
            ->assertOk()
            ->assertJsonPath('meta.group_label', config('verticals.school.terminology.groups'));
    }

    // ---------- store ----------

    #[Test]
    public function store_creates_a_group_and_derives_the_slug_from_the_name(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->groupsUrl(), [
            'name' => 'Halaqa Al Fajr',
            'kind' => Group::KIND_HALAQA,
        ])->assertStatus(201);

        $this->assertDatabaseHas('groups', [
            'masjid_id' => $this->masjidA->id,
            'name' => 'Halaqa Al Fajr',
            'slug' => 'halaqa-al-fajr',
            'kind' => Group::KIND_HALAQA,
        ]);
    }

    #[Test]
    public function store_defaults_an_absent_kind_to_general(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->groupsUrl(), ['name' => 'Sisters Circle'])
            ->assertStatus(201)
            ->assertJsonPath('data.kind', Group::KIND_GENERAL);
    }

    #[Test]
    public function store_rejects_an_unknown_kind(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->groupsUrl(), ['name' => 'Weird', 'kind' => 'cohort'])
            ->assertStatus(422);
    }

    #[Test]
    public function store_rejects_a_slug_already_used_in_this_organization(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->groupsUrl(), ['name' => 'Grade 3'])
            ->assertStatus(422);
    }

    #[Test]
    public function store_allows_a_slug_another_organization_already_uses(): void
    {
        // A slug that ONLY the other organization holds must not block this one:
        // the unique index is (masjid_id, slug), not (slug).
        Group::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'name' => 'Volunteers',
            'slug' => 'volunteers',
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->groupsUrl(), ['name' => 'Volunteers'])
            ->assertStatus(201);

        $this->assertSame(
            2,
            Group::withoutMasjidScope()->where('slug', 'volunteers')->count()
        );
    }

    #[Test]
    public function store_ignores_a_client_supplied_masjid_id(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->groupsUrl(), [
            'masjid_id' => $this->masjidB->id,
            'name' => 'Planted Group',
        ])->assertStatus(201);

        $this->assertSame($this->masjidA->id, $response->json('data.masjid_id'));
        $this->assertDatabaseMissing('groups', [
            'name' => 'Planted Group',
            'masjid_id' => $this->masjidB->id,
        ]);
    }

    #[Test]
    public function store_requires_a_name(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->groupsUrl(), ['kind' => Group::KIND_CLASS])
            ->assertStatus(422);
    }

    // ---------- show ----------

    #[Test]
    public function show_returns_the_admins_own_group(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->groupsUrl() . '/' . $this->groupA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->groupA->id);
    }

    #[Test]
    public function show_cannot_read_another_organizations_group_via_own_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->groupsUrl() . '/' . $this->groupB->id)
            ->assertStatus(404);
    }

    #[Test]
    public function admin_cannot_target_another_masjid_in_the_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->groupsUrl($this->masjidB) . '/' . $this->groupB->id)
            ->assertStatus(403);
    }

    // ---------- update ----------

    #[Test]
    public function update_changes_the_admins_own_group(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->groupsUrl() . '/' . $this->groupA->id, [
            'name' => 'Grade 3 — Boys',
            'is_active' => false,
        ])->assertOk();

        $this->assertDatabaseHas('groups', [
            'id' => $this->groupA->id,
            'name' => 'Grade 3 — Boys',
            'is_active' => false,
            // Untouched: a partial update must not silently regenerate the slug.
            'slug' => 'grade-3',
        ]);
    }

    #[Test]
    public function update_cannot_touch_another_organizations_group(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->groupsUrl() . '/' . $this->groupB->id, ['name' => 'HIJACKED'])
            ->assertStatus(404);

        $this->assertDatabaseMissing('groups', ['id' => $this->groupB->id, 'name' => 'HIJACKED']);
    }

    // ---------- destroy ----------

    #[Test]
    public function destroy_soft_deletes_the_admins_own_group(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->groupsUrl() . '/' . $this->groupA->id)->assertOk();

        $this->assertSoftDeleted('groups', ['id' => $this->groupA->id]);
    }

    #[Test]
    public function destroy_cannot_delete_another_organizations_group(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->groupsUrl() . '/' . $this->groupB->id)->assertStatus(404);

        $this->assertDatabaseHas('groups', ['id' => $this->groupB->id, 'deleted_at' => null]);
    }

    // ---------- roster ----------

    #[Test]
    public function a_contact_can_be_added_as_a_member(): void
    {
        $contact = $this->makeContact($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->membersUrl($this->groupA), [
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ])->assertStatus(201);

        $this->assertDatabaseHas('group_memberships', [
            'masjid_id' => $this->masjidA->id,
            'group_id' => $this->groupA->id,
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'guardian_of_contact_id' => null,
        ]);
    }

    #[Test]
    public function a_contact_can_be_added_as_a_leader(): void
    {
        $contact = $this->makeContact($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->membersUrl($this->groupA), [
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_LEADER,
        ])->assertStatus(201)
            ->assertJsonPath('data.role', GroupMembership::ROLE_LEADER);
    }

    #[Test]
    public function a_guardian_is_linked_to_a_named_member(): void
    {
        $child = $this->makeContact($this->masjidA);
        $parent = $this->makeContact($this->masjidA);
        $this->seedMembership($this->masjidA, $this->groupA, $child, GroupMembership::ROLE_MEMBER);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->membersUrl($this->groupA), [
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
        ])->assertStatus(201)
            ->assertJsonPath('data.guardian_of_contact_id', $child->id);
    }

    #[Test]
    public function a_guardian_membership_without_a_named_member_is_rejected(): void
    {
        $parent = $this->makeContact($this->masjidA);

        Sanctum::actingAs($this->adminA);

        // "Guardian of nobody" is exactly the ambiguity the column removes.
        $this->postJson($this->membersUrl($this->groupA), [
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_non_guardian_membership_may_not_name_a_member(): void
    {
        $child = $this->makeContact($this->masjidA);
        $other = $this->makeContact($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->membersUrl($this->groupA), [
            'contact_id' => $other->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'guardian_of_contact_id' => $child->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function nobody_can_be_their_own_guardian(): void
    {
        $contact = $this->makeContact($this->masjidA);
        $this->seedMembership($this->masjidA, $this->groupA, $contact, GroupMembership::ROLE_MEMBER);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->membersUrl($this->groupA), [
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $contact->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_guardian_cannot_be_linked_to_someone_outside_the_group(): void
    {
        $stranger = $this->makeContact($this->masjidA);   // in the org, not in the group
        $parent = $this->makeContact($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->membersUrl($this->groupA), [
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $stranger->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function the_same_membership_cannot_be_added_twice(): void
    {
        $contact = $this->makeContact($this->masjidA);
        $this->seedMembership($this->masjidA, $this->groupA, $contact, GroupMembership::ROLE_MEMBER);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->membersUrl($this->groupA), [
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_contact_from_another_organization_cannot_be_added(): void
    {
        $foreign = $this->makeContact($this->masjidB);

        Sanctum::actingAs($this->adminA);

        // The scoped Contact::findOrFail misses -> 404, not a cross-tenant row.
        $this->postJson($this->membersUrl($this->groupA), [
            'contact_id' => $foreign->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ])->assertStatus(404);

        $this->assertDatabaseCount('group_memberships', 0);
    }

    #[Test]
    public function a_group_from_another_organization_has_no_reachable_roster(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->membersUrl($this->groupB))->assertStatus(404);
    }

    #[Test]
    public function the_roster_lists_the_groups_memberships(): void
    {
        $child = $this->makeContact($this->masjidA);
        $parent = $this->makeContact($this->masjidA);
        $this->seedMembership($this->masjidA, $this->groupA, $child, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->masjidA, $this->groupA, $parent, GroupMembership::ROLE_GUARDIAN, $child);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->membersUrl($this->groupA))->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function a_membership_can_be_removed(): void
    {
        $contact = $this->makeContact($this->masjidA);
        $membership = $this->seedMembership($this->masjidA, $this->groupA, $contact, GroupMembership::ROLE_MEMBER);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->membersUrl($this->groupA) . '/' . $membership->id)->assertOk();

        $this->assertDatabaseMissing('group_memberships', ['id' => $membership->id]);
    }

    #[Test]
    public function removing_a_member_also_removes_the_guardian_edges_over_them(): void
    {
        $child = $this->makeContact($this->masjidA);
        $parent = $this->makeContact($this->masjidA);
        $childMembership = $this->seedMembership($this->masjidA, $this->groupA, $child, GroupMembership::ROLE_MEMBER);
        $edge = $this->seedMembership($this->masjidA, $this->groupA, $parent, GroupMembership::ROLE_GUARDIAN, $child);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->membersUrl($this->groupA) . '/' . $childMembership->id)->assertOk();

        $this->assertDatabaseCount('group_memberships', 0);
        $this->assertDatabaseMissing('group_memberships', ['id' => $edge->id]);
    }

    #[Test]
    public function a_membership_belonging_to_another_organization_is_a_404(): void
    {
        $foreignContact = $this->makeContact($this->masjidB);
        $foreignMembership = $this->seedMembership(
            $this->masjidB, $this->groupB, $foreignContact, GroupMembership::ROLE_MEMBER
        );

        Sanctum::actingAs($this->adminA);

        // A's own group in the path, B's membership id -> scoped miss.
        $this->deleteJson($this->membersUrl($this->groupA) . '/' . $foreignMembership->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('group_memberships', ['id' => $foreignMembership->id]);
    }
}
