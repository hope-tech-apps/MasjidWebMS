<?php

use App\Models\{ClassSubject, Contact, Group, GroupMembership, Masjid, MasjidUser, SchoolYear, User};
use App\Support\{CapabilityWriter, TenantContext, TimetableReader};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Auth};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-10 16:00:00', 'UTC'));
    $this->school = Masjid::create(['name'=>'Practice School', 'email'=>'practice@example.invalid', 'phone'=>'+15555550100', 'country_id'=>'1', 'city_id'=>'1', 'address'=>'Practice', 'latitude'=>0, 'longitude'=>0, 'org_type'=>'school', 'crm_enabled'=>true]);
    $this->school->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_calendar_terms'=>true,'school_timetable'=>true,'class_subjects'=>true]])->save();
    $this->admin=User::factory()->create(['type'=>'MasjidAdmin','phone'=>'+15555550101']);
    MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$this->admin->id,'role'=>'masjid-admin','is_default'=>true]);
    Sanctum::actingAs($this->admin, ['staff']);
    $this->year=SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Practice year','first_day'=>'2026-09-07','last_day'=>'2027-06-25','meeting_weekdays'=>[1,2,3,4,5]]);
    $this->base="/api/admin/masjids/{$this->school->id}/timetable/years/{$this->year->id}";
    $this->class=fn ($name='Practice Class')=>Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>$name]);
    $this->teacher=function ($name='Practice Teacher') { $t=User::factory()->create(['type'=>'Teacher','name'=>$name,'phone'=>'+1'.random_int(1000000000,9999999999)]); MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$t->id,'role'=>'teacher']); return $t; };
    $this->subject=fn ($g,$name='Science')=>ClassSubject::create(['masjid_id'=>$this->school->id,'group_id'=>$g->id,'name'=>$name]);
    $this->set=function ($name,$rows) { return $this->postJson($this->base.'/sets',['name'=>$name,'periods'=>array_map(fn($r,$i)=>['name'=>$r[0],'starts_at'=>$r[1],'ends_at'=>$r[2],'kind'=>$r[3]??'teaching','position'=>$i+1],$rows,array_keys($rows))])->assertCreated()->json('data'); };
    $this->days=fn ($id,$days,$g=null)=>$this->putJson($this->base.'/days',['group_id'=>$g?->id,'days'=>array_map(fn($d)=>['weekday'=>$d,'period_set_id'=>$id],$days)])->assertOk();
    $this->place=function ($g,$period,$day,$extra=[]) { return $this->postJson($this->base.'/meetings', $extra+['group_id'=>$g->id,'period_id'=>$period,'weekday'=>$day,'kind'=>'activity','activity_name'=>'Practice activity','teacher_ids'=>[],'effective_from'=>'2026-10-10']); };
});
afterEach(fn()=>app(TenantContext::class)->forgetTenant());

it('Timetable acceptance A full time different weekday sets specialist and nap have ordered weeks without clashes', function () {
    $regular=($this->set)('Regular', [['Morning','08:00','08:15','block'],['P1','08:15','09:00'],['P2','09:10','09:55'],['P3','10:05','10:50'],['Recess','10:50','11:20','block'],['Lunch','11:20','11:50','block'],['P4','11:50','12:35'],['P5','12:45','13:30'],['Prayer','13:45','14:00','block'],['P6','14:00','14:45']]);
    $friday=($this->set)('Friday', [['P1','08:15','09:00'],['P2','09:10','09:55'],['Recess','09:55','10:25','block'],['P3','10:25','11:10'],['P4','11:10','11:55'],['Lunch','11:55','12:25','block']]);
    ($this->days)($regular['id'],[1,2,3,4]); ($this->days)($friday['id'],[5]);
    $a=($this->class)('Practice Junior'); $b=($this->class)('Practice Senior'); $specialist=($this->teacher)('Practice Specialist'); $nap=($this->teacher)('Practice Duty');
    $juniorTeacher=($this->teacher)('Practice Junior Teacher'); $seniorTeacher=($this->teacher)('Practice Senior Teacher');
    $juniorSubject=($this->subject)($a,'Reading');
    $science=($this->subject)($b); $pe=($this->subject)($b,'PE'); $social=($this->subject)($b,'Social Studies');
    foreach([1,2,3,4] as $day) {
        foreach([1,2,3,6,9] as $i) ($this->place)($a,$regular['periods'][$i]['id'],$day,['kind'=>'subject','class_subject_id'=>$juniorSubject->id,'activity_name'=>null,'teacher_ids'=>[$juniorTeacher->id]])->assertCreated();
        foreach([1,3] as $i) ($this->place)($b,$regular['periods'][$i]['id'],$day,['kind'=>'subject','class_subject_id'=>$science->id,'activity_name'=>null,'teacher_ids'=>[$seniorTeacher->id]])->assertCreated();
        ($this->place)($a,$regular['periods'][7]['id'],$day,['activity_name'=>'Nap','teacher_ids'=>[$nap->id]])->assertCreated();
        foreach([2,6,7] as $i) ($this->place)($b,$regular['periods'][$i]['id'],$day,['kind'=>'subject','class_subject_id'=>$science->id,'activity_name'=>null,'teacher_ids'=>[$specialist->id]])->assertCreated();
        ($this->place)($b,$regular['periods'][9]['id'],$day,['kind'=>'subject','class_subject_id'=>($day===2?$science:($day===4?$social:$pe))->id,'activity_name'=>null,'teacher_ids'=>[($this->teacher)()->id]])->assertCreated();
    }
    foreach([$a,$b] as $g) foreach([0,1,3,4] as $i) ($this->place)($g,$friday['periods'][$i]['id'],5)->assertCreated();
    foreach(['2026-10-14','2026-10-16'] as $date) foreach([$a,$b] as $g) {
        $r=$this->getJson($this->base.'/week?group_id='.$g->id.'&as_of='.$date)->assertOk();
        expect(array_column($r->json('data.days'),'weekday'))->toBe([1,2,3,4,5]);
        expect(count($r->json('data.days.0.periods')))->toBe(10); expect(count($r->json('data.days.4.periods')))->toBe(6);
        expect(count(array_filter($r->json('data.meetings'),fn($m)=>$m['weekday']===5)))->toBe(4);
    }
    $wednesday=$this->getJson($this->base.'/week?group_id='.$a->id.'&as_of=2026-10-14')->assertOk()->json('data.meetings');
    $napMeeting=array_values(array_filter($wednesday,fn($m)=>$m['weekday']===3 && $m['label']==='Nap')); expect($napMeeting[0]['teachers'][0]['id'])->toBe($nap->id);
    $senior=$this->getJson($this->base.'/week?group_id='.$b->id.'&as_of=2026-10-14')->assertOk()->json('data.meetings');
    expect(array_column(array_values(array_filter($senior,fn($m)=>$m['period_id']===$regular['periods'][9]['id'])),'label'))->toBe(['PE','Science','PE','Social Studies']);
    $meetings=app(TimetableReader::class)->meetings($this->school->id,$this->year->id,'2026-10-12','teacher',$specialist->id,1);
    expect(array_column($meetings,'starts_at'))->toBe(['09:10','11:50','12:45']);
    $this->getJson($this->base.'/clashes?as_of=2026-10-12')->assertOk()->assertJsonPath('data',[]);
});

