<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B1 — a negative behaviour always SUBTRACTS (HELD FOR THE OWNER: this file is
 * the sign-fix commit's test and goes or stays with it).
 *
 * THE BUG: a teacher-made negative skill ("Talking out of turn", polarity
 * negative, default_points 1) is stored as +1, because the vocabulary keeps the
 * magnitude and lets polarity carry the direction. Every read aggregate then
 * SUMmed the stored value, so the picker showed "−1" and the child's total went
 * UP by one. The direction is now taken from the polarity at READ time:
 * `SUM(CASE WHEN skill_polarity = 'negative' THEN -ABS(points) ELSE points END)`
 * (`BehaviorAward::signedPointsSql()`), in the staff summary, the family summary
 * and the class totals. Only NEGATIVE-polarity rows can move: every other row reads
 * as stored, so a positive skill given with a negative override (a teacher docking
 * a child) keeps netting what it always did. NO STORED ROW CHANGES: production has
 * 0 live negative awards, and the last test below pins that reads never write.
 *
 * Fixtures are chosen so the old SUM(points) and the new signed SUM disagree on
 * every asserted figure.
 */
class BehaviorSignTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private User $admin;
    private User $teacher;
    private Group $group;
    private Contact $parent;
    private GroupMembership $child;
    private GroupMembership $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Test School '.uniqid(), 'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
        ]);
        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->group = Group::factory()->create([
            'masjid_id' => $this->masjid->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 3',
        ]);

        // The class's teacher, for the teacher realm's own routes (the class totals live there).
        $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create([
            'masjid_id' => $this->masjid->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true,
        ]);
        $this->group->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->masjid->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $leader = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => $this->admin->email]);
        $this->membership($leader, GroupMembership::ROLE_LEADER);

        $childContact = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => null]);
        $this->child = $this->membership($childContact, GroupMembership::ROLE_MEMBER);
        $siblingContact = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => null]);
        $this->sibling = $this->membership($siblingContact, GroupMembership::ROLE_MEMBER);

        $this->parent = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->parent->forceFill([
            'login_email' => 'parent-'.uniqid().'@test.local', 'login_enabled_at' => now(),
        ])->save();
        $this->membership($this->parent, GroupMembership::ROLE_GUARDIAN, $childContact);
    }

    private function membership(Contact $contact, string $role, ?Contact $ward = null): GroupMembership
    {
        return GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->group->id,
            'contact_id' => $contact->id, 'role' => $role, 'guardian_of_contact_id' => $ward?->id,
        ]);
    }

    private function award(GroupMembership $m, string $label, string $polarity, int $points, array $extra = []): BehaviorAward
    {
        return BehaviorAward::factory()->create($extra + [
            'masjid_id' => $this->masjid->id, 'group_id' => $this->group->id,
            'group_membership_id' => $m->id, 'skill_label' => $label,
            'skill_polarity' => $polarity, 'points' => $points, 'awarded_at' => now(),
        ]);
    }

    private function staffUrl(string $path): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/groups/{$this->group->id}{$path}";
    }

    private function teacherUrl(string $path): string
    {
        return "/api/teacher/masjids/{$this->masjid->id}/groups/{$this->group->id}{$path}";
    }

    private function asTeacher(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($this->teacher, ['staff']);
    }

    private function familySummary(): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader('Authorization', 'Bearer '.$this->parent->createFamilyToken()->plainTextToken)
            ->getJson("/api/family/masjids/{$this->masjid->id}/groups/{$this->group->id}/members/{$this->child->id}/awards/summary")
            ->assertOk();
    }

    /** The teacher-made negative skill stores its MAGNITUDE: this is the row the bug is about. */
    private function seedMixedRecord(GroupMembership $m): void
    {
        $this->award($m, 'Kindness', BehaviorSkill::POLARITY_POSITIVE, 3);
        $this->award($m, 'Talking out of turn', BehaviorSkill::POLARITY_NEGATIVE, 1);   // stored +1
        $this->award($m, 'Talking out of turn', BehaviorSkill::POLARITY_NEGATIVE, 1);   // stored +1
        $this->award($m, 'Late', BehaviorSkill::POLARITY_NEGATIVE, -2);                 // an older row stored SIGNED
    }

    #[Test]
    public function a_negative_skill_subtracts_in_the_staff_summary_whether_it_was_stored_positive_or_signed(): void
    {
        $this->seedMixedRecord($this->child);
        Sanctum::actingAs($this->admin);

        $r = $this->getJson($this->staffUrl("/members/{$this->child->id}/awards/summary"))->assertOk();

        // 3 - 1 - 1 - 2. The old SUM(points) said 3 + 1 + 1 - 2 = 3.
        $this->assertSame(-1, $r->json('data.totals.points'));
        $this->assertSame(3, $r->json('data.by_polarity.positive.points'));
        $this->assertSame(-4, $r->json('data.by_polarity.negative.points'));

        $by = collect($r->json('data.by_skill'))->keyBy('skill_label');
        $this->assertSame(-2, $by['Talking out of turn']['points']);
        // A row already stored signed is not flipped a second time (-ABS, not negate).
        $this->assertSame(-2, $by['Late']['points']);
        $this->assertSame(3, $by['Kindness']['points']);
    }

    #[Test]
    public function the_family_summary_reads_the_same_figures_as_the_staff_summary(): void
    {
        $this->seedMixedRecord($this->child);
        Sanctum::actingAs($this->admin);
        $staff = $this->getJson($this->staffUrl("/members/{$this->child->id}/awards/summary"))->assertOk()->json('data');

        $family = $this->familySummary()->json('data');

        $this->assertSame(-1, $family['totals']['points']);
        $this->assertSame($staff['totals'], $family['totals']);
        $this->assertSame($staff['by_polarity'], $family['by_polarity']);
        $this->assertSame($staff['by_skill'], $family['by_skill']);
    }

    #[Test]
    public function the_class_totals_equal_each_childs_summary_and_the_class_figure_is_their_sum(): void
    {
        $this->seedMixedRecord($this->child);
        $this->award($this->sibling, 'Talking out of turn', BehaviorSkill::POLARITY_NEGATIVE, 1);
        $this->award($this->sibling, 'Helped', BehaviorSkill::POLARITY_POSITIVE, 2);
        $this->asTeacher();

        $totals = $this->getJson($this->teacherUrl('/awards/totals'))->assertOk()->json('data');
        $byId = collect($totals['students'])->keyBy('membership_id');

        $this->assertSame(-1, $byId[$this->child->id]['points']);
        $this->assertSame(1, $byId[$this->sibling->id]['points']);
        $this->assertSame(0, $totals['class']['points']);

        // One definition: the number on the class screen is the number in the child's summary.
        foreach ([$this->child, $this->sibling] as $m) {
            $summary = $this->getJson($this->teacherUrl("/members/{$m->id}/awards/summary"))->json('data.totals.points');
            $this->assertSame($summary, $byId[$m->id]['points']);
        }
    }

    #[Test]
    public function a_positive_skill_given_with_a_negative_override_keeps_docking_the_child(): void
    {
        // StoreBehaviorAwardRequest accepts an override from -max to +max and the controller snapshots it as
        // given: a teacher who gives 'Kindness' with -3 is taking points off. Reading that as +3 (ABS) would
        // turn a deduction into a reward in every total; the rule leaves such a row exactly as stored.
        $this->award($this->child, 'Effort', BehaviorSkill::POLARITY_POSITIVE, 10);
        $this->award($this->child, 'Kindness', BehaviorSkill::POLARITY_POSITIVE, -3);
        Sanctum::actingAs($this->admin);

        $staff = $this->getJson($this->staffUrl("/members/{$this->child->id}/awards/summary"))->assertOk();

        $this->assertSame(7, $staff->json('data.totals.points'), '10 - 3, exactly what SUM(points) said before this change');
        $this->assertSame(7, $staff->json('data.by_polarity.positive.points'), 'the office Positive column agrees');
        $this->assertSame(-3, collect($staff->json('data.by_skill'))->firstWhere('skill_label', 'Kindness')['points']);

        $family = $this->familySummary()->json('data');
        $this->assertSame(7, $family['totals']['points']);
        $this->assertSame($staff->json('data.by_polarity'), $family['by_polarity']);

        $this->asTeacher();
        $this->getJson($this->teacherUrl('/awards/totals'))->assertOk()->assertJsonPath('data.class.points', 7);
    }

    #[Test]
    public function a_negative_skill_given_with_a_positive_or_negative_override_still_subtracts(): void
    {
        // Direction comes from the polarity for a NEGATIVE skill, whatever sign the override was typed with.
        $this->award($this->child, 'Effort', BehaviorSkill::POLARITY_POSITIVE, 10);
        $this->award($this->child, 'Talking out of turn', BehaviorSkill::POLARITY_NEGATIVE, 4);
        $this->award($this->child, 'Talking out of turn', BehaviorSkill::POLARITY_NEGATIVE, -2);
        Sanctum::actingAs($this->admin);

        $this->getJson($this->staffUrl("/members/{$this->child->id}/awards/summary"))
            ->assertOk()->assertJsonPath('data.totals.points', 4);
    }

    #[Test]
    public function an_unreadable_polarity_degrades_to_positive_and_reads_as_stored_as_it_always_has(): void
    {
        $this->award($this->child, 'Odd', 'sideways', 4);
        $this->award($this->child, 'Odd docked', 'sideways', -1);
        Sanctum::actingAs($this->admin);

        $r = $this->getJson($this->staffUrl("/members/{$this->child->id}/awards/summary"))->assertOk();
        $this->assertSame(3, $r->json('data.totals.points'));
        $this->assertSame(3, $r->json('data.by_polarity.positive.points'));
        $this->assertSame(0, $r->json('data.by_polarity.negative.points'));
    }

    #[Test]
    public function a_revoked_negative_award_no_longer_subtracts(): void
    {
        $this->award($this->child, 'Kindness', BehaviorSkill::POLARITY_POSITIVE, 3);
        $bad = $this->award($this->child, 'Talking out of turn', BehaviorSkill::POLARITY_NEGATIVE, 1);
        Sanctum::actingAs($this->admin);

        $this->assertSame(2, $this->getJson($this->staffUrl("/members/{$this->child->id}/awards/summary"))->json('data.totals.points'));

        $this->deleteJson($this->staffUrl("/awards/{$bad->id}"))->assertOk();

        $this->assertSame(3, $this->getJson($this->staffUrl("/members/{$this->child->id}/awards/summary"))->json('data.totals.points'));
    }

    #[Test]
    public function a_teacher_made_negative_skill_awarded_through_the_api_subtracts_end_to_end(): void
    {
        $this->asTeacher();
        $skill = $this->postJson("/api/teacher/masjids/{$this->masjid->id}/behavior-skills", [
            'label' => 'Talking out of turn', 'polarity' => 'negative', 'default_points' => 1, 'is_active' => true,
        ])->assertStatus(201)->json('data.id');

        $this->postJson($this->teacherUrl('/awards'), [
            'membership_id' => $this->child->id, 'behavior_skill_id' => $skill,
        ])->assertStatus(201);

        // What is STORED is unchanged by this fix: the magnitude the vocabulary keeps.
        $this->assertSame(1, BehaviorAward::withoutMasjidScope()->firstOrFail()->points);

        $this->getJson($this->teacherUrl("/members/{$this->child->id}/awards/summary"))
            ->assertOk()->assertJsonPath('data.totals.points', -1);
        $this->getJson($this->teacherUrl('/awards/totals'))
            ->assertOk()->assertJsonPath('data.class.points', -1);
    }

    #[Test]
    public function reading_the_totals_never_rewrites_a_stored_award(): void
    {
        $this->seedMixedRecord($this->child);
        $before = BehaviorAward::withoutMasjidScope()->orderBy('id')->get(['id', 'skill_polarity', 'points', 'updated_at'])->toArray();

        Sanctum::actingAs($this->admin);
        $this->getJson($this->staffUrl("/members/{$this->child->id}/awards/summary"))->assertOk();
        $this->asTeacher();
        $this->getJson($this->teacherUrl('/awards/totals'))->assertOk();
        $this->familySummary();

        $after = BehaviorAward::withoutMasjidScope()->orderBy('id')->get(['id', 'skill_polarity', 'points', 'updated_at'])->toArray();
        $this->assertSame($before, $after);
    }
}
