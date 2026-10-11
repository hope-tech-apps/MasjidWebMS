<?php

use App\Models\{ClassSubject, Contact, Group, GroupStaff, Masjid, MasjidUser, SchoolClosure, SchoolYear, TimetableMeeting, TimetableMeetingTeacher, TimetablePeriod, TimetablePeriodSet, User};
use App\Support\{TenantContext, StudentAge};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
beforeEach(function () {
    fake()->seed(9153);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-12 13:20:00', 'UTC'));
    $this->school = Masjid::create(['name'=>'Practice School','email'=>'practice@example.invalid','phone'=>'+15555550200','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true,'timezone'=>'America/New_York']);
    $this->school->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_calendar_terms'=>true,'school_timetable'=>true,'class_subjects'=>true]])->save();
    $this->teacher = User::factory()->create(['type'=>'Teacher','name'=>'Practice Specialist','email'=>'specialist@example.invalid','phone'=>'+15555550201']);
    MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$this->teacher->id,'role'=>'teacher','is_default'=>true]);
    $this->year = SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Practice year','first_day'=>'2026-09-07','last_day'=>'2027-06-25','meeting_weekdays'=>[1,2,3,4,5]]);
    $this->class = Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>'Practice Class','slug'=>'practice-class']);
    $this->subject = ClassSubject::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'name'=>'Science']);
    $this->staff = GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'user_id'=>$this->teacher->id,'class_subject_ids'=>[$this->subject->id]]);
    $this->set = TimetablePeriodSet::create(['masjid_id'=>$this->school->id,'school_year_id'=>$this->year->id,'name'=>'Regular']);
    $this->period = function ($start, $end, $kind='teaching', $set=null) {
        return TimetablePeriod::create(['masjid_id'=>$this->school->id,'period_set_id'=>($set??$this->set)->id,'name'=>'Practice period','starts_at'=>$start,'ends_at'=>$end,'kind'=>$kind,'position'=>TimetablePeriod::count()+1]);
    };
    $this->meeting = function ($period, $extra=[], $teachers=null) {
        $m = TimetableMeeting::create($extra+['masjid_id'=>$this->school->id,'school_year_id'=>$this->year->id,'group_id'=>$this->class->id,'period_id'=>$period->id,'weekday'=>1,'kind'=>'subject','class_subject_id'=>$this->subject->id,'effective_from'=>'2026-09-07']);
        foreach ($teachers??[$this->teacher] as $t) TimetableMeetingTeacher::create(['masjid_id'=>$this->school->id,'meeting_id'=>$m->id,'user_id'=>$t->id]);
        return $m;
    };
    $this->url = "/api/teacher/masjids/{$this->school->id}/schedule";
    Sanctum::actingAs($this->teacher,['staff']);
});
afterEach(fn()=>app(TenantContext::class)->forgetTenant());

it('Teacher Timetable acceptance A specialist sees only three named Monday meetings in clock order and no Friday meetings', function () {
    $junior=Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>'Practice Junior']);
    $lead=User::factory()->create(['type'=>'Teacher','name'=>'Practice Lead','phone'=>'+15555550202']);
    MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$lead->id,'role'=>'teacher']);
    GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'user_id'=>$lead->id,'class_subject_ids'=>[]]);
    foreach ([['12:45','13:30'],['09:10','09:55'],['11:50','12:35']] as [$a,$b]) foreach([1,2,3,4] as $day) ($this->meeting)(($this->period)($a,$b),['weekday'=>$day]);
    ($this->meeting)(($this->period)('08:15','09:00'),[],[$lead]);
    ($this->meeting)(($this->period)('12:45','13:30'),['group_id'=>$junior->id,'kind'=>'activity','class_subject_id'=>null,'activity_name'=>'Nap'],[$lead]);
    ($this->meeting)(($this->period)('10:50','11:20','block'),['kind'=>'activity','class_subject_id'=>null,'activity_name'=>'Break duty'],[$lead]);
    $r=$this->getJson($this->url)->assertOk()->assertJsonPath('data.today.date','2026-10-12')->assertJsonPath('data.today.state','meetings');
    expect(array_column($r->json('data.today.meetings'),'starts_at'))->toBe(['09:10','11:50','12:45']);
    expect(array_column($r->json('data.days'),'weekday'))->toBe([1,2,3,4]);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-16 16:00:00','UTC'));
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.today.state','unplaced')->assertJsonPath('data.today.meetings',[]);
    Sanctum::actingAs($lead,['staff']);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-12 16:00:00','UTC'));
    expect(array_column($this->getJson($this->url)->assertOk()->json('data.today.meetings'),'label'))->toContain('Break duty','Nap');
});