it('Timetable acceptance B twelve weekend classes finds teacher room student cross-set overlaps and allows confirmation', function () {
    $this->year->update(['first_day'=>'2026-09-06','last_day'=>'2027-06-27','meeting_weekdays'=>[0]]);
    $set=($this->set)('Sunday',[['P1','11:00','11:40'],['P2','11:40','12:20'],['Break','12:20','12:45','block'],['P3','12:45','13:30'],['Cleaning','13:30','13:40','block'],['Prayer','13:45','14:00','block']]);
    $override=($this->set)('Later',[['P1','11:00','11:45'],['P2','11:45','12:30'],['Break','12:30','12:40','block'],['P3','12:40','13:30']]); ($this->days)($set['id'],[0]);
    $groups=[]; $shared=($this->teacher)('Practice Shared'); $pupil=Contact::factory()->create(['masjid_id'=>$this->school->id,'first_name'=>'Practice','last_name'=>'Pupil']);
    for($i=0;$i<12;$i++) {
        $g=$groups[]=($this->class)('Practice '.$i); $rows=$i===11?$override['periods']:$set['periods']; if($i===11) ($this->days)($override['id'],[0],$g);
        $room=$this->postJson($this->base.'/rooms',['name'=>'Practice Room '.$i,'capacity'=>20,'active'=>true])->assertCreated()->json('data');
        $usualId=$i===1?DB::table('timetable_class_rooms')->where('group_id',$groups[0]->id)->value('room_id'):$room['id'];
        $this->putJson($this->base.'/classes/'.$g->id.'/room',['room_id'=>$usualId])->assertOk();
        if(in_array($i,[0,11])) GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$g->id,'contact_id'=>$pupil->id,'role'=>'member']);
        $subjects=array_map(fn($n)=>($this->subject)($g,$n), ['Arabic','Science','Reading']);
        $teachers=[($this->teacher)()->id,($this->teacher)()->id]; if($i===11) $teachers[] = ($this->teacher)()->id;
        foreach([0,1,3] as $k=>$row) {
            $ids=$row===0&&in_array($i,[0,11])?[$shared->id]:$teachers;
            $r=($this->place)($g,$rows[$row]['id'],0,['kind'=>'subject','activity_name'=>null,'class_subject_id'=>$subjects[($k+$i)%3]->id,'teacher_ids'=>$ids]);
            if($i===11&&$row===0) { $r->assertConflict(); expect(array_column($r->json('clashes'),'kind'))->toContain('teacher','student'); ($this->place)($g,$rows[$row]['id'],0,['kind'=>'subject','activity_name'=>null,'class_subject_id'=>$subjects[$i%3]->id,'teacher_ids'=>$ids,'confirm_clashes'=>true,'clash_fingerprint'=>$r->json('clash_fingerprint')])->assertCreated(); } elseif($i===11) { $r->assertConflict(); ($this->place)($g,$rows[$row]['id'],0,['kind'=>'subject','activity_name'=>null,'class_subject_id'=>$subjects[($k+$i)%3]->id,'teacher_ids'=>$ids,'confirm_clashes'=>true,'clash_fingerprint'=>$r->json('clash_fingerprint')])->assertCreated(); } elseif($i===1) { $r->assertConflict(); expect(array_column($r->json('clashes'),'kind'))->toContain('room'); expect(array_column($r->json('clashes.0.meetings'),'group_name'))->toContain('Practice 0','Practice 1'); ($this->place)($g,$rows[$row]['id'],0,['kind'=>'subject','activity_name'=>null,'class_subject_id'=>$subjects[($k+$i)%3]->id,'teacher_ids'=>$ids,'confirm_clashes'=>true,'clash_fingerprint'=>$r->json('clash_fingerprint')])->assertCreated(); } else $r->assertCreated();
        }
        $duty=['activity_name'=>'Break duty','teacher_ids'=>[($this->teacher)()->id]]; $warning=($this->place)($g,$rows[2]['id'],0,$duty);
        if ($warning->status()===409) ($this->place)($g,$rows[2]['id'],0,$duty+['confirm_clashes'=>true,'clash_fingerprint'=>$warning->json('clash_fingerprint')])->assertCreated(); else $warning->assertCreated();
    }
    $usual=DB::table('timetable_class_rooms')->where('group_id',$groups[0]->id)->value('room_id');
    $this->putJson($this->base.'/classes/'.$groups[1]->id.'/room',['room_id'=>$usual])->assertOk();
    $r=$this->getJson($this->base.'/clashes?as_of=2026-10-11')->assertOk(); expect(array_column($r->json('data'),'kind'))->toContain('teacher','room','student');
    expect(count(app(TimetableReader::class)->meetings($this->school->id,$this->year->id,'2026-10-11','room',(int)$usual)))->toBe(8);
    expect(count(app(TimetableReader::class)->meetings($this->school->id,$this->year->id,'2026-10-11','school')))->toBe(48);
    $nextBody=['group_id'=>$groups[2]->id,'weekday'=>0,'period_id'=>$set['periods'][1]['id'],'kind'=>'activity','activity_name'=>'Next duty','teacher_ids'=>[$shared->id],'effective_from'=>'2026-10-10'];
    $nextUrl=$this->base.'/meetings/'.DB::table('timetable_meetings')->where('group_id',$groups[2]->id)->where('period_id',$set['periods'][1]['id'])->value('id');
    $nextWarning=$this->putJson($nextUrl,$nextBody);
    if ($nextWarning->status()===409) $this->putJson($nextUrl,$nextBody+['confirm_clashes'=>true,'clash_fingerprint'=>$nextWarning->json('clash_fingerprint')])->assertOk(); else $nextWarning->assertOk();
    $clashes=app(TimetableReader::class)->clashes($this->school->id,$this->year->id,'2026-10-11');
    expect(array_filter($clashes,fn($c)=>$c['kind']==='teacher'&&in_array($groups[0]->id,array_column($c['meetings'],'group_id'))&&in_array($groups[2]->id,array_column($c['meetings'],'group_id'))))->toBe([]);
});

