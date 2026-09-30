<?php

namespace Tests\Feature;

use App\Models\ClassAssignment;
use App\Models\ClassGradeWeight;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The OFFICE sets and clears a class's grade weights (review F5 follow-up, 2026-09-29).
 *
 * A teacher limited to some subjects may not change the weights: they move every
 * subject's average, which families read (SubjectFence::mayWeighClass). A class whose
 * every teacher is limited, the common case at Al-Razi, would then have nobody who
 * could set them, so the office has its own route to the same write, behind
 * `permission:manage contacts` and with no subject fence, because the office is not
 * subject-limited.
 *
 * Both routes run the same request and the same service (ClassGradeWeightsService), so
 * the effects are pinned once, here, and by GradebookWeightingTest for the teacher's.
 * What this file adds is who is let in and who is not: the tenant, the permission, and
 * the limited teacher, whose refusal on the teacher route must not have moved.
 */
class AdminGradeWeightsTest extends TestCase
{
    use RefreshDatabase;

    private const WEIGHTS = ['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10];

    private Masjid $school;
    private Group $class;
    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        [$this->school, $this->office] = $this->schoolWithOffice('Al-Razi Test');
        $this->class = $this->classIn($this->school, 'Grade 3');

        $this->actAs($this->office);
    }

    // ------------------------------------------------------- the office may set

    #[Test]
    public function the_office_can_set_a_classs_weights_and_is_recorded_as_who_set_them(): void
    {
        $this->putJson($this->url(), ['weights' => self::WEIGHTS])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.weighting_enabled', true)
            ->assertJsonPath('data.cleared_overrides', 0)
            ->assertJsonPath('data.weights.test', 40)
            ->assertJsonPath('data.weights.other', 10);

        $this->assertEquals(self::WEIGHTS, $this->stored($this->class));
        $rows = ClassGradeWeight::withoutMasjidScope()->where('group_id', $this->class->id);
        $this->assertSame([$this->office->id], (clone $rows)->pluck('updated_by_user_id')->unique()->values()->all());
        $this->assertSame([$this->school->id], (clone $rows)->pluck('masjid_id')->unique()->values()->all());

        // And the office's own gradebook read now says the class is weighted.
        $this->getJson($this->url('/assignments'))
            ->assertOk()
            ->assertJsonPath('weighting_enabled', true)
            ->assertJsonPath('weights.quiz', 20);
    }

    #[Test]
    public function the_office_can_replace_the_weights_and_can_clear_them_with_every_override(): void
    {
        $this->putJson($this->url(), ['weights' => self::WEIGHTS])->assertOk();
        $this->putJson($this->url(), ['weights' => ['test' => 50, 'quiz' => 25, 'homework' => 15, 'classwork' => 5, 'other' => 5]])
            ->assertOk();
        $this->assertSame(5, ClassGradeWeight::withoutMasjidScope()->count(), 'a replace is five rows, not ten');
        $this->assertSame(50, $this->stored($this->class)['test']);

        $piece = $this->work($this->class, 'Big project', 30);
        $foreignClass = $this->classIn($this->school, 'Grade 5');
        $elsewhere = $this->work($foreignClass, 'Elsewhere', 77);

        $this->putJson($this->url(), ['clear' => true])
            ->assertOk()
            ->assertJsonPath('data.weighting_enabled', false)
            ->assertJsonPath('data.cleared_overrides', 1);

        $this->assertSame([], $this->stored($this->class));
        $this->assertNull($piece->fresh()->weight, 'a dormant override would come back to life the day weights were set again');
        $this->assertSame(77, $elsewhere->fresh()->weight, "another class's override is not this class's to clear");
    }

