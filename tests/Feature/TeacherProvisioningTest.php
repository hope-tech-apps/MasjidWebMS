<?php

namespace Tests\Feature;

use App\Mail\AccountAccessMail;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin side of teachers: an office creating, re-assigning, re-inviting and
 * removing them — and never reaching into another school's staff.
 */
class TeacherProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $admin;
    private Group $classOne;
    private Group $classTwo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        [$this->school, $this->admin] = $this->makeSchoolWithAdmin();

        $this->classOne = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'g1',
        ]);
        $this->classTwo = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 2', 'slug' => 'g2',
        ]);

        Sanctum::actingAs($this->admin, ['staff']);
    }

    #[Test]
    public function an_admin_creates_a_teacher_assigns_a_class_and_an_invite_goes_out(): void
    {
        Mail::fake();

        $response = $this->postJson($this->base().'/teachers', [
            'name' => 'Ustadha Maryam',
            'email' => 'maryam@school.test',
            'class_ids' => [$this->classOne->id],
        ])->assertCreated();

        $id = $response->json('data.id');
        $teacher = User::findOrFail($id);

        $this->assertSame('Teacher', $teacher->type);
        $this->assertTrue($teacher->hasRole('teacher'));
        $this->assertSame([$this->classOne->id], $this->ledClassIds($teacher));
        $this->assertDatabaseHas('masjid_user', [
            'masjid_id' => $this->school->id, 'user_id' => $id, 'role' => 'teacher',
        ]);

        Mail::assertSent(AccountAccessMail::class);
    }

    #[Test]
    public function a_class_outside_the_school_is_refused(): void
    {
        [$otherSchool] = $this->makeSchoolWithAdmin();
        $foreignClass = Group::factory()->create([
            'masjid_id' => $otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'X', 'slug' => 'x',
        ]);

        $this->postJson($this->base().'/teachers', [
            'name' => 'Nope', 'email' => 'nope@school.test',
            'class_ids' => [$foreignClass->id],
        ])->assertStatus(422);
    }

    #[Test]
    public function editing_a_teacher_syncs_their_class_assignments(): void
    {
        $teacher = $this->makeTeacher([$this->classOne->id]);

        // Add the second class.
        $this->putJson($this->base()."/teachers/{$teacher->id}", [
            'name' => 'Renamed', 'class_ids' => [$this->classOne->id, $this->classTwo->id],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->classOne->id, $this->classTwo->id],
            $this->ledClassIds($teacher)
        );
        $this->assertSame('Renamed', $teacher->fresh()->name);

        // Drop the first — a sync, not an append.
        $this->putJson($this->base()."/teachers/{$teacher->id}", [
            'name' => 'Renamed', 'class_ids' => [$this->classTwo->id],
        ])->assertOk();

        $this->assertSame([$this->classTwo->id], $this->ledClassIds($teacher));
    }

    #[Test]
    public function removing_a_teacher_strips_access_and_retires_an_orphaned_login(): void
    {
        $teacher = $this->makeTeacher([$this->classOne->id]);

        $this->deleteJson($this->base()."/teachers/{$teacher->id}")->assertOk();

        $this->assertSame([], $this->ledClassIds($teacher));
        $this->assertDatabaseMissing('masjid_user', [
            'masjid_id' => $this->school->id, 'user_id' => $teacher->id,
        ]);
        // Belonged to no other school -> the login is soft-deleted.
        $this->assertSoftDeleted('users', ['id' => $teacher->id]);
    }

    #[Test]
    public function an_admin_cannot_touch_a_teacher_from_another_school(): void
    {
        // A teacher who belongs only to ANOTHER school.
        [$otherSchool, $otherAdmin] = $this->makeSchoolWithAdmin();
        $otherClass = Group::factory()->create([
            'masjid_id' => $otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'Y', 'slug' => 'y',
        ]);
        Sanctum::actingAs($otherAdmin, ['staff']);
        $foreignTeacher = $this->makeTeacherFor($otherSchool, [$otherClass->id]);

        // Back to OUR admin: the foreign teacher is not in our school.
        Sanctum::actingAs($this->admin, ['staff']);

        $this->getJson($this->base()."/teachers/{$foreignTeacher->id}")->assertNotFound();
        $this->putJson($this->base()."/teachers/{$foreignTeacher->id}", [
            'name' => 'Hijack', 'class_ids' => [$this->classOne->id],
        ])->assertNotFound();
        $this->deleteJson($this->base()."/teachers/{$foreignTeacher->id}")->assertNotFound();

        // ...and the foreign teacher is untouched.
        $this->assertSame([$otherClass->id], $this->ledClassIdsFor($foreignTeacher, $otherSchool));
    }

    #[Test]
    public function an_invite_can_be_re_sent(): void
    {
        Mail::fake();
        $teacher = $this->makeTeacher([$this->classOne->id]);

        $this->postJson($this->base()."/teachers/{$teacher->id}/invite")->assertOk();

        Mail::assertSent(AccountAccessMail::class);
    }

    // ------------------------------------------------------------- helpers

    private function base(): string
    {
        return "/api/admin/masjids/{$this->school->id}";
    }

    private function makeTeacher(array $classIds): User
    {
        return $this->makeTeacherFor($this->school, $classIds);
    }

    private function makeTeacherFor(Masjid $school, array $classIds): User
    {
        $teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $school->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true,
        ]);
        foreach ($classIds as $classId) {
            Group::withoutMasjidScope()->findOrFail($classId)->staff()->attach($teacher->id, [
                'masjid_id' => $school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
            ]);
        }

        return $teacher;
    }

    private function ledClassIds(User $teacher): array
    {
        return $this->ledClassIdsFor($teacher, $this->school);
    }

    private function ledClassIdsFor(User $teacher, Masjid $school): array
    {
        return GroupStaff::withoutMasjidScope()
            ->where('user_id', $teacher->id)
            ->where('masjid_id', $school->id)
            ->pluck('group_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array{0: Masjid, 1: User} */
    private function makeSchoolWithAdmin(): array
    {
        $school = Masjid::create([
            'name' => 'Al-Razi Test '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $school->user_id = $admin->id;
        $school->save();

        return [$school, $admin];
    }
}