it('Timetable acceptance C a dated teacher change preserves old row and reads both dates', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)(); $a=($this->teacher)(); $b=($this->teacher)();
    $id=($this->place)($g,$set['periods'][0]['id'],1,['teacher_ids'=>[$a->id]])->assertCreated()->json('data.id');
    $this->putJson($this->base.'/meetings/'.$id,['group_id'=>$g->id,'period_id'=>$set['periods'][0]['id'],'weekday'=>1,'kind'=>'activity','activity_name'=>'Practice activity','teacher_ids'=>[$b->id],'effective_from'=>'2026-11-01'])->assertOk();
    expect(DB::table('timetable_meetings')->where('id',$id)->value('effective_until'))->toBe('2026-10-31');
    foreach(['2026-10-31'=>$a->id,'2026-11-01'=>$b->id] as $date=>$tid) $this->getJson($this->base.'/week?group_id='.$g->id.'&as_of='.$date)->assertOk()->assertJsonPath('data.meetings.0.teachers.0.id',$tid);
    expect(DB::table('timetable_meetings')->count())->toBe(2);
});

it('Timetable acceptance D whole class works without class subjects and subject placement refuses in words', function () {
    $g=($this->class)(); $subject=($this->subject)($g); $this->school->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_timetable'=>true]])->save();
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]);
    ($this->place)($g,$set['periods'][0]['id'],1,['kind'=>'class','activity_name'=>null,'teacher_ids'=>[($this->teacher)()->id]])->assertCreated();
    ($this->place)($g,$set['periods'][0]['id'],1,['kind'=>'subject','activity_name'=>null,'class_subject_id'=>$subject->id,'teacher_ids'=>[($this->teacher)()->id]])->assertUnprocessable()->assertJsonPath('data.kind.0','Switch on class subjects before placing a subject.');
});

it('Timetable setup rejects overlapping times bad weekdays duplicates subject blocks and empty subject teachers', function () {
    $this->postJson($this->base.'/sets',['name'=>'Bad','periods'=>[['name'=>'One','starts_at'=>'08:00','ends_at'=>'09:00','kind'=>'teaching','position'=>1],['name'=>'Two','starts_at'=>'08:30','ends_at'=>'09:30','kind'=>'block','position'=>2]]])->assertUnprocessable();
    $set=($this->set)('Regular',[['Break','08:00','09:00','block']]); $g=($this->class)(); $sub=($this->subject)($g); ($this->days)($set['id'],[1]);
    $this->putJson($this->base.'/days',['days'=>[['weekday'=>0,'period_set_id'=>$set['id']]]])->assertUnprocessable();
    ($this->place)($g,$set['periods'][0]['id'],1,['kind'=>'subject','activity_name'=>null,'class_subject_id'=>$sub->id,'teacher_ids'=>[]])->assertUnprocessable();
    ($this->place)($g,$set['periods'][0]['id'],1)->assertCreated(); ($this->place)($g,$set['periods'][0]['id'],1,['confirm_clashes'=>true])->assertUnprocessable();
    $this->deleteJson($this->base.'/sets/'.$set['id'])->assertUnprocessable()->assertJsonPath('data.set.0','This period set has 1 meeting. Remove its meetings first.');
    $this->putJson($this->base.'/sets/'.$set['id'],['name'=>'Regular','periods'=>[]])->assertUnprocessable();
});

it('Timetable capability needs school calendar for single bulk and creation writers and hides new routes while off', function () {
    $this->school->forceFill(['capability_overrides'=>[]])->save();
    try { CapabilityWriter::apply($this->school,['school_timetable'=>true],$this->admin->id); $this->fail('allowed without calendar'); } catch(\Illuminate\Validation\ValidationException $e) { expect($e->errors()['capability'][0])->toContain('calendar'); }
    $this->getJson($this->base.'/setup')->assertNotFound();
    foreach([fn()=>CapabilityWriter::apply($this->school,['school_calendar_terms'=>true,'school_timetable'=>true],$this->admin->id), fn()=>CapabilityWriter::applyAtCreation(Masjid::make(['org_type'=>'school']),['school_timetable'=>true],$this->admin->id)] as $write) { try { $write(); $this->fail('Allowed timetable without calendar'); } catch(\Illuminate\Validation\ValidationException $e) { expect($e->errors()['capability'][0])->toContain('calendar'); } }
    $this->school->forceFill(['org_type'=>'community','capability_overrides'=>['school_calendar'=>true]])->save();
    try { CapabilityWriter::apply($this->school,['school_timetable'=>true],$this->admin->id); $this->fail('allowed outside school'); } catch(\Illuminate\Validation\ValidationException $e) { expect($e->errors()['capability'][0])->toContain('school'); }
    $this->school->forceFill(['org_type'=>'school','capability_overrides'=>['school_calendar'=>true]])->save();
    $super=User::factory()->create(['type'=>'SuperAdmin','name'=>'Practice Operator','phone'=>'+15555550300']); Sanctum::actingAs($super,['staff']);
    $caps="/api/admin/masjids/{$this->school->id}/capabilities";
    $this->patchJson($caps.'/school_timetable',['enabled'=>true])->assertOk()->assertJsonPath('data.capabilities.school_timetable',true);
    $this->getJson($this->base.'/setup')->assertOk()->assertJsonPath('data.weekdays',[1]);
    $this->patchJson($caps.'/school_calendar',['enabled'=>false])->assertUnprocessable();
    $this->patchJson($caps,['capabilities'=>['school_timetable'=>false,'school_calendar'=>false]])->assertOk();
    $this->getJson($this->base.'/setup')->assertNotFound();
});

it('Timetable bulk readers and clash counts are fixed at two and twelve classes for all four lenses', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $t=($this->teacher)(); $g=null;
    $counts=[];
    for($i=1;$i<=12;$i++) {
        $g=($this->class)(); $warning=($this->place)($g,$set['periods'][0]['id'],1,['teacher_ids'=>[$t->id]]);
        if ($warning->status()===409) ($this->place)($g,$set['periods'][0]['id'],1,['teacher_ids'=>[$t->id],'confirm_clashes'=>true,'clash_fingerprint'=>$warning->json('clash_fingerprint')])->assertCreated(); else $warning->assertCreated();
        if(!in_array($i,[2,12])) continue;
        foreach(['teacher','class','room','school','clashes'] as $lens) {
            DB::flushQueryLog(); DB::enableQueryLog(); $reader=app(TimetableReader::class);
            $lens==='clashes'?$reader->clashes($this->school->id,$this->year->id,'2026-10-12'):$reader->meetings($this->school->id,$this->year->id,'2026-10-12',$lens,$lens==='teacher'?$t->id:($lens==='class'?$g->id:999));
            $counts[$i][$lens]=count(DB::getQueryLog()); DB::disableQueryLog();
        }
    }
    expect($counts[2])->toBe($counts[12])->toBe(['teacher'=>2,'class'=>2,'room'=>2,'school'=>2,'clashes'=>3]);
});

