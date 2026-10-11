<?php

use App\Models\{DutyRota, DutyRotaFamily, DutyRotaAssignment, Form, FormResponse, Masjid, SchoolYear, SchoolClosure, User};
use App\Support\{DutyRotaService, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail, Queue, Artisan};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
afterEach(fn () => app(TenantContext::class)->forgetTenant());
require_once __DIR__.'/../Support/DutyRotaFixtures.php';
beforeEach(function () { $this->travelTo(\Carbon\Carbon::parse('2026-10-11 18:00:00','UTC')); Queue::fake([\App\Jobs\BuildDutyRotas::class]); });
it('DutyRota earliest submissions keep capacity and ties use id with rollback-only preview',function () {
    [$s,$y,$f,$r]=dutySetup();
    dutyResponse($f,'maple@example.invalid',['2026-10-18']); dutyResponse($f,'cedar@example.invalid',['2026-10-18']); dutyResponse($f,'elm@example.invalid',['2026-10-18'],'2026-10-02 10:00:00');
    $svc=app(DutyRotaService::class); expect($svc->build($r,true))->toMatchArray(['registrations'=>3,'picks'=>3,'placed'=>2,'unplaced'=>1]); expect(DutyRotaFamily::count())->toBe(0); $svc->build($r);
    $data=$svc->office($r); expect(array_column($data['days'][2]['assignments'],'name'))->toBe(['Maple Family','Cedar Family']); expect(collect($data['families'])->firstWhere('email','elm@example.invalid')['unplaced_picks'])->toBe(['2026-10-18']);
});
it('DutyRota four registrations are one copied family and rebuild is idempotent',function () {
    [$s,$y,$f,$r]=dutySetup(); foreach(range(1,4) as $i) dutyResponse($f,$i===1?' MAPLE@example.invalid ':'maple@example.invalid',['2026-10-18','2026-10-25']);
    $svc=app(DutyRotaService::class); $svc->build($r); $svc->build($r); expect(DutyRotaFamily::count())->toBe(1)->and(DutyRotaFamily::first()->registration_count)->toBe(4)->and(DutyRotaAssignment::whereNull('released_at')->count())->toBe(2);
    DB::table('form_responses')->update(['respondent_name'=>'Changed']); expect($svc->office($r)['families'][0]['name'])->not->toBe('Changed');
});
it('DutyRota past and today are uncapped beyond owed and invalid open dates skipped',function () {
    [$s,$y,$f,$r]=dutySetup(['capacity'=>1,'turns_owed'=>1]); foreach(['maple','cedar','elm'] as $n) dutyResponse($f,"$n@example.invalid",['2026-10-04','2026-10-11','2026-10-18','2026-10-19']); app(DutyRotaService::class)->build($r);
    expect(DutyRotaAssignment::whereNull('released_at')->count())->toBe(6)->and(collect(app(DutyRotaService::class)->office($r)['families'])->sum('still_owed'))->toBe(0);
});
it('DutyRota new response queues automatic active build and places or flags it',function () {
    [$s,$y,$f,$r]=dutySetup(['capacity'=>1]); dutyResponse($f,'maple@example.invalid',['2026-10-18']); Queue::assertPushed(\App\Jobs\BuildDutyRotas::class); (new \App\Jobs\BuildDutyRotas($s->id,$f->id))->handle(); dutyResponse($f,'cedar@example.invalid',['2026-10-18']); (new \App\Jobs\BuildDutyRotas($s->id,$f->id))->handle();
    expect(DutyRotaFamily::count())->toBe(2)->and(DutyRotaAssignment::whereNull('released_at')->count())->toBe(1);
});
it('DutyRota cancellation releases future form turns after final live response and keeps past',function () {
    [$s,$y,$f,$r]=dutySetup(['turns_owed'=>4]); $a=dutyResponse($f,'maple@example.invalid',['2026-10-04','2026-10-18']); $b=dutyResponse($f,'maple@example.invalid',['2026-10-18']); $svc=app(DutyRotaService::class); $svc->build($r); $a->update(['status'=>'cancelled']); $svc->build($r); expect(DutyRotaAssignment::whereNull('released_at')->count())->toBe(2); $b->update(['status'=>'cancelled']); $svc->build($r);
    expect(DutyRotaAssignment::whereNull('released_at')->count())->toBe(1)->and(DutyRotaAssignment::whereNotNull('released_at')->first()->release_reason)->toContain('cancelled');
});
it('DutyRota same mutex path guards the last place and saves free picks with fresh refusal list',function () {
    [$s,$y,$f,$r]=dutySetup(); foreach(['maple','cedar','elm'] as $n) dutyResponse($f,"$n@example.invalid",[]); $svc=app(DutyRotaService::class); $svc->build($r); $families=DutyRotaFamily::orderBy('id')->get(); $tokens=$families->map(fn($family)=>$svc->issue($r,$family)['token']);
    foreach([0,1] as $i) $this->postJson(dutyPublic($s,$r).'/claim',['token'=>$tokens[$i],'dates'=>['2026-10-18']])->assertOk();
    expect(file_get_contents(app_path('Support/DutyRotaService.php')))->toContain('lockForUpdate()');
    $this->postJson(dutyPublic($s,$r).'/claim',['token'=>$tokens[2],'dates'=>['2026-10-18','2026-10-25']])->assertOk()->assertJsonPath('data.saved.0','2026-10-25')->assertJsonPath('data.refused.0','2026-10-18');
    $page=$this->postJson(dutyPublic($s,$r).'/page',['token'=>$tokens[2]])->assertOk(); expect(array_column($page->json('data.available'),'date'))->not->toContain('2026-10-18'); expect($page->getContent())->not->toContain('Maple Family');
    $this->postJson(dutyPublic($s,$r).'/give-back',['token'=>$tokens[0],'date'=>'2026-10-18'])->assertOk(); $this->postJson(dutyPublic($s,$r).'/page',['token'=>$tokens[2]])->assertOk()->assertJsonFragment(['date'=>'2026-10-18','left'=>1]); $svc->build($r); expect(DutyRotaAssignment::where('family_id',$families[0]->id)->whereNull('released_at')->count())->toBe(0);
});
it('DutyRota bad expired void foreign token and school id have one neutral refusal',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',[]); $svc=app(DutyRotaService::class); $svc->build($r); $family=DutyRotaFamily::first(); $a=$svc->issue($r,$family); $b=$svc->issue($r,$family); $other=dutySchool();
    foreach(['page','claim','give-back'] as $action) { foreach(['bad',$a['token']] as $token) $this->postJson(dutyPublic($s,$r)."/$action",['token'=>$token])->assertStatus(410)->assertJsonPath('message',DutyRotaService::UNAVAILABLE); $this->postJson(dutyPublic($other,$r)."/$action",['token'=>$b['token']])->assertStatus(410); }
    $this->travelTo(\Carbon\Carbon::parse('2026-11-30 18:00:00','UTC')); $this->postJson(dutyPublic($s,$r).'/page',['token'=>$b['token']])->assertStatus(410);
});
it('DutyRota endpoints throttle token probes',function () { [$s,$y,$f,$r]=dutySetup(); foreach(range(1,30) as $i) $this->postJson(dutyPublic($s,$r).'/page',['token'=>'bad'])->assertStatus(410); $this->postJson(dutyPublic($s,$r).'/page',['token'=>'bad'])->assertStatus(429); });
it('DutyRota closure releases assignments with recorded reason and no automatic mail',function () {
    Mail::fake(); [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',['2026-10-18','2026-10-25']); $svc=app(DutyRotaService::class); $svc->build($r); SchoolClosure::create(['school_year_id'=>$y->id,'closed_on'=>'2026-10-25','reason'=>'Practice closure']); $data=$svc->office($r);
    expect(array_column($data['days'],'date'))->not->toContain('2026-10-25'); expect($data['families'][0]['still_owed'])->toBe(1)->and($data['families'][0]['notices'][0])->toContain('closed'); Mail::assertNothingSent();
});
it('DutyRota source year and form refuse deletion in words and switching off retains history',function () {
    [$s,$y,$f,$r]=dutySetup(); foreach([$y,$f] as $model) { try { $model->fresh()->delete(); test()->fail('deleted'); } catch(\Illuminate\Validation\ValidationException $e) { expect($e->getMessage())->toContain('rota'); } }
    app(\App\Support\CapabilityWriter::class)->apply($s,['family_duty_rota'=>false],null); dutyOffice(); $this->getJson(dutyUrl($s,$r))->assertNotFound(); expect(DutyRota::count())->toBe(1);
});
it('DutyRota scheduler OFF has one narrow read and no output',function () {
    dutySchool(false); DB::flushQueryLog(); DB::enableQueryLog(); Artisan::call('duties:remind'); $output=Artisan::output(); $queries=DB::getQueryLog(); DB::disableQueryLog(); expect($output)->toBe('')->and($queries)->toHaveCount(1)->and($queries[0]['query'])->not->toContain('*');
});
it('DutyRota fixed by-day query count at ten and sixty families without response joins',function () {
    [$s,$y,$f,$r]=dutySetup(); $svc=app(DutyRotaService::class); $counts=[]; foreach([10,60] as $size) { foreach(range(DutyRotaFamily::count()+1,$size) as $i) dutyResponse($f,"family$i@example.invalid",[]); $svc->build($r); dutyOffice(); DB::flushQueryLog(); DB::enableQueryLog(); $this->getJson(dutyUrl($s,$r))->assertOk(); $queries=DB::getQueryLog(); DB::disableQueryLog(); $counts[]=count($queries); expect(implode(' ',array_column($queries,'query')))->not->toContain('form_responses'); }
    expect($counts[1])->toBe($counts[0]); file_put_contents(base_path('artifacts/duty-rota-query-counts.json'),json_encode($counts));
});

it('DutyRota actual lock builder takes rota mutex before assignment reads and second committed claim loses the last place',function () {
    [$s,$y,$f,$r]=dutySetup(['capacity'=>1,'turns_owed'=>1]);dutyResponse($f,'maple@example.invalid',[]);dutyResponse($f,'cedar@example.invalid',[]);$svc=app(DutyRotaService::class);$svc->build($r);$families=DutyRotaFamily::orderBy('id')->get();$links=$families->map(fn($f)=>$svc->issue($r,$f));
    $connection=DB::connection();$original=$connection->getQueryGrammar();
    $grammar=new class($connection) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
        public array $observed=[];
        public function compileSelect(\Illuminate\Database\Query\Builder $query) { if(DB::transactionLevel()>1 && in_array($query->from,['masjids','duty_rotas','duty_rota_assignments','duty_rota_families'],true))$this->observed[]=['table'=>$query->from,'lock'=>$query->lock];return parent::compileSelect($query); }
    };
    $connection->setQueryGrammar($grammar);
    try {
        $first=$svc->publicAction($r,$links[0]['token'],'claim',['2026-10-18']);
        $second=$svc->publicAction($r,$links[1]['token'],'claim',['2026-10-18']);
        expect($first['saved'])->toBe(['2026-10-18'])->and($second['saved'])->toBe([])->and($second['refused'])->toBe(['2026-10-18']);
        expect(array_slice($grammar->observed,0,2))->toBe([['table'=>'masjids','lock'=>true],['table'=>'duty_rotas','lock'=>true]]);
        foreach(['duty_rota_families','duty_rota_assignments'] as $table)expect(collect($grammar->observed)->firstWhere('table',$table)['lock'])->toBeTrue();
    } finally { $connection->setQueryGrammar($original); }
});
it('DutyRota office moves and deliberate over capacity survive rebuild and removal opens a place',function () {
    [$s,$y,$f,$r]=dutySetup(['capacity'=>1]);dutyResponse($f,'maple@example.invalid',['2026-10-18']);dutyResponse($f,'cedar@example.invalid',['2026-10-25']);$svc=app(DutyRotaService::class);$svc->build($r);$maple=DutyRotaFamily::where('email','maple@example.invalid')->first();$a=DutyRotaAssignment::where('family_id',$maple->id)->first();
    expect(fn()=>$svc->officeAssignment($r,['action'=>'move','assignment_id'=>$a->id,'date'=>'2026-10-25']))->toThrow(\Illuminate\Validation\ValidationException::class);
    $svc->officeAssignment($r,['action'=>'move','assignment_id'=>$a->id,'date'=>'2026-10-25','over_capacity'=>true]);$svc->build($r);
    expect(DutyRotaAssignment::where('family_id',$maple->id)->whereNull('released_at')->pluck('duty_on')->all())->toBe(['2026-10-25']);expect(DutyRotaAssignment::where('duty_on','2026-10-25')->whereNull('released_at')->count())->toBe(2);
    $moved=DutyRotaAssignment::where('family_id',$maple->id)->whereNull('released_at')->first();$svc->officeAssignment($r,['action'=>'remove','assignment_id'=>$moved->id]);$svc->build($r);
    expect(DutyRotaAssignment::where('family_id',$maple->id)->whereNull('released_at')->count())->toBe(0)->and(DutyRotaAssignment::where('duty_on','2026-10-25')->whereNull('released_at')->count())->toBe(1);
     $cedar=DutyRotaAssignment::where('duty_on','2026-10-25')->whereNull('released_at')->first();$svc->officeAssignment($r,['action'=>'remove','assignment_id'=>$cedar->id]);$svc->build($r);
    expect(collect($svc->office($r)['days'])->firstWhere('date','2026-10-25')['taken'])->toBe(0);$svc->officeAssignment($r,['action'=>'add','family_id'=>$maple->id,'date'=>'2026-10-25']);expect(DutyRotaAssignment::where('duty_on','2026-10-25')->whereNull('released_at')->count())->toBe(1);
});
it('DutyRota phone fallback normalizes digits and a no-contact response remains separate',function () {
    [$s,$y,$f,$r]=dutySetup();foreach(['+1 (555) 555-0102','15555550102'] as $phone) FormResponse::create(['form_id'=>$f->id,'masjid_id'=>$s->id,'respondent_name'=>'Maple Family','respondent_phone'=>$phone,'data'=>['days'=>['2026-10-18']],'status'=>'new','submitted_at'=>now()]);
    foreach(range(1,2) as $i) FormResponse::create(['form_id'=>$f->id,'masjid_id'=>$s->id,'respondent_name'=>'No contact','data'=>['days'=>[]],'status'=>'new','submitted_at'=>now()]);
    app(DutyRotaService::class)->build($r);expect(DutyRotaFamily::count())->toBe(3)->and(DutyRotaFamily::first()->registration_count)->toBe(2);expect(DutyRotaFamily::whereNull('phone')->whereNull('email')->pluck('identity_hash')->unique())->toHaveCount(2);
});
it('DutyRota give back today is refused and cancellation never releases claim or office turns',function () {
    [$s,$y,$f,$r]=dutySetup(['turns_owed'=>4]);$response=dutyResponse($f,'maple@example.invalid',['2026-10-11']);$svc=app(DutyRotaService::class);$svc->build($r);$family=DutyRotaFamily::first();$link=$svc->issue($r,$family);
    $svc->publicAction($r,$link['token'],'claim',['2026-10-18']);$svc->officeAssignment($r,['action'=>'add','family_id'=>$family->id,'date'=>'2026-10-25']);
    expect(fn()=>$svc->publicAction($r,$link['token'],'give-back',[],'2026-10-11'))->toThrow(\Illuminate\Validation\ValidationException::class);
    $response->update(['status'=>'cancelled']);$svc->build($r);expect(DutyRotaAssignment::whereNull('released_at')->count())->toBe(3);
});
it('DutyRota retains hidden source hints while off and reenable resumes automatic building',function () {
    [$s,$y,$f,$r]=dutySetup();\App\Support\CapabilityWriter::apply($s,['family_duty_rota'=>false],null);$this->travelTo(\Carbon\Carbon::parse('2026-10-12 18:00:00','UTC'));
    expect($f->fresh()->has_duty_rota_records)->toBe(1);\App\Support\CapabilityWriter::apply($s,['family_duty_rota'=>true],null);Queue::fake();dutyResponse($f,'maple@example.invalid',[]);Queue::assertPushed(\App\Jobs\BuildDutyRotas::class);
});
