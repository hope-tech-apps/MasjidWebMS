<?php

use App\Models\{DutyRotaFamily, DutyRotaAssignment, DutyRotaMail};
use App\Support\{DutyRotaService, DutyRotaMailService, TenantContext};
use App\Jobs\{SendDutyRotaMail, BuildDutyRotas};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail, Queue, Artisan};

require_once __DIR__.'/../Support/DutyRotaFixtures.php';
uses(RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-15 20:00:00','UTC'));
    config(['queue.default'=>'database','queue.connections.database.after_commit'=>false]);
    Queue::fake([BuildDutyRotas::class]);
});
afterEach(fn()=>app(TenantContext::class)->forgetTenant());

it('DutyRota explicit email confirms queues real serializable token-free payload once per press skips no email and reissues token', function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',['2026-10-18']);
    \App\Models\FormResponse::create(['masjid_id'=>$s->id,'form_id'=>$f->id,'respondent_name'=>'Cedar Family','respondent_phone'=>'+15555550103','data'=>['days'=>[]],'status'=>'new','submitted_at'=>now()]);
    $svc=app(DutyRotaService::class); $svc->build($r); dutyOffice();
    $this->postJson(dutyUrl($s,$r).'/email',[])->assertStatus(422);
    $this->post(dutyUrl($s,$r).'/email',['confirmed'=>'true'])->assertOk()->assertJsonPath('data.result.queued',1)->assertJsonPath('data.result.skipped_no_email',1)->assertJsonPath('data.families.0.link_mail_status','queued');
    expect(DB::table('jobs')->count())->toBe(1)->and(DutyRotaMail::first()->status)->toBe('queued');
    $payload=json_decode(DB::table('jobs')->first()->payload,true,512,JSON_THROW_ON_ERROR); $job=unserialize($payload['data']['command']);
    expect($job)->toBeInstanceOf(SendDutyRotaMail::class);
    expect(get_object_vars($job))->not->toHaveKey('encryptedContent');
    Mail::fake(); $job->handle(); $job->handle(); Mail::assertSent(\App\Mail\DutyRotaMail::class,1);
    $sent=Mail::sent(\App\Mail\DutyRotaMail::class)->first(); $rendered=$sent->render();
    expect($rendered)->toContain('<a href=')->toContain('#token=');
    expect($sent->mailSubject)->toBe('Pick your cleaning day')->and($sent->bodyText)->toContain('Days you hold: Sunday, October 18, 2026.');
    preg_match('/#token=([a-f0-9]{64})/',$sent->bodyText,$token);
    expect(DB::table('jobs')->first()->payload)->not->toContain($token[1])->not->toContain('maple@example.invalid');
    expect(DutyRotaFamily::where('email','maple@example.invalid')->first()->token_hash)->not->toBe($token[1]);
    expect(DutyRotaMail::first()->fresh()->status)->toBe('sent')->and(DutyRotaFamily::where('email','maple@example.invalid')->first()->link_sent_at)->not->toBeNull();
    $this->postJson(dutyUrl($s,$r).'/email',['confirmed'=>true])->assertOk()->assertJsonPath('data.result.queued',1);
    expect(DutyRotaMail::count())->toBe(2);
    $this->postJson(dutyPublic($s,$r).'/page',['token'=>$token[1]])->assertStatus(410);
});
it('DutyRota reminder catches missed day window never past once per assignment and uses stable private link',function () {
    [$s,$y,$f,$r]=dutySetup(['turns_owed'=>4]); dutyResponse($f,'maple@example.invalid',['2026-10-11','2026-10-18','2026-10-25']); app(DutyRotaService::class)->build($r);
    $family=DutyRotaFamily::first(); $link=app(DutyRotaService::class)->issue($r,$family);
    // Due since October 15; a missed day is caught on October 16.
    $this->travelTo(\Carbon\Carbon::parse('2026-10-16 20:00:00','UTC'));
    Artisan::call('duties:remind'); $first=Artisan::output(); Artisan::call('duties:remind'); $second=Artisan::output();
    expect($first)->toContain('1 reminder(s) queued')->and($second)->toContain('0 reminder(s) queued');
    expect(DutyRotaMail::where('kind','reminder')->count())->toBe(1)->and(DutyRotaAssignment::where('duty_on','2026-10-11')->first()->reminder_queued_at)->toBeNull();
    $payload=json_decode(DB::table('jobs')->first()->payload,true); $job=unserialize($payload['data']['command']);
    Mail::fake(); $job->handle(); $job->handle(); Mail::assertSent(\App\Mail\DutyRotaMail::class,1);
    $sent=Mail::sent(\App\Mail\DutyRotaMail::class)->first();
    expect($sent->mailSubject)->toBe('Reminder: Cleaning on Sunday, October 18, 2026')->and($sent->bodyText)->toContain('Cleaning starts at 1:30 PM after class.')->toContain($link['url']);
    expect(DutyRotaAssignment::where('duty_on','2026-10-18')->first()->reminder_sent_at)->not->toBeNull();
});
it('DutyRota later assignment gets next run and school local hour gates reminders',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',[]); app(DutyRotaService::class)->build($r); $family=DutyRotaFamily::first();
    $this->travelTo(\Carbon\Carbon::parse('2026-10-17 10:00:00','UTC'));
    app(DutyRotaService::class)->officeAssignment($r,['family_id'=>$family->id,'date'=>'2026-10-18','action'=>'add']); Artisan::call('duties:remind'); expect(DutyRotaMail::count())->toBe(0);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-17 20:00:00','UTC')); Artisan::call('duties:remind'); expect(DutyRotaMail::count())->toBe(1);
});
it('DutyRota claim and give back confirmations each sent once and no email only skips delivery',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',[]); $svc=app(DutyRotaService::class); $svc->build($r); $link=$svc->issue($r,DutyRotaFamily::first());
    $this->postJson(dutyPublic($s,$r).'/claim',['token'=>$link['token'],'dates'=>['2026-10-18']])->assertOk();
    $this->postJson(dutyPublic($s,$r).'/give-back',['token'=>$link['token'],'date'=>'2026-10-18'])->assertOk();
    expect(DutyRotaMail::where('kind','confirmation')->count())->toBe(2)->and(DB::table('jobs')->count())->toBe(2);
    Mail::fake(); foreach(DB::table('jobs')->get() as $raw) { $job=unserialize(json_decode($raw->payload,true)['data']['command']); $job->handle(); $job->handle(); }
    Mail::assertSent(\App\Mail\DutyRotaMail::class,2);
    $mail=new \App\Mail\DutyRotaMail('Practice School','office@example.invalid','Your Cleaning days','Days you hold: None.');$mail->build();expect($mail->replyTo[0]['address'])->toBe('office@example.invalid')->and($mail->render())->toContain('Practice School')->and($mail->render())->toContain('Days you hold: None.');
});
it('DutyRota closure suppresses an old queued reminder and records it as failed',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',['2026-10-18']); app(DutyRotaService::class)->build($r); Artisan::call('duties:remind');
    $raw=DB::table('jobs')->first();$job=unserialize(json_decode($raw->payload,true)['data']['command']); \App\Models\SchoolClosure::create(['masjid_id'=>$s->id,'school_year_id'=>$y->id,'closed_on'=>'2026-10-18','reason'=>'Practice closure']);
    Mail::fake();$job->handle();Mail::assertNothingSent(); expect(DutyRotaMail::first()->status)->toBe('failed')->and(DutyRotaMail::first()->failed_at)->not->toBeNull();
});
it('DutyRota no-email claims and reminders do not queue mail and copy preserves while deliberate reissue voids a link',function () {
    [$s,$y,$f,$r]=dutySetup();\App\Models\FormResponse::create(['form_id'=>$f->id,'masjid_id'=>$s->id,'respondent_name'=>'Cedar Family','respondent_phone'=>'+15555550122','data'=>['days'=>[]],'status'=>'new','submitted_at'=>now()]);$svc=app(DutyRotaService::class);$svc->build($r);$family=DutyRotaFamily::first();$link=$svc->issue($r,$family);
    $svc->publicAction($r,$link['token'],'claim',['2026-10-18']);Artisan::call('duties:remind');expect(DutyRotaMail::count())->toBe(0);
    dutyOffice();$url=dutyUrl($s,$r).'/families/'.$family->id.'/link';
    $this->postJson($url,[])->assertOk()->assertJsonPath('data.url',$link['url']);
    $this->postJson($url,['reissue'=>true])->assertOk();$this->postJson(dutyPublic($s,$r).'/page',['token'=>$link['token']])->assertStatus(410);
});