it('Timetable all routes fence teacher family foreign year class period set room subject and staff ids', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)(); $teacher=($this->teacher)();
    $id=($this->place)($g,$set['periods'][0]['id'],1)->assertCreated()->json('data.id');
    $room=$this->postJson($this->base.'/rooms',['name'=>'Practice Room'])->assertCreated()->json('data.id');
    $calls=[['GET','/setup',[]],['GET','/week?group_id='.$g->id,[]],['GET','/clashes',[]],['POST','/sets',['name'=>'Bad','periods'=>[]]],['PUT','/sets/'.$set['id'],['name'=>'Bad','periods'=>[]]],['DELETE','/sets/'.$set['id'],[]],['PUT','/days',['days'=>[]]],['POST','/rooms',['name'=>'Bad']],['PUT','/rooms/'.$room,['name'=>'Bad']],['DELETE','/rooms/'.$room,[]],['PUT','/classes/'.$g->id.'/room',['room_id'=>null]],['POST','/meetings',[]],['PUT','/meetings/'.$id,[]],['DELETE','/meetings/'.$id,['effective_from'=>'2026-10-10']],['POST','/copy-day',[]]];
    $family=Contact::factory()->create(['masjid_id'=>$this->school->id,'login_email'=>'family@example.invalid','login_enabled_at'=>now()]);
    foreach([$teacher->createToken('practice',['staff'])->plainTextToken,$family->createToken('practice',['family'])->plainTextToken] as $token) {
        Auth::forgetGuards(); $this->withToken($token); foreach($calls as [$method,$suffix,$body]) expect($this->json($method,$this->base.$suffix,$body)->status())->toBeIn([401,403]);
        expect($this->getJson("/api/admin/masjids/{$this->school->id}/timetable")->status())->toBeIn([401,403]);
    }
    Auth::forgetGuards(); Sanctum::actingAs($this->admin,['staff']);
    $foreign=Masjid::create(['name'=>'Other Practice School','email'=>'other@example.invalid','phone'=>'+15555550109','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
    $fg=app(TenantContext::class)->runWithout(fn()=>Group::factory()->create(['masjid_id'=>$foreign->id,'kind'=>'class']));
    $ft=User::factory()->create(['type'=>'Teacher','phone'=>'+15555550150']); MasjidUser::create(['masjid_id'=>$foreign->id,'user_id'=>$ft->id,'role'=>'teacher']);
    $subject=($this->subject)($g); $foreignSubject=app(TenantContext::class)->runWithout(fn()=>ClassSubject::create(['masjid_id'=>$foreign->id,'group_id'=>$fg->id,'name'=>'Practice Foreign']));
    foreach(['group_id'=>$fg->id,'period_id'=>999999,'class_subject_id'=>$foreignSubject->id,'room_id'=>999999,'teacher_ids'=>[$ft->id]] as $key=>$value) {
        $extra=[$key=>$value]; if($key==='class_subject_id') $extra+=['kind'=>'subject','activity_name'=>null,'teacher_ids'=>[$teacher->id]];
        ($this->place)($g,$set['periods'][0]['id'],1,$extra)->assertNotFound();
    }
    $this->getJson($this->base.'/week?group_id='.$fg->id)->assertNotFound();
    foreach(['/sets/999999','/rooms/999999','/meetings/999999'] as $suffix) { $this->putJson($this->base.$suffix,[])->assertNotFound(); $this->deleteJson($this->base.$suffix,[])->assertNotFound(); }
    $this->putJson($this->base.'/classes/'.$fg->id.'/room',['room_id'=>null])->assertNotFound();
    $this->putJson($this->base.'/classes/'.$g->id.'/room',['room_id'=>999999])->assertNotFound();
    $this->putJson($this->base.'/days',['days'=>[['weekday'=>1,'period_set_id'=>999999]]])->assertNotFound();
    $foreignYear=app(TenantContext::class)->runWithout(fn()=>SchoolYear::create(['masjid_id'=>$foreign->id,'label'=>'Other Practice year','first_day'=>'2026-09-07','last_day'=>'2027-06-25']));
    foreach($calls as [$method,$suffix,$body]) { if($suffix==='/sets' && $method==='POST') $body=['name'=>'Practice','periods'=>[['name'=>'P1','starts_at'=>'08:00','ends_at'=>'09:00','kind'=>'teaching','position'=>1]]]; $this->json($method,str_replace('/years/'.$this->year->id,'/years/'.$foreignYear->id,$this->base).$suffix,$body)->assertNotFound(); }
});

it('Timetable dated remove edit in place copy atomic duplicates and room case uniqueness hold', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1,2]); $g=($this->class)();
    $id=($this->place)($g,$set['periods'][0]['id'],1)->assertCreated()->json('data.id');
    $this->putJson($this->base.'/meetings/'.$id,['group_id'=>$g->id,'period_id'=>$set['periods'][0]['id'],'weekday'=>1,'kind'=>'activity','activity_name'=>'Revised','teacher_ids'=>[],'effective_from'=>'2026-10-10'])->assertOk(); expect(DB::table('timetable_meetings')->count())->toBe(1);
    $this->postJson($this->base.'/copy-day',['group_id'=>$g->id,'source_weekday'=>1,'target_weekdays'=>[2],'as_of'=>'2026-10-10','effective_from'=>'2026-10-10'])->assertCreated();
    $this->postJson($this->base.'/copy-day',['group_id'=>$g->id,'source_weekday'=>1,'target_weekdays'=>[2],'as_of'=>'2026-10-10','effective_from'=>'2026-10-10'])->assertUnprocessable(); expect(DB::table('timetable_meetings')->count())->toBe(2);
    $this->deleteJson($this->base.'/meetings/'.$id,['effective_from'=>'2026-11-01'])->assertOk(); expect(DB::table('timetable_meetings')->where('id',$id)->value('effective_until'))->toBe('2026-10-31');
    ($this->place)($g,$set['periods'][0]['id'],1,['effective_from'=>'2026-09-01'])->assertUnprocessable();
    $this->postJson($this->base.'/rooms',['name'=>'Hall'])->assertCreated(); $this->postJson($this->base.'/rooms',['name'=>'hall'])->assertUnprocessable();
});

it('Timetable referenced class subject room staff school membership deletes refuse and keep records', function () {
    $g=($this->class)(); $t=($this->teacher)(); $s=($this->subject)($g); $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]);
    $room=$this->postJson($this->base.'/rooms',['name'=>'Practice Room'])->assertCreated()->json('data.id'); $this->putJson($this->base.'/classes/'.$g->id.'/room',['room_id'=>$room])->assertOk();
    ($this->place)($g,$set['periods'][0]['id'],1,['kind'=>'subject','activity_name'=>null,'class_subject_id'=>$s->id,'teacher_ids'=>[$t->id]])->assertCreated();
    $this->deleteJson($this->base.'/rooms/'.$room)->assertUnprocessable();
    foreach([$g,$s,$t,MasjidUser::where('masjid_id',$this->school->id)->where('user_id',$t->id)->first()] as $model) {
        try { $model->delete(); $this->fail('Deleted timetable reference'); } catch(\Illuminate\Validation\ValidationException $e) { expect(json_encode($e->errors()))->toContain('meeting'); }
    }
    $this->deleteJson("/api/admin/masjids/{$this->school->id}/teachers/{$t->id}")->assertUnprocessable();
});

