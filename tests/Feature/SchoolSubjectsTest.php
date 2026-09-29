<?php

namespace Tests\Feature;

use App\Models\ClassAssignment;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-001.3: the school's own list of subjects, kept by the office in the Subjects
 * screen and offered to teachers setting work and planning lessons.
 *
 * Two things matter above the rest. First, editing the list moves NO mark: work
 * keeps a snapshot of the name it was set under. Second, a subject the office has
 * not listed is never invented by the system: the guide's own subjects are shown
 * for reference and only ever offered when the school has no list of its own.
 */
class SchoolSubjectsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Masjid $other;
    private User $admin;
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

        $this->school = $this->makeSchool();
        $this->other = $this->makeSchool();

        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->school->user_id = $this->admin->id;
        $this->school->save();

        $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);

        $this->class = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 2', 'slug' => 'g2']);
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
    }

    private function makeSchool(): Masjid
    {
        return Masjid::create([
            'name' => 'School '.uniqid(), 'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->school->id}/school-subjects".$path;
    }

    private function subject(string $name, ?array $grades = null, int $position = 0, ?Masjid $in = null): SchoolSubject
    {
        return SchoolSubject::withoutMasjidScope()->create([
            'masjid_id' => ($in ?? $this->school)->id, 'name' => $name, 'grade_labels' => $grades, 'position' => $position,
        ]);
    }

    // ---------------------------------------------------------- the office screen

    #[Test]
    public function the_office_lists_adds_renames_and_removes_subjects(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), ['name' => "Qur'an", 'position' => 2])->assertCreated()->assertJsonPath('data.name', "Qur'an");
        $id = $this->postJson($this->url(), ['name' => 'Mathematics', 'position' => 1])->assertCreated()->json('data.id');

        // Position, then name.
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.name', 'Mathematics')
            ->assertJsonPath('data.1.name', "Qur'an")
            ->assertJsonPath('data.0.grade_labels', null)
            ->assertJsonPath('meta.grade_levels.0', 'Pre-K');

        $this->putJson($this->url("/{$id}"), ['name' => 'Math', 'position' => 1])->assertOk()->assertJsonPath('data.name', 'Math');
        $this->assertSame('math', SchoolSubject::find($id)->name_key);

        $this->deleteJson($this->url("/{$id}"))->assertOk();
        $this->assertNull(SchoolSubject::find($id));
    }

    #[Test]
    public function two_spellings_of_one_subject_are_refused_with_a_sentence_not_a_500(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), ['name' => "Qur'an"])->assertCreated();

        $this->postJson($this->url(), ['name' => 'Qur’an'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('data.name_key.0', 'That subject is already on the list.');

        $this->postJson($this->url(), ['name' => '  QURAN '])->assertStatus(422);
        $this->assertSame(1, SchoolSubject::query()->count());
    }

    #[Test]
    public function saving_a_subject_under_its_own_name_is_not_a_clash(): void
    {
        Sanctum::actingAs($this->admin);
        $s = $this->subject('Science');

        $this->putJson($this->url("/{$s->id}"), ['name' => 'Science', 'grade_labels' => ['3rd']])->assertOk();

        $other = $this->subject('Art');
        $this->putJson($this->url("/{$other->id}"), ['name' => 'science'])->assertStatus(422);
    }

    #[Test]
    public function the_name_and_grade_levels_are_validated_at_the_columns_own_limits(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), ['name' => ''])->assertStatus(422);
        $this->postJson($this->url(), ['name' => str_repeat('x', 65)])->assertStatus(422);
        $this->postJson($this->url(), ['name' => str_repeat('é', 64)])->assertCreated();

        // Free text would hide a subject from a whole grade on a typo.
        $this->postJson($this->url(), ['name' => 'Art', 'grade_labels' => ['Grade Three']])->assertStatus(422);
        $this->postJson($this->url(), ['name' => 'Art', 'grade_labels' => '3rd'])->assertStatus(422);
        $this->postJson($this->url(), ['name' => 'Art', 'grade_labels' => ['3rd', '3rd']])->assertStatus(422);
        $this->postJson($this->url(), ['name' => 'Art', 'position' => 1001])->assertStatus(422);

        $this->postJson($this->url(), ['name' => 'Art', 'grade_labels' => ['3rd', '4th']])->assertCreated()
            ->assertJsonPath('data.grade_labels', ['3rd', '4th']);
    }

    #[Test]
    public function an_empty_grade_list_means_every_grade(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), ['name' => 'Art', 'grade_labels' => []])->assertCreated()
            ->assertJsonPath('data.grade_labels', null);

        $this->assertNull(SchoolSubject::query()->where('name', 'Art')->first()->grade_labels);
    }

    #[Test]
    public function the_list_reports_how_much_work_carries_each_name_and_editing_moves_no_mark(): void
    {
        Sanctum::actingAs($this->admin);
        $s = $this->subject("Qur'an");

        $work = ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => 'Fatiha',
            'points_possible' => 10, 'scale' => 'points', 'subject' => 'Qur’an', 'assigned_on' => now()->toDateString(),
        ]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.0.work_count', 1);

        // Rename, then delete: the work keeps the words it was set under.
        $this->putJson($this->url("/{$s->id}"), ['name' => 'Quran Recitation'])->assertOk();
        $this->deleteJson($this->url("/{$s->id}"))->assertOk();

        $this->assertSame('Qur’an', $work->fresh()->subject);
    }

    #[Test]
    public function the_guide_is_shown_for_reference_and_never_turned_into_subjects(): void
    {
        CurriculumWeek::create([
            'masjid_id' => $this->school->id, 'grade_label' => '2nd', 'subject' => 'Science', 'week_no' => 1, 'focus' => 'Plants',
        ]);
        Sanctum::actingAs($this->admin);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('meta.guide_subjects', ['Science'])
            ->assertJsonCount(0, 'data');
        $this->assertSame(0, SchoolSubject::query()->count());
    }

    // ------------------------------------------------------------ who may use it

    #[Test]
    public function reading_needs_view_contacts_and_writing_needs_manage_contacts(): void
    {
        $gates = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_contains($route->uri(), 'school-subjects') || ! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }
            $permission = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $gates[] = "{$method} {$permission}";
            }
        }

        $this->assertEqualsCanonicalizing([
            'GET permission:view contacts',
            'POST permission:manage contacts',
            'PUT permission:manage contacts',
            'DELETE permission:manage contacts',
        ], $gates);
    }

    #[Test]
    public function a_teacher_cannot_reach_the_office_subjects_screen(): void
    {
        Sanctum::actingAs($this->teacher, ['staff']);

        // The admin realm refuses a Teacher token outright (401 from its `admin`
        // gate); either refusal is the boundary, and nothing may be written.
        $this->assertContains($this->getJson($this->url())->status(), [401, 403]);
        $this->assertContains($this->postJson($this->url(), ['name' => 'Sneaky'])->status(), [401, 403]);
        $this->assertSame(0, SchoolSubject::withoutMasjidScope()->count());
    }

    #[Test]
    public function another_schools_subject_is_a_404_and_its_office_cannot_write_here(): void
    {
        $theirs = $this->subject('Theirs', null, 0, $this->other);
        Sanctum::actingAs($this->admin);

        $this->putJson($this->url("/{$theirs->id}"), ['name' => 'Mine now'])->assertNotFound();
        $this->deleteJson($this->url("/{$theirs->id}"))->assertNotFound();
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('Theirs', SchoolSubject::withoutMasjidScope()->find($theirs->id)->name);

        // And the other school's own office cannot name this school in the URL.
        $otherAdmin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->other->user_id = $otherAdmin->id;
        $this->other->save();
        app(\App\Support\TenantContext::class)->forgetTenant();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        Sanctum::actingAs($otherAdmin);

        $this->postJson($this->url(), ['name' => 'Injected'])->assertForbidden();
        $this->assertSame(0, SchoolSubject::withoutMasjidScope()->where('name', 'Injected')->count());
    }

    // ------------------------------------------- what the lesson plan form offers

    #[Test]
    public function the_lesson_plan_subject_list_adds_the_schools_own_and_keeps_the_guides_spelling(): void
    {
        foreach ([['2nd', 'Science'], ['2nd', 'Qur’an & Islamic Studies']] as [$grade, $subject]) {
            CurriculumWeek::create(['masjid_id' => $this->school->id, 'grade_label' => $grade, 'subject' => $subject, 'week_no' => 1, 'focus' => 'x']);
        }
        $this->subject('Science');
        $this->subject('Arabic Language');
        $this->subject('Tajweed', ['5th']);
        Sanctum::actingAs($this->teacher, ['staff']);

        $subjects = $this->getJson("/api/teacher/masjids/{$this->school->id}/curriculum?grade=2nd")
            ->assertOk()->json('data.subjects');

        // The guide's own name for the combined column is still there (hiding it
        // would remove the standards search); Arabic Language arrives from the
        // list; Science is one entry; a Grade 5 subject is not offered at 2nd.
        $this->assertEqualsCanonicalizing(['Arabic Language', 'Qur’an & Islamic Studies', 'Science'], $subjects);
    }

    #[Test]
    public function arabic_has_no_standards_and_the_search_never_makes_one_up(): void
    {
        CurriculumWeek::create([
            'masjid_id' => $this->school->id, 'grade_label' => '2nd', 'subject' => 'Science', 'week_no' => 1,
            'focus' => 'Plants', 'standard_code' => 'NC.2.L.1',
        ]);
        $this->subject('Arabic Language');
        Sanctum::actingAs($this->teacher, ['staff']);
        $base = "/api/teacher/masjids/{$this->school->id}/curriculum";

        // A weeks lookup for the list-only subject is empty, not fabricated.
        $this->getJson("{$base}?grade=2nd&subject=".rawurlencode('Arabic Language'))
            ->assertOk()->assertJsonPath('data.weeks', []);

        $this->getJson("{$base}/standards?q=arabic&grade=2nd&subject=".rawurlencode('Arabic Language'))
            ->assertOk()->assertJsonPath('data.matches', []);
    }
}
