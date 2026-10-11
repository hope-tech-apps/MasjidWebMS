<?php

namespace Tests\Feature;

use App\Http\Requests\Teacher\SaveAttendanceRequest;
use App\Models\{AttendanceRecord, Contact, Group, GroupMembership, GroupStaff, Masjid, MasjidUser, SchoolClosure, SchoolYear, StudentAllergy, User};
use App\Support\{StudentAllergies, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Tests\TestCase;

class OfficeAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Group $class;
    private GroupMembership $student;
    private GroupMembership $second;
    private User $office;
    private User $teacher;
    private SchoolYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-19 16:00:00', 'UTC'));
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->school = Masjid::create(['name' => 'Practice School', 'email' => 'practice@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true, 'timezone' => 'America/New_York', 'capability_overrides' => ['school_calendar_terms' => true, 'school_calendar' => true]]);
        $this->school->forceFill(['capability_overrides' => ['school_calendar_terms' => true, 'school_calendar' => true]])->save();
        $this->class = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => 'class', 'name' => 'Practice Class']);
        $this->student = $this->enrol($this->class);
        $this->second = $this->enrol($this->class);
        $this->office = User::factory()->create(['type' => 'MasjidAdmin', 'name' => 'Practice Office', 'phone' => '+15555550101']);
        $this->office->assignRole('masjid-admin');
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->office->id, 'role' => 'admin', 'is_default' => true]);
        $this->teacher = User::factory()->create(['type' => 'Teacher', 'name' => 'Practice Teacher', 'phone' => '+15555550102']);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
        GroupStaff::create(['masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
        $this->year = SchoolYear::create(['masjid_id' => $this->school->id, 'label' => 'Practice year', 'first_day' => '2026-10-01', 'last_day' => '2027-06-30', 'meeting_weekdays' => [1,2,3,4,5]]);
        $this->as($this->office);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forgetTenant();
        parent::tearDown();
    }

    private function as(User $user): void { Auth::forgetGuards(); Sanctum::actingAs($user, ['staff']); }
    private function url(): string { return "/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/attendance"; }
    private function overview(): string { return "/api/admin/masjids/{$this->school->id}/attendance/registers"; }
    private function enrol(Group $class): GroupMembership
    {
        return GroupMembership::create(['masjid_id' => $class->masjid_id, 'group_id' => $class->id, 'contact_id' => Contact::factory()->create(['masjid_id' => $class->masjid_id])->id, 'role' => 'member']);
    }
    private function save(string $date = '2026-10-19', ?array $marks = null)
    {
        return $this->putJson($this->url(), ['session_date' => $date, 'marks' => $marks ?? [['membership_id' => $this->student->id, 'status' => 'present']]]);
    }
    private function close(string $day = '2026-10-19'): void
    {
        SchoolClosure::create(['masjid_id' => $this->school->id, 'school_year_id' => $this->year->id, 'closed_on' => $day, 'reason' => 'Practice closure']);
    }

    #[Test]
    public function local_today_unmarked_students_and_mark_all_round_trip(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-20 01:00:00', 'UTC'));
        $r = $this->getJson($this->url())->assertOk()->assertJsonPath('data.session_date', '2026-10-19')->assertJsonPath('data.taken', false);
        $this->assertCount(2, $r->json('data.students'));
        $this->assertNull($r->json('data.students.0.status'));
        $this->save(marks: array_map(fn ($m) => ['membership_id' => $m->id, 'status' => 'present'], [$this->student, $this->second]))->assertOk()->assertJsonPath('data.taken', true);
        $this->assertDatabaseCount('attendance_records', 2);
        $this->assertDatabaseHas('attendance_records', ['group_membership_id' => $this->student->id, 'marked_by_user_id' => $this->office->id]);
    }

    #[Test]
    public function office_future_bounds_use_school_today_on_both_sides_of_utc_midnight(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-20 01:00:00', 'UTC'));
        $this->save('2026-10-20')->assertUnprocessable()->assertJsonPath('data.session_date.0', 'You cannot take a register for a day that has not happened yet.');
        $this->school->forceFill(['timezone' => 'Asia/Tokyo'])->save();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-19 23:00:00', 'UTC'));
        $this->save('2026-10-20')->assertOk()->assertJsonPath('data.session_date', '2026-10-20');
    }

    #[Test]
    public function browser_encoded_marks_accept_every_status_and_validate_notes(): void
    {
        foreach (AttendanceRecord::STATUSES as $status) {
            $this->put($this->url(), ['session_date' => '2026-10-19', 'marks' => [['membership_id' => (string) $this->student->id, 'status' => $status, 'note' => 'Practice note']]], ['Accept' => 'application/json'])->assertOk();
            $this->assertDatabaseHas('attendance_records', ['group_membership_id' => $this->student->id, 'status' => $status]);
        }
        $this->save(marks: [['membership_id' => $this->student->id, 'status' => 'maybe']])->assertUnprocessable();
        $this->save(marks: [['membership_id' => $this->student->id, 'status' => 'present', 'note' => str_repeat('x', 501)]])->assertUnprocessable();
        $this->assertDatabaseCount('attendance_records', 1);
    }

    #[Test]
    public function teacher_marker_is_shown_and_office_correction_records_its_own_marker(): void
    {
        $this->as($this->teacher);
        $this->putJson(str_replace('/admin/', '/teacher/', $this->url()), ['session_date' => '2026-10-19', 'marks' => [['membership_id' => $this->student->id, 'status' => 'absent', 'note' => 'Practice note']]])->assertOk();
        $this->as($this->office);
        $r = $this->getJson($this->url().'?date=2026-10-19')->assertOk();
        $row = collect($r->json('data.students'))->firstWhere('membership_id', $this->student->id);
        $this->assertSame('Practice Teacher', $row['marked_by']);
        $this->assertNotNull($row['marked_at']);
        $this->assertSame('Practice note', $row['note']);
        $this->save(marks: [['membership_id' => $this->student->id, 'status' => 'late', 'note' => 'Corrected practice note']])->assertOk();
        $r = $this->getJson($this->url().'?date=2026-10-19')->assertOk();
        $row = collect($r->json('data.students'))->firstWhere('membership_id', $this->student->id);
        $this->assertSame('Office: Practice Office', $row['marked_by']);
        $this->assertSame('late', $row['status']);
        $this->assertSame('Corrected practice note', $row['note']);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public static function refusedDays(): array { return [['2026-10-20', 'has not happened yet'], ['2026-10-18', "isn't one of"], ['2026-09-30', 'outside the school year']]; }
    #[Test, DataProvider('refusedDays')]
    public function calendar_refusals_apply_to_read_and_save(string $date, string $words): void
    {
        $r = $this->getJson($this->url().'?date='.$date)->assertOk();
        $this->assertSame([], $r->json('data.students'));
        $this->assertStringContainsString($words, $r->json('data.refusal'));
        $r = $this->save($date)->assertUnprocessable();
        $this->assertStringContainsString($words, $r->json('data.session_date.0'));
        $this->assertDatabaseCount('attendance_records', 0);
    }

    #[Test]
    public function closure_and_a_closure_added_after_validation_use_teacher_wording(): void
    {
        $this->close();
        $words = 'There was no school on Monday, October 19, 2026 (Practice closure), so there is no register to take.';
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.refusal', $words);
        $this->save()->assertUnprocessable()->assertJsonPath('data.session_date.0', $words);
        SchoolClosure::query()->delete();
        $this->app->afterResolving(SaveAttendanceRequest::class, function ($request) {
            $this->assertTrue((fn () => $this->validator !== null)->call($request));
            $this->close();
        });
        $this->save()->assertUnprocessable()->assertJsonPath('data.session_date.0', $words);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    #[Test]
    public function left_students_and_foreign_ids_refuse_the_whole_save(): void
    {
        $this->second->forceFill(['left_on' => '2026-10-19'])->save();
        $this->save(marks: [['membership_id' => $this->student->id, 'status' => 'present'], ['membership_id' => $this->second->id, 'status' => 'absent']])->assertUnprocessable()->assertJsonPath('data.marks.0', 'That register names a child who has left the class — reload it and mark the rest.');
        $foreign = $this->enrol(Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => 'class']));
        $this->save(marks: [['membership_id' => $foreign->id, 'status' => 'present']])->assertUnprocessable()->assertJsonPath('data.marks.0', 'That register names someone who is not a student in this class.');
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertCount(1, $this->getJson($this->url())->assertOk()->json('data.students'));
    }

    #[Test]
    public function another_school_class_membership_and_calendar_are_isolated(): void
    {
        $other = Masjid::create(['name' => 'Other School', 'email' => 'other@example.invalid', 'phone' => '+15555550109', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
        $otherClass = Group::factory()->create(['masjid_id' => $other->id, 'kind' => 'class']);
        $foreign = $this->enrol($otherClass);
        $year = SchoolYear::create(['masjid_id' => $other->id, 'label' => 'Other year', 'first_day' => '2026-10-01', 'last_day' => '2027-06-30']);
        SchoolClosure::create(['masjid_id' => $other->id, 'school_year_id' => $year->id, 'closed_on' => '2026-10-19', 'reason' => 'Foreign closure']);
        $foreignUrl = str_replace('/groups/'.$this->class->id.'/', '/groups/'.$otherClass->id.'/', $this->url());
        $this->getJson($foreignUrl)->assertNotFound();
        $this->putJson($foreignUrl, ['session_date' => '2026-10-19', 'marks' => [['membership_id' => $foreign->id, 'status' => 'present']]])->assertNotFound();
        $this->getJson(str_replace('/'.$this->school->id.'/', '/'.$other->id.'/', $foreignUrl))->assertForbidden();
        $this->getJson(str_replace('/'.$this->school->id.'/', '/'.$other->id.'/', $this->overview()))->assertForbidden();
        $this->save(marks: [['membership_id' => $foreign->id, 'status' => 'present']])->assertUnprocessable();
        $this->save()->assertOk();
        $this->assertCount(1, $this->getJson($this->overview().'?date=2026-10-19')->assertOk()->json('data.classes'));
    }

    #[Test]
    public function crm_and_manage_contacts_are_required_including_reads(): void
    {
        $this->office->syncRoles([]);
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->overview())->assertForbidden();
        $this->save()->assertForbidden();
        $this->office->assignRole('masjid-admin');
        $this->school->forceFill(['crm_enabled' => false])->save();
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->overview())->assertForbidden();
        $this->save()->assertForbidden();
    }

    #[Test]
    public function teacher_and_family_tokens_cannot_use_any_office_register_route(): void
    {
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->teacher->createToken('practice-staff', ['staff'])->plainTextToken);
        $this->getJson($this->url())->assertUnauthorized();
        $this->getJson($this->overview())->assertUnauthorized();
        $this->save()->assertUnauthorized();
        Auth::forgetGuards();
        $parent = Contact::factory()->create(['masjid_id' => $this->school->id]);
        $parent->forceFill(['login_email' => 'family@example.invalid', 'login_enabled_at' => now()])->save();
        $token = $parent->createFamilyToken()->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson($this->url())->assertUnauthorized();
        Auth::forgetGuards();
        $this->getJson($this->overview())->assertUnauthorized();
        Auth::forgetGuards();
        $this->save()->assertUnauthorized();
    }

    public static function sizes(): array { return [[2], [12]]; }
    #[Test, DataProvider('sizes')]
    public function overview_is_two_queries_for_two_or_twelve_classes(int $size): void
    {
        for ($i = 1; $i < $size; $i++) Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => 'class']);
        Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => 'team']);
        $this->save()->assertOk();
        app(TenantContext::class)->set($this->school->id);
        DB::flushQueryLog(); DB::enableQueryLog();
        $rows = app(\App\Services\Schools\AttendanceRegister::class)->overview($this->school->id, '2026-10-19');
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(2, $queries);
        $this->assertCount($size, $rows);
        $row = collect($rows)->firstWhere('id', $this->class->id);
        $this->assertSame('Office: Practice Office', $row['marked_by']);
        $this->assertNotNull($row['marked_at']);
        $this->assertCount($size - 1, collect($rows)->where('taken', false));
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->getJson($this->overview().'?date=2026-10-19')->assertOk()->assertJsonCount($size, 'data.classes');
        $httpQueries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(10, $httpQueries, 'the whole HTTP request must be fixed at both class sizes');
    }

    #[Test]
    public function office_allergies_reuse_the_teacher_projection_only_when_enabled(): void
    {
        StudentAllergies::edit($this->student->contact, 'Practice allergy', $this->office->id);
        $this->school->forceFill(['capability_overrides' => ['school_calendar_terms' => true, 'student_allergies' => true]])->save();
        $r = $this->getJson($this->url())->assertOk();
        $this->assertSame('Practice allergy', collect($r->json('data.students'))->firstWhere('membership_id', $this->student->id)['student_allergy']['allergies']);
    }

    #[Test]
    public function an_office_mark_reaches_the_teacher_register_and_the_family_report_card(): void
    {
        $this->save(marks: [['membership_id' => $this->student->id, 'status' => 'late', 'note' => 'Practice note']])->assertOk();
        $this->as($this->teacher);
        $teacher = str_replace('/admin/', '/teacher/', $this->url());
        $r = $this->getJson($teacher.'?date=2026-10-19')->assertOk();
        $this->assertSame('late', collect($r->json('data.students'))->firstWhere('membership_id', $this->student->id)['status']);
        $cardBase = str_replace('/attendance', '/members/'.$this->student->id.'/report-card', $teacher);
        $period = '?term=1&school_year=2026-2027';
        $this->getJson($cardBase.$period)->assertOk();
        $card = $this->postJson($cardBase.'/publish'.$period, ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk()->json('data.id');
        $parent = Contact::factory()->create(['masjid_id' => $this->school->id]);
        GroupMembership::create(['masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'contact_id' => $parent->id, 'role' => 'guardian', 'guardian_of_contact_id' => $this->student->contact_id, 'provenance' => 'confirmed']);
        $parent->forceFill(['login_email' => 'parent@example.invalid', 'login_enabled_at' => now()])->save();
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        $this->withHeader('Authorization', 'Bearer '.$parent->createFamilyToken()->plainTextToken)
            ->getJson("/api/family/masjids/{$this->school->id}/groups/{$this->class->id}/members/{$this->student->id}/report-cards/{$card}")
            ->assertOk()->assertJsonPath('data.attendance.present', 1)->assertJsonPath('data.attendance.late', 1);
    }
}