it('Timetable all eight scoped models refuse foreign reads writes deletes and stamp the bound tenant', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)(); $t=($this->teacher)();
    $room=$this->postJson($this->base.'/rooms',['name'=>'Practice Hall'])->assertCreated()->json('data.id'); $this->putJson($this->base.'/classes/'.$g->id.'/room',['room_id'=>$room])->assertOk(); ($this->days)($set['id'],[1],$g);
    ($this->place)($g,$set['periods'][0]['id'],1,['teacher_ids'=>[$t->id]])->assertCreated();
    $other=Masjid::create(['name'=>'Other Practice School','email'=>'other2@example.invalid','phone'=>'+15555550209','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    foreach([\App\Models\TimetablePeriodSet::class,\App\Models\TimetablePeriod::class,\App\Models\TimetableDay::class,\App\Models\TimetableClassDay::class,\App\Models\TimetableRoom::class,\App\Models\TimetableClassRoom::class,\App\Models\TimetableMeeting::class,\App\Models\TimetableMeetingTeacher::class] as $model) {
        $attrs=$model::firstOrFail()->getAttributes(); unset($attrs['id'],$attrs['created_at'],$attrs['updated_at']); $attrs['masjid_id']=$other->id;
        if($model===\App\Models\TimetableMeetingTeacher::class) $attrs['user_id']=($this->teacher)()->id;
        $foreign=app(TenantContext::class)->runWithout(fn()=>$model::create($attrs)); app(TenantContext::class)->set($this->school->id);
        $this->assertNull($model::find($foreign->id)); $this->assertSame(0,$model::whereKey($foreign->id)->update(['updated_at'=>now()])); $this->assertSame(0,$model::whereKey($foreign->id)->delete());
        app(TenantContext::class)->set($other->id); $this->assertSame($other->id,(int)$model::findOrFail($foreign->id)->masjid_id);
        app(TenantContext::class)->set($this->school->id);
    }
});

it('Timetable changing school weekday set moves meetings by row position for the whole year and rejects missing slots', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); $later=($this->set)('Later',[['P1','09:00','10:00']]); ($this->days)($set['id'],[1]); $g=($this->class)();
    ($this->place)($g,$set['periods'][0]['id'],1)->assertCreated(); ($this->days)($later['id'],[1]);
    foreach(['2026-10-10','2026-11-01'] as $date) $this->getJson($this->base.'/week?group_id='.$g->id.'&as_of='.$date)->assertOk()->assertJsonPath('data.meetings.0.starts_at','09:00');
    $longer=($this->set)('Two periods',[['P1','09:00','10:00'],['P2','10:00','11:00']]); ($this->days)($longer['id'],[1]); ($this->place)($g,$longer['periods'][1]['id'],1)->assertCreated();
    $this->putJson($this->base.'/days',['days'=>[['weekday'=>1,'period_set_id'=>$later['id']]]])->assertUnprocessable();
});

it('Timetable student roster intersection excludes adjacent leaving and arriving dates and future membership', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $a=($this->class)(); $b=($this->class)(); $c=Contact::factory()->create(['masjid_id'=>$this->school->id]);
    $ma=GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$a->id,'contact_id'=>$c->id,'role'=>'member']); $ma->forceFill(['created_at'=>'2026-09-07','left_on'=>'2026-10-12'])->save();
    $mb=GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$b->id,'contact_id'=>$c->id,'role'=>'member']); $mb->forceFill(['created_at'=>'2026-10-12'])->save();
    ($this->place)($a,$set['periods'][0]['id'],1)->assertCreated(); ($this->place)($b,$set['periods'][0]['id'],1)->assertCreated();
    $this->getJson($this->base.'/clashes?as_of=2026-10-12')->assertOk()->assertJsonPath('data',[]);
});

it('Timetable hiding a class subject ends its meetings today while preserving earlier timetable', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)(); $s=($this->subject)($g);
    $id=($this->place)($g,$set['periods'][0]['id'],1,['kind'=>'subject','class_subject_id'=>$s->id,'activity_name'=>null,'teacher_ids'=>[($this->teacher)()->id],'effective_from'=>'2026-09-07'])->assertCreated()->json('data.id');
    $this->deleteJson("/api/admin/masjids/{$this->school->id}/groups/{$g->id}/subjects/{$s->id}")->assertOk();
    $this->getJson($this->base.'/week?group_id='.$g->id.'&as_of=2026-10-09')->assertOk()->assertJsonPath('data.meetings.0.id',$id);
    $this->getJson($this->base.'/week?group_id='.$g->id.'&as_of=2026-10-10')->assertOk()->assertJsonPath('data.meetings',[]);
});

it('Timetable every new route is a 404 while the school switch is off', function () {
    $this->school->forceFill(['capability_overrides'=>['school_calendar'=>true]])->save();
    foreach([['GET','/setup'],['GET','/week?group_id=999'],['GET','/clashes'],['POST','/sets'],['PUT','/sets/999'],['DELETE','/sets/999'],['PUT','/days'],['POST','/rooms'],['PUT','/rooms/999'],['DELETE','/rooms/999'],['PUT','/classes/999/room'],['POST','/meetings'],['PUT','/meetings/999'],['DELETE','/meetings/999'],['POST','/copy-day']] as [$method,$suffix]) $this->json($method,$this->base.$suffix,[])->assertNotFound();
    $this->getJson("/api/admin/masjids/{$this->school->id}/timetable")->assertNotFound();
});

it('Timetable HTTP setup week and clashes have constant query counts at two and twelve classes', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $counts=[]; $g=null;
    for($i=1;$i<=12;$i++) {
        $g=($this->class)(); ($this->place)($g,$set['periods'][0]['id'],1)->assertCreated();
        if(!in_array($i,[2,12])) continue;
        foreach(['setup'=>'/setup','week'=>'/week?group_id='.$g->id.'&as_of=2026-10-12','clashes'=>'/clashes?as_of=2026-10-12'] as $name=>$suffix) {
            $this->getJson($this->base.$suffix)->assertOk(); DB::flushQueryLog(); DB::enableQueryLog();
            $this->getJson($this->base.$suffix)->assertOk(); $counts[$i][$name]=count(DB::getQueryLog()); DB::disableQueryLog();
        }
    }
    file_put_contents(base_path('artifacts/timetable-http-query-counts.json'),json_encode($counts,JSON_PRETTY_PRINT)."\n");
    expect($counts[2])->toBe($counts[12])->toBe(['setup'=>19,'week'=>11,'clashes'=>7]);
});

