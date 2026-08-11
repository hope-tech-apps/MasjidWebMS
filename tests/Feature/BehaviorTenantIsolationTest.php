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
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer guarantees behind the Classroom module (PLAN T-013).
 *
 * MySQL has NO row-level security, so App\Models\Concerns\BelongsToMasjid is
 * the only thing keeping one organization's behaviour records out of another's
 * queries. .claude/rules/tenant-scoping.md makes a cross-tenant test mandatory
 * for every new tenant-scoped model; this is it for BehaviorSkill AND
 * BehaviorAward, binding TenantContext directly the way ResolveMasjidTenant
 * does per request.
 *
 * It also pins what the MODELS own rather than the HTTP boundary, because those
 * guarantees must hold for every caller — importers and seeders included:
 *
 *   - THE SNAPSHOT: editing or deleting a skill never rewrites an award that
 *     was already given, the same promise the fee plans make in-flight
 *     registrations;
 *   - REVOCATION is the soft delete, so a revoked award leaves every listing
 *     and every total through one mechanism;
 *   - DELETION follows the student: an award cannot outlive the membership row
 *     its whole audience is derived from;
 *   - RETENTION: an award is stamped with a window on create and swept by the
 *     shared `groups:purge-feed` command.
 */
class BehaviorTenantIsolationTest extends TestCase
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

    private function makeSkill(Masjid $masjid, array $overrides = []): BehaviorSkill
    {
        return BehaviorSkill::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
        ], $overrides));
    }

    /** A student: a contact with a participant membership in the group. */
    private function makeStudent(Masjid $masjid, Group $group): GroupMembership
    {
        $contact = Contact::factory()->create(['masjid_id' => $masjid->id, 'email' => null]);

        return GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
    }

    private function makeAward(
        Masjid $masjid,
        Group $group,
        ?GroupMembership $student = null,
        array $overrides = []
    ): BehaviorAward {
        $student ??= $this->makeStudent($masjid, $group);

        return BehaviorAward::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'group_membership_id' => $student->id,
        ], $overrides));
    }

    // ---------- tenant scope: skills ----------

    #[Test]
    public function the_scope_hides_another_organizations_skills(): void
    {
        $this->makeSkill($this->masjidA, ['label' => 'Participation']);
        $foreign = $this->makeSkill($this->masjidB, ['label' => 'Kindness']);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, BehaviorSkill::count());
        $this->assertSame($this->masjidA->id, BehaviorSkill::first()->masjid_id);
        $this->assertNull(BehaviorSkill::find($foreign->id));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_on_a_skill(): void
    {
        $this->tenant->set($this->masjidA->id);

        // A client-supplied masjid_id must lose to the bound tenant.
        $skill = BehaviorSkill::create([
            'masjid_id' => $this->masjidB->id,
            'label' => 'Participation',
            'polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'default_points' => 1,
        ]);

        $this->assertSame($this->masjidA->id, $skill->masjid_id);
    }

    #[Test]
    public function two_organizations_may_both_define_the_same_skill_label(): void
    {
        // Uniqueness is PER TENANT, so a shared vocabulary word is not a clash.
        $this->makeSkill($this->masjidA, ['label' => 'Participation']);
        $this->makeSkill($this->masjidB, ['label' => 'Participation']);

        $this->assertSame(2, BehaviorSkill::withoutMasjidScope()->where('label', 'Participation')->count());
    }

    #[Test]
    public function without_masjid_scope_sees_every_organizations_skills(): void
    {
        $this->makeSkill($this->masjidA);
        $this->makeSkill($this->masjidB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, BehaviorSkill::withoutMasjidScope()->count());
    }

    // ---------- tenant scope: awards ----------

    #[Test]
    public function the_scope_hides_another_organizations_awards(): void
    {
        $this->makeAward($this->masjidA, $this->groupA);
        $foreign = $this->makeAward($this->masjidB, $this->groupB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, BehaviorAward::count());
        $this->assertNull(BehaviorAward::find($foreign->id));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_on_an_award(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);

        $this->tenant->set($this->masjidA->id);

        $award = BehaviorAward::create([
            'masjid_id' => $this->masjidB->id,
            'group_id' => $this->groupA->id,
            'group_membership_id' => $student->id,
            'skill_label' => 'Participation',
            'skill_polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'points' => 2,
        ]);

        $this->assertSame($this->masjidA->id, $award->masjid_id);
    }

    #[Test]
    public function without_masjid_scope_sees_every_organizations_awards(): void
    {
        $this->makeAward($this->masjidA, $this->groupA);
        $this->makeAward($this->masjidB, $this->groupB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, BehaviorAward::withoutMasjidScope()->count());
    }

    // ---------- the snapshot ----------

    #[Test]
    public function an_award_defaults_its_awarded_at_to_now(): void
    {
        // Every listing and every date filter reads awarded_at, so it can never
        // be null — the model, not the controller, guarantees that.
        $award = $this->makeAward($this->masjidA, $this->groupA);

        $this->assertNotNull($award->awarded_at);
    }

    #[Test]
    public function editing_a_skill_never_rewrites_an_award_already_given(): void
    {
        $skill = $this->makeSkill($this->masjidA, [
            'label' => 'Disruption',
            'polarity' => BehaviorSkill::POLARITY_NEGATIVE,
            'default_points' => 1,
        ]);

        $award = $this->makeAward($this->masjidA, $this->groupA, null, [
            'behavior_skill_id' => $skill->id,
            'skill_label' => $skill->label,
            'skill_polarity' => $skill->polarity(),
            'points' => $skill->default_points,
        ]);

        // The office renames and re-weights the skill for NEXT term.
        $skill->update([
            'label' => 'Off-task',
            'polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'default_points' => 9,
        ]);

        $award->refresh();

        $this->assertSame('Disruption', $award->skill_label);
        $this->assertSame(BehaviorSkill::POLARITY_NEGATIVE, $award->polarity());
        $this->assertSame(1, $award->points);
    }

    #[Test]
    public function deleting_a_skill_keeps_the_awards_it_produced(): void
    {
        $skill = $this->makeSkill($this->masjidA, ['label' => 'Kindness']);
        $award = $this->makeAward($this->masjidA, $this->groupA, null, [
            'behavior_skill_id' => $skill->id,
            'skill_label' => 'Kindness',
        ]);

        $skill->delete();

        $award->refresh();

        // Provenance nulls; the record itself is untouched, because the record
        // never depended on the vocabulary row.
        $this->assertNull($award->behavior_skill_id);
        $this->assertSame('Kindness', $award->skill_label);
    }

    #[Test]
    public function an_unreadable_stored_polarity_degrades_rather_than_inventing_a_bucket(): void
    {
        $award = $this->makeAward($this->masjidA, $this->groupA);
        $award->forceFill(['skill_polarity' => 'sideways'])->saveQuietly();

        $this->assertSame(BehaviorSkill::POLARITY_POSITIVE, $award->fresh()->polarity());
    }

    // ---------- revocation and deletion ----------

    #[Test]
    public function revoking_an_award_removes_it_from_ordinary_queries(): void
    {
        $award = $this->makeAward($this->masjidA, $this->groupA);

        $award->delete();

        // deleted_at IS the revocation clock, so one mechanism drops it from
        // every listing and every total at once.
        $this->assertSoftDeleted('behavior_awards', ['id' => $award->id]);
        $this->assertNull(BehaviorAward::withoutMasjidScope()->find($award->id));
        $this->assertTrue(BehaviorAward::withoutMasjidScope()->withTrashed()->find($award->id)->isRevoked());
    }

    #[Test]
    public function removing_a_student_from_the_roster_takes_their_awards(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);
        $award = $this->makeAward($this->masjidA, $this->groupA, $student);

        $student->delete();

        // The audience for an award is derived entirely from this membership
        // row. With it gone the record could never be disclosed to anyone, so
        // keeping it would be unreadable data about a minor.
        $this->assertDatabaseMissing('behavior_awards', ['id' => $award->id]);
    }

    #[Test]
    public function force_deleting_a_group_takes_its_awards(): void
    {
        $award = $this->makeAward($this->masjidA, $this->groupA);

        $this->groupA->forceDelete();

        $this->assertDatabaseMissing('behavior_awards', ['id' => $award->id]);
    }

    #[Test]
    public function soft_deleting_a_group_keeps_its_awards(): void
    {
        $award = $this->makeAward($this->masjidA, $this->groupA);

        $this->groupA->delete();

        // A mis-click must not destroy a term of records; only the retention
        // purge hard-deletes.
        $this->assertDatabaseHas('behavior_awards', ['id' => $award->id]);
    }

    // ---------- retention ----------

    #[Test]
    public function a_new_award_inherits_the_configured_retention_window(): void
    {
        config(['groups.behavior.retention_days' => 30]);

        $award = $this->makeAward($this->masjidA, $this->groupA);

        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $award->retained_until->toDateString()
        );
    }

    #[Test]
    public function a_retention_window_the_caller_sets_is_respected(): void
    {
        config(['groups.behavior.retention_days' => 30]);

        $award = $this->makeAward($this->masjidA, $this->groupA, null, [
            'retained_until' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertSame(now()->addDays(2)->toDateString(), $award->retained_until->toDateString());
    }

    #[Test]
    public function retention_days_of_zero_leaves_the_window_open(): void
    {
        config(['groups.behavior.retention_days' => 0]);

        $award = $this->makeAward($this->masjidA, $this->groupA);

        $this->assertNull($award->retained_until);
    }

    #[Test]
    public function the_purge_sweep_removes_awards_past_their_retention_date(): void
    {
        $due = $this->makeAward($this->masjidA, $this->groupA, null, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);

        // Revoked AND past retention: exactly the row that must not linger.
        $revoked = $this->makeAward($this->masjidA, $this->groupA, null, [
            'retained_until' => now()->subMonth()->toDateString(),
        ]);
        $revoked->delete();

        Artisan::call('groups:purge-feed');

        $this->assertDatabaseMissing('behavior_awards', ['id' => $due->id]);
        $this->assertDatabaseMissing('behavior_awards', ['id' => $revoked->id]);
    }

    #[Test]
    public function the_purge_sweep_leaves_awards_inside_their_window(): void
    {
        $keep = $this->makeAward($this->masjidA, $this->groupA, null, [
            'retained_until' => now()->addYear()->toDateString(),
        ]);
        $open = $this->makeAward($this->masjidA, $this->groupA, null, ['retained_until' => null]);

        Artisan::call('groups:purge-feed');

        $this->assertDatabaseHas('behavior_awards', ['id' => $keep->id]);
        // A null window means "nobody has decided", never "purge me now".
        $this->assertDatabaseHas('behavior_awards', ['id' => $open->id]);
    }

    #[Test]
    public function the_purge_sweep_can_be_limited_to_one_organization(): void
    {
        $mine = $this->makeAward($this->masjidA, $this->groupA, null, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);
        $theirs = $this->makeAward($this->masjidB, $this->groupB, null, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);

        Artisan::call('groups:purge-feed', ['--masjid' => $this->masjidA->id]);

        $this->assertDatabaseMissing('behavior_awards', ['id' => $mine->id]);
        $this->assertDatabaseHas('behavior_awards', ['id' => $theirs->id]);
    }

    #[Test]
    public function a_dry_run_purge_deletes_no_awards(): void
    {
        $due = $this->makeAward($this->masjidA, $this->groupA, null, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);

        Artisan::call('groups:purge-feed', ['--dry-run' => true]);

        $this->assertDatabaseHas('behavior_awards', ['id' => $due->id]);
    }

    // ---------- accountability ----------

    #[Test]
    public function an_award_records_which_account_gave_it_and_survives_that_account(): void
    {
        $teacher = User::factory()->create(['phone' => '+1' . random_int(1000000000, 9999999999)]);

        $award = $this->makeAward($this->masjidA, $this->groupA, null, [
            'awarded_by_user_id' => $teacher->id,
        ]);

        // Retiring a teacher is the ordinary case, and User soft-deletes — the
        // row is still there, so attribution is untouched.
        $teacher->delete();

        $this->assertSame($teacher->id, $award->fresh()->awarded_by_user_id);
        $this->assertDatabaseHas('behavior_awards', ['id' => $award->id]);

        // Erasing the account for good is the case nullOnDelete exists for:
        // the attribution goes, a term of recognition does NOT.
        $teacher->forceDelete();

        $award->refresh();

        $this->assertNull($award->awarded_by_user_id);
        $this->assertDatabaseHas('behavior_awards', ['id' => $award->id]);
    }
}