it('Teacher Timetable acceptance B weekend co-teachers and independent period sets show adjacent classes at 1100 and 1140', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-11 16:00:00','UTC'));
    $this->year->update(['first_day'=>'2026-09-06','last_day'=>'2027-06-27','meeting_weekdays'=>[0]]);
    $other=Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>'Practice Other']);
    $co=User::factory()->create(['type'=>'Teacher','name'=>'Practice Co Teacher','phone'=>'+15555550203']);
    MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$co->id,'role'=>'teacher']);
    $later=TimetablePeriodSet::create(['masjid_id'=>$this->school->id,'school_year_id'=>$this->year->id,'name'=>'Later']);
    ($this->meeting)(($this->period)('11:40','12:20'),['weekday'=>0,'group_id'=>$other->id,'kind'=>'class','class_subject_id'=>null]);
    ($this->meeting)(($this->period)('11:00','11:40'),['weekday'=>0],[$this->teacher,$co]);
    ($this->meeting)(($this->period)('12:40','13:30','teaching',$later),['weekday'=>0,'group_id'=>$other->id,'kind'=>'activity','class_subject_id'=>null,'activity_name'=>'Reading']);
    $r=$this->getJson($this->url)->assertOk();
    expect(array_column($r->json('data.today.meetings'),'starts_at'))->toBe(['11:00','11:40','12:40']);
    expect(array_column($r->json('data.today.meetings.0.co_teachers'),'name'))->toBe(['Practice Co Teacher']);
    expect(array_column($r->json('data.days'),'weekday'))->toBe([0]);
    expect($r->json('data.today.meetings.1.label'))->toBe('Practice Other');
});

it('Teacher Timetable dated changes resolve each actual weekday and selected week and keep today independent', function () {
    $p=($this->period)('08:15','09:00');
    ($this->meeting)($p,['effective_until'=>'2026-10-13']);
    ($this->meeting)($p,['effective_from'=>'2026-10-14','weekday'=>3,'kind'=>'activity','class_subject_id'=>null,'activity_name'=>'New duty']);
    ($this->meeting)($p,['effective_from'=>'2026-10-19','kind'=>'activity','class_subject_id'=>null,'activity_name'=>'Next week duty']);
    $r=$this->getJson($this->url)->assertOk();
    expect(array_column($r->json('data.days.1.meetings'),'label'))->toBe(['New duty']);
    $next=$this->getJson($this->url.'?week=2026-10-19')->assertOk();
    expect(array_column($next->json('data.days.0.meetings'),'label'))->toBe(['Next week duty']);
    expect(array_column($next->json('data.today.meetings'),'label'))->toBe(['Science']);
    expect(DB::table('timetable_meetings')->count())->toBe(3);
});