it('Timetable changing a referenced teacher away from teacher access is a refused removal', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)(); $t=($this->teacher)();
    ($this->place)($g,$set['periods'][0]['id'],1,['teacher_ids'=>[$t->id]])->assertCreated();
    try { $t->forceFill(['type'=>'MasjidAdmin'])->save(); $this->fail('Changed a referenced teacher’s access'); }
    catch(\Illuminate\Validation\ValidationException $e) { expect(json_encode($e->errors()))->toContain('meeting'); }
    expect($t->fresh()->type)->toBe('Teacher');
    $other=Masjid::create(['name'=>'Practice Second School','email'=>'second@example.invalid','phone'=>'+15555550402','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    $other->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_timetable'=>true]])->save();
    app(TenantContext::class)->set($other->id);
    try { $t->fresh()->forceFill(['type'=>'MasjidAdmin'])->save(); $this->fail('Changed global teacher access from another timetable school'); }
    catch(\Illuminate\Validation\ValidationException $e) { expect(json_encode($e->errors()))->toContain('meeting'); }
    expect($t->fresh()->type)->toBe('Teacher');
});

it('Timetable changes before an original start edit its row from the chosen date and removal closes the chosen day before', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)();
    $id=($this->place)($g,$set['periods'][0]['id'],1)->assertCreated()->json('data.id');
    $this->putJson($this->base.'/meetings/'.$id,['group_id'=>$g->id,'weekday'=>1,'period_id'=>$set['periods'][0]['id'],'kind'=>'activity','activity_name'=>'Earlier routine','teacher_ids'=>[],'effective_from'=>'2026-10-01'])->assertOk();
    expect(DB::table('timetable_meetings')->count())->toBe(1); expect(DB::table('timetable_meetings')->where('id',$id)->value('effective_from'))->toBe('2026-10-01');
    $this->deleteJson($this->base.'/meetings/'.$id,['effective_from'=>'2026-09-15'])->assertOk();
    expect(DB::table('timetable_meetings')->where('id',$id)->value('effective_until'))->toBe('2026-09-14');
});

it('Timetable regression closed removal only shortens and change after its end refuses leaving one live slot', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)();
    $a=($this->place)($g,$set['periods'][0]['id'],1,['activity_name'=>'A'])->assertCreated()->json('data.id');
    $body=['group_id'=>$g->id,'period_id'=>$set['periods'][0]['id'],'weekday'=>1,'kind'=>'activity','activity_name'=>'B','teacher_ids'=>[],'effective_from'=>'2026-11-01'];
    $b=$this->putJson($this->base.'/meetings/'.$a,$body)->assertOk()->json('data.id');
    $this->deleteJson($this->base.'/meetings/'.$a,['effective_from'=>'2026-12-01'])->assertOk();
    expect(DB::table('timetable_meetings')->where('id',$a)->value('effective_until'))->toBe('2026-10-31');
    $this->getJson($this->base.'/week?group_id='.$g->id.'&as_of=2026-11-16')->assertOk()->assertJsonCount(1,'data.meetings')->assertJsonPath('data.meetings.0.id',$b);
    $this->putJson($this->base.'/meetings/'.$a,array_replace($body,['effective_from'=>'2026-12-01','effective_until'=>'2027-06-25']))->assertUnprocessable()->assertJsonPath('data.effective_from.0','This meeting has already ended before that date. Choose the meeting live on that date.');
    $this->getJson($this->base.'/week?group_id='.$g->id.'&as_of=2026-12-07')->assertOk()->assertJsonCount(1,'data.meetings')->assertJsonPath('data.meetings.0.id',$b);
});

it('Timetable regression confirmation fingerprints refuse new clashes and changed submitted bodies', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $t=($this->teacher)();
    $a=($this->class)('Practice A'); $b=($this->class)('Practice B'); $c=($this->class)('Practice C');
    ($this->place)($a,$set['periods'][0]['id'],1,['teacher_ids'=>[$t->id]])->assertCreated();
    $extra=['teacher_ids'=>[$t->id]];
    $seen=($this->place)($b,$set['periods'][0]['id'],1,$extra)->assertConflict(); expect($seen->json('clash_fingerprint'))->toBeString();
    $cw=($this->place)($c,$set['periods'][0]['id'],1,$extra)->assertConflict();
    ($this->place)($c,$set['periods'][0]['id'],1,$extra+['confirm_clashes'=>true,'clash_fingerprint'=>$cw->json('clash_fingerprint')])->assertCreated();
    $fresh=($this->place)($b,$set['periods'][0]['id'],1,$extra+['confirm_clashes'=>true,'clash_fingerprint'=>$seen->json('clash_fingerprint')])->assertConflict()->assertJsonCount(2,'clashes');
    expect($fresh->json('clash_fingerprint'))->not->toBe($seen->json('clash_fingerprint'));
    expect(DB::table('timetable_meetings')->where('group_id',$b->id)->count())->toBe(0);
    ($this->place)($b,$set['periods'][0]['id'],1,$extra+['confirm_clashes'=>true,'clash_fingerprint'=>$fresh->json('clash_fingerprint'),'activity_name'=>'Changed body'])->assertConflict();
    ($this->place)($b,$set['periods'][0]['id'],1,$extra+['confirm_clashes'=>true,'clash_fingerprint'=>$fresh->json('clash_fingerprint')])->assertCreated();
});

it('Timetable regression recorded roster joining dates bound student clashes in both directions with move fallback', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $a=($this->class)('Practice A'); $b=($this->class)('Practice B');
    $c=Contact::factory()->create(['masjid_id'=>$this->school->id]); $rows=[];
    foreach([$a,$b] as $g) { $r=GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$g->id,'contact_id'=>$c->id,'role'=>'member','joined_at'=>'2026-11-01']); $r->forceFill(['created_at'=>'2026-10-10','moved_from_group_id'=>$a->id,'moved_on'=>'2026-10-10'])->save(); $rows[]=$r; }
    ($this->place)($a,$set['periods'][0]['id'],1,['effective_from'=>'2026-09-07'])->assertCreated();
    $w=($this->place)($b,$set['periods'][0]['id'],1,['effective_from'=>'2026-09-07'])->assertConflict()->assertJsonPath('clashes.0.effective_from','2026-11-01');
    ($this->place)($b,$set['periods'][0]['id'],1,['effective_from'=>'2026-09-07','confirm_clashes'=>true,'clash_fingerprint'=>$w->json('clash_fingerprint')])->assertCreated();
    $this->getJson($this->base.'/clashes?as_of=2026-10-12')->assertOk()->assertJsonPath('data',[]);
    foreach($rows as $r) $r->update(['joined_at'=>'2026-09-07']);
    $this->getJson($this->base.'/clashes?as_of=2026-09-14')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.effective_from','2026-09-07');
    foreach($rows as $r) $r->update(['joined_at'=>null]);
    $this->getJson($this->base.'/clashes?as_of=2026-09-14')->assertOk()->assertJsonPath('data',[]);
    $this->getJson($this->base.'/clashes?as_of=2026-10-12')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.effective_from','2026-10-10');
});

