<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-003.1 — "Positive always on top" (owner's list, 2026-09-28).
 *
 * WHAT THIS FILE PINS: the picker reads positives, then negatives, then anything
 * unrecognised, and by label inside each run — in the skills list AND in both
 * `by_skill` summaries (the teacher/office one and the family one). Before the
 * change all three ordered by the polarity COLUMN, which is alphabetical, so
 * "negative" sat above "positive": the opposite of what their own docblocks said.
 *
 * WHAT IT DELIBERATELY DOES NOT PIN: the award LOG, which stays newest-first
 * (one test below holds that, so nobody "fixes" it into this order).
 *
 * The labels are chosen so that alphabetical-by-label, insertion order and
 * alphabetical-by-polarity would each give a DIFFERENT answer from the right one;
 * a fixture where two orders coincide would pass with the fix deleted.
 */
class BehaviorSkillOrderTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private User $admin;
    private Group $group;
    private Contact $adminPerson;
    private Contact $parent;
    private GroupMembership $child;

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

        $this->masjid = Masjid::create([
            'name' => 'Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->group = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        // The admin is a leader of the class, so the staff summary is theirs to read.
        $this->adminPerson = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'email' => $this->admin->email,
        ]);
        $this->membership($this->adminPerson, GroupMembership::ROLE_LEADER);

        $childContact = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => null]);
        $this->child = $this->membership($childContact, GroupMembership::ROLE_MEMBER);

        $this->parent = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->parent->forceFill([
            'login_email' => 'parent-'.uniqid().'@test.local',
            'login_enabled_at' => now(),
        ])->save();
        $this->membership($this->parent, GroupMembership::ROLE_GUARDIAN, $childContact);
    }

    private function membership(Contact $contact, string $role, ?Contact $ward = null): GroupMembership
    {
        return GroupMembership::create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);
    }

    private function skill(string $label, string $polarity): BehaviorSkill
    {
        return BehaviorSkill::factory()->create([
            'masjid_id' => $this->masjid->id,
            'label' => $label,
            'polarity' => $polarity,
        ]);
    }

    /**
     * Created in the WORST order for the fix: a negative first, then positives in
     * reverse label order. Insertion order, label order and polarity-column order
     * all disagree with "positives by label, then negatives by label".
     */
    private function seedVocabulary(): void
    {
        $this->skill('Argues', BehaviorSkill::POLARITY_NEGATIVE);
        $this->skill('Zealous', BehaviorSkill::POLARITY_POSITIVE);
        $this->skill('Kind', BehaviorSkill::POLARITY_POSITIVE);
        $this->skill('Late', BehaviorSkill::POLARITY_NEGATIVE);
        $this->skill('Helpful', BehaviorSkill::POLARITY_POSITIVE);
    }

    private function seedAwardFor(string $label, string $polarity, int $points, $awardedAt = null): BehaviorAward
    {
        return BehaviorAward::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'group_membership_id' => $this->child->id,
            'skill_label' => $label,
            'skill_polarity' => $polarity,
            'points' => $points,
            'awarded_at' => $awardedAt ?? now(),
        ]);
    }

    private function familyAs(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader('Authorization', 'Bearer '.$parent->createFamilyToken()->plainTextToken);
    }

    private function seedSummaryAwards(): void
    {
        // Again the worst order: negatives and late labels first.
        $this->seedAwardFor('Late', BehaviorSkill::POLARITY_NEGATIVE, -1);
        $this->seedAwardFor('Zealous', BehaviorSkill::POLARITY_POSITIVE, 2);
        $this->seedAwardFor('Argues', BehaviorSkill::POLARITY_NEGATIVE, -1);
        $this->seedAwardFor('Kind', BehaviorSkill::POLARITY_POSITIVE, 3);
        $this->seedAwardFor('Helpful', BehaviorSkill::POLARITY_POSITIVE, 1);
    }

    private const EXPECTED_ORDER = ['Helpful', 'Kind', 'Zealous', 'Argues', 'Late'];

    // ------------------------------------------------------------------ tests

    #[Test]
    public function the_skills_list_reads_positives_then_negatives_and_by_label_within_each(): void
    {
        $this->seedVocabulary();
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/admin/masjids/'.$this->masjid->id.'/behavior-skills')->assertOk();

        $this->assertSame(self::EXPECTED_ORDER, array_column($response->json('data.data'), 'label'));
    }

    #[Test]
    public function the_polarity_filter_and_paging_keep_the_same_order(): void
    {
        $this->seedVocabulary();
        Sanctum::actingAs($this->admin);
        $url = '/api/admin/masjids/'.$this->masjid->id.'/behavior-skills';

        // The order must hold across a page boundary, not just inside one page.
        $first = $this->getJson($url.'?per_page=2&page=1')->assertOk();
        $second = $this->getJson($url.'?per_page=2&page=2')->assertOk();
        $this->assertSame(['Helpful', 'Kind'], array_column($first->json('data.data'), 'label'));
        $this->assertSame(['Zealous', 'Argues'], array_column($second->json('data.data'), 'label'));

        $negatives = $this->getJson($url.'?polarity=negative')->assertOk();
        $this->assertSame(['Argues', 'Late'], array_column($negatives->json('data.data'), 'label'));
    }

    #[Test]
    public function an_unrecognised_polarity_sorts_after_both_known_ones(): void
    {
        $this->skill('Argues', BehaviorSkill::POLARITY_NEGATIVE);
        $this->skill('Kind', BehaviorSkill::POLARITY_POSITIVE);
        // A value the app does not know, written straight to the row.
        $odd = $this->skill('Aardvark', BehaviorSkill::POLARITY_POSITIVE);
        BehaviorSkill::withoutMasjidScope()->whereKey($odd->id)->update(['polarity' => 'sideways']);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/admin/masjids/'.$this->masjid->id.'/behavior-skills')->assertOk();

        $this->assertSame(['Kind', 'Argues', 'Aardvark'], array_column($response->json('data.data'), 'label'));
    }

    #[Test]
    public function the_staff_by_skill_summary_lists_positives_first(): void
    {
        $this->seedSummaryAwards();
        Sanctum::actingAs($this->admin);

        $response = $this->getJson(
            '/api/admin/masjids/'.$this->masjid->id.'/groups/'.$this->group->id
            .'/members/'.$this->child->id.'/awards/summary'
        )->assertOk();

        $this->assertSame(self::EXPECTED_ORDER, array_column($response->json('data.by_skill'), 'skill_label'));
        // Ordering must not disturb the arithmetic it sits beside.
        $this->assertSame(4, $response->json('data.totals.points'));
    }

    #[Test]
    public function the_family_by_skill_summary_lists_positives_first(): void
    {
        $this->seedSummaryAwards();

        $response = $this->familyAs($this->parent)->getJson(
            '/api/family/masjids/'.$this->masjid->id.'/groups/'.$this->group->id
            .'/members/'.$this->child->id.'/awards/summary'
        )->assertOk();

        $this->assertSame(self::EXPECTED_ORDER, array_column($response->json('data.by_skill'), 'skill_label'));
        $this->assertSame(4, $response->json('data.totals.points'));
    }

    #[Test]
    public function the_award_log_stays_newest_first_whatever_the_polarity(): void
    {
        // A negative award is the NEWEST: if the log took the picker order it would sink.
        $this->seedAwardFor('Kind', BehaviorSkill::POLARITY_POSITIVE, 1, now()->subDays(3));
        $this->seedAwardFor('Helpful', BehaviorSkill::POLARITY_POSITIVE, 1, now()->subDays(2));
        $this->seedAwardFor('Late', BehaviorSkill::POLARITY_NEGATIVE, -1, now()->subDay());

        Sanctum::actingAs($this->admin);
        $response = $this->getJson(
            '/api/admin/masjids/'.$this->masjid->id.'/groups/'.$this->group->id
            .'/members/'.$this->child->id.'/awards'
        )->assertOk();

        $this->assertSame(['Late', 'Helpful', 'Kind'], array_column($response->json('data.data'), 'skill_label'));
    }
}
