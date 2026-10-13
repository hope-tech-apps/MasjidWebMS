<?php

use App\Models\{Contact, Group, GroupMembership, Masjid, MasjidUser, SchoolYear, TimetableMeeting, TimetableMeetingTeacher, TimetablePeriod, TimetablePeriodSet, User};
use App\Support\{TenantContext, TimetableReader};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB};
use Laravel\Sanctum\Sanctum;

/** A lesson two classes take together: the real case is one PE teacher with both classes at 2:00 PM. */
uses(RefreshDatabase::class);
beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-10 16:00:00', 'UTC'));
    $this->school = Masjid::create(['name'=>'Practice School', 'email'=>'practice@example.invalid', 'phone'=>'+15555550100', 'country_id'=>'1', 'city_id'=>'1', 'address'=>'Practice', 'latitude'=>0, 'longitude'=>0, 'org_type'=>'school', 'crm_enabled'=>true]);
    $this->school->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_calendar_terms'=>true,'school_timetable'=>true]])->save();
    $this->admin=User::factory()->create(['type'=>'MasjidAdmin','phone'=>'+15555550101']);
    MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$this->admin->id,'role'=>'masjid-admin','is_default'=>true]);
    Sanctum::actingAs($this->admin, ['staff']);
    $this->year=SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Practice year','first_day'=>'2026-09-07','last_day'=>'2027-06-25','meeting_weekdays'=>[1,2,3,4,5]]);
    $this->base="/api/admin/masjids/{$this->school->id}/timetable/years/{$this->year->id}";
    $this->class=fn ($name)=>Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>$name]);
    $this->teacher=function ($name='Practice Teacher') { $t=User::factory()->create(['type'=>'Teacher','name'=>$name,'phone'=>'+1'.random_int(1000000000,9999999999)]); MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$t->id,'role'=>'teacher']); return $t; };
    $this->set=function ($name,$rows) { return $this->postJson($this->base.'/sets',['name'=>$name,'periods'=>array_map(fn($r,$i)=>['name'=>$r[0],'starts_at'=>$r[1],'ends_at'=>$r[2],'kind'=>$r[3]??'teaching','position'=>$i+1],$rows,array_keys($rows))])->assertCreated()->json('data'); };
    $this->days=fn ($id,$days,$g=null)=>$this->putJson($this->base.'/days',['group_id'=>$g?->id,'days'=>array_map(fn($d)=>['weekday'=>$d,'period_set_id'=>$id],$days)])->assertOk();
    $this->body=fn ($g,$period,$day,$extra=[])=>$extra+['group_id'=>$g->id,'period_id'=>$period,'weekday'=>$day,'kind'=>'activity','activity_name'=>'PE','teacher_ids'=>[$this->adam->id],'effective_from'=>'2026-10-10'];
    $this->place=fn ($g,$period,$day,$extra=[])=>$this->postJson($this->base.'/meetings',($this->body)($g,$period,$day,$extra));
    // Placed the way the office had to before: the second class's lesson is a confirmed clash.
    $this->anyway=function ($g,$period,$day,$extra=[]) { $w=($this->place)($g,$period,$day,$extra)->assertConflict(); return ($this->place)($g,$period,$day,$extra+['confirm_clashes'=>true,'clash_fingerprint'=>$w->json('clash_fingerprint')])->assertCreated()->json('data.id'); };
    $this->clashes=fn ($date='2026-10-12')=>$this->getJson($this->base.'/clashes?as_of='.$date)->assertOk()->json('data');
    // The class's 2:00 PM lesson on that weekday, as the class week reads it on that date.
    $this->lesson=fn ($g,$date='2026-10-12',$day=1)=>array_values(array_filter($this->getJson($this->base.'/week?group_id='.$g->id.'&as_of='.$date)->assertOk()->json('data.meetings'),fn($m)=>$m['weekday']===$day && $m['starts_at']>='14:00'))[0]??null;
    $this->join=fn ($id,$with)=>$this->putJson($this->base.'/meetings/'.$id.'/joint',['meeting_ids'=>$with]);
    $regular=($this->set)('Regular',[['P5','12:45','13:30'],['P6','14:00','14:45']]); ($this->days)($regular['id'],[1,3]);
    $this->p5=$regular['periods'][0]['id']; $this->p6=$regular['periods'][1]['id'];
    $this->juniors=($this->class)('Practice Juniors'); $this->seniors=($this->class)('Practice Seniors'); $this->adam=($this->teacher)('Practice Coach');
    $this->a=($this->place)($this->juniors,$this->p6,1)->assertCreated()->json('data.id');
    $this->b=($this->anyway)($this->seniors,$this->p6,1);
});
afterEach(fn()=>app(TenantContext::class)->forgetTenant());