it('Teacher Timetable closure suppresses live meetings and names closure today and in week', function () {
    ($this->meeting)(($this->period)('08:15','09:00'));
    SchoolClosure::create(['masjid_id'=>$this->school->id,'school_year_id'=>$this->year->id,'closed_on'=>'2026-10-12','reason'=>'Practice planning day']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.today.state','closure')->assertJsonPath('data.today.reason','Practice planning day')->assertJsonPath('data.today.meetings',[])->assertJsonPath('data.days.0.reason','Practice planning day')->assertJsonPath('data.days.0.meetings',[]);
});

it('Teacher Timetable states use school local today and year bounds and calendar weekday authority', function () {
    foreach(['2026-09-01 16:00:00'=>'not_started','2027-07-01 16:00:00'=>'ended','2026-10-10 16:00:00'=>'non_meeting','2026-10-12 16:00:00'=>'unplaced'] as $clock=>$state) {
        $this->travelTo(\Carbon\Carbon::parse($clock,'UTC')); $this->getJson($this->url)->assertOk()->assertJsonPath('data.today.state',$state);
    }
    $this->school->forceFill(['timezone'=>'Pacific/Honolulu'])->save();
    $this->travelTo(\Carbon\Carbon::parse('2026-10-12 02:00:00','UTC'));
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.today.date','2026-10-11')->assertJsonPath('data.timezone','Pacific/Honolulu');
    $this->school->forceFill(['timezone'=>'UTC','capability_overrides'=>['school_calendar'=>true,'school_timetable'=>true]])->save();
    $this->travelTo(\Carbon\Carbon::parse('2026-10-13 16:00:00','UTC'));
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.timezone','America/New_York')->assertJsonPath('data.today.state','non_meeting');
});

it('Teacher Timetable class subject navigation follows saved subject authority and locations resolve only when set', function () {
    $m=($this->meeting)(($this->period)('08:15','09:00'));
    $href="/teacher/classes/{$this->class->id}?subject={$this->subject->id}";
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.today.meetings.0.href',$href)->assertJsonPath('data.today.meetings.0.room_name',null);
    $this->staff->forceFill(['class_subject_ids'=>[],'class_subject_ids_edited_at'=>now()])->save();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.today.meetings.0.href',"/teacher/classes/{$this->class->id}");
    $room=\App\Models\TimetableRoom::create(['masjid_id'=>$this->school->id,'name'=>'Practice Hall','name_key'=>'practice hall','active'=>true]);
    \App\Models\TimetableClassRoom::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'room_id'=>$room->id]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.today.meetings.0.room_name','Practice Hall');
    DB::table('group_staff')->where('id',$this->staff->id)->update(['class_subject_ids'=>null,'class_subjects_mapped_at'=>null,'class_subject_ids_edited_at'=>null]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.today.meetings.0.href',"/teacher/classes/{$this->class->id}");
});

it('Teacher Timetable another school token is refused and a two school teacher sees only each bound schools meetings', function () {
    ($this->meeting)(($this->period)('08:15','09:00'));
    $other=Masjid::create(['name'=>'Other Practice School','email'=>'other@example.invalid','phone'=>'+15555550204','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    $other->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_calendar_terms'=>true,'school_timetable'=>true]])->save();
    $foreign=User::factory()->create(['type'=>'Teacher','name'=>'Practice Foreign','phone'=>'+15555550205']);
    MasjidUser::create(['masjid_id'=>$other->id,'user_id'=>$foreign->id,'role'=>'teacher']);
    Sanctum::actingAs($foreign,['staff']); $this->getJson($this->url)->assertForbidden();
    Auth::forgetGuards(); $this->withToken($foreign->createToken('practice',['staff'])->plainTextToken);
    $this->getJson($this->url)->assertForbidden();
    Auth::forgetGuards();
    Sanctum::actingAs($this->teacher,['staff']); $otherUrl="/api/teacher/masjids/{$other->id}/schedule";
    $this->getJson($otherUrl)->assertForbidden();
    app(TenantContext::class)->forgetTenant(); Auth::forgetGuards();
    MasjidUser::create(['masjid_id'=>$other->id,'user_id'=>$this->teacher->id,'role'=>'teacher']);
    $y=SchoolYear::withoutMasjidScope()->create(['masjid_id'=>$other->id,'label'=>'Other year','first_day'=>'2026-09-07','last_day'=>'2027-06-25','meeting_weekdays'=>[1]]);
    $g=Group::factory()->create(['masjid_id'=>$other->id,'kind'=>'class','name'=>'Other Practice Class']);
    $s=TimetablePeriodSet::create(['masjid_id'=>$other->id,'school_year_id'=>$y->id,'name'=>'Other set']);
    $p=TimetablePeriod::create(['masjid_id'=>$other->id,'period_set_id'=>$s->id,'name'=>'P1','starts_at'=>'10:00','ends_at'=>'11:00','kind'=>'teaching','position'=>1]);
    $m=TimetableMeeting::create(['masjid_id'=>$other->id,'school_year_id'=>$y->id,'group_id'=>$g->id,'period_id'=>$p->id,'weekday'=>1,'kind'=>'class','effective_from'=>'2026-09-07']);
    TimetableMeetingTeacher::create(['masjid_id'=>$other->id,'meeting_id'=>$m->id,'user_id'=>$this->teacher->id]);
    Sanctum::actingAs($this->teacher,['staff']);
    $this->getJson($otherUrl)->assertOk()->assertHeader('X-Tenant-Id',(string)$other->id)->assertJsonPath('data.today.meetings.0.group_name','Other Practice Class');
    $this->getJson($this->url.'?teacher_id='.$foreign->id)->assertOk()->assertJsonPath('data.today.meetings.0.group_name','Practice Class');
});

it('Teacher Timetable family and office bearer tokens are refused and off returns 404', function () {
    $family=Contact::factory()->create(['masjid_id'=>$this->school->id,'login_email'=>'family@example.invalid','login_enabled_at'=>now()]);
    $office=User::factory()->create(['type'=>'SuperAdmin','phone'=>'+15555550206']);
    foreach([$family->createToken('practice',['family'])->plainTextToken,$office->createToken('practice',['staff'])->plainTextToken] as $token) {
        Auth::forgetGuards(); $this->withToken($token); expect($this->getJson($this->url)->status())->toBeIn([401,403]);
    }
    Auth::forgetGuards(); $this->withToken($this->teacher->createToken('practice',['staff'])->plainTextToken);
    $this->school->forceFill(['capability_overrides'=>[]])->save(); $this->getJson($this->url)->assertNotFound();
});

