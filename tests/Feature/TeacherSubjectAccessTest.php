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
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A teacher sees — and may use — only the parts of a class their subject owns.
 *
 * Owner, 2026-09-21, promised at the BISS teacher meeting: "they'll have only
 * access specific to the subject they're teaching." Arabic owns the letters and
 * the daily Arabic notes; Qur'an owns hifdh; Islamic Studies owns neither. Every
 * other part of a class belongs to whoever teaches it at all.
 *
 * Refused on the SERVER, not only hidden: a hidden tab is not a boundary. And an
 * assignment with no subjects recorded teaches everything, because that is every
 * assignment that existed before this and every full-time teacher — the case the
 * first test pins, since breaking it would lock Al-Razi's teachers out of hifdh.
 */
class TeacherSubjectAccessTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private Group $class;
    private GroupMembership $student;

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
            'name' => 'BISS Test '.uniqid(), 'email' => 'biss-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => '1st & 2nd Grade', 'slug' => 'first-second',
        ]);

        $child = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Esraa']);
        $this->student = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    #[Test]
    public function a_teacher_with_no_subjects_recorded_keeps_the_whole_class(): void
    {
        // Every assignment before 2026-09-21, and every full-time teacher.
        $this->assign(null);

        $this->getJson($this->url('/letters'))->assertOk();
        $this->getJson($this->url('/hifz'))->assertOk();
        $this->getJson($this->url('/members/'.$this->student->id.'/arabic-notes'))->assertOk();
        $this->getJson($this->url('/'))->assertOk()->assertJsonPath('data.my_subjects', null);
    }

    #[Test]
    public function an_arabic_teacher_gets_the_letters_and_not_the_quran(): void
    {
        $this->assign([GroupStaff::SUBJECT_ARABIC]);

        $this->getJson($this->url('/letters'))->assertOk();
        $this->getJson($this->url('/members/'.$this->student->id.'/arabic-notes'))->assertOk();

        $this->getJson($this->url('/hifz'))
            ->assertForbidden()
            ->assertJsonPath('message', "You do not teach Qur'an in this class.");
        $this->postJson($this->url('/hifz'), [])->assertForbidden();
    }

    #[Test]
    public function a_quran_teacher_gets_hifdh_and_not_the_letters(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);

        $this->getJson($this->url('/hifz'))->assertOk();

        $this->getJson($this->url('/letters'))->assertForbidden();
        $this->putJson($this->url('/members/'.$this->student->id.'/letters'), [])->assertForbidden();
        $this->getJson($this->url('/members/'.$this->student->id.'/arabic-notes'))->assertForbidden();
    }

    #[Test]
    public function an_islamic_studies_teacher_gets_neither_but_keeps_everything_shared(): void
    {
        $this->assign([GroupStaff::SUBJECT_ISLAMIC_STUDIES]);

        $this->getJson($this->url('/letters'))->assertForbidden();
        $this->getJson($this->url('/hifz'))->assertForbidden();

        // The shared parts of the class are theirs.
        $this->getJson($this->url('/'))->assertOk()
            ->assertJsonPath('data.my_subjects', [GroupStaff::SUBJECT_ISLAMIC_STUDIES]);
        $this->getJson($this->url('/awards'))->assertOk();
        $this->getJson($this->url('/posts'))->assertOk();
    }

    #[Test]
    public function a_teacher_of_two_subjects_gets_both(): void
    {
        $this->assign([GroupStaff::SUBJECT_ARABIC, GroupStaff::SUBJECT_QURAN]);

        $this->getJson($this->url('/letters'))->assertOk();
        $this->getJson($this->url('/hifz'))->assertOk();
    }

    #[Test]
    public function an_empty_list_means_everything_so_no_one_is_locked_out_of_their_own_class(): void
    {
        $this->assign([]);

        $this->getJson($this->url('/letters'))->assertOk();
        $this->getJson($this->url('/hifz'))->assertOk();
    }

    private function assign(?array $subjects): void
    {
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id,
            'role' => GroupStaff::ROLE_TEACHER,
            'subjects' => $subjects,
            'assigned_at' => now(),
        ]);
    }

    private function url(string $path): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}".rtrim($path, '/');
    }
}