it('Timetable joint lesson a joined pair reports no teacher location or student clash and each class reads with the other', function () {
    $hall=$this->postJson($this->base.'/rooms',['name'=>'Practice Hall'])->assertCreated()->json('data.id');
    foreach ([$this->juniors,$this->seniors] as $g) $this->putJson($this->base.'/classes/'.$g->id.'/room',['room_id'=>$hall])->assertOk();
    $pupil=Contact::factory()->create(['masjid_id'=>$this->school->id,'first_name'=>'Practice','last_name'=>'Pupil']);
    foreach ([$this->juniors,$this->seniors] as $g) GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$g->id,'contact_id'=>$pupil->id,'role'=>'member','joined_at'=>'2026-09-07']);
    $before=($this->clashes)(); $kinds=array_column($before,'kind'); sort($kinds); expect($kinds)->toBe(['room','student','teacher']);
    expect(($this->lesson)($this->juniors))->not->toHaveKey('joint');
    $r=($this->join)($this->a,[$this->b])->assertOk();
    expect($r->json('data.meeting.id'))->toBe($this->a); expect(array_column($r->json('data.joint'),'id'))->toBe([$this->b]); expect($r->json('data.candidates'))->toBe([]);
    expect(($this->clashes)())->toBe([]);
    expect(($this->lesson)($this->juniors)['joint'])->toBe([['id'=>$this->b,'group_id'=>$this->seniors->id,'group_name'=>'Practice Seniors','effective_from'=>'2026-10-10','effective_until'=>null]]);
    expect(array_column(($this->lesson)($this->seniors)['joint'],'group_name'))->toBe(['Practice Juniors']);
    $whole=$this->getJson($this->base.'/schedules/school?as_of=2026-10-12&weekday=1')->assertOk()->json('data');
    expect($whole['clashes'])->toBe([]); expect(count($whole['meetings']))->toBe(2);
    foreach ($whole['meetings'] as $m) expect(count($m['joint']))->toBe(1);
    // A location's day and the teacher's day each show it once, naming the other class.
    $location=$this->getJson($this->base.'/schedules/location/'.$hall.'?as_of=2026-10-12')->assertOk()->json('data.days.0.meetings');
    expect(count($location))->toBe(1); expect($location[0]['group_name'])->toBe('Practice Juniors'); expect(array_column($location[0]['joint'],'group_name'))->toBe(['Practice Seniors']);
    $byClass=$this->getJson($this->base.'/schedules/class/'.$this->seniors->id.'?as_of=2026-10-12')->assertOk()->json('data.days.0.meetings');
    expect(count($byClass))->toBe(1); expect(array_column($byClass[0]['joint'],'group_name'))->toBe(['Practice Juniors']);
    // The key is the server's own: no payload carries it.
    foreach (['/week?group_id='.$this->juniors->id.'&as_of=2026-10-12&include_clashes=1','/schedules/school?as_of=2026-10-12','/meetings/'.$this->a.'/joint','/setup'] as $suffix) expect($this->getJson($this->base.$suffix)->assertOk()->getContent())->not->toContain('joint_key');
    expect(DB::table('timetable_meetings')->whereNotNull('joint_key')->distinct()->count('joint_key'))->toBe(1);
});

it('Timetable joint lesson a third class with the same teacher at that time still clashes with each joined class', function () {
    ($this->join)($this->a,[$this->b])->assertOk();
    $third=($this->class)('Practice Thirds');
    $w=($this->place)($third,$this->p6,1,['activity_name'=>'Reading'])->assertConflict();
    $pairs=array_map(fn($c)=>[$c['kind'],array_column($c['meetings'],'group_name')],$w->json('clashes'));
    expect($pairs)->toBe([['teacher',['Practice Juniors','Practice Thirds']],['teacher',['Practice Seniors','Practice Thirds']]]);
    ($this->place)($third,$this->p6,1,['activity_name'=>'Reading','confirm_clashes'=>true,'clash_fingerprint'=>$w->json('clash_fingerprint')])->assertCreated();
    $list=($this->clashes)(); expect(count($list))->toBe(2);
    foreach ($list as $c) expect(array_column($c['meetings'],'group_name'))->toContain('Practice Thirds');
    // The same teacher at another time is not a clash, and neither joined class clashes with the other.
    ($this->place)($third,$this->p5,1,['activity_name'=>'Library'])->assertCreated();
    expect(count(($this->clashes)()))->toBe(2);
});

