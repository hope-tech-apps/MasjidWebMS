<?php

namespace Tests\Feature;

use App\Models\{AttendanceRecord, Contact, Group, GroupMembership, GroupStaff, Masjid, MasjidUser, PeriodAttendanceRecord, SchoolClosure, TimetableMeeting, TimetableMeetingTeacher, TimetablePeriod, TimetablePeriodSet, User};
use App\Services\Schools\AttendanceReader;
use App\Support\{CapabilityWriter, TenantContext};
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Tests\Support\ParentAttendanceFixtures;
use Tests\TestCase;

class PeriodAttendanceTest extends TestCase
{
    use RefreshDatabase, ParentAttendanceFixtures;
    private $school; private $class; private $child; private $yearRow; private $teacher; private $co; private $lead; private $office; private $first; private $second;
    protected function setUp(): void
    {
        parent::setUp(); fake()->seed(6014);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-19 17:00:00 UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->school = $this->makeMasjid(); $this->yearRow = $this->year($this->school);
        $this->school->forceFill(['capability_overrides'=>array_merge($this->school->capability_overrides, ['school_timetable'=>true])])->save();
        $this->class = $this->classroom($this->school); $this->child = $this->enrol($this->class);
        foreach (['teacher','co','lead'] as $i => $key) {
            $this->$key = User::factory()->create(['type'=>'Teacher','name'=>'Practice '.$key,'email'=>$key.'@example.invalid','phone'=>'+1555555030'.$i]);
            MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$this->$key->id,'role'=>'teacher','is_default'=>true]);
        }
        GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'user_id'=>$this->lead->id]);
        $this->office = User::factory()->create(['type'=>'MasjidAdmin','name'=>'Practice Office','email'=>'admin@example.invalid','phone'=>'+15555550309']);
        MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$this->office->id,'role'=>'admin','is_default'=>true]);
        $set = TimetablePeriodSet::create(['masjid_id'=>$this->school->id,'school_year_id'=>$this->yearRow->id,'name'=>'Practice day']);
        foreach (['first'=>'08:00','second'=>'09:00'] as $key=>$time) {
            $p = TimetablePeriod::create(['masjid_id'=>$this->school->id,'period_set_id'=>$set->id,'name'=>ucfirst($key),'starts_at'=>$time,'ends_at'=>($key==='first'?'08:45':'09:45'),'position'=>$key==='first'?1:2,'kind'=>'teaching']);
            $this->$key = TimetableMeeting::create(['masjid_id'=>$this->school->id,'school_year_id'=>$this->yearRow->id,'group_id'=>$this->class->id,'period_id'=>$p->id,'weekday'=>1,'kind'=>'activity','activity_name'=>'Practice lesson','effective_from'=>'2026-08-24']);
            foreach ([$this->teacher,$this->co] as $t) TimetableMeetingTeacher::create(['masjid_id'=>$this->school->id,'meeting_id'=>$this->$key->id,'user_id'=>$t->id]);
        }
        CapabilityWriter::apply($this->school, ['period_attendance'=>true], null);
        $this->school->refresh();
    }
    protected function tearDown(): void { app(TenantContext::class)->forgetTenant(); parent::tearDown(); }
    private function url($meeting, bool $office=false): string { return '/api/'.($office?'admin':'teacher').'/masjids/'.$this->school->id.'/period-attendance/'.$meeting->id; }
    private function take($meeting, string $status, $user=null, bool $office=false, string $date='2026-10-19')
    {
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($user??$this->teacher, ['staff']);
        $read=$this->getJson($this->url($meeting,$office).'?date='.$date)->assertOk();
        return $this->putJson($this->url($meeting,$office), ['session_date'=>$date,'marks'=>[['membership_id'=>$this->child->id,'status'=>$status,'note'=>'Staff-only practice note']]], ['X-Attendance-Revision'=>$read->headers->get('X-Attendance-Revision')]);
    }
    public static function rules(): array
    {
        return [
            'none taken'=>[null,null,null], 'one present'=>[null,'present','present'], 'later late without first'=>[null,'late','present'],
            'first absent later late'=>['absent','late','present'], 'first late'=>['late',null,'late'], 'late then absent'=>['late','absent','late'],
            'leave mid-day'=>['present','absent','present'], 'absent all taken'=>['absent','absent','absent'],
            'all excused'=>['excused','excused','excused'], 'one excused'=>[null,'excused','excused'], 'mixed away is not absent all day'=>['excused','absent','excused'], 'absent then excused'=>['absent','excused','excused'], 'one absent only'=>[null,'absent','absent'],
        ];
    }
    #[Test, DataProvider('rules')]
    public function day_rule_considers_only_taken_periods(?string $a, ?string $b, ?string $expected): void
    {
        if ($a) $this->take($this->first,$a)->assertOk(); if ($b) $this->take($this->second,$b)->assertOk();
        $marks=app(AttendanceReader::class)->marks($this->school->id,[$this->child->id],'2026-10-19');
        $this->assertSame($expected,$marks->first()['status']??null);
        $this->assertCount($expected===null?0:1,$marks);
    }
    #[Test]
    public function either_meeting_teacher_class_teacher_and_office_can_mark_and_conflicts_reload(): void
    {
        $this->take($this->first,'present',$this->co)->assertOk();
        $this->getJson('/api/teacher/masjids/'.$this->school->id.'/period-attendance/classes/'.$this->class->id.'?date=2026-10-19')->assertOk()->assertJsonCount(2,'data.registers');
        $stale=$this->getJson($this->url($this->first).'?date=2026-10-19')->assertOk()->headers->get('X-Attendance-Revision');
        $this->take($this->first,'late',$this->lead)->assertOk();
        $this->take($this->first,'excused',$this->office,true)->assertOk()->assertJsonPath('data.students.0.marked_by','Office: Practice Office');
        $this->putJson($this->url($this->first,true), ['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$this->child->id,'status'=>'absent']]], ['X-Attendance-Revision'=>$stale])->assertStatus(409);
        $outsider=User::factory()->create(['type'=>'Teacher','phone'=>'+15555550308']); MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$outsider->id,'role'=>'teacher']);
        auth()->forgetGuards(); Sanctum::actingAs($outsider,['staff']);
        $this->getJson($this->url($this->first).'?date=2026-10-19')->assertForbidden();
        $this->getJson('/api/teacher/masjids/'.$this->school->id.'/period-attendance/classes/'.$this->class->id.'?date=2026-10-19')->assertForbidden();
        $this->putJson($this->url($this->first),['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$this->child->id,'status'=>'present']]])->assertForbidden();
    }
    #[Test]
    public function removed_meeting_keeps_snapshot_and_switch_off_resumes_daily_with_read_only_periods(): void
    {
        $this->take($this->first,'late')->assertOk(); $this->first->delete();
        $this->getJson($this->url($this->first).'?date=2026-10-19')->assertOk()->assertJsonPath('data.meeting.period_name','First')->assertJsonPath('data.read_only',false);
        $this->assertSame('late',app(AttendanceReader::class)->marks($this->school->id,[$this->child->id])->first()['status']);
        $this->take($this->first,'present')->assertOk();
        $this->assertSame('present',app(AttendanceReader::class)->marks($this->school->id,[$this->child->id])->first()['status']);
        $newPupil=$this->enrol($this->class);
        $this->getJson($this->url($this->first).'?date=2026-10-19')->assertOk()->assertJsonCount(1,'data.students');
        $this->putJson($this->url($this->first),['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$newPupil->id,'status'=>'present']]])->assertStatus(422);
        CapabilityWriter::apply($this->school->fresh(),['period_attendance'=>false],null);
        $this->getJson($this->url($this->first).'?date=2026-10-19')->assertOk()->assertJsonPath('data.read_only',true);
        $this->putJson($this->url($this->first),['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$this->child->id,'status'=>'absent']]])->assertStatus(422);
        $this->assertDatabaseCount('period_attendance_records',1);
    }
    #[Test]
    public function switch_boundary_preserves_old_daily_marks_and_closure_has_no_register(): void
    {
        $this->mark($this->child,'2026-10-12','absent');
        auth()->forgetGuards(); Sanctum::actingAs($this->teacher,['staff']);
        $this->getJson($this->url($this->first).'?date=2026-10-12')->assertNotFound();
        $this->assertSame('absent',app(AttendanceReader::class)->marks($this->school->id,[$this->child->id])->first()['status']);
        SchoolClosure::create(['masjid_id'=>$this->school->id,'school_year_id'=>$this->yearRow->id,'closed_on'=>'2026-10-19','reason'=>'Practice closure']);
        $this->getJson($this->url($this->first).'?date=2026-10-19')->assertOk()->assertJsonPath('data.students',[]);
        $this->getJson('/api/teacher/masjids/'.$this->school->id.'/period-attendance/classes/'.$this->class->id.'?date=2026-10-19')->assertOk()->assertJsonPath('data.registers',[]);
        $this->take($this->first,'present')->assertStatus(422); $this->assertDatabaseCount('period_attendance_records',0);
    }
    #[Test]
    public function prerequisite_hidden_off_and_tenant_owned_snapshot(): void
    {
        $other=$this->makeMasjid('b');
        try { CapabilityWriter::apply($other,['period_attendance'=>true],null); $this->fail('Must require timetable'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('timetable',json_encode($e->errors())); }
        $this->assertArrayNotHasKey('period_attendance',$other->getCapabilitiesAttribute());
        $this->take($this->first,'present')->assertOk();
        app(TenantContext::class)->set($other->id);
        $this->assertNull(PeriodAttendanceRecord::find(1));
        $this->assertCount(0,app(AttendanceReader::class)->marks($other->id));
    }
    #[Test]
    public function family_sees_day_and_period_lines_without_notes_and_email_uses_day_mark(): void
    {
        $parent=$this->guardian($this->child); $this->take($this->first,'absent')->assertOk(); $this->take($this->second,'present')->assertOk();
        $this->family($parent); $r=$this->getJson($this->familyUrl($this->child))->assertOk()->assertJsonPath('data.days.0.status','present');
        $this->assertCount(2,$r->json('data.days.0.periods')); $this->assertStringNotContainsString('Staff-only',$r->getContent());
        $this->assertStringNotContainsString('"note"',$r->getContent());
    }
    #[Test]
    public function joint_lesson_teacher_marks_each_class_roster_and_schedule_has_both_register_links(): void
    {
        $other=$this->classroom($this->school,'Practice Other Class'); $student=$this->enrol($other);
        $partner=TimetableMeeting::create(['masjid_id'=>$this->school->id,'school_year_id'=>$this->yearRow->id,'group_id'=>$other->id,'period_id'=>$this->first->period_id,'weekday'=>1,'kind'=>'activity','activity_name'=>'Practice shared lesson','effective_from'=>'2026-08-24']);
        $key='00000000-0000-4000-8000-000000000001'; $this->first->update(['joint_key'=>$key]); $partner->update(['joint_key'=>$key]);
        $this->take($this->first,'present')->assertOk();
        $read=$this->getJson($this->url($partner).'?date=2026-10-19')->assertOk()->assertJsonPath('data.students.0.membership_id',$student->id);
        $this->putJson($this->url($partner),['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$student->id,'status'=>'absent']]],['X-Attendance-Revision'=>$read->headers->get('X-Attendance-Revision')])->assertOk();
        $this->assertDatabaseCount('period_attendance_records',2);
        $schedule=$this->getJson('/api/teacher/masjids/'.$this->school->id.'/schedule')->assertOk();
        $this->assertSame('/teacher/period-registers/'.$this->first->id.'?date=2026-10-19',$schedule->json('data.today.meetings.0.period_register'));
        $this->assertSame('/teacher/period-registers/'.$partner->id.'?date=2026-10-19',$schedule->json('data.today.meetings.0.joint.0.period_register'));
    }
    #[Test]
    public function report_card_email_board_and_export_read_the_same_day_mark(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $parent=$this->guardian($this->child);
        $this->take($this->first,'absent')->assertOk(); $this->take($this->second,'present')->assertOk();
        $this->assertSame(0,app(\App\Services\Schools\AttendanceAbsenceEmails::class)->queue($this->school));
        $service=app(\App\Services\Schools\ReportCardService::class);
        $card=$service->prepare($this->child,'term','2026-27',1);
        $service->publish($card,\Illuminate\Support\Carbon::parse('2026-10-19'),\Illuminate\Support\Carbon::parse('2026-10-19'));
        $this->assertSame(1,(int)$card->fresh()->days_present); $this->assertSame(0,(int)$card->fresh()->days_absent);
        $this->take($this->second,'absent')->assertOk();
        $this->assertSame(1,app(\App\Services\Schools\AttendanceAbsenceEmails::class)->queue($this->school));
        $this->assertDatabaseCount('attendance_absence_mail_children',1);
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($this->office,['staff']);
        $r=$this->getJson('/api/admin/masjids/'.$this->school->id.'/attendance/members/'.$this->child->id.'?from=2026-10-19&to=2026-10-19')->assertOk();
        $this->assertSame('absent',$r->json('data.entries.0.status'));
        $csv=$this->get('/api/admin/masjids/'.$this->school->id.'/records/export?dataset=attendance')->assertOk()->streamedContent();
        $rows=array_map('str_getcsv',array_values(array_filter(explode("\n",trim($csv)))));
        $this->assertCount(2,$rows); $this->assertSame('absent',$rows[1][5]);
    }
    #[Test]
    public function switch_off_daily_save_overrides_day_result_but_keeps_read_only_period_lines(): void
    {
        $this->take($this->first,'late')->assertOk();
        CapabilityWriter::apply($this->school->fresh(),['period_attendance'=>false],null);
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($this->lead,['staff']);
        $url='/api/teacher/masjids/'.$this->school->id.'/groups/'.$this->class->id.'/attendance';
        $r=$this->getJson($url.'?date=2026-10-19')->assertOk()->assertJsonPath('data.retained_periods.0.read_only',true);
        $this->putJson($url,['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$this->child->id,'status'=>'excused']]],['X-Attendance-Revision'=>$r->headers->get('X-Attendance-Revision')])->assertOk();
        $this->assertSame('excused',app(AttendanceReader::class)->marks($this->school->id,[$this->child->id])->first()['status']);
        $this->assertDatabaseCount('period_attendance_records',1);
        CapabilityWriter::apply($this->school->fresh(),['period_attendance'=>true],null);
        $this->take($this->first,'present')->assertOk();
        $this->assertSame('present',app(AttendanceReader::class)->marks($this->school->id,[$this->child->id])->first()['status']);
    }
    #[Test]
    public function snapshots_survive_changed_meeting_and_duplicate_saves_have_one_cell(): void
    {
        $this->take($this->first,'late')->assertOk();
        $this->first->update(['activity_name'=>'Changed practice lesson','period_id'=>$this->second->period_id]);
        $this->take($this->first,'present')->assertOk();
        $row=PeriodAttendanceRecord::firstOrFail();
        $this->assertSame('First',$row->period_name); $this->assertSame('Practice lesson',$row->subject_name);
        $this->assertSame('text',\Illuminate\Support\Facades\Schema::getColumnType('period_attendance_records','note'));
        $this->assertDatabaseCount('period_attendance_records',1);
        $indexes=\Illuminate\Support\Facades\Schema::getIndexes('period_attendance_records');
        $this->assertTrue(collect($indexes)->contains(fn($index)=>$index['primary']));
        $this->assertTrue(collect($indexes)->contains(fn($index)=>$index['unique'] && $index['columns']===['masjid_id','session_date','meeting_id','student_contact_id']));
    }
    #[Test]
    public function an_old_day_mark_on_switch_on_day_is_not_used_before_any_period_is_taken(): void
    {
        $this->mark($this->child,'2026-10-19','absent');
        $this->assertCount(0,app(AttendanceReader::class)->marks($this->school->id,[$this->child->id],'2026-10-19'));
    }
    #[Test]
    public function marked_day_cannot_be_closed_and_a_member_login_deletion_keeps_period_history(): void
    {
        $this->take($this->first,'present')->assertOk();
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($this->office,['staff']);
        $this->postJson('/api/admin/masjids/'.$this->school->id.'/school-calendar/closures',['school_year_id'=>$this->yearRow->id,'closed_on'=>'2026-10-19','reason'=>'Practice closure'])->assertStatus(422);
        $contact=$this->child->contact;
        $this->assertContains('period_attendance_records',app(\App\Services\Member\MemberAccountDeletion::class)->reasonsToKeep($contact));
        $result=app(\App\Services\Member\MemberAccountDeletion::class)->delete($contact,'app');
        $this->assertSame('login_removed',$result['outcome']); $this->assertDatabaseCount('period_attendance_records',1);
    }

    #[Test]
    public function a_day_page_cannot_write_after_periods_are_enabled_at_its_save_transaction(): void
    {
        CapabilityWriter::apply($this->school->fresh(),['period_attendance'=>false],null);
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($this->lead,['staff']);
        $url='/api/teacher/masjids/'.$this->school->id.'/groups/'.$this->class->id.'/attendance';
        $r=$this->getJson($url.'?date=2026-10-19')->assertOk(); $armed=true;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class,function ($event) use (&$armed) {
            if (! $armed) return;
            $armed=false;
            $values=$this->school->fresh()->capability_overrides; $values['period_attendance']=true;
            DB::table('masjids')->where('id',$this->school->id)->update(['capability_overrides'=>json_encode($values)]);
        });
        $this->putJson($url,['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$this->child->id,'status'=>'present']]],['X-Attendance-Revision'=>$r->headers->get('X-Attendance-Revision')])->assertStatus(422);
        $this->assertFalse($armed); $this->assertDatabaseCount('attendance_records',0);
    }

    #[Test]
    public function a_lunch_staff_login_with_a_teacher_role_can_take_its_meeting_register(): void
    {
        config(['tenancy.roles_per_organisation'=>true]);
        $helper=User::factory()->create(['type'=>'LunchStaff','name'=>'Practice Helper','email'=>'helper@example.invalid','phone'=>'+15555550307']);
        MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$helper->id,'role'=>'teacher','is_default'=>true]);
        TimetableMeetingTeacher::where('meeting_id',$this->first->id)->where('user_id',$this->co->id)->delete();
        TimetableMeetingTeacher::create(['masjid_id'=>$this->school->id,'meeting_id'=>$this->first->id,'user_id'=>$helper->id]);
        $this->take($this->first,'present',$helper)->assertOk()->assertJsonPath('data.students.0.marked_by','Practice Helper');
    }
    #[Test]
    public function a_period_page_cannot_write_after_the_switch_is_disabled_at_its_save_transaction(): void
    {
        $this->take($this->first,'present')->assertOk(); $armed=true;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class,function ($event) use (&$armed) {
            if (! $armed) return; $armed=false;
            $values=$this->school->fresh()->capability_overrides; $values['period_attendance']=false;
            DB::table('masjids')->where('id',$this->school->id)->update(['capability_overrides'=>json_encode($values)]);
        });
        $this->putJson($this->url($this->first),['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$this->child->id,'status'=>'absent']]],['X-Attendance-Revision'=>'old-period-revision'])->assertStatus(422);
        $this->assertFalse($armed); $this->assertSame('present',PeriodAttendanceRecord::firstOrFail()->status);
    }

    #[Test]
    public function staging_scrubs_saved_note_and_editable_timetable_titles_without_erasing_marks(): void
    {
        $this->take($this->first,'present')->assertOk(); $id=PeriodAttendanceRecord::firstOrFail()->id;
        $this->app->detectEnvironment(fn ()=>'staging');
        config(['app.env'=>'staging','database.connections.sqlite.database'=>'masjids_staging']);
        $this->artisan('staging:scrub',['--i-understand-this-destroys-personal-data'=>true])->assertSuccessful();
        $row=DB::table('period_attendance_records')->where('id',$id)->first();
        $this->assertSame(\App\Support\ScrubStrategies::FREE_TEXT,$row->note);
        $this->assertSame('Period '.$id,$row->period_name); $this->assertSame('Lesson '.$id,$row->subject_name);
        $this->assertSame('present',$row->status); $this->assertSame('Practice Class',$row->class_name);
    }

    #[Test]
    public function saved_period_cells_remain_correctable_after_a_child_leaves_the_roster(): void
    {
        $this->take($this->first,'present')->assertOk();
        $this->child->forceFill(['left_on'=>'2026-10-19'])->save();
        $this->take($this->first,'late')->assertOk()->assertJsonPath('data.students.0.status','late');
        $this->assertSame('late',app(AttendanceReader::class)->marks($this->school->id,[$this->child->id])->first()['status']);
        $this->take($this->second,'absent')->assertStatus(422);
    }

    #[Test]
    public function merging_pupil_identities_keeps_periods_and_the_earliest_first_period(): void
    {
        $this->take($this->first,'absent')->assertOk(); $this->take($this->second,'absent')->assertOk();
        $other=$this->classroom($this->school,'Practice Other Class'); $target=$this->enrol($other);
        $meeting=TimetableMeeting::create(['masjid_id'=>$this->school->id,'school_year_id'=>$this->yearRow->id,'group_id'=>$other->id,'period_id'=>$this->second->period_id,'weekday'=>1,'kind'=>'activity','activity_name'=>'Practice lesson','effective_from'=>'2026-08-24']);
        TimetableMeetingTeacher::create(['masjid_id'=>$this->school->id,'meeting_id'=>$meeting->id,'user_id'=>$this->teacher->id]);
        $read=$this->getJson($this->url($meeting).'?date=2026-10-19')->assertOk();
        $this->putJson($this->url($meeting),['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$target->id,'status'=>'late']]],['X-Attendance-Revision'=>$read->headers->get('X-Attendance-Revision')])->assertOk();
        $this->first->delete();
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($this->office,['staff']);
        $this->postJson('/api/admin/masjids/'.$this->school->id.'/contacts/'.$this->child->contact_id.'/merge',['target_contact_id'=>$target->contact_id])->assertOk();
        $this->assertDatabaseCount('period_attendance_records',3);
        $marks=app(AttendanceReader::class)->marks($this->school->id,[$this->child->id,$target->id]);
        $this->assertSame(['present','present'],$marks->pluck('status')->all());
        $this->take($this->second,'excused')->assertOk();
        $this->assertDatabaseCount('period_attendance_records',3);
    }

    #[Test]
    public function overlapping_period_histories_refuse_contact_merge_without_losing_either_cell(): void
    {
        $target=$this->enrol($this->class); $this->take($this->first,'present')->assertOk();
        $read=$this->getJson($this->url($this->first).'?date=2026-10-19')->assertOk();
        $this->putJson($this->url($this->first),['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$target->id,'status'=>'absent']]],['X-Attendance-Revision'=>$read->headers->get('X-Attendance-Revision')])->assertOk();
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($this->office,['staff']);
        $this->postJson('/api/admin/masjids/'.$this->school->id.'/contacts/'.$this->child->contact_id.'/merge',['target_contact_id'=>$target->contact_id])->assertStatus(422);
        $this->assertDatabaseCount('period_attendance_records',2);
        $this->assertNotNull(Contact::find($this->child->contact_id));
        $this->assertSame($this->child->contact_id, $this->child->fresh()->contact_id);
    }

    #[Test]
    public function a_partial_resumed_day_register_keeps_other_pupils_recorded_period_results(): void
    {
        $other=$this->enrol($this->class); $this->take($this->first,'present')->assertOk();
        $read=$this->getJson($this->url($this->first).'?date=2026-10-19')->assertOk();
        $this->putJson($this->url($this->first),['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$other->id,'status'=>'late']]],['X-Attendance-Revision'=>$read->headers->get('X-Attendance-Revision')])->assertOk();
        CapabilityWriter::apply($this->school->fresh(),['period_attendance'=>false],null);
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($this->lead,['staff']);
        $url='/api/teacher/masjids/'.$this->school->id.'/groups/'.$this->class->id.'/attendance'; $read=$this->getJson($url.'?date=2026-10-19')->assertOk();
        $this->putJson($url,['session_date'=>'2026-10-19','marks'=>[['membership_id'=>$this->child->id,'status'=>'excused']]],['X-Attendance-Revision'=>$read->headers->get('X-Attendance-Revision')])->assertOk();
        $marks=app(AttendanceReader::class)->marks($this->school->id)->keyBy('membership_id');
        $this->assertSame('excused',$marks[$this->child->id]['status']); $this->assertSame('late',$marks[$other->id]['status']);
    }

    #[Test]
    public function activating_periods_does_not_allow_a_closure_over_the_retained_day_register(): void
    {
        $this->mark($this->child,'2026-10-19','present');
        $this->assertCount(0,app(AttendanceReader::class)->marks($this->school->id,[$this->child->id]));
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($this->office,['staff']);
        $this->postJson('/api/admin/masjids/'.$this->school->id.'/school-calendar/closures',['school_year_id'=>$this->yearRow->id,'closed_on'=>'2026-10-19','reason'=>'Practice closure'])->assertStatus(422);
        $this->assertDatabaseCount('school_closures',0);
    }

}