it('Timetable regression teacher removal locks organisation first with class subjects off', function () {
    $this->school->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_timetable'=>true]])->save();
    $t=($this->teacher)();
    $other=Masjid::create(['name'=>'Other Practice School','email'=>'teacherother@example.invalid','phone'=>'+15555550909','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    MasjidUser::create(['masjid_id'=>$other->id,'user_id'=>$t->id,'role'=>'teacher']);
    $statements=[];
    DB::listen(function ($q) use (&$statements) { $statements[]=$q->sql; });
    $transactionFirst=[];
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class,function () use (&$statements,&$transactionFirst) { $statements=[]; $transactionFirst[]=&$statements; });
    $this->deleteJson("/api/admin/masjids/{$this->school->id}/teachers/{$t->id}")->assertOk();
    expect(User::find($t->id))->not->toBeNull();
    expect($statements[0])->toContain('"masjids"')->not->toContain('"users"');
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)();
    ($this->place)($g,$set['periods'][0]['id'],1,['teacher_ids'=>[$t->id]])->assertNotFound();
});

it('Timetable regression retention follows rows with the switch off for year class subject room and account', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)(); $s=($this->subject)($g); $t=($this->teacher)();
    $room=$this->postJson($this->base.'/rooms',['name'=>'Practice Hall'])->assertCreated()->json('data.id');
    ($this->place)($g,$set['periods'][0]['id'],1,['kind'=>'subject','activity_name'=>null,'class_subject_id'=>$s->id,'room_id'=>$room,'teacher_ids'=>[$t->id]])->assertCreated();
    CapabilityWriter::apply($this->school,['school_timetable'=>false],$this->admin->id);
    foreach([$this->year,$g,$s,\App\Models\TimetableRoom::findOrFail($room),$t] as $model) {
        try { $model->fresh()->forceDelete(); $this->fail('Deleted held timetable record'); }
        catch(\Illuminate\Validation\ValidationException $e) { expect(json_encode($e->errors()))->toContain('timetable'); }
    }
});

it('Timetable regression reenable ends invalid subject class teacher weekday and mapping meetings and reports count', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1,2]);
    $unmapped=($this->set)('Unmapped',[['P1','09:00','10:00']]);
    $ids=[]; $groups=[]; $subjects=[]; $teachers=[];
    foreach(['Hidden subject','Archived class','Removed teacher','Removed weekday','Removed mapping','Valid'] as $i=>$name) {
        $g=$groups[]=($this->class)('Practice '.$name); $t=$teachers[]=($this->teacher)(); $s=$subjects[]=($this->subject)($g);
        $ids[]=($this->place)($g,$set['periods'][0]['id'],$i===3?2:1,['kind'=>'subject','activity_name'=>null,'class_subject_id'=>$s->id,'teacher_ids'=>[$t->id],'effective_from'=>'2026-09-07'])->assertCreated()->json('data.id');
    }
    CapabilityWriter::apply($this->school,['school_timetable'=>false],$this->admin->id);
    $this->deleteJson("/api/admin/masjids/{$this->school->id}/groups/{$groups[0]->id}/subjects/{$subjects[0]->id}")->assertOk();
    // Other legacy inconsistencies can predate the new retention guards.
    DB::table('groups')->where('id',$groups[1]->id)->update(['is_active'=>false,'deleted_at'=>now()]);
    DB::table('masjid_user')->where('masjid_id',$this->school->id)->where('user_id',$teachers[2]->id)->delete();
    DB::table('school_years')->where('id',$this->year->id)->update(['meeting_weekdays'=>json_encode([1,3,4,5])]);
    DB::table('timetable_class_days')->insert(['masjid_id'=>$this->school->id,'school_year_id'=>$this->year->id,'group_id'=>$groups[4]->id,'weekday'=>1,'period_set_id'=>$unmapped['id']]);
    $super=User::factory()->create(['type'=>'SuperAdmin','phone'=>'+15555550800']); Sanctum::actingAs($super,['staff']);
    $r=$this->patchJson("/api/admin/masjids/{$this->school->id}/capabilities/school_timetable",['enabled'=>true])->assertOk();
    expect($r->json('data.timetable_ended_meetings'))->toBe(5);
    expect(DB::table('timetable_meetings')->whereIn('id',array_slice($ids,0,5))->pluck('effective_until')->all())->toBe(array_fill(0,5,'2026-10-09'));
    expect(DB::table('timetable_meetings')->where('id',$ids[5])->value('effective_until'))->toBeNull();
    expect(app(TimetableReader::class)->meetings($this->school->id,$this->year->id,'2026-10-12'))->toHaveCount(1);
    expect(app(TimetableReader::class)->meetings($this->school->id,$this->year->id,'2026-09-14'))->toHaveCount(6);
});

it('Timetable regression class location creates selects and clears atomically with no setup or year', function () {
    $this->year->delete();
    $g=($this->class)(); $url="/api/admin/masjids/{$this->school->id}/groups/{$g->id}/location";
    $this->getJson($url)->assertOk()->assertJsonPath('data.locations',[])->assertJsonPath('data.location',null);
    $r=$this->putJson($url,['name'=>'Practice Hall'])->assertOk(); $id=$r->json('data.location.id'); expect($id)->toBeInt();
    $this->putJson($url,['name'=>'practice HALL'])->assertOk()->assertJsonPath('data.location.id',$id); expect(DB::table('timetable_rooms')->count())->toBe(1);
    $this->putJson($url,['room_id'=>$id])->assertOk()->assertJsonPath('data.location.name','Practice Hall');
    $this->putJson($url,['name'=>str_repeat('x',61)])->assertUnprocessable(); expect(DB::table('timetable_class_rooms')->where('group_id',$g->id)->value('room_id'))->toBe($id);
    $this->putJson($url,['name'=>''])->assertOk()->assertJsonPath('data.location',null); expect(DB::table('timetable_rooms')->count())->toBe(1);
    CapabilityWriter::apply($this->school,['school_timetable'=>false],$this->admin->id);
    $this->getJson($url)->assertNotFound(); $this->putJson($url,['name'=>'Hidden'])->assertNotFound();
});

it('Timetable copy rolls back after an earlier tentative insert before a later duplicate', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00'],['P2','09:00','10:00']]); ($this->days)($set['id'],[1,2]); $g=($this->class)();
    foreach($set['periods'] as $p) ($this->place)($g,$p['id'],1)->assertCreated();
    ($this->place)($g,$set['periods'][1]['id'],2)->assertCreated();
    $tentative=[];
    \App\Models\TimetableMeeting::created(function ($m) use (&$tentative) { $tentative[]=['weekday'=>(int)$m->weekday,'period_id'=>(int)$m->period_id,'visible'=>DB::table('timetable_meetings')->where('id',$m->id)->exists()]; });
    $before=DB::table('timetable_meetings')->orderBy('id')->get()->toArray();
    $this->postJson($this->base.'/copy-day',['group_id'=>$g->id,'source_weekday'=>1,'target_weekdays'=>[2],'as_of'=>'2026-10-10','effective_from'=>'2026-10-10'])->assertUnprocessable();
    expect($tentative)->toBe([['weekday'=>2,'period_id'=>$set['periods'][0]['id'],'visible'=>true]]);
    expect(DB::table('timetable_meetings')->orderBy('id')->get()->toArray())->toEqual($before);
});