it('Teacher Timetable uses a fixed page query count at one and twelve classes and any selected week', function () {
    $p=($this->period)('08:15','09:00'); $counts=[];
    for($i=1;$i<=12;$i++) {
        $g=$i===1?$this->class:Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>'Practice '.$i]);
        ($this->meeting)($p,['group_id'=>$g->id,'kind'=>'class','class_subject_id'=>null]);
        if(!in_array($i,[1,12])) continue;
        foreach(['2026-10-12','2026-10-19','2027-07-05','2026-08-03'] as $week) {
            $this->teacher->unsetRelations();
            DB::flushQueryLog(); DB::enableQueryLog();
            $this->getJson($this->url.'?week='.$week)->assertOk();
            $counts[$i][$week]=count(DB::getQueryLog()); file_put_contents(base_path('artifacts/school-timetable-step2-sql-'.$i.'-'.$week.'.json'),json_encode(DB::getQueryLog(),JSON_PRETTY_PRINT)); DB::disableQueryLog();
        }
    }
    file_put_contents(base_path('artifacts/school-timetable-step2-query-counts.json'),json_encode($counts,JSON_PRETTY_PRINT));
    expect(array_values(array_unique(array_merge(array_values($counts[1]),array_values($counts[12])))))->toBe([11]);
});

it('Teacher Timetable rejects invalid week and chooses the year containing every week date', function () {
    $this->getJson($this->url.'?week=2026-02-30')->assertUnprocessable();
    ($this->meeting)(($this->period)('08:15','09:00'));
    $this->getJson($this->url.'?week=2026-10-14')->assertOk()->assertJsonPath('data.week_start','2026-10-12');
    $this->getJson($this->url.'?week=2027-07-05')->assertOk()->assertJsonPath('data.days',[]);
});

it('Teacher Timetable header adds only a true flag for the selected school without a new query', function () {
    $this->getJson("/api/teacher/masjids/{$this->school->id}/school")->assertOk()->assertJsonPath('data.school_timetable_enabled',true);
    $this->getJson('/api/teacher/user')->assertOk()->assertJsonPath('data.masjid.school_timetable_enabled',true);
    $this->school->forceFill(['capability_overrides'=>[]])->save();
    DB::flushQueryLog(); DB::enableQueryLog();
    $this->getJson("/api/teacher/masjids/{$this->school->id}/school")->assertOk()->assertJsonMissingPath('data.school_timetable_enabled');
    $sql=array_column(DB::getQueryLog(),'query'); DB::disableQueryLog();
    expect($sql)->toBe(json_decode(file_get_contents(base_path('tests/fixtures/teacher-schedule-off-15011d6c.json')),true)['school']['sql']);
});

it('Teacher Timetable a week crossing school years uses each dates containing year only', function () {
    $this->year->update(['last_day'=>'2026-10-13']);
    ($this->meeting)(($this->period)('09:10','09:55'));
    $new=SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Next practice year','first_day'=>'2026-10-14','last_day'=>'2027-06-25','meeting_weekdays'=>[3]]);
    ($this->meeting)(($this->period)('10:00','11:00'),['school_year_id'=>$new->id,'weekday'=>3,'effective_from'=>'2026-10-14','kind'=>'activity','class_subject_id'=>null,'activity_name'=>'Next year duty']);
    $r=$this->getJson($this->url)->assertOk();
    expect(array_column($r->json('data.days'),'date'))->toBe(['2026-10-12','2026-10-14']);
    expect(array_column($r->json('data.days.1.meetings'),'label'))->toBe(['Next year duty']);
    expect(array_column($r->json('data.today.meetings'),'label'))->toBe(['Science']);
});

it('Teacher Timetable empty named meetings and many meeting versions keep the same page query count', function () {
    $p=($this->period)('08:15','09:00'); $counts=[];
    foreach([0,1,12] as $amount) {
        if ($amount) for($i=0;$i<$amount;$i++) ($this->meeting)($p,['effective_from'=>'2026-10-19']);
        $this->teacher->unsetRelations(); DB::flushQueryLog(); DB::enableQueryLog();
        $this->getJson($this->url.'?week=2026-10-19')->assertOk();
        $counts[]=count(DB::getQueryLog()); DB::disableQueryLog();
    }
    expect($counts)->toBe([11,11,11]);
});

it('Teacher Timetable OFF literal base home header and class list payload and SQL pins', function () {
    // Faker unique state survives seed resets; pin the fixture's optional description explicitly.
    $this->class->update(['description'=>null]);
    $this->school->forceFill(['capability_overrides'=>[]])->save(); StudentAge::forget();
    $actual=[];
    foreach(['home'=>'/api/teacher/user','school'=>"/api/teacher/masjids/{$this->school->id}/school",'classes'=>"/api/teacher/masjids/{$this->school->id}/groups"] as $key=>$url) {
        DB::flushQueryLog(); DB::enableQueryLog(); $r=$this->getJson($url)->assertOk();
        $actual[$key]=['sql'=>array_column(DB::getQueryLog(),'query'),'payload'=>$r->json()]; DB::disableQueryLog();
    }
    expect($actual)->toBe(json_decode(file_get_contents(base_path('tests/fixtures/teacher-schedule-off-15011d6c.json')),true));
});
