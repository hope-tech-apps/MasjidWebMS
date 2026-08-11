<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
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
 * The Classroom module over HTTP — behaviour skills and awards (PLAN T-013):
 * /api/admin/masjids/{id}/behavior-skills
 * /api/admin/masjids/{id}/groups/{id}/awards
 *
 * Mirrors GroupFeedTest / GroupMessagingTest for tenancy — B's id under A's own
 * route is a 404, B's masjid in the route is a 403 — and pins the guarantee
 * this slice exists for:
 *
 *   - A CHILD'S BEHAVIOUR RECORD IS PRIVATE. It reaches the group's leaders,
 *     the student, and THAT student's own guardians. ANOTHER GUARDIAN IN THE
 *     SAME GROUP IS REFUSED — at the endpoint (403) AND at the listing-query
 *     level, so a forbidden award never appears in a page, a paginator total,
 *     or a summary. "Guardian here" never meant "guardian of this child", the
 *     same reasoning that made guardianship an explicit edge.
 *   - There is no class-wide ranking anywhere in the payloads: every aggregate
 *     is per student, over rows the caller was already entitled to.
 *   - POINTS ARE SNAPSHOTTED: editing the vocabulary afterwards describes the
 *     next term, never last October.
 *   - Consent gates BROADCASTS, not a parent's view of their own child — a
 *     guardian with no consent record still reads their own ward's awards, the
 *     same call T-005c made for participant threads.
 *
 * The acting person resolves as in the feed: the admin's tenant Contact with
 * the same login email — see App\Support\GroupAudience.
 */
class BehaviorAwardsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    private Group $groupA;
    private Group $groupB;

    /** The Contact that IS adminA, in masjid A. Not in any group by default. */
    private Contact $personA;

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

        // The awards reuse the CONTACTS permissions (see routes/admin.php), so
        // the bridged masjid-admin role must be seeded before the admins exist.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->groupA = Group::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3',
        ]);
        $this->groupB = Group::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3',
        ]);

        $this->personA = Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'email' => $this->adminA->email,
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
            // The module lives inside the CRM route group, gated by crm_enabled.
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

    // ---------------------------------------------------------------- helpers

    private function skillsUrl(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id . '/behavior-skills';
    }

    private function awardsUrl(?Group $group = null, ?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id
            . '/groups/' . ($group ?? $this->groupA)->id . '/awards';
    }

    private function memberAwardsUrl(GroupMembership $membership, ?Group $group = null, ?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id
            . '/groups/' . ($group ?? $this->groupA)->id
            . '/members/' . $membership->id . '/awards';
    }

    /** Put a contact in a group directly (unbound), as the roster endpoints would. */
    private function seedMembership(
        Masjid $masjid,
        Group $group,
        Contact $contact,
        string $role,
        ?Contact $ward = null,
        ?string $consentScope = null
    ): GroupMembership {
        return GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
            'consent_granted_at' => $consentScope === null ? null : now(),
            'consent_scope' => $consentScope,
        ]);
    }

    /** A child (email-less, so identity can never collide) who is a member of group A. */
    private function seedChildInGroupA(): array
    {
        $child = Contact::factory()->create(['masjid_id' => $this->masjidA->id, 'email' => null]);
        $membership = $this->seedMembership($this->masjidA, $this->groupA, $child, GroupMembership::ROLE_MEMBER);

        return [$child, $membership];
    }

    /** Make adminA's person a guardian of a (new) child in group A. Returns [$child, $childMembership]. */
    private function makeAdminAGuardian(?string $consentScope = null): array
    {
        [$child, $childMembership] = $this->seedChildInGroupA();

        $this->seedMembership(
            $this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_GUARDIAN, $child, $consentScope
        );

        return [$child, $childMembership];
    }

    private function seedSkill(?Masjid $masjid = null, array $overrides = []): BehaviorSkill
    {
        return BehaviorSkill::factory()->create(array_merge([
            'masjid_id' => ($masjid ?? $this->masjidA)->id,
        ], $overrides));
    }

    /** An award written straight to the DB (unbound), as the endpoint would create it. */
    private function seedAward(GroupMembership $student, array $overrides = []): BehaviorAward
    {
        return BehaviorAward::factory()->create(array_merge([
            'masjid_id' => $student->masjid_id,
            'group_id' => $student->group_id,
            'group_membership_id' => $student->id,
            'skill_label' => 'Participation',
            'skill_polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'points' => 1,
        ], $overrides));
    }

    // ------------------------------------------------------------------ auth

    #[Test]
    public function the_module_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->skillsUrl())->assertStatus(401);
        $this->getJson($this->awardsUrl())->assertStatus(401);
    }

    // ------------------------------------------------- the vocabulary (CRUD)

    #[Test]
    public function an_admin_defines_edits_and_deletes_a_skill(): void
    {
        Sanctum::actingAs($this->adminA);

        $created = $this->postJson($this->skillsUrl(), [
            'label' => 'Participation',
            'polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'default_points' => 2,
        ])->assertStatus(201);

        $id = $created->json('data.id');

        $this->assertDatabaseHas('behavior_skills', [
            'id' => $id,
            // Stamped from the bound tenant, never from client input.
            'masjid_id' => $this->masjidA->id,
            'label' => 'Participation',
            'default_points' => 2,
        ]);

        $this->putJson($this->skillsUrl() . '/' . $id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson($this->skillsUrl())->assertOk()->assertJsonPath('data.total', 1);

        $this->deleteJson($this->skillsUrl() . '/' . $id)->assertOk();
        $this->assertDatabaseMissing('behavior_skills', ['id' => $id]);
    }

    #[Test]
    public function a_skill_requires_a_label_and_a_recognized_polarity(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->skillsUrl(), [])->assertStatus(422);
        $this->postJson($this->skillsUrl(), [
            'label' => 'Hmm',
            'polarity' => 'sideways',
            'default_points' => 1,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_skill_label_is_unique_within_one_organization(): void
    {
        $this->seedSkill(null, ['label' => 'Kindness']);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->skillsUrl(), [
            'label' => 'Kindness',
            'polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'default_points' => 1,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_skill_point_value_is_bounded_in_both_directions(): void
    {
        config(['groups.behavior.max_points' => 10]);

        Sanctum::actingAs($this->adminA);

        // A school may run negative weights; it may not run a fat-fingered one.
        $this->postJson($this->skillsUrl(), [
            'label' => 'Disruption',
            'polarity' => BehaviorSkill::POLARITY_NEGATIVE,
            'default_points' => -3,
        ])->assertStatus(201);

        $this->postJson($this->skillsUrl(), [
            'label' => 'Runaway',
            'polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'default_points' => 100000,
        ])->assertStatus(422);
    }

    #[Test]
    public function another_organizations_skill_is_a_miss_not_a_leak(): void
    {
        $foreign = $this->seedSkill($this->masjidB);

        Sanctum::actingAs($this->adminA);

        // A's own route, B's id: invisible to the bound tenant.
        $this->getJson($this->skillsUrl() . '/' . $foreign->id)->assertStatus(404);
        // B's route: refused before anything is looked up at all.
        $this->getJson($this->skillsUrl($this->masjidB))->assertStatus(403);
    }

    // ------------------------------------------------------ giving an award

    #[Test]
    public function a_leader_awards_a_skill_and_the_values_are_snapshotted(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();
        $skill = $this->seedSkill(null, ['label' => 'Participation', 'default_points' => 3]);

        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->awardsUrl(), [
            'membership_id' => $student->id,
            'behavior_skill_id' => $skill->id,
            'note' => 'Read aloud for the whole class.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('behavior_awards', [
            'id' => $response->json('data.id'),
            'masjid_id' => $this->masjidA->id,
            'group_id' => $this->groupA->id,
            'group_membership_id' => $student->id,
            'behavior_skill_id' => $skill->id,
            // The snapshot, taken from the skill at award time.
            'skill_label' => 'Participation',
            'skill_polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'points' => 3,
            // The ACCOUNT that awarded it, never a client claim.
            'awarded_by_user_id' => $this->adminA->id,
        ]);
    }

    #[Test]
    public function an_award_may_override_the_skills_default_points(): void
    {
        [, $student] = $this->seedChildInGroupA();
        $skill = $this->seedSkill(null, ['default_points' => 1]);

        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->awardsUrl(), [
            'membership_id' => $student->id,
            'behavior_skill_id' => $skill->id,
            'points' => 5,
        ])->assertStatus(201);

        $this->assertSame(5, $response->json('data.points'));
    }

    #[Test]
    public function editing_the_skill_afterwards_does_not_rewrite_the_award(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();
        $skill = $this->seedSkill(null, ['label' => 'Participation', 'default_points' => 3]);

        Sanctum::actingAs($this->adminA);

        $awardId = $this->postJson($this->awardsUrl(), [
            'membership_id' => $student->id,
            'behavior_skill_id' => $skill->id,
        ])->json('data.id');

        // The office re-weights and renames the skill for next term.
        $this->putJson($this->skillsUrl() . '/' . $skill->id, [
            'label' => 'Speaking up',
            'default_points' => 9,
        ])->assertOk();

        $this->getJson($this->awardsUrl())
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $awardId)
            ->assertJsonPath('data.data.0.skill_label', 'Participation')
            ->assertJsonPath('data.data.0.points', 3);
    }

    #[Test]
    public function an_award_must_name_a_participant_of_this_group(): void
    {
        // adminA is a guardian, so a guardian EDGE id is available to try.
        [$child] = $this->makeAdminAGuardian();
        $edge = GroupMembership::where('contact_id', $this->personA->id)->firstOrFail();
        $skill = $this->seedSkill();

        // A membership from ANOTHER organization's group.
        $foreignContact = Contact::factory()->create(['masjid_id' => $this->masjidB->id, 'email' => null]);
        $foreignMembership = $this->seedMembership(
            $this->masjidB, $this->groupB, $foreignContact, GroupMembership::ROLE_MEMBER
        );

        Sanctum::actingAs($this->adminA);

        // A guardian edge names a relationship, not a person to recognise.
        $this->postJson($this->awardsUrl(), [
            'membership_id' => $edge->id,
            'behavior_skill_id' => $skill->id,
        ])->assertStatus(422);

        // A foreign membership id is invisible to the scoped lookup — refused
        // the same way, revealing nothing about whether it exists.
        $this->postJson($this->awardsUrl(), [
            'membership_id' => $foreignMembership->id,
            'behavior_skill_id' => $skill->id,
        ])->assertStatus(422);

        $this->assertSame(0, BehaviorAward::withoutMasjidScope()->count());
        $this->assertNotNull($child);
    }

    #[Test]
    public function an_award_must_name_a_skill_of_this_organization_that_is_active(): void
    {
        [, $student] = $this->seedChildInGroupA();
        $foreignSkill = $this->seedSkill($this->masjidB);
        $retired = $this->seedSkill(null, ['is_active' => false]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->awardsUrl(), [
            'membership_id' => $student->id,
            'behavior_skill_id' => $foreignSkill->id,
        ])->assertStatus(422);

        // `is_active` is a decision, not a UI hint the API quietly ignores.
        $this->postJson($this->awardsUrl(), [
            'membership_id' => $student->id,
            'behavior_skill_id' => $retired->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function an_award_cannot_be_dated_in_the_future(): void
    {
        [, $student] = $this->seedChildInGroupA();
        $skill = $this->seedSkill();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->awardsUrl(), [
            'membership_id' => $student->id,
            'behavior_skill_id' => $skill->id,
            'awarded_at' => now()->addWeek()->toIso8601String(),
        ])->assertStatus(422);
    }

    #[Test]
    public function an_admin_not_in_the_group_can_award_but_not_read_it_back(): void
    {
        // The feed's documented read/write asymmetry, mirrored: giving
        // recognition is administration, reading the record is disclosure.
        [, $student] = $this->seedChildInGroupA();
        $skill = $this->seedSkill();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->awardsUrl(), [
            'membership_id' => $student->id,
            'behavior_skill_id' => $skill->id,
        ])->assertStatus(201);

        $this->getJson($this->awardsUrl())->assertStatus(403);
    }

    // ------------------------------------------------------- WHO MAY READ IT

    #[Test]
    public function a_leader_reads_every_award_in_the_group(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $first] = $this->seedChildInGroupA();
        [, $second] = $this->seedChildInGroupA();
        $this->seedAward($first);
        $this->seedAward($second);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->awardsUrl())->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson($this->memberAwardsUrl($second))->assertOk()->assertJsonPath('data.total', 1);
    }

    #[Test]
    public function a_member_reads_only_their_own_record(): void
    {
        $mine = $this->seedMembership(
            $this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_MEMBER
        );
        [, $someoneElse] = $this->seedChildInGroupA();

        $own = $this->seedAward($mine);
        $this->seedAward($someoneElse);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->awardsUrl())->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($own->id, $response->json('data.data.0.id'));

        $this->getJson($this->memberAwardsUrl($someoneElse))->assertStatus(403);
    }

    #[Test]
    public function a_guardian_reads_their_own_wards_record_without_any_consent_on_file(): void
    {
        // Consent gates BROADCASTS of a child's data to the guardian audience.
        // A parent reading their own child's record is not a broadcast, so
        // requiring feed consent here would lock them out of the one record
        // most obviously theirs — the same call T-005c made for participant
        // threads.
        [, $ward] = $this->makeAdminAGuardian();
        $award = $this->seedAward($ward);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->memberAwardsUrl($ward))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $award->id);
    }

    #[Test]
    public function another_guardian_is_refused_a_different_childs_record(): void
    {
        // THE test this slice exists for. adminA's person is a guardian in this
        // group — with full media consent, even — but of a DIFFERENT child.
        // "Guardian here" never meant "guardian of this child".
        $this->makeAdminAGuardian(GroupMembership::CONSENT_MEDIA);

        // A second family: another child in the same group, another parent.
        [, $otherChild] = $this->seedChildInGroupA();
        $this->seedAward($otherChild, ['skill_label' => 'Disruption']);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->memberAwardsUrl($otherChild))->assertStatus(403);
        $this->getJson($this->memberAwardsUrl($otherChild) . '/summary')->assertStatus(403);
    }

    #[Test]
    public function another_guardians_awards_are_absent_from_the_listing_query(): void
    {
        // The second half of the guarantee: refusing the dedicated endpoint is
        // not enough if the group listing still carries the row. A forbidden
        // award must never be FETCHED — not in a page, not in a paginator
        // total, not through a membership_id filter aimed at another family.
        [, $ward] = $this->makeAdminAGuardian(GroupMembership::CONSENT_MEDIA);
        [, $otherChild] = $this->seedChildInGroupA();

        $mine = $this->seedAward($ward, ['skill_label' => 'Kindness']);
        $theirs = $this->seedAward($otherChild, ['skill_label' => 'Disruption']);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->awardsUrl())->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($mine->id, $response->json('data.data.0.id'));
        $response->assertJsonMissing(['skill_label' => 'Disruption']);

        // Aiming the filter at the other family yields nothing rather than a
        // leak: the filter narrows an already-constrained query.
        $filtered = $this->getJson($this->awardsUrl() . '?membership_id=' . $otherChild->id)->assertOk();

        $this->assertSame(0, $filtered->json('data.total'));
        $this->assertNotNull($theirs->id);
    }

    #[Test]
    public function an_admin_with_no_standing_in_the_group_is_refused_the_listing(): void
    {
        [, $student] = $this->seedChildInGroupA();
        $this->seedAward($student);

        Sanctum::actingAs($this->adminA);

        // `view contacts` is held by every masjid admin; it is necessary but
        // deliberately not sufficient for a group-scoped read.
        $this->getJson($this->awardsUrl())->assertStatus(403);
        $this->getJson($this->memberAwardsUrl($student))->assertStatus(403);
    }

    // -------------------------------------------------- filters and summary

    #[Test]
    public function the_date_range_filter_narrows_a_listing_by_when_it_happened(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();

        $old = $this->seedAward($student, ['awarded_at' => now()->subMonths(2)]);
        $recent = $this->seedAward($student, ['awarded_at' => now()->subDay()]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson(
            $this->awardsUrl() . '?from=' . now()->subWeek()->toDateString()
        )->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($recent->id, $response->json('data.data.0.id'));

        $earlier = $this->getJson(
            $this->awardsUrl() . '?to=' . now()->subMonth()->toDateString()
        )->assertOk();

        $this->assertSame(1, $earlier->json('data.total'));
        $this->assertSame($old->id, $earlier->json('data.data.0.id'));
    }

    #[Test]
    public function the_per_student_summary_totals_the_snapshotted_values(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();
        [, $someoneElse] = $this->seedChildInGroupA();

        $this->seedAward($student, ['skill_label' => 'Participation', 'points' => 2]);
        $this->seedAward($student, ['skill_label' => 'Participation', 'points' => 3]);
        $this->seedAward($student, [
            'skill_label' => 'Disruption',
            'skill_polarity' => BehaviorSkill::POLARITY_NEGATIVE,
            'points' => -1,
        ]);
        // Another child's record must not reach this student's totals.
        $this->seedAward($someoneElse, ['skill_label' => 'Participation', 'points' => 50]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->memberAwardsUrl($student) . '/summary')->assertOk();

        $this->assertSame(3, $response->json('data.totals.awards'));
        $this->assertSame(4, $response->json('data.totals.points'));
        $this->assertSame(5, $response->json('data.by_polarity.positive.points'));
        $this->assertSame(-1, $response->json('data.by_polarity.negative.points'));

        $bySkill = collect($response->json('data.by_skill'))->keyBy('skill_label');
        $this->assertSame(2, $bySkill['Participation']['awards']);
        $this->assertSame(5, $bySkill['Participation']['points']);
        $this->assertSame(-1, $bySkill['Disruption']['points']);
    }

    #[Test]
    public function a_guardians_summary_of_their_own_ward_ignores_every_other_child(): void
    {
        [, $ward] = $this->makeAdminAGuardian();
        [, $otherChild] = $this->seedChildInGroupA();

        $this->seedAward($ward, ['points' => 2]);
        $this->seedAward($otherChild, ['points' => 99]);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->memberAwardsUrl($ward) . '/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.awards', 1)
            ->assertJsonPath('data.totals.points', 2);
    }

    // ------------------------------------------------------------- revoking

    #[Test]
    public function revoking_an_award_removes_it_from_listings_and_totals(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();
        $award = $this->seedAward($student, ['points' => 4]);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->awardsUrl() . '/' . $award->id)->assertOk();

        $this->assertSoftDeleted('behavior_awards', ['id' => $award->id]);
        // A correction to a child's record is itself accountable.
        $this->assertDatabaseHas('behavior_awards', [
            'id' => $award->id,
            'revoked_by_user_id' => $this->adminA->id,
        ]);

        $this->getJson($this->awardsUrl())->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson($this->memberAwardsUrl($student) . '/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.points', 0);
    }

    #[Test]
    public function revoking_is_idempotent(): void
    {
        [, $student] = $this->seedChildInGroupA();
        $award = $this->seedAward($student);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->awardsUrl() . '/' . $award->id)->assertOk();
        // The caller's intent is already true; saying so is not an error.
        $this->deleteJson($this->awardsUrl() . '/' . $award->id)->assertOk();
    }

    // ------------------------------------------------------------- tenancy

    #[Test]
    public function another_organizations_group_is_a_miss_and_its_route_is_a_refusal(): void
    {
        Sanctum::actingAs($this->adminA);

        // A's own route, B's group id: invisible to the bound tenant.
        $this->getJson($this->awardsUrl($this->groupB))->assertStatus(404);
        // B's masjid in the route: refused by ResolveMasjidTenant.
        $this->getJson($this->awardsUrl($this->groupB, $this->masjidB))->assertStatus(403);
    }

    #[Test]
    public function another_organizations_award_cannot_be_revoked(): void
    {
        $foreignContact = Contact::factory()->create(['masjid_id' => $this->masjidB->id, 'email' => null]);
        $foreignStudent = $this->seedMembership(
            $this->masjidB, $this->groupB, $foreignContact, GroupMembership::ROLE_MEMBER
        );
        $foreignAward = $this->seedAward($foreignStudent);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->awardsUrl() . '/' . $foreignAward->id)->assertStatus(404);
        $this->assertDatabaseHas('behavior_awards', ['id' => $foreignAward->id, 'deleted_at' => null]);
    }
}