    #[Test]
    public function a_form_encoded_body_from_the_spa_is_understood_as_the_teachers_route_understands_it(): void
    {
        $this->put($this->url(), [
            'weights' => ['test' => '40', 'quiz' => '20', 'homework' => '10', 'classwork' => '10', 'other' => '10'],
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertEquals(self::WEIGHTS, $this->stored($this->class));

        // "true" as a string, which Laravel's own `boolean` rule would refuse.
        $this->put($this->url(), ['clear' => 'true'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.weighting_enabled', false);
        $this->assertSame([], $this->stored($this->class));
    }

    #[Test]
    public function the_office_is_held_to_the_same_rules_and_a_refused_request_writes_nothing(): void
    {
        foreach ([
            ['weights' => ['test' => 40, 'quiz' => 20]],
            ['weights' => self::WEIGHTS + ['bonus' => 5]],
            ['weights' => ['test' => 101] + self::WEIGHTS],
            ['weights' => array_fill_keys(array_keys(self::WEIGHTS), 0)],
            ['clear' => true, 'weights' => self::WEIGHTS],
            [],
        ] as $body) {
            $this->putJson($this->url(), $body)->assertStatus(422)->assertJsonPath('status', 'failed');
        }

        $this->assertSame(0, ClassGradeWeight::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_office_route_and_the_teachers_have_the_same_effect(): void
    {
        // Two identical classes, one set through each door. The same body must leave the same rows and
        // answer the same `data`, or the "one shared write" is only a claim.
        $teacherClass = $this->classIn($this->school, 'Grade 4');
        $teacher = $this->teacherOf($teacherClass, null);

        $viaOffice = $this->putJson($this->url(), ['weights' => self::WEIGHTS])->assertOk()->json('data');
        $officePiece = $this->work($this->class, 'Project', 30);
        $clearedViaOffice = $this->putJson($this->url(), ['clear' => true])->assertOk()->json('data');

        $this->actAs($teacher, ['staff']);
        $teacherUrl = "/api/teacher/masjids/{$this->school->id}/groups/{$teacherClass->id}/grade-weights";
        $viaTeacher = $this->putJson($teacherUrl, ['weights' => self::WEIGHTS])->assertOk()->json('data');
        $this->assertEquals(self::WEIGHTS, $this->stored($teacherClass));
        $teacherPiece = $this->work($teacherClass, 'Project', 30);
        $clearedViaTeacher = $this->putJson($teacherUrl, ['clear' => true])->assertOk()->json('data');

        $this->assertSame($viaOffice, $viaTeacher);
        $this->assertSame($clearedViaOffice, $clearedViaTeacher);
        $this->assertSame([], $this->stored($teacherClass));
        $this->assertSame($officePiece->fresh()->weight, $teacherPiece->fresh()->weight);
        $this->assertNull($teacherPiece->fresh()->weight);
    }

    // ------------------------------------------------------------- the tenant

    #[Test]
    public function another_organisations_office_gets_a_404_for_this_organisations_class_and_nothing_changes(): void
    {
        $this->putJson($this->url(), ['weights' => self::WEIGHTS])->assertOk();
        $piece = $this->work($this->class, 'Project', 30);
        $before = $this->stored($this->class);

        [$other, $otherOffice] = $this->schoolWithOffice('Other School');
        $this->actAs($otherOffice);

        // Their own masjid in the URL, our class id in the path: the `masjid_id` scope on Group answers.
        $foreignUrl = "/api/admin/masjids/{$other->id}/groups/{$this->class->id}/grade-weights";
        $this->putJson($foreignUrl, ['weights' => ['test' => 5, 'quiz' => 5, 'homework' => 80, 'classwork' => 5, 'other' => 5]])
            ->assertNotFound();
        $this->putJson($foreignUrl, ['clear' => true])->assertNotFound();

        // Or our masjid in the URL: the tenant middleware refuses an administrator of another organisation.
        $this->putJson($this->url(), ['clear' => true])->assertForbidden();

        $this->assertEquals($before, $this->stored($this->class), 'the weights are unchanged');
        $this->assertSame(30, $piece->fresh()->weight, 'and so is the override');
        $this->assertSame(5, ClassGradeWeight::withoutMasjidScope()->count(), 'nothing was written anywhere else either');
    }

    // ------------------------------------------------------------ the limited teacher

    #[Test]
    public function a_subject_limited_teacher_is_still_refused_and_the_office_sets_weights_for_a_class_of_limited_teachers(): void
    {
        // Every teacher of the class is limited to some subjects: the case the office route is for.
        $quran = $this->teacherOf($this->class, [GroupStaff::SUBJECT_QURAN]);
        $arabic = $this->teacherOf($this->class, [GroupStaff::SUBJECT_ARABIC]);
        $teacherUrl = "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/grade-weights";
        $refusal = "The class's weights decide how much each type of work counts in every subject's average, "
            .'so only a teacher of all the subjects in this class, or the office, can change them.';

        foreach ([$quran, $arabic] as $teacher) {
            $this->actAs($teacher, ['staff']);

            $this->putJson($teacherUrl, ['weights' => self::WEIGHTS])->assertForbidden()->assertJsonPath('message', $refusal);
            $this->putJson($teacherUrl, ['clear' => true])->assertForbidden();
            // The office door is not theirs either: a Teacher does not pass the `admin` gate (401, its own envelope).
            $this->putJson($this->url(), ['weights' => self::WEIGHTS])->assertUnauthorized();
            $this->assertSame([], $this->stored($this->class), 'a refused set writes nothing');
        }

        $this->actAs($this->office);
        $this->putJson($this->url(), ['weights' => self::WEIGHTS])->assertOk();
        $this->assertEquals(self::WEIGHTS, $this->stored($this->class));

        // And still, once the office has set them, a limited teacher cannot take them back.
        $this->actAs($quran, ['staff']);
        $this->putJson($teacherUrl, ['clear' => true])->assertForbidden();
        $this->assertEquals(self::WEIGHTS, $this->stored($this->class));
    }

    // ------------------------------------------------------------ the permission

    #[Test]
    public function an_office_user_without_manage_contacts_is_refused_and_nothing_changes(): void
    {
        $this->putJson($this->url(), ['weights' => self::WEIGHTS])->assertOk();
        $before = $this->stored($this->class);

        // The seeded `member` set, which holds no CRM permission at all.
        $member = $this->officeUser($this->school, 'member');
        $this->actAs($member);
        $this->putJson($this->url(), ['clear' => true])->assertForbidden();

        // `view contacts` alone: may read the gradebook, may not change how it counts. The route mints
        // no permission of its own.
        $reader = $this->officeUser($this->school, null);
        $reader->givePermissionTo('view contacts');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actAs($reader->fresh());

        $this->getJson($this->url('/assignments'))->assertOk();
        $this->putJson($this->url(), ['clear' => true])->assertForbidden();
        $this->putJson($this->url(), ['weights' => ['test' => 5, 'quiz' => 5, 'homework' => 80, 'classwork' => 5, 'other' => 5]])
            ->assertForbidden();

        $this->assertEquals($before, $this->stored($this->class), 'the weights are unchanged');
    }

    #[Test]
    public function a_request_with_no_sign_in_is_refused(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        $this->putJson($this->url(), ['weights' => self::WEIGHTS])->assertUnauthorized();

        $this->assertSame(0, ClassGradeWeight::withoutMasjidScope()->count());
    }

    // ----------------------------------------------------------------- helpers

    private function url(string $path = '/grade-weights'): string
    {
        return "/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}".$path;
    }

    /** @return array<string,int> the class's stored weights by type */
    private function stored(Group $class): array
    {
        // Past the tenant scope: after a request as another organisation the context is bound to THEM,
        // and a check that reads through it would see an empty class and pass for the wrong reason.
        return ClassGradeWeight::withoutMasjidScope()->where('group_id', $class->id)->pluck('weight', 'assignment_type')->map(fn ($w) => (int) $w)->all();
    }

    private function actAs(User $user, array $abilities = ['*']): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($user, $abilities);
    }

    /** @return array{0: Masjid, 1: User} a school and the administrator who owns it */
    private function schoolWithOffice(string $name): array
    {
        $school = Masjid::create([
            'name' => $name.' '.uniqid(), 'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $school->user_id = $admin->id;
        $school->save();

        return [$school, $admin];
    }

    /** An administrator of `$school` holding the seeded `$role`, or no role at all when null. */
    private function officeUser(Masjid $school, ?string $role): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $school->id, 'user_id' => $user->id, 'role' => $role ?? 'member', 'is_default' => true]);
        $user->syncRoles($role === null ? [] : [$role]);

        return $user->fresh();
    }

    private function classIn(Masjid $school, string $name): Group
    {
        return Group::factory()->create([
            'masjid_id' => $school->id, 'kind' => Group::KIND_CLASS, 'name' => $name, 'slug' => 'c-'.uniqid(),
        ]);
    }

    /** @param list<string>|null $subjects NULL: a teacher of everything */
    private function teacherOf(Group $class, ?array $subjects): User
    {
        $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $class->masjid_id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
        $class->staff()->attach($teacher->id, [
            'masjid_id' => $class->masjid_id, 'role' => GroupStaff::ROLE_TEACHER,
            'subjects' => $subjects, 'assigned_at' => now(),
        ]);

        return $teacher;
    }

    private function work(Group $class, string $title, ?int $weight): ClassAssignment
    {
        return ClassAssignment::create([
            'masjid_id' => $class->masjid_id, 'group_id' => $class->id, 'title' => $title,
            'points_possible' => 10, 'scale' => ClassAssignment::SCALE_POINTS, 'type' => 'homework',
            'weight' => $weight, 'assigned_on' => now()->toDateString(),
        ]);
    }
}