it('Timetable reenable handles a missing subject or lost mapping and never extends already ended rows', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]);
    $g=($this->class)(); $h=($this->class)('Practice Other'); $s=($this->subject)($g); $t=($this->teacher)();
    ($this->days)($set['id'],[3],$h);
    $missing=($this->place)($g,$set['periods'][0]['id'],1,['kind'=>'subject','class_subject_id'=>$s->id,'activity_name'=>null,'teacher_ids'=>[$t->id],'effective_from'=>'2026-09-07'])->assertCreated()->json('data.id');
    $mapping=($this->place)($h,$set['periods'][0]['id'],3,['effective_from'=>'2026-11-01'])->assertCreated()->json('data.id');
    $ended=($this->place)($h,$set['periods'][0]['id'],1,['effective_from'=>'2026-09-07','effective_until'=>'2026-10-01'])->assertCreated()->json('data.id');
    CapabilityWriter::apply($this->school,['school_timetable'=>false],$this->admin->id);
    DB::table('timetable_meetings')->where('id',$missing)->update(['class_subject_id'=>null]);
    DB::table('timetable_class_days')->where('group_id',$h->id)->delete();
    $outcome=CapabilityWriter::apply($this->school,['school_timetable'=>true,'school_calendar_terms'=>true],$this->admin->id);
    expect($outcome['timetable_ended_meetings'])->toBe(2);
    expect(DB::table('timetable_meetings')->whereIn('id',[$missing,$mapping])->pluck('effective_until')->all())->toBe(['2026-10-09','2026-10-09']);
    expect(DB::table('timetable_meetings')->where('id',$ended)->value('effective_until'))->toBe('2026-10-01');
});

it('Timetable class location refuses other school ids teachers families and nonclass groups', function () {
    $g=($this->class)(); $url="/api/admin/masjids/{$this->school->id}/groups/{$g->id}/location";
    $other=Masjid::create(['name'=>'Other Practice School','email'=>'otherloc@example.invalid','phone'=>'+15555550809','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    [$fg,$room]=app(TenantContext::class)->runWithout(fn()=>[Group::factory()->create(['masjid_id'=>$other->id,'kind'=>'class']),\App\Models\TimetableRoom::create(['masjid_id'=>$other->id,'name'=>'Other Hall','name_key'=>'other hall','active'=>true])]);
    $this->putJson($url,['room_id'=>$room->id])->assertNotFound();
    $foreign=str_replace('/groups/'.$g->id,'/groups/'.$fg->id,$url); $this->getJson($foreign)->assertNotFound(); $this->putJson($foreign,['name'=>'New'])->assertNotFound();
    $general=Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'general']); $this->getJson(str_replace('/groups/'.$g->id,'/groups/'.$general->id,$url))->assertNotFound();
    $t=($this->teacher)(); $family=Contact::factory()->create(['masjid_id'=>$this->school->id,'login_email'=>'familyloc@example.invalid','login_enabled_at'=>now()]);
    foreach([$t->createToken('practice',['staff'])->plainTextToken,$family->createToken('practice',['family'])->plainTextToken] as $token) {
        Auth::forgetGuards(); $this->withToken($token);
        foreach(['GET','PUT'] as $verb) expect($this->json($verb,$url,['name'=>'Foreign'])->status())->toBeIn([401,403]);
    }
});

it('Timetable confirmation returns a fresh empty list when the reviewed clashes disappear', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $a=($this->class)('Practice A'); $b=($this->class)('Practice B'); $t=($this->teacher)();
    $id=($this->place)($a,$set['periods'][0]['id'],1,['teacher_ids'=>[$t->id]])->assertCreated()->json('data.id');
    $warning=($this->place)($b,$set['periods'][0]['id'],1,['teacher_ids'=>[$t->id]])->assertConflict();
    $this->deleteJson($this->base.'/meetings/'.$id,['effective_from'=>'2026-10-10'])->assertOk();
    ($this->place)($b,$set['periods'][0]['id'],1,['teacher_ids'=>[$t->id],'confirm_clashes'=>true,'clash_fingerprint'=>$warning->json('clash_fingerprint')])->assertConflict()->assertJsonPath('clashes',[]);
    expect(DB::table('timetable_meetings')->where('group_id',$b->id)->count())->toBe(0);
});

it('Timetable zero location slot payloads name their class including whole class writes and date reads', function () {
    $this->school->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_timetable'=>true]])->save();
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)('Practice Class'); $t=($this->teacher)();
    $this->postJson($this->base.'/meetings',['group_id'=>$g->id,'period_id'=>$set['periods'][0]['id'],'weekday'=>1,'kind'=>'class','teacher_ids'=>[$t->id],'effective_from'=>'2026-10-10'])->assertCreated()->assertJsonPath('data.group_name','Practice Class')->assertJsonPath('data.label','Practice Class');
    $this->getJson($this->base.'/week?group_id='.$g->id.'&as_of=2026-10-12')->assertOk()->assertJsonPath('data.group_name','Practice Class')->assertJsonPath('data.meetings.0.group_name','Practice Class')->assertJsonPath('data.meetings.0.label','Practice Class')->assertJsonPath('data.meetings.0.room_name',null);
    expect(DB::table('timetable_rooms')->count())->toBe(0);
});

it('Timetable retention hints are persisted on a later write after a tentative transaction rolls back', function () {
    $set=($this->set)('Regular',[['P1','08:00','09:00']]); ($this->days)($set['id'],[1]); $g=($this->class)(); $t=($this->teacher)();
    $m=($this->place)($g,$set['periods'][0]['id'],1)->assertCreated()->json('data.id');
    try { DB::transaction(function () use ($t,$m) {
        \App\Models\TimetableMeetingTeacher::create(['masjid_id'=>$this->school->id,'meeting_id'=>$m,'user_id'=>$t->id]);
        throw new \RuntimeException('tentative');
    }); } catch (\RuntimeException $e) { expect($e->getMessage())->toBe('tentative'); }
    expect((int)DB::table('users')->where('id',$t->id)->value('has_timetable_records'))->toBe(0);
    \App\Models\TimetableMeetingTeacher::create(['masjid_id'=>$this->school->id,'meeting_id'=>$m,'user_id'=>$t->id]);
    expect((int)DB::table('users')->where('id',$t->id)->value('has_timetable_records'))->toBe(1);
    expect(array_key_exists('has_timetable_records',$t->fresh()->toArray()))->toBeFalse();
    expect(array_key_exists('has_timetable_records',$this->school->fresh()->toArray()))->toBeFalse();
});