it('Timetable joint lesson joining across organisations years weekdays times classes or dates that never meet is refused', function () {
    $wednesday=($this->place)($this->seniors,$this->p6,3)->assertCreated()->json('data.id');
    $earlier=($this->place)($this->seniors,$this->p5,1,['activity_name'=>'Library'])->assertCreated()->json('data.id');
    $own=($this->place)($this->juniors,$this->p5,1,['activity_name'=>'Library','teacher_ids'=>[]])->assertCreated()->json('data.id');
    $same='Classes can only take a lesson together on the same weekday at the same time.';
    ($this->join)($this->a,[$wednesday])->assertUnprocessable()->assertJsonPath('data.meeting_ids.0',$same);
    ($this->join)($this->a,[$earlier])->assertUnprocessable()->assertJsonPath('data.meeting_ids.0',$same);
    ($this->join)($this->a,[$own])->assertUnprocessable()->assertJsonPath('data.meeting_ids.0','Choose a lesson of another class.');
    ($this->join)($this->a,[$this->a])->assertUnprocessable();
    ($this->join)($this->a,[])->assertUnprocessable();
    // Same weekday and clocks through another period set still counts; clocks that only overlap do not.
    $other=($this->set)('Other',[['Late','14:00','14:45'],['Long','12:45','13:45']]); $thirds=($this->class)('Practice Thirds'); ($this->days)($other['id'],[1],$thirds);
    $late=($this->anyway)($thirds,$other['periods'][0]['id'],1); $long=($this->anyway)($thirds,$other['periods'][1]['id'],1,['activity_name'=>'Library']);
    ($this->join)($earlier,[$long])->assertUnprocessable()->assertJsonPath('data.meeting_ids.0',$same);
    ($this->join)($this->a,[$late])->assertOk();
    // Dates that never meet: the seniors' lesson ends before a later lesson of a fourth class begins.
    $this->deleteJson($this->base.'/meetings/'.$this->b,['effective_from'=>'2026-11-02'])->assertOk();
    $fourths=($this->class)('Practice Fourths'); $later=($this->anyway)($fourths,$this->p6,1,['effective_from'=>'2026-11-02']);
    ($this->join)($this->b,[$later])->assertUnprocessable()->assertJsonPath('data.meeting_ids.0','These lessons are never in effect on the same dates.');
    // Another school year of this school, and another organisation: neither id exists for this year's writer.
    $next=SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Next practice year','first_day'=>'2027-09-06','last_day'=>'2028-06-23','meeting_weekdays'=>[1,3]]);
    $nextSet=TimetablePeriodSet::create(['masjid_id'=>$this->school->id,'school_year_id'=>$next->id,'name'=>'Regular']);
    $nextPeriod=TimetablePeriod::create(['masjid_id'=>$this->school->id,'period_set_id'=>$nextSet->id,'name'=>'P6','starts_at'=>'14:00','ends_at'=>'14:45','kind'=>'teaching','position'=>1]);
    $nextYear=TimetableMeeting::create(['masjid_id'=>$this->school->id,'school_year_id'=>$next->id,'group_id'=>$this->seniors->id,'weekday'=>1,'period_id'=>$nextPeriod->id,'kind'=>'activity','activity_name'=>'PE','effective_from'=>'2027-09-06','joint_key'=>DB::table('timetable_meetings')->where('id',$this->a)->value('joint_key')]);
    ($this->join)($this->a,[$nextYear->id])->assertNotFound();
    // An open-ended lesson and a forged equal key in the next year: a teacher's read across both years still keeps them apart.
    TimetableMeetingTeacher::create(['masjid_id'=>$this->school->id,'meeting_id'=>$nextYear->id,'user_id'=>$this->adam->id]);
    $versions=array_column(app(TimetableReader::class)->teacherVersions($this->school->id,[$this->year->id,$next->id],$this->adam->id),null,'id');
    expect($versions[$nextYear->id])->not->toHaveKey('joint'); expect(array_column($versions[$this->a]['joint'],'id'))->toBe([$late]);
    $this->putJson(str_replace('/years/'.$this->year->id,'/years/'.$next->id,$this->base).'/meetings/'.$this->a.'/joint',['meeting_ids'=>[$nextYear->id]])->assertNotFound();
    $foreign=Masjid::create(['name'=>'Other Practice School','email'=>'other@example.invalid','phone'=>'+15555550109','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
    $key=DB::table('timetable_meetings')->where('id',$this->a)->value('joint_key'); expect($key)->toBeString();
    // The same key on another weekday (only a forged row could carry it) is not the same lesson either.
    DB::table('timetable_meetings')->where('id',$wednesday)->update(['joint_key'=>$key]);
    expect(($this->lesson)($this->seniors,'2026-10-12',3))->not->toHaveKey('joint');
    $elsewhere=app(TenantContext::class)->runWithout(function () use ($foreign,$key) {
        $g=Group::factory()->create(['masjid_id'=>$foreign->id,'kind'=>'class','name'=>'Practice Elsewhere']); $y=SchoolYear::create(['masjid_id'=>$foreign->id,'label'=>'Other year','first_day'=>'2026-09-07','last_day'=>'2027-06-25','meeting_weekdays'=>[1]]);
        $s=TimetablePeriodSet::create(['masjid_id'=>$foreign->id,'school_year_id'=>$y->id,'name'=>'Regular']); $p=TimetablePeriod::create(['masjid_id'=>$foreign->id,'period_set_id'=>$s->id,'name'=>'P6','starts_at'=>'14:00','ends_at'=>'14:45','kind'=>'teaching','position'=>1]);
        // Even a forged equal key in another organisation or year never joins anything here.
        return TimetableMeeting::create(['masjid_id'=>$foreign->id,'school_year_id'=>$y->id,'group_id'=>$g->id,'weekday'=>1,'period_id'=>$p->id,'kind'=>'activity','activity_name'=>'PE','effective_from'=>'2026-10-10','joint_key'=>$key]);
    });
    ($this->join)($this->a,[$elsewhere->id])->assertNotFound();
    foreach (['GET','PUT','DELETE'] as $method) $this->json($method,$this->base.'/meetings/'.$elsewhere->id.'/joint',['meeting_ids'=>[$this->a]])->assertNotFound();
    expect(array_column(($this->lesson)($this->juniors)['joint'],'group_name'))->toBe(['Practice Thirds']);
    // Joining two joint lessons into one re-keys this year's rows only.
    $fifths=($this->class)('Practice Fifths'); $sixths=($this->class)('Practice Sixths');
    $five=($this->anyway)($fifths,$this->p6,1); $six=($this->anyway)($sixths,$this->p6,1); ($this->join)($five,[$six])->assertOk();
    DB::table('timetable_meetings')->whereIn('id',[$elsewhere->id,$nextYear->id])->update(['joint_key'=>DB::table('timetable_meetings')->where('id',$five)->value('joint_key')]);
    ($this->join)($this->a,[$five])->assertOk();
    expect(array_column(($this->lesson)($this->juniors)['joint'],'group_name'))->toBe(['Practice Fifths','Practice Sixths','Practice Thirds']);
    expect(DB::table('timetable_meetings')->whereIn('id',[$elsewhere->id,$nextYear->id])->pluck('joint_key')->unique()->values()->all())->not->toContain($key);
    expect(DB::table('timetable_meetings')->where('id',$six)->value('joint_key'))->toBe($key);
});

it('Timetable joint lesson routes fence teacher and family tokens another office and the switch', function () {
    $calls=[['GET',[]],['PUT',['meeting_ids'=>[$this->b]]],['DELETE',[]]]; $url=$this->base.'/meetings/'.$this->a.'/joint';
    $family=Contact::factory()->create(['masjid_id'=>$this->school->id,'login_email'=>'family@example.invalid','login_enabled_at'=>now()]);
    foreach ([$this->adam->createToken('practice',['staff'])->plainTextToken,$family->createToken('practice',['family'])->plainTextToken] as $token) {
        Auth::forgetGuards(); $this->withToken($token); foreach ($calls as [$method,$body]) expect($this->json($method,$url,$body)->status())->toBeIn([401,403]);
    }
    $this->withHeaders(['Authorization'=>'']); Auth::forgetGuards();
    $foreign=Masjid::create(['name'=>'Other Practice School','email'=>'other@example.invalid','phone'=>'+15555550109','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
    $foreign->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_timetable'=>true]])->save();
    $outsider=User::factory()->create(['type'=>'MasjidAdmin','phone'=>'+15555550111']); MasjidUser::create(['masjid_id'=>$foreign->id,'user_id'=>$outsider->id,'role'=>'masjid-admin','is_default'=>true]);
    Sanctum::actingAs($outsider,['staff']);
    foreach ($calls as [$method,$body]) $this->json($method,$url,$body)->assertForbidden();
    foreach ($calls as [$method,$body]) $this->json($method,str_replace('/masjids/'.$this->school->id,'/masjids/'.$foreign->id,$url),$body)->assertNotFound();
    expect(DB::table('timetable_meetings')->whereNotNull('joint_key')->count())->toBe(0);
    Auth::forgetGuards(); Sanctum::actingAs($this->admin,['staff']);
    ($this->join)($this->a,[$this->b])->assertOk();
    $this->school->forceFill(['capability_overrides'=>['school_calendar'=>true]])->save();
    foreach ($calls as [$method,$body]) $this->json($method,$url,$body)->assertNotFound();
    expect(DB::table('timetable_meetings')->whereNotNull('joint_key')->count())->toBe(2);
});

it('Timetable joint lesson taking a class out editing moving or deleting leaves the others correct and brings a real clash back', function () {
    $thirds=($this->class)('Practice Thirds'); $c=($this->anyway)($thirds,$this->p6,1);
    ($this->join)($this->a,[$this->b,$c])->assertOk(); expect(($this->clashes)())->toBe([]);
    // Taking one class out: the other two stay one lesson, and the class taken out is a real clash with each again.
    $this->deleteJson($this->base.'/meetings/'.$c.'/joint')->assertOk()->assertJsonPath('data.joint',[])->assertJsonCount(2,'data.candidates');
    expect(array_column(($this->lesson)($this->juniors)['joint'],'group_name'))->toBe(['Practice Seniors']);
    expect(($this->lesson)($thirds))->not->toHaveKey('joint');
    expect(array_map(fn($x)=>array_column($x['meetings'],'group_name'),($this->clashes)()))->toBe([['Practice Juniors','Practice Thirds'],['Practice Seniors','Practice Thirds']]);
    // Deleting the clashing lesson: nothing is left to report, and the pair is untouched.
    $this->deleteJson($this->base.'/meetings/'.$c,['effective_from'=>'2026-10-10'])->assertOk(); expect(DB::table('timetable_meetings')->where('id',$c)->exists())->toBeFalse();
    expect(($this->clashes)())->toBe([]); expect(array_column(($this->lesson)($this->seniors)['joint'],'id'))->toBe([$this->a]);
    // A dated change that keeps the weekday and period is still the joint lesson, before and after its date.
    $helper=($this->teacher)('Practice Helper');
    $a2=$this->putJson($this->base.'/meetings/'.$this->a,($this->body)($this->juniors,$this->p6,1,['teacher_ids'=>[$this->adam->id,$helper->id],'effective_from'=>'2026-11-02']))->assertOk()->json('data.id');
    expect($a2)->not->toBe($this->a);
    expect(array_column(($this->lesson)($this->seniors,'2026-10-26')['joint'],'id'))->toBe([$this->a]);
    expect(array_column(($this->lesson)($this->seniors,'2026-11-02')['joint'],'id'))->toBe([$a2]);
    foreach (['2026-10-26','2026-11-02'] as $date) expect(($this->clashes)($date))->toBe([]);
    // Moving a class's lesson to another period takes it out: with the same teacher still there, nothing clashes and nothing is joint.
    $this->putJson($this->base.'/meetings/'.$a2,($this->body)($this->juniors,$this->p5,1,['effective_from'=>'2026-11-02']))->assertOk();
    expect(($this->lesson)($this->juniors,'2026-11-02'))->not->toHaveKey('joint'); expect(($this->lesson)($this->seniors,'2026-11-02'))->not->toHaveKey('joint');
    expect(($this->clashes)('2026-11-02'))->toBe([]);
    expect(array_column(($this->lesson)($this->seniors,'2026-10-26')['joint'],'id'))->toBe([$this->a]);
    // Un-joining the pair that is left brings its own clash back, and joining again removes it.
    $this->deleteJson($this->base.'/meetings/'.$this->b.'/joint')->assertOk();
    expect(array_column(($this->clashes)('2026-10-26'),'kind'))->toBe(['teacher']);
    ($this->join)($this->b,[$this->a])->assertOk(); expect(($this->clashes)('2026-10-26'))->toBe([]);
});

it('Timetable joint lesson follows effective dates and a set change that separates the clocks is a clash again', function () {
    ($this->join)($this->a,[$this->b])->assertOk();
    // The seniors' lesson ends: joint while both are in effect, an ordinary lesson afterwards.
    $this->deleteJson($this->base.'/meetings/'.$this->b,['effective_from'=>'2026-11-02'])->assertOk();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-04 16:00:00','UTC'));
    $this->deleteJson($this->base.'/meetings/'.$this->b,['effective_from'=>'2026-11-02'])->assertOk();
    expect(array_column(($this->lesson)($this->juniors,'2026-10-26')['joint'],'id'))->toBe([$this->b]);
    expect(($this->lesson)($this->juniors,'2026-11-02'))->not->toHaveKey('joint');
    expect($this->getJson($this->base.'/meetings/'.$this->a.'/joint')->assertOk()->json('data.joint.0.effective_until'))->toBe('2026-11-01');
    // A class moved to a set whose clocks only overlap is no longer taking the same lesson: the key is inert, the clash is real.
    $thirds=($this->class)('Practice Thirds'); $c=($this->anyway)($thirds,$this->p6,1,['effective_from'=>'2026-11-04']); ($this->join)($this->a,[$c])->assertOk();
    expect(($this->clashes)('2026-11-09'))->toBe([]);
    // One key, three classes: the lesson that ended and the lesson that began later never met, so neither names the other.
    $versions=array_column(app(TimetableReader::class)->meetings($this->school->id,$this->year->id,null),null,'id');
    expect(array_column($versions[$this->b]['joint'],'id'))->toBe([$this->a]); expect(array_column($versions[$c]['joint'],'id'))->toBe([$this->a]); expect(array_column($versions[$this->a]['joint'],'id'))->toBe([$this->b,$c]);
    $shifted=($this->set)('Shifted',[['P5','12:45','13:30'],['P6','14:15','15:00']]); ($this->days)($shifted['id'],[1],$thirds);
    expect(array_column(($this->clashes)('2026-11-09'),'kind'))->toBe(['teacher']); expect(($this->lesson)($this->juniors,'2026-11-09'))->not->toHaveKey('joint');
    // Back on the school's set the office's statement holds again, with nothing to re-enter.
    $this->putJson($this->base.'/days',['group_id'=>$thirds->id,'days'=>[['weekday'=>1,'period_set_id'=>null]]])->assertOk();
    expect(($this->clashes)('2026-11-09'))->toBe([]); expect(array_column(($this->lesson)($this->juniors,'2026-11-09')['joint'],'group_name'))->toBe(['Practice Thirds']);
});

it('Timetable joint lesson copying a day copies the lessons and not the join', function () {
    ($this->join)($this->a,[$this->b])->assertOk();
    $copy=fn ($g,$extra=[])=>$this->postJson($this->base.'/copy-day',$extra+['group_id'=>$g->id,'source_weekday'=>1,'target_weekdays'=>[3],'as_of'=>'2026-10-10','effective_from'=>'2026-10-10']);
    $copy($this->juniors)->assertCreated();
    expect(($this->lesson)($this->juniors,'2026-10-12',3))->not->toBeNull()->not->toHaveKey('joint');
    $w=$copy($this->seniors)->assertConflict(); expect(array_column($w->json('clashes'),'kind'))->toBe(['teacher']);
    expect(DB::table('timetable_meetings')->where('weekday',3)->count())->toBe(1);
    $copy($this->seniors,['confirm_clashes'=>true,'clash_fingerprint'=>$w->json('clash_fingerprint')])->assertCreated();
    // Monday is still one lesson; Wednesday is a clash until the office says it is joint too.
    expect(array_column(($this->clashes)(),'weekday'))->toBe([3]); expect(array_column(($this->lesson)($this->juniors)['joint'],'id'))->toBe([$this->b]);
    ($this->join)(($this->lesson)($this->juniors,'2026-10-12',3)['id'],[($this->lesson)($this->seniors,'2026-10-12',3)['id']])->assertOk();
    expect(($this->clashes)())->toBe([]);
    expect(DB::table('timetable_meetings')->distinct()->count('joint_key'))->toBe(2);
});

it('Timetable joint lesson a save that meets a clash can say the classes take the lesson together and still reports what remains', function () {
    // A new lesson for a third class: the review lists both classes it would clash with.
    $thirds=($this->class)('Practice Thirds');
    $w=($this->place)($thirds,$this->p6,1)->assertConflict()->assertJsonCount(2,'clashes');
    expect(DB::table('timetable_meetings')->where('group_id',$thirds->id)->count())->toBe(0);
    // Taken together with the juniors only: that clash is gone and the seniors' is still asked about.
    $rest=($this->place)($thirds,$this->p6,1,['joint_with'=>[$this->a]])->assertConflict()->assertJsonCount(1,'clashes');
    expect(array_column($rest->json('clashes.0.meetings'),'group_name'))->toBe(['Practice Seniors','Practice Thirds']);
    expect(DB::table('timetable_meetings')->whereNotNull('joint_key')->count())->toBe(0);
    // The reviewed fingerprint binds the join as well: a different choice is a fresh review, never a save.
    ($this->place)($thirds,$this->p6,1,['joint_with'=>[$this->b],'confirm_clashes'=>true,'clash_fingerprint'=>$rest->json('clash_fingerprint')])->assertConflict();
    ($this->place)($thirds,$this->p6,1,['confirm_clashes'=>true,'clash_fingerprint'=>$rest->json('clash_fingerprint')])->assertConflict();
    expect(DB::table('timetable_meetings')->where('group_id',$thirds->id)->count())->toBe(0);
    $c=($this->place)($thirds,$this->p6,1,['joint_with'=>[$this->a],'confirm_clashes'=>true,'clash_fingerprint'=>$rest->json('clash_fingerprint')])->assertCreated()->json('data.id');
    expect(array_column(($this->lesson)($thirds)['joint'],'group_name'))->toBe(['Practice Juniors']);
    expect(array_map(fn($x)=>array_column($x['meetings'],'group_name'),($this->clashes)()))->toBe([['Practice Juniors','Practice Seniors'],['Practice Seniors','Practice Thirds']]);
    // Naming every class at once needs no confirmation at all, on a new lesson or a change.
    $fourths=($this->class)('Practice Fourths');
    $d=($this->place)($fourths,$this->p6,1,['joint_with'=>[$this->a,$this->b,$c]])->assertCreated()->json('data.id');
    expect(($this->clashes)())->toBe([]);
    expect(array_column(($this->lesson)($this->seniors)['joint'],'group_name'))->toBe(['Practice Fourths','Practice Juniors','Practice Thirds']);
    expect(DB::table('timetable_meetings')->distinct()->count('joint_key'))->toBe(1);
    // A change that would clash (the fifth class's lesson gains the same teacher) offers the same way out.
    $fifths=($this->class)('Practice Fifths'); $e=($this->place)($fifths,$this->p6,1,['teacher_ids'=>[]])->assertCreated()->json('data.id');
    $change=($this->body)($fifths,$this->p6,1); $this->putJson($this->base.'/meetings/'.$e,$change)->assertConflict()->assertJsonCount(4,'clashes');
    $e=$this->putJson($this->base.'/meetings/'.$e,$change+['joint_with'=>[$d]])->assertOk()->json('data.id');
    expect(($this->clashes)())->toBe([]); expect(count(($this->lesson)($fifths)['joint']))->toBe(4);
    // The same refusals as the direct writer, and nothing is saved by a refused request.
    $late=($this->place)($this->juniors,$this->p5,1,['activity_name'=>'Library','teacher_ids'=>[]])->assertCreated()->json('data.id');
    $sixths=($this->class)('Practice Sixths');
    ($this->place)($sixths,$this->p6,1,['joint_with'=>[$late]])->assertUnprocessable()->assertJsonPath('data.joint_with.0','Classes can only take a lesson together on the same weekday at the same time.');
    ($this->place)($sixths,$this->p6,1,['joint_with'=>[999999]])->assertNotFound();
    expect(DB::table('timetable_meetings')->where('group_id',$sixths->id)->count())->toBe(0);
    // Neither answer carries the key.
    expect($this->putJson($this->base.'/meetings/'.$e,$change)->assertOk()->getContent())->not->toContain('joint');
});

it('Timetable joint lesson the teacher schedule shows one lesson naming both classes and the office sees the same', function () {
    $schedule="/api/teacher/masjids/{$this->school->id}/schedule?week=2026-10-12"; $office=$this->base.'/schedules/teacher/'.$this->adam->id.'?as_of=2026-10-12';
    $helper=($this->teacher)('Practice Helper');
    $this->b=$this->putJson($this->base.'/meetings/'.$this->b,($this->body)($this->seniors,$this->p6,1,['teacher_ids'=>[$this->adam->id,$helper->id],'confirm_clashes'=>true,'clash_fingerprint'=>$this->putJson($this->base.'/meetings/'.$this->b,($this->body)($this->seniors,$this->p6,1,['teacher_ids'=>[$this->adam->id,$helper->id]]))->assertConflict()->json('clash_fingerprint')]))->assertOk()->json('data.id');
    expect(count($this->getJson($office)->assertOk()->json('data.days.0.meetings')))->toBe(2);
    Auth::forgetGuards(); Sanctum::actingAs($this->adam,['staff']);
    $two=$this->getJson($schedule)->assertOk()->json('data.days.0.meetings'); expect(count($two))->toBe(2); expect($two[0])->not->toHaveKey('joint');
    Auth::forgetGuards(); Sanctum::actingAs($this->admin,['staff']);
    ($this->join)($this->a,[$this->b])->assertOk();
    $officeDay=$this->getJson($office)->assertOk()->json('data.days.0.meetings');
    expect(count($officeDay))->toBe(1); expect($officeDay[0]['href'])->toBeNull(); expect($officeDay[0]['joint'])->toBe([['id'=>$this->b,'group_id'=>$this->seniors->id,'group_name'=>'Practice Seniors','href'=>null]]);
    Auth::forgetGuards(); Sanctum::actingAs($this->adam,['staff']);
    $one=$this->getJson($schedule)->assertOk()->json('data.days.0.meetings');
    expect(count($one))->toBe(1);
    expect($one[0]['group_name'])->toBe('Practice Juniors'); expect($one[0]['href'])->toBe('/teacher/classes/'.$this->juniors->id); expect($one[0]['label'])->toBe('PE');
    expect($one[0]['joint'])->toBe([['id'=>$this->b,'group_id'=>$this->seniors->id,'group_name'=>'Practice Seniors','href'=>'/teacher/classes/'.$this->seniors->id]]);
    expect(array_column($one[0]['co_teachers'],'name'))->toBe(['Practice Helper']);
    // A teacher placed with only one of the classes sees that class's lesson, with the other class named and not linked.
    Auth::forgetGuards(); Sanctum::actingAs($helper,['staff']);
    $theirs=$this->getJson($schedule)->assertOk()->json('data.days.0.meetings');
    expect(count($theirs))->toBe(1); expect($theirs[0]['group_name'])->toBe('Practice Seniors');
    expect($theirs[0]['joint'])->toBe([['id'=>$this->a,'group_id'=>$this->juniors->id,'group_name'=>'Practice Juniors','href'=>null]]);
    // Today's list is the same one lesson on the day itself, and only while both classes' lessons are in effect.
    $this->travelTo(\Carbon\Carbon::parse('2026-10-12 16:00:00','UTC')); Auth::forgetGuards(); Sanctum::actingAs($this->adam,['staff']);
    expect(count($this->getJson("/api/teacher/masjids/{$this->school->id}/schedule")->assertOk()->assertJsonPath('data.today.state','meetings')->json('data.today.meetings')))->toBe(1);
    Auth::forgetGuards(); Sanctum::actingAs($this->admin,['staff']);
    $this->deleteJson($this->base.'/meetings/'.$this->b,['effective_from'=>'2026-10-19'])->assertOk();
    Auth::forgetGuards(); Sanctum::actingAs($this->adam,['staff']);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-19 16:00:00','UTC'));
    $later=$this->getJson("/api/teacher/masjids/{$this->school->id}/schedule")->assertOk()->json('data.today.meetings');
    expect(count($later))->toBe(1); expect($later[0])->not->toHaveKey('joint');
});

it('Timetable joint lesson adds no query for a school without one and exactly one for a narrower lens that holds one', function () {
    $count=function (callable $read) { DB::flushQueryLog(); DB::enableQueryLog(); $read(); $n=count(DB::getQueryLog()); DB::disableQueryLog(); return $n; };
    $reader=app(TimetableReader::class); $school=$this->school->id; $year=$this->year->id;
    $lenses=fn ()=>['class'=>$count(fn()=>$reader->meetings($school,$year,'2026-10-12','class',$this->juniors->id)),'teacher'=>$count(fn()=>$reader->meetings($school,$year,'2026-10-12','teacher',$this->adam->id)),'school'=>$count(fn()=>$reader->meetings($school,$year,'2026-10-12')),'clashes'=>$count(fn()=>$reader->clashes($school,$year,'2026-10-12'))];
    $week=$this->base.'/week?group_id='.$this->juniors->id.'&as_of=2026-10-12';
    $http=function () use ($week,$count) { $this->getJson($week)->assertOk(); return $count(fn()=>$this->getJson($week)->assertOk()); };
    expect($lenses())->toBe(['class'=>2,'teacher'=>2,'school'=>2,'clashes'=>3]); expect($http())->toBe(12);
    ($this->join)($this->a,[$this->b])->assertOk();
    $with=['class'=>3,'teacher'=>3,'school'=>2,'clashes'=>3]; expect($lenses())->toBe($with); expect($http())->toBe(13);
    for ($i=0;$i<10;$i++) { $g=($this->class)('Practice Extra '.$i); $id=($this->anyway)($g,$this->p6,1); ($this->join)($this->a,[$id])->assertOk(); }
    expect($lenses())->toBe($with); expect($http())->toBe(13); expect(count(($this->lesson)($this->juniors)['joint']))->toBe(11);
});

it('Timetable joint lesson a school that never joins a lesson receives the rows and answers it received before', function () {
    $row=($this->lesson)($this->juniors);
    expect(array_keys($row))->toBe(['id','school_year_id','group_id','weekday','period_id','kind','class_subject_id','activity_name','room_id','effective_from','effective_until','group_name','subject_name','period_name','starts_at','ends_at','room_name','year_last_day','resolved_room_id','label','teachers']);
    $whole=$this->getJson($this->base.'/schedules/school?as_of=2026-10-12&weekday=1')->assertOk()->json('data');
    expect(array_keys($whole['meetings'][0]))->toBe([...array_keys($row),'href','co_teachers','column_key']);
    expect(array_keys($whole['clashes'][0]))->toBe(['kind','id','name','weekday','effective_from','effective_until','meetings']);
    expect(array_keys($whole['clashes'][0]['meetings'][0]))->toBe(array_keys($row));
    $thirds=($this->class)('Practice Thirds');
    $saved=($this->place)($thirds,$this->p5,1,['teacher_ids'=>[]])->assertCreated()->json('data');
    expect(array_keys($saved))->toBe(['masjid_id','school_year_id','group_id','weekday','period_id','kind','class_subject_id','activity_name','room_id','effective_from','effective_until','updated_at','created_at','id','group_name','label']);
    $changed=$this->putJson($this->base.'/meetings/'.$saved['id'],($this->body)($thirds,$this->p5,1,['teacher_ids'=>[],'activity_name'=>'Library']))->assertOk()->json('data');
    expect($changed)->not->toHaveKey('joint_key'); expect(array_diff(array_keys($saved),array_keys($changed)))->toBe([]);
    $w=($this->place)($thirds,$this->p6,1)->assertConflict();
    expect(array_keys($w->json()))->toBe(['status','clashes','clash_fingerprint']);
    Auth::forgetGuards(); Sanctum::actingAs($this->adam,['staff']);
    $mine=$this->getJson("/api/teacher/masjids/{$this->school->id}/schedule?week=2026-10-12")->assertOk()->json('data.days.0.meetings.0');
    expect(array_keys($mine))->toBe(['id','group_id','kind','class_subject_id','effective_from','effective_until','group_name','starts_at','ends_at','room_name','label','href','co_teachers']);
});
