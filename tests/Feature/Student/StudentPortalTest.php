<?php

namespace Tests\Feature\Student;

use App\Models\{AssignmentScore, ClassAssignment, Contact, Group, GroupMembership, GroupStaff, StudentHandIn, StudentWorkSetting, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http, Storage};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

class StudentPortalTest extends StudentTestCase
{
    private function work(): ClassAssignment
    {
        $a=ClassAssignment::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'created_by_user_id'=>$this->office->id,'title'=>'Practice work','points_possible'=>10,'scale'=>'points','assigned_on'=>'2026-10-14']);
        StudentWorkSetting::create(['masjid_id'=>$this->school->id,'class_assignment_id'=>$a->id,'students_hand_in'=>true,'due_on'=>'2026-10-14']);
        return $a;
    }
    private function memberBase(): string { return $this->base().'/classes/'.$this->member->id; }
    private function handUrl(ClassAssignment $a): string { return $this->memberBase().'/work/'.$a->id.'/hand-ins'; }

    #[Test]
    public function dates_and_times_are_formatted_by_the_school_without_shifting_calendar_days(): void
    {
        $this->school->forceFill(['timezone'=>'America/Los_Angeles'])->save();
        $a=$this->work();
        StudentWorkSetting::query()->update(['due_on'=>'2026-10-20']);
        $year=\App\Models\SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Practice year','first_day'=>'2026-08-24','last_day'=>'2027-06-04','meeting_weekday'=>1,'meeting_weekdays'=>[1]]);
        \App\Models\SchoolTerm::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'name'=>'First term','starts_on'=>'2026-08-24','ends_on'=>'2026-12-18','position'=>1]);
        \App\Models\SchoolClosure::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'closed_on'=>'2026-10-19','reason'=>'Practice closure']);
        $account=$this->account(); $account->forceFill(['last_sign_in_at'=>'2026-10-15 00:17:00'])->save();
        StudentHandIn::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'class_assignment_id'=>$a->id,'group_membership_id'=>$this->member->id,'student_contact_id'=>$this->child->id,'version'=>1,'kind'=>'link','link'=>'https://example.invalid/work','late'=>false,'submitted_at'=>'2026-10-15 00:17:00']);
        $token=$account->createToken('student',['student'])->plainTextToken;
        $this->bearer($token)->getJson($this->memberBase().'/work')->assertOk()
            ->assertJsonPath('data.0.assigned_on_label','Wed, Oct 14')->assertJsonPath('data.0.due_on_label','Tue, Oct 20');
        $this->bearer($token)->getJson($this->handUrl($a))->assertOk()->assertJsonPath('data.history.0.submitted_at_label','Oct 14, 2026, 5:17 PM');
        $this->bearer($token)->getJson($this->base().'/timetable?week=2026-10-12')->assertOk()->assertJsonPath('data.days.0.date_label','Mon, Oct 12');
        foreach ([false,true] as $terms) {
            $this->school->forceFill(['capability_overrides'=>['student_sign_in'=>true,'school_calendar'=>true,'school_calendar_terms'=>$terms]])->save();
            $r=$this->bearer($token)->getJson($this->base().'/school-calendar')->assertOk()
                ->assertJsonPath('data.years.0.first_day_label','Mon, Aug 24, 2026')->assertJsonPath('data.years.0.last_day_label','Fri, Jun 4, 2027')
                ->assertJsonPath('data.years.0.closures.0.closed_on_label','Mon, Oct 19');
            if ($terms) $r->assertJsonPath('data.years.0.terms.0.starts_on_label','Mon, Aug 24, 2026')->assertJsonPath('data.years.0.terms.0.ends_on_label','Fri, Dec 18, 2026');
        }
        $parent=Contact::factory()->create(['masjid_id'=>$this->school->id,'login_enabled_at'=>now(),'login_email'=>'parent@example.invalid']);
        GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$parent->id,'role'=>'guardian','guardian_of_contact_id'=>$this->child->id,'provenance'=>'confirmed']);
        $this->bearer($parent->createFamilyToken()->plainTextToken)->getJson('/api/family/masjids/'.$this->school->id.'/student-accounts')->assertOk()->assertJsonPath('data.0.last_sign_in_label','Oct 14, 2026, 5:17 PM');
        $this->school->forceFill(['timezone'=>'Pacific/Auckland'])->save();
        $this->bearer($token)->getJson($this->handUrl($a))->assertOk()->assertJsonPath('data.history.0.submitted_at_label','Oct 15, 2026, 1:17 PM');
        $this->bearer($token)->getJson($this->memberBase().'/work')->assertOk()->assertJsonPath('data.0.due_on_label','Tue, Oct 20');
    }

    #[Test]
    public function reads_only_own_classes_work_marks_and_calendar_and_excludes_every_staff_field(): void
    {
        $a=$this->work(); $token=$this->account()->createToken('student',['student'])->plainTextToken;
        $other=Contact::factory()->create(['masjid_id'=>$this->school->id]);
        $otherMember=GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$other->id,'role'=>'member','grade_label'=>'6th']);
        AssignmentScore::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'class_assignment_id'=>$a->id,'group_membership_id'=>$this->member->id,'scored_by_user_id'=>$this->office->id,'status'=>'scored','points_earned'=>8,'note'=>'PRIVATE_STAFF_SENTINEL']);
        AssignmentScore::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'class_assignment_id'=>$a->id,'group_membership_id'=>$otherMember->id,'status'=>'scored','points_earned'=>2]);
        $classes=$this->bearer($token)->getJson($this->base().'/classes')->assertOk()->json('data');
        $this->assertCount(1,$classes); $this->assertSame(['membership_id','name','teachers'],array_keys($classes[0]));
        $this->bearer($token)->getJson($this->memberBase().'/work')->assertOk()->assertJsonPath('data.0.title','Practice work')->assertJsonPath('data.0.due_on','2026-10-14');
        $marks=$this->bearer($token)->getJson($this->memberBase().'/marks')->assertOk();
        $this->assertSame(8.0,(float)$marks->json('data.scores.0.points_earned'));
        $this->assertSame(['assignment','status','points_earned','mark_label'],array_keys($marks->json('data.scores.0')));
        foreach(['PRIVATE_STAFF_SENTINEL','note','override_reason','scored_by_user_id','contact_id','roster'] as $key) $this->assertStringNotContainsString('"'.$key.'"',$marks->getContent());
        foreach(['/work','/marks'] as $page) $this->bearer($token)->getJson($this->base().'/classes/'.$otherMember->id.$page)->assertNotFound();
        $this->bearer($token)->getJson($this->base().'/school-calendar')->assertOk();
        $this->bearer($token)->getJson($this->base().'/timetable?week=2026-10-12')->assertOk();
    }

    #[Test]
    public function file_photo_and_link_hand_ins_are_private_late_versioned_and_locked_after_marking(): void
    {
        Storage::fake('local'); Http::preventStrayRequests();
        $a=$this->work(); $token=$this->account()->createToken('student',['student'])->plainTextToken;
        $first=$this->bearer($token)->post($this->handUrl($a),['kind'=>'file','file'=>UploadedFile::fake()->createWithContent('practice.pdf',"%PDF-1.4\nPractice")],['Accept'=>'application/json'])->assertOk();
        $this->assertTrue($first->json('data.late'));
        $row=StudentHandIn::first(); Storage::disk('local')->assertExists($row->path);
        $this->assertStringNotContainsString('practice.pdf',$row->path);
        $this->assertArrayNotHasKey('path',$first->json('data')); $this->assertArrayNotHasKey('disk',$first->json('data'));
        $this->bearer($token)->get($this->handUrl($a).'/'.$row->id.'/download')->assertOk();
        $this->bearer($token)->post($this->handUrl($a),['kind'=>'photo','file'=>UploadedFile::fake()->image('photo.png')],['Accept'=>'application/json'])->assertOk();
        $this->bearer($token)->postJson($this->handUrl($a),['kind'=>'link','link'=>'https://example.invalid/practice'])->assertOk()->assertJsonPath('data.version',3);
        Http::assertNothingSent(); $this->assertSame(3,StudentHandIn::count());
        StudentWorkSetting::query()->update(['students_hand_in'=>false]);
        $this->bearer($token)->getJson($this->memberBase().'/work')->assertOk()->assertJsonPath('data.0.has_hand_in',true);
        $this->bearer($token)->getJson($this->handUrl($a))->assertOk();
        StudentWorkSetting::query()->update(['students_hand_in'=>true]);
        $this->assertSame($this->child->id,(int)StudentHandIn::first()->student_contact_id);
        $this->assertCount(3,$this->bearer($token)->getJson($this->handUrl($a))->assertOk()->json('data.history'));
        foreach(['javascript:alert(1)','file:///tmp/practice','ftp://example.invalid/file','data:text/html,test'] as $link) $this->bearer($token)->postJson($this->handUrl($a),['kind'=>'link','link'=>$link])->assertStatus(422);
        $this->bearer($token)->post($this->handUrl($a),['kind'=>'file','file'=>UploadedFile::fake()->createWithContent('bad.svg','<svg></svg>')],['Accept'=>'application/json'])->assertStatus(422);
        AssignmentScore::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'class_assignment_id'=>$a->id,'group_membership_id'=>$this->member->id,'status'=>'scored','points_earned'=>9]);
        $this->bearer($token)->postJson($this->handUrl($a),['kind'=>'link','link'=>'https://example.invalid/replaced'])->assertStatus(409)->assertJsonPath('message','This work has been marked. It can no longer be replaced.');
        $this->assertSame(3,StudentHandIn::count());
    }

    #[Test]
    public function assigned_teacher_marks_from_hand_ins_office_reads_and_family_sees_account_status_only(): void
    {
        $a=$this->work(); $token=$this->account()->createToken('student',['student'])->plainTextToken;
        $this->bearer($token)->postJson($this->handUrl($a),['kind'=>'link','link'=>'https://example.invalid/work'])->assertOk();
        $teacher=User::factory()->create(['type'=>'Teacher','phone'=>'+15555550103','name'=>'Practice Teacher']);
        \App\Models\MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$teacher->id,'role'=>'teacher','is_default'=>true]);
        GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'user_id'=>$teacher->id]);
        $this->withHeader('Authorization',''); Sanctum::actingAs($teacher,['staff']);
        $staff='/api/teacher/masjids/'.$this->school->id.'/groups/'.$this->class->id.'/assignments/'.$a->id;
        $this->getJson($staff.'/hand-ins')->assertOk()->assertJsonPath('data.students.0.history.0.link','https://example.invalid/work');
        $markTransactionLevel=0;
        AssignmentScore::saving(function () use (&$markTransactionLevel) { $markTransactionLevel=\Illuminate\Support\Facades\DB::transactionLevel(); });
        $this->putJson($staff.'/scores',['scores'=>[['membership_id'=>$this->member->id,'status'=>'scored','points_earned'=>9]]])->assertOk();
        $this->assertSame(2,$markTransactionLevel,'Marking must hold the hand-in mutex around the existing gradebook transaction.');
        $this->assertSame(1,AssignmentScore::count());
        $this->bearer($token)->postJson($this->handUrl($a),['kind'=>'link','link'=>'https://example.invalid/new'])->assertStatus(409);
        $this->withHeader('Authorization',''); Sanctum::actingAs($this->office,['staff']);
        $this->getJson(str_replace('/teacher/','/admin/',$staff).'/hand-ins')->assertOk();
        $parent=Contact::factory()->create(['masjid_id'=>$this->school->id,'login_enabled_at'=>now(),'login_email'=>'parent@example.invalid']);
        GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$parent->id,'role'=>'guardian','guardian_of_contact_id'=>$this->child->id,'provenance'=>'confirmed']);
        $family=$this->bearer($parent->createFamilyToken()->plainTextToken)->getJson('/api/family/masjids/'.$this->school->id.'/student-accounts')->assertOk();
        $this->assertSame('practice6',$family->json('data.0.username'));
        $this->assertSame(['membership_id','has_account','username','enabled','last_sign_in_at','last_sign_in_label'],array_keys($family->json('data.0')));
        $this->assertStringNotContainsString('password',$family->getContent());
    }

    #[Test]
    public function timetable_reads_own_class_teacher_and_dated_versions_and_respects_closures(): void
    {
        $this->school->forceFill(['capability_overrides'=>['student_sign_in'=>true,'school_calendar'=>true,'school_calendar_terms'=>true,'school_timetable'=>true],'timezone'=>'America/New_York'])->save();
        $year=\App\Models\SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Practice Year','first_day'=>'2026-09-01','last_day'=>'2027-06-30','meeting_weekdays'=>[1,2,3,4,5]]);
        $set=\App\Models\TimetablePeriodSet::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'name'=>'Practice periods']);
        $period=\App\Models\TimetablePeriod::create(['masjid_id'=>$this->school->id,'period_set_id'=>$set->id,'name'=>'Practice period','starts_at'=>'09:00','ends_at'=>'09:45','kind'=>'teaching','position'=>1]);
        $teacher=User::factory()->create(['type'=>'Teacher','name'=>'Practice Teacher','phone'=>'+15555550103','email'=>'teacher@example.invalid']);
        GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'user_id'=>$teacher->id]);
        $otherClass=Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>'OTHER_CLASS_SENTINEL']);
        foreach([[$this->class,'Own lesson'],[$otherClass,'OTHER_LESSON_SENTINEL']] as [$group,$label]) {
            $m=\App\Models\TimetableMeeting::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'group_id'=>$group->id,'period_id'=>$period->id,'weekday'=>4,'kind'=>'activity','activity_name'=>$label,'effective_from'=>'2026-09-01']);
            \App\Models\TimetableMeetingTeacher::create(['masjid_id'=>$this->school->id,'meeting_id'=>$m->id,'user_id'=>$teacher->id]);
        }
        $token=$this->account()->createToken('student',['student'])->plainTextToken;
        $this->bearer($token)->getJson($this->base().'/classes')->assertOk()->assertJsonPath('data.0.teachers.0','Practice Teacher');
        $r=$this->bearer($token)->getJson($this->base().'/timetable?week=2026-10-12')->assertOk();
        $this->assertSame('Own lesson',$r->json('data.days.3.meetings.0.label'));
        $r->assertJsonPath('data.days.3.meetings.0.starts_at_label','9:00 AM')->assertJsonPath('data.days.3.meetings.0.ends_at_label','9:45 AM');
        $this->assertStringNotContainsString('SENTINEL',$r->getContent());
        \App\Models\SchoolClosure::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'closed_on'=>'2026-10-15','reason'=>'Practice closure']);
        $this->bearer($token)->getJson($this->base().'/timetable?week=2026-10-12')->assertOk()->assertJsonPath('data.days.3.meetings',[])->assertJsonPath('data.days.3.closed',true);
    }

    #[Test]
    public function student_letters_reuse_the_published_family_projection_and_never_include_override_or_comment(): void
    {
        $this->school->forceFill(['letter_grade_settings'=>['enabled'=>false,'from_grade'=>'6th','scale'=>\App\Support\LetterGrades::DEFAULT_SCALE]])->save();
        $card=\App\Models\ReportCard::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'group_membership_id'=>$this->member->id,'type'=>'report_card','school_year'=>'2026-2027','term'=>1,'published_at'=>now()]);
        $card->forceFill(['published_at'=>now()])->save();
        $mark=new \App\Models\ReportCardMark(); $mark->forceFill(['masjid_id'=>$this->school->id,'report_card_id'=>$card->id,'kind'=>'term_grade','subject'=>'Practice Science','criterion'=>'Term grade','position'=>1,'final_letter'=>'B','final_percentage'=>85.5,'final_comment'=>'PRIVATE_COMMENT_SENTINEL','override_reason'=>'PRIVATE_REASON_SENTINEL','letter_snapshot_at'=>now()])->save();
        $expected=array_intersect_key(\App\Support\LetterGrades::family($card)[0],array_flip(['subject','final_letter','percentage']));
        $token=$this->account()->createToken('student',['student'])->plainTextToken;
        $r=$this->bearer($token)->getJson($this->memberBase().'/marks')->assertOk();
        $this->assertSame($expected,$r->json('data.reports.0.letter_grades.0'));
        $this->assertStringNotContainsString('SENTINEL',$r->getContent());
        $card->forceFill(['published_at'=>null])->save();
        $this->bearer($token)->getJson($this->memberBase().'/marks')->assertOk()->assertJsonPath('data.reports',[]);
    }

    #[Test]
    public function another_students_hand_in_and_work_ids_are_404_and_a_closed_hand_in_flag_refuses_submission(): void
    {
        $a=$this->work();$token=$this->account()->createToken('student',['student'])->plainTextToken;
        $other=Contact::factory()->create(['masjid_id'=>$this->school->id,'email'=>'other@example.invalid']);
        $member=GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$other->id,'role'=>'member','grade_label'=>'6th']);
        $h=StudentHandIn::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'class_assignment_id'=>$a->id,'group_membership_id'=>$member->id,'student_contact_id'=>$other->id,'version'=>1,'kind'=>'link','link'=>'https://example.invalid/other','late'=>false,'submitted_at'=>now()]);
        $this->bearer($token)->getJson($this->handUrl($a).'/'.$h->id.'/download')->assertNotFound();
        $otherClass=Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class']);
        $otherWork=ClassAssignment::create(['masjid_id'=>$this->school->id,'group_id'=>$otherClass->id,'title'=>'Other work','points_possible'=>10,'assigned_on'=>'2026-10-15']);
        $this->bearer($token)->postJson($this->handUrl($otherWork),['kind'=>'link','link'=>'https://example.invalid/work'])->assertNotFound();
        StudentWorkSetting::query()->update(['students_hand_in'=>false]);
        $this->bearer($token)->postJson($this->handUrl($a),['kind'=>'link','link'=>'https://example.invalid/work'])->assertForbidden();
        $this->assertSame(1,StudentHandIn::count());
    }

    #[Test]
    public function subject_limited_teacher_cannot_read_or_configure_other_subjects_hand_ins(): void
    {
        $a=$this->work();$a->update(['subject'=>'Science']);
        $teacher=User::factory()->create(['type'=>'Teacher','phone'=>'+15555550103','email'=>'teacher@example.invalid']);
        \App\Models\MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$teacher->id,'role'=>'teacher','is_default'=>true]);
        GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'user_id'=>$teacher->id,'subjects'=>['arabic']]);
        Sanctum::actingAs($teacher,['staff']);
        $base='/api/teacher/masjids/'.$this->school->id.'/groups/'.$this->class->id.'/assignments/'.$a->id;
        $this->getJson($base.'/hand-ins')->assertNotFound();
        $this->put($base.'/student-hand-in',['students_hand_in'=>'1','due_on'=>'2026-10-16'],['Accept'=>'application/json'])->assertNotFound();
        $a->update(['subject'=>'Arabic']);
        $this->put($base.'/student-hand-in',['students_hand_in'=>'1','due_on'=>'2026-10-16'],['Accept'=>'application/json'])->assertOk();
        $this->getJson($base.'/hand-ins')->assertOk()->assertJsonPath('data.due_on','2026-10-16');
    }
}
