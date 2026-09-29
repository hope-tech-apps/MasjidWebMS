<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-001.3 from the teacher's side: which subjects a piece of work may be filed
 * under, and what happens when it is filed under one that is not offered.
 *
 * (The office's own screen is in `SchoolSubjectsTest`.) The list a class offers is
 * the school's own list, else the guide's, else nothing to check against; a
 * subject limited to grades is offered only where a child in the class is in one
 * of them; and a child with no grade label never hides a subject.
 */
class GradebookSubjectsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private Group $class;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = Masjid::create([
            'name' => 'School '.uniqid(), 'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);

        $this->class = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 2', 'slug' => 'g2']);
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
    }

    private function subject(string $name, ?array $grades = null, int $position = 0): SchoolSubject
    {
        return SchoolSubject::withoutMasjidScope()->create([
            'masjid_id' => $this->school->id, 'name' => $name, 'grade_labels' => $grades, 'position' => $position,
        ]);
    }

    private function enrol(string $grade): GroupMembership
    {
        $child = Contact::factory()->create(['masjid_id' => $this->school->id]);

        return GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER, 'grade_label' => $grade,
        ]);
    }

    /** @return array<int,array{name:string,key:string}> what the teacher's work form offers for the class */
    private function offered(): array
    {
        Sanctum::actingAs($this->teacher, ['staff']);

        return $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/assignments")
            ->assertOk()->json('subjects');
    }

    #[Test]
    public function a_school_with_a_list_offers_that_list_and_ignores_the_guide(): void
    {
        $this->enrol('2nd');
        CurriculumWeek::create([
            'masjid_id' => $this->school->id, 'grade_label' => 'Grade 2', 'subject' => 'Guide Only', 'week_no' => 1, 'focus' => 'x',
        ]);
        $this->subject('Mathematics', null, 1);
        $this->subject("Qur'an", null, 0);

        $names = array_column($this->offered(), 'name');

        $this->assertSame(["Qur'an", 'Mathematics'], $names);
    }

    #[Test]
    public function a_school_with_no_list_offers_the_guides_subjects_for_the_grades_in_the_class(): void
    {
        $this->enrol('2nd');
        foreach ([['Grade 2', 'Science'], ['Grade 2', 'Reading'], ['Grade 5', 'Chemistry']] as [$grade, $subject]) {
            CurriculumWeek::create([
                'masjid_id' => $this->school->id, 'grade_label' => $grade, 'subject' => $subject, 'week_no' => 1, 'focus' => 'x',
            ]);
        }

        // "2nd" on the roster and "Grade 2" in the guide are one grade.
        $this->assertEqualsCanonicalizing(['Science', 'Reading'], array_column($this->offered(), 'name'));
    }

    #[Test]
    public function a_school_with_neither_offers_nothing_to_check_against_and_free_text_is_accepted(): void
    {
        $this->assertSame([], $this->offered());

        $this->postJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/assignments", [
            'title' => 'Free', 'scale' => 'points', 'points_possible' => 10,
            'assigned_on' => now()->toDateString(), 'subject' => 'Anything At All',
        ])->assertCreated()->assertJsonPath('data.subject', 'Anything At All');
    }

    #[Test]
    public function a_subject_limited_to_grades_is_offered_only_where_a_child_is_in_one_of_them(): void
    {
        $this->subject('Mathematics');
        $this->subject('Tajweed', ['3rd', '4th']);
        $this->enrol('2nd');

        $this->assertSame(['Mathematics'], array_column($this->offered(), 'name'));

        // A combined class: one child in 4th is enough to teach it.
        $this->enrol('Grade 4');
        $this->assertEqualsCanonicalizing(['Mathematics', 'Tajweed'], array_column($this->offered(), 'name'));
    }

    #[Test]
    public function a_child_with_no_grade_label_never_hides_a_subject(): void
    {
        $this->subject('Tajweed', ['3rd']);
        $child = Contact::factory()->create(['masjid_id' => $this->school->id]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER, 'grade_label' => null,
        ]);

        $this->assertSame(['Tajweed'], array_column($this->offered(), 'name'));
    }

    #[Test]
    public function work_must_be_filed_under_a_listed_subject_by_any_spelling(): void
    {
        $this->enrol('2nd');
        $this->subject("Qur'an");
        Sanctum::actingAs($this->teacher, ['staff']);
        $url = "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/assignments";
        $base = ['title' => 'W', 'scale' => 'points', 'points_possible' => 10, 'assigned_on' => now()->toDateString()];

        $this->postJson($url, $base + ['subject' => 'Underwater Basket Weaving'])
            ->assertStatus(422)
            ->assertJsonPath('data.subject.0', "That subject is not on this school's list. Choose one from the list.");

        // The typographic apostrophe is the listed subject.
        $this->postJson($url, $base + ['subject' => 'Qur’an'])->assertCreated();
        // No subject is allowed in the API (G10): required in the form, not here.
        $this->postJson($url, $base)->assertCreated()->assertJsonPath('data.subject', null);
    }

    #[Test]
    public function a_subject_retired_after_work_was_set_does_not_make_that_work_uneditable(): void
    {
        $this->enrol('2nd');
        $s = $this->subject('Science');
        Sanctum::actingAs($this->teacher, ['staff']);
        $url = "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/assignments";
        $base = ['title' => 'W', 'scale' => 'points', 'points_possible' => 10, 'assigned_on' => now()->toDateString()];

        $id = $this->postJson($url, $base + ['subject' => 'Science'])->assertCreated()->json('data.id');
        $s->delete();
        $this->subject('Art');

        // Still Science: unchanged, so accepted. A NEW retired subject is not.
        $this->putJson("{$url}/{$id}", ['title' => 'Renamed'] + $base + ['subject' => 'Science'])->assertOk();
        $this->postJson($url, $base + ['subject' => 'Science'])->assertStatus(422);
    }

    #[Test]
    public function existing_work_cannot_be_refiled_under_a_subject_the_school_never_listed(): void
    {
        $this->enrol('2nd');
        $this->subject('Science');
        $this->subject('Art');
        Sanctum::actingAs($this->teacher, ['staff']);
        $url = "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/assignments";
        $base = ['title' => 'W', 'scale' => 'points', 'points_possible' => 10, 'assigned_on' => now()->toDateString()];

        $id = $this->postJson($url, $base + ['subject' => 'Science'])->assertCreated()->json('data.id');

        // A typo or a made-up subject on work that already exists: refused, and
        // the work keeps the subject it had. (Only an UNCHANGED subject is exempt,
        // which is what the retired-subject test above pins.)
        $this->putJson("{$url}/{$id}", $base + ['subject' => 'Maths '])
            ->assertStatus(422)
            ->assertJsonPath('data.subject.0', "That subject is not on this school's list. Choose one from the list.");
        $this->assertSame('Science', \App\Models\ClassAssignment::query()->find($id)->subject);

        // A listed subject is a fine thing to move it to.
        $this->putJson("{$url}/{$id}", $base + ['subject' => 'Art'])->assertOk()->assertJsonPath('data.subject', 'Art');
    }
}
