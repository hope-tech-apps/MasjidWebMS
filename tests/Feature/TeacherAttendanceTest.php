<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
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
 * The class register.
 *
 * What these pin, in order of how badly each would hurt if it broke: a register
 * cannot be written across class boundaries; taking it twice does not double it;
 * an untaken day does not read as a room full of absences; and a combined class
 * still tells the teacher which grade each child is in.
 */
class TeacherAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private Group $mine;
    private Group $notMine;
    private GroupMembership $preK;
    private GroupMembership $kg;
    private GroupMembership $otherClassStudent;

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
            'name' => 'Al-Razi Test '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->teacher = User::factory()->create([
            'type' => 'Teacher',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        // The real shape of Al-Razi's 2026 term: ONE class, TWO grades.
        $this->mine = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Pre-K & Kindergarten', 'slug' => 'pre-k-kindergarten',
        ]);
        $this->notMine = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => '1st & 2nd Grade', 'slug' => '1st-2nd-grade',
        ]);

        $this->mine->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->mine->masjid_id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);

        $this->preK = $this->enrol($this->mine, 'Kareem', 'Pre-K');
        $this->kg = $this->enrol($this->mine, 'Jibril', 'KG');
        $this->otherClassStudent = $this->enrol($this->notMine, 'Aalaa', '1st');

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    #[Test]
    public function an_untaken_day_is_not_a_room_full_of_absences(): void
    {
        $response = $this->getJson($this->url().'/attendance?date='.now()->toDateString())->assertOk();

        $this->assertFalse($response->json('data.taken'));
        foreach ($response->json('data.students') as $student) {
            $this->assertNull(
                $student['status'],
                'an unmarked child must read as unmarked, never as absent'
            );
        }
    }

    #[Test]
    public function the_roster_says_which_grade_each_child_is_in(): void
    {
        $response = $this->getJson($this->url().'/attendance')->assertOk();

        $grades = collect($response->json('data.students'))
            ->mapWithKeys(fn ($s) => [$s['contact']['first_name'] => $s['grade_label']]);

        $this->assertSame('Pre-K', $grades['Kareem']);
        $this->assertSame('KG', $grades['Jibril']);
    }

    #[Test]
    public function a_teacher_takes_the_register_and_it_reads_back(): void
    {
        $today = now()->toDateString();

        $this->putJson($this->url().'/attendance', [
            'session_date' => $today,
            'marks' => [
                ['membership_id' => $this->preK->id, 'status' => 'present'],
                ['membership_id' => $this->kg->id, 'status' => 'late', 'note' => 'arrived 9:20'],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('attendance_records', 2);

        $response = $this->getJson($this->url().'/attendance?date='.$today)->assertOk();
        $this->assertTrue($response->json('data.taken'));

        $byName = collect($response->json('data.students'))
            ->mapWithKeys(fn ($s) => [$s['contact']['first_name'] => $s]);
        $this->assertSame('present', $byName['Kareem']['status']);
        $this->assertSame('late', $byName['Jibril']['status']);
        $this->assertSame('arrived 9:20', $byName['Jibril']['note']);
    }

    #[Test]
    public function saving_twice_corrects_the_mark_instead_of_duplicating_it(): void
    {
        $today = now()->toDateString();
        $mark = fn (string $status) => $this->putJson($this->url().'/attendance', [
            'session_date' => $today,
            'marks' => [['membership_id' => $this->kg->id, 'status' => $status]],
        ])->assertOk();

        // Marked absent at 9:05, walks in at 9:20 — the SAME cell changes.
        $mark('absent');
        $mark('late');

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame('late', AttendanceRecord::first()->status);
    }

    #[Test]
    public function a_register_cannot_name_a_child_from_another_class(): void
    {
        $this->putJson($this->url().'/attendance', [
            'session_date' => now()->toDateString(),
            'marks' => [['membership_id' => $this->otherClassStudent->id, 'status' => 'present']],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('attendance_records', 0);
    }

    #[Test]
    public function a_teacher_cannot_reach_the_register_of_a_class_they_do_not_lead(): void
    {
        $this->getJson($this->urlFor($this->notMine).'/attendance')->assertForbidden();

        $this->putJson($this->urlFor($this->notMine).'/attendance', [
            'session_date' => now()->toDateString(),
            'marks' => [['membership_id' => $this->otherClassStudent->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    #[Test]
    public function a_register_cannot_be_taken_for_a_day_that_has_not_happened(): void
    {
        $this->putJson($this->url().'/attendance', [
            'session_date' => now()->addDay()->toDateString(),
            'marks' => [['membership_id' => $this->kg->id, 'status' => 'present']],
        ])->assertUnprocessable();
    }

    #[Test]
    public function an_unknown_mark_is_refused(): void
    {
        $this->putJson($this->url().'/attendance', [
            'session_date' => now()->toDateString(),
            'marks' => [['membership_id' => $this->kg->id, 'status' => 'maybe']],
        ])->assertUnprocessable();
    }

    #[Test]
    public function one_childs_history_counts_late_as_present(): void
    {
        AttendanceRecord::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
            'group_membership_id' => $this->kg->id,
            'session_date' => now()->subDays(2), 'status' => 'present',
        ]);
        AttendanceRecord::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
            'group_membership_id' => $this->kg->id,
            'session_date' => now()->subDay(), 'status' => 'late',
        ]);
        AttendanceRecord::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
            'group_membership_id' => $this->kg->id,
            'session_date' => now(), 'status' => 'excused',
        ]);

        $summary = $this->getJson($this->url()."/members/{$this->kg->id}/attendance")
            ->assertOk()->json('data.summary');

        $this->assertSame(3, $summary['recorded']);
        $this->assertSame(2, $summary['present'], 'late is attendance');
        $this->assertSame(1, $summary['excused']);
        $this->assertSame(0, $summary['absent'], 'an excused absence is not an absence');
    }

    /**
     * The regression this pins is invisible at every realistic test size, which
     * is exactly why it shipped: the summary used to be counted from the same
     * bounded page the list returns, so it stayed right until a child had more
     * marked days than the page holds — around the second year of a 180-day
     * calendar, which is when a parent asks how many days were missed.
     *
     * Shrinking the page rather than creating 201 rows is the point: it tests
     * the RELATIONSHIP between the total and the page, at any page size.
     */
    #[Test]
    public function the_year_total_is_counted_over_every_day_not_over_the_page(): void
    {
        config(['groups.records_page_size' => 3]);

        foreach (range(1, 8) as $i) {
            AttendanceRecord::create([
                'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
                'group_membership_id' => $this->kg->id,
                'session_date' => now()->subDays($i),
                // The two absences are the OLDEST days, so they fall outside the
                // three-day page. Counting the page would report zero absences.
                'status' => $i >= 7 ? 'absent' : 'present',
            ]);
        }

        $data = $this->getJson($this->url()."/members/{$this->kg->id}/attendance")
            ->assertOk()->json('data');

        $this->assertSame(8, $data['summary']['recorded'], 'the total is the year, not the page');
        $this->assertSame(2, $data['summary']['absent'], 'both absences fall OUTSIDE the page');
        $this->assertSame(6, $data['summary']['present']);

        $this->assertSame(3, $data['records_shown']);
        $this->assertTrue($data['records_truncated'], 'a short list under a big total must say so');
        $this->assertSame(
            now()->subDay()->toDateString(),
            $data['records'][0]['session_date'],
            'the page is the most RECENT days — ordered before the limit'
        );
    }

    #[Test]
    public function the_register_never_carries_a_guardians_contact_details(): void
    {
        $this->putJson($this->url().'/attendance', [
            'session_date' => now()->toDateString(),
            'marks' => [['membership_id' => $this->kg->id, 'status' => 'present']],
        ])->assertOk();

        $body = $this->getJson($this->url().'/attendance')->assertOk()->getContent();

        $this->assertStringNotContainsString('@example.test', $body);
        $this->assertStringNotContainsString('+1555', $body);
    }

    private function enrol(Group $group, string $firstName, string $grade): GroupMembership
    {
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => $firstName, 'last_name' => 'Test',
            'email' => strtolower($firstName).'@example.test',
            'phone' => '+15551230001',
        ]);

        return GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $group->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
            'grade_label' => $grade,
        ]);
    }

    private function url(): string
    {
        return $this->urlFor($this->mine);
    }

    private function urlFor(Group $group): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$group->id}";
    }
}
