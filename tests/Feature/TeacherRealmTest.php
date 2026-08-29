<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The teacher realm over HTTP — the boundaries that make a Teacher a Teacher.
 *
 * A teacher is a staff login scoped to the classes they lead: they can grade and
 * talk to parents in THEIR classes and nowhere else, they never see guardian
 * contact details, and the realm exposes no roster mutation, no donations and no
 * thread lifecycle. These tests pin all of that.
 */
class TeacherRealmTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private Group $mine;      // a class the teacher leads
    private Group $notMine;   // a class in the same school the teacher does NOT lead
    private GroupMembership $student;
    private string $guardianEmail;

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

        $this->teacher = User::factory()->create([
            'type' => 'Teacher',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        // A teacher owns no masjid; their school is a masjid_user membership.
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->mine = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $this->notMine = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 5', 'slug' => 'grade-5',
        ]);

        // The teacher leads only `mine`.
        $this->mine->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->mine->masjid_id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);

        // A student with real contact details, and a guardian with a distinctive
        // email — neither must ever reach a teacher payload.
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => 'Amina', 'last_name' => 'Yusuf',
            'email' => 'amina.child@example.test', 'phone' => '+15551230001',
        ]);
        $this->student = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->guardianEmail = 'parent.secret@example.test';
        $parent = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => 'Huda', 'last_name' => 'Yusuf',
            'email' => $this->guardianEmail, 'phone' => '+15559998888',
        ]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
        ]);

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    #[Test]
    public function the_teacher_realm_exposes_exactly_these_writes(): void
    {
        // Counting the write verbs, family-realm style: adding a fourteenth — a
        // roster mutation, a donation, a thread lifecycle verb — has to be a
        // DELIBERATE edit here, not a silent widening of what a teacher can do.
        $writes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/teacher')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['GET', 'HEAD'], true)) {
                    continue;
                }
                $writes[] = $method.' /'.$route->uri();
            }
        }

        $this->assertEqualsCanonicalizing([
            'POST /api/teacher/logout',
            'PUT /api/teacher/masjids/{masjid_id}/groups/{group_id}/letters/stage',
            'PUT /api/teacher/masjids/{masjid_id}/groups/{group_id}/members/{membership_id}/letters',
            'POST /api/teacher/masjids/{masjid_id}/groups/{group_id}/awards',
            'DELETE /api/teacher/masjids/{masjid_id}/groups/{group_id}/awards/{award_id}',
            'POST /api/teacher/masjids/{masjid_id}/groups/{group_id}/hifz',
            'DELETE /api/teacher/masjids/{masjid_id}/groups/{group_id}/hifz/{entry_id}',
            'POST /api/teacher/masjids/{masjid_id}/groups/{group_id}/posts',
            'PUT /api/teacher/masjids/{masjid_id}/groups/{group_id}/posts/{post_id}',
            'DELETE /api/teacher/masjids/{masjid_id}/groups/{group_id}/posts/{post_id}',
            'POST /api/teacher/masjids/{masjid_id}/groups/{group_id}/threads/{thread_id}/messages',
            'PUT /api/teacher/masjids/{masjid_id}/groups/{group_id}/members/{membership_id}/avatar',
            'DELETE /api/teacher/masjids/{masjid_id}/groups/{group_id}/members/{membership_id}/avatar/override',
        ], $writes);
    }

    #[Test]
    public function a_non_teacher_staff_login_cannot_enter_the_teacher_realm(): void
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        Sanctum::actingAs($admin, ['staff']);

        $this->getJson($this->base().'/groups')->assertUnauthorized();
    }

    #[Test]
    public function a_teacher_sees_only_the_classes_they_lead(): void
    {
        $response = $this->getJson($this->base().'/groups')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->mine->id);
    }

    #[Test]
    public function a_teacher_is_refused_a_class_in_their_school_they_do_not_lead(): void
    {
        $this->getJson($this->base()."/groups/{$this->notMine->id}")->assertForbidden();

        // ...and cannot mark letters in it.
        $foreignStudent = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->notMine->id,
            'contact_id' => Contact::factory()->create(['masjid_id' => $this->school->id])->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->putJson(
            $this->base()."/groups/{$this->notMine->id}/members/{$foreignStudent->id}/letters",
            ['drill_id' => 'ba', 'status' => 'learning']
        )->assertForbidden();
    }

    #[Test]
    public function a_teacher_can_mark_a_letter_in_a_class_they_lead(): void
    {
        $this->putJson(
            $this->base()."/groups/{$this->mine->id}/members/{$this->student->id}/letters",
            ['drill_id' => 'ba', 'status' => 'learning']
        )->assertOk();
    }

    #[Test]
    public function the_teacher_roster_carries_names_only_never_guardian_pii(): void
    {
        $response = $this->getJson($this->base()."/groups/{$this->mine->id}")->assertOk();

        // The student is there by name...
        $response->assertJsonPath('data.students.0.contact.first_name', 'Amina');

        // ...but no contact detail of ANY person reaches a teacher.
        $response->assertJsonMissing(['email' => 'amina.child@example.test']);
        $response->assertJsonMissing(['phone' => '+15551230001']);

        // The guardian is not in the roster at all, and their email cannot be
        // hiding anywhere in the raw body (paginator side-channels included).
        $body = $response->getContent();
        $this->assertStringNotContainsString($this->guardianEmail, $body);
        $this->assertStringNotContainsString('amina.child@example.test', $body);
        $this->assertStringNotContainsString('+15551230001', $body);
    }

    #[Test]
    public function a_teacher_cannot_address_another_school_in_the_url(): void
    {
        $otherSchool = $this->makeSchool();
        $otherClass = Group::factory()->create([
            'masjid_id' => $otherSchool->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Foreign', 'slug' => 'foreign',
        ]);

        // Naming a masjid the teacher holds no membership for fails closed at the
        // tenant boundary, whichever class id follows.
        $this->getJson("/api/teacher/masjids/{$otherSchool->id}/groups/{$otherClass->id}")
            ->assertForbidden();
    }

    // ------------------------------------------------------------- helpers

    private function base(): string
    {
        return "/api/teacher/masjids/{$this->school->id}";
    }

    private function makeSchool(): Masjid
    {
        return Masjid::create([
            'name' => 'Al-Razi Test '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }
}
