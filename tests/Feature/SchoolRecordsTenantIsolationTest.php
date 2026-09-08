<?php

namespace Tests\Feature;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupResource;
use App\Models\LessonPlan;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer boundary around the three school records added with the
 * gradebook slice: lesson plans, assignments and their scores, and class files.
 *
 * MySQL has no row-level security, so `BelongsToMasjid` is the only thing
 * keeping one school's gradebook out of another school's queries — and
 * .claude/rules/tenant-scoping.md makes a cross-tenant test mandatory for every
 * new tenant-scoped model. TenantContext is bound directly here, the way
 * ResolveMasjidTenant binds it per request.
 */
class SchoolRecordsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;
    private Masjid $schoolA;
    private Masjid $schoolB;
    private Group $classA;
    private Group $classB;
    private GroupMembership $studentB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->schoolA = $this->makeMasjid();
        $this->schoolB = $this->makeMasjid();

        $this->classA = $this->makeClass($this->schoolA, 'a');
        $this->classB = $this->makeClass($this->schoolB, 'b');
        $this->studentB = $this->enrol($this->schoolB, $this->classB);
    }

    #[Test]
    public function the_scope_hides_another_schools_lesson_plans(): void
    {
        $foreign = $this->tenant->runWithout(fn () => LessonPlan::create([
            'masjid_id' => $this->schoolB->id, 'group_id' => $this->classB->id,
            'session_date' => '2026-09-08', 'body' => 'Their plan, not ours.',
        ]));

        $this->tenant->set($this->schoolA->id);

        $this->assertNull(LessonPlan::find($foreign->id));
        $this->assertSame(0, LessonPlan::where('id', $foreign->id)->update(['body' => 'tampered']));
        $this->assertSame(0, LessonPlan::where('id', $foreign->id)->delete());
    }

    #[Test]
    public function the_scope_hides_another_schools_class_assignments(): void
    {
        $foreign = $this->foreignAssignment();

        $this->tenant->set($this->schoolA->id);

        $this->assertNull(ClassAssignment::find($foreign->id));
        $this->assertSame(0, ClassAssignment::where('id', $foreign->id)->update(['title' => 'tampered']));
        $this->assertCount(0, ClassAssignment::all());
    }

    #[Test]
    public function the_scope_hides_another_schools_assignment_scores(): void
    {
        $foreign = $this->tenant->runWithout(function () {
            $assignment = $this->foreignAssignmentUnbound();

            return AssignmentScore::create([
                'masjid_id' => $this->schoolB->id, 'group_id' => $this->classB->id,
                'class_assignment_id' => $assignment->id,
                'group_membership_id' => $this->studentB->id,
                'status' => AssignmentScore::STATUS_SCORED, 'points_earned' => 9,
            ]);
        });

        $this->tenant->set($this->schoolA->id);

        $this->assertNull(AssignmentScore::find($foreign->id));
        $this->assertSame(0, AssignmentScore::where('id', $foreign->id)->update(['points_earned' => 0]));
        $this->assertSame(0, AssignmentScore::where('id', $foreign->id)->delete());
    }

    #[Test]
    public function the_scope_hides_another_schools_group_resources(): void
    {
        $foreign = $this->tenant->runWithout(fn () => GroupResource::create([
            'masjid_id' => $this->schoolB->id, 'group_id' => $this->classB->id,
            'title' => 'Their worksheet', 'visibility' => GroupResource::VISIBILITY_FAMILIES,
            'original_name' => 'w.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 10, 'disk' => 'local', 'path' => 'x/y/z.pdf',
        ]));

        $this->tenant->set($this->schoolA->id);

        $this->assertNull(GroupResource::find($foreign->id));
        $this->assertCount(0, GroupResource::all());
    }

    #[Test]
    public function create_stamps_the_bound_tenant_over_a_client_supplied_masjid_id(): void
    {
        $this->tenant->set($this->schoolA->id);

        // Payloads that TRY to file these under the other school.
        $plan = LessonPlan::create([
            'masjid_id' => $this->schoolB->id, 'group_id' => $this->classA->id,
            'session_date' => '2026-09-08', 'body' => 'ours',
        ]);
        $assignment = ClassAssignment::create([
            'masjid_id' => $this->schoolB->id, 'group_id' => $this->classA->id,
            'title' => 'Ours', 'points_possible' => 10, 'assigned_on' => '2026-09-08',
        ]);
        $resource = GroupResource::create([
            'masjid_id' => $this->schoolB->id, 'group_id' => $this->classA->id,
            'title' => 'Ours', 'original_name' => 'a.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 1, 'disk' => 'local', 'path' => 'a/b/c.pdf',
        ]);

        foreach ([$plan, $assignment, $resource] as $row) {
            $this->assertSame(
                $this->schoolA->id,
                (int) $row->masjid_id,
                'the bound tenant must win over a masjid_id carried in from a request body'
            );
        }
    }

    /** A withdrawn assignment must not resurrect its scores into a child's record. */
    #[Test]
    public function scores_of_a_withdrawn_assignment_are_excluded_when_read_through_the_assignment(): void
    {
        $this->tenant->set($this->schoolA->id);

        $student = $this->enrol($this->schoolA, $this->classA);
        $assignment = ClassAssignment::create([
            'masjid_id' => $this->schoolA->id, 'group_id' => $this->classA->id,
            'title' => 'Spelling', 'points_possible' => 10, 'assigned_on' => '2026-09-08',
        ]);
        AssignmentScore::create([
            'masjid_id' => $this->schoolA->id, 'group_id' => $this->classA->id,
            'class_assignment_id' => $assignment->id, 'group_membership_id' => $student->id,
            'status' => AssignmentScore::STATUS_SCORED, 'points_earned' => 8,
        ]);

        $assignment->delete();

        // The row still exists — withdrawing work must not destroy marks — but
        // every read joins the assignment, so it stops counting.
        $this->assertCount(0, AssignmentScore::whereHas('assignment')->get());
        $this->assertSame(1, AssignmentScore::count(), 'the mark itself is retained');
    }

    private function foreignAssignment(): ClassAssignment
    {
        return $this->tenant->runWithout(fn () => $this->foreignAssignmentUnbound());
    }

    private function foreignAssignmentUnbound(): ClassAssignment
    {
        return ClassAssignment::create([
            'masjid_id' => $this->schoolB->id, 'group_id' => $this->classB->id,
            'title' => 'Their work', 'points_possible' => 10, 'assigned_on' => '2026-09-08',
        ]);
    }

    private function enrol(Masjid $school, Group $class): GroupMembership
    {
        return $this->tenant->runWithout(function () use ($school, $class) {
            $child = Contact::factory()->create([
                'masjid_id' => $school->id, 'first_name' => 'Child', 'last_name' => 'X',
            ]);

            return GroupMembership::create([
                'masjid_id' => $school->id, 'group_id' => $class->id,
                'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
            ]);
        });
    }

    private function makeClass(Masjid $school, string $suffix): Group
    {
        return $this->tenant->runWithout(fn () => Group::factory()->create([
            'masjid_id' => $school->id, 'name' => 'Class', 'slug' => 'class-' . $suffix,
            'kind' => Group::KIND_CLASS,
        ]));
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'school',
        ], $overrides));
    }
}
