<?php

use App\Models\{DutyRotaFamily, DutyRotaAssignment, DutyRotaMail, FormResponse};
use App\Support\{DutyRotaService, DutyRotaMailService, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail, Queue, Artisan};

require_once __DIR__.'/../Support/DutyRotaFixtures.php';
uses(RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-15 20:00:00','UTC'));
    config(['queue.default'=>'database','queue.connections.database.after_commit'=>false]);
    Queue::fake([\App\Jobs\BuildDutyRotas::class]);
});
afterEach(fn()=>app(TenantContext::class)->forgetTenant());
function dutyQueuedJob() { return unserialize(json_decode(DB::table('jobs')->orderBy('id')->first()->payload,true)['data']['command']); }

it('DutyRota review queued pick uses current issuance and payload has no token even encrypted',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',['2026-10-18']); $svc=app(DutyRotaService::class); $svc->build($r);
    app(DutyRotaMailService::class)->emailNeeds($r); $family=DutyRotaFamily::first(); $job=dutyQueuedJob();
    $link=$svc->issue($r,$family); Mail::fake(); $job->handle();
    Mail::assertSent(\App\Mail\DutyRotaMail::class,fn($mail)=>str_contains($mail->render(),$link['url']));
    expect(array_keys(get_object_vars($job)))->not->toContain('encryptedContent');
    $this->postJson(dutyPublic($s,$r).'/page',['token'=>$link['token']])->assertOk();

});
it('DutyRota review queued reminder uses a reissued current link at send time',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',['2026-10-18']); $svc=app(DutyRotaService::class); $svc->build($r);
    Artisan::call('duties:remind'); $job=dutyQueuedJob(); $link=$svc->issue($r,DutyRotaFamily::first()); Mail::fake(); $job->handle();
    Mail::assertSent(\App\Mail\DutyRotaMail::class,fn($mail)=>str_contains($mail->render(),$link['url']));
    expect(get_object_vars($job))->not->toHaveKey('encryptedContent');
    expect(json_encode(DutyRotaMail::first()->getAttributes()))->not->toContain($link['token']);
});
it('DutyRota review queued pick no longer needed is dropped with reason',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',[]); $svc=app(DutyRotaService::class); $svc->build($r);
    app(DutyRotaMailService::class)->emailNeeds($r); $family=DutyRotaFamily::first(); $link=$svc->issue($r,$family);
    $svc->publicAction($r,$link['token'],'claim',['2026-10-18','2026-10-25']); Mail::fake(); dutyQueuedJob()->handle(); Mail::assertNothingSent();
    expect(DutyRotaMail::where('kind','pick')->first()->status)->toBe('dropped')->and(DutyRotaMail::where('kind','pick')->first()->failure_reason)->toBe('Family no longer needs a day');
});
it('DutyRota review existing office family accepts blank name while typed family requires name',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',[]); app(DutyRotaService::class)->build($r); dutyOffice();
    $this->post(dutyUrl($s,$r).'/assignments',['action'=>'add','family_id'=>DutyRotaFamily::first()->id,'name'=>'','date'=>'2026-10-18'])->assertOk();
    $this->postJson(dutyUrl($s,$r).'/assignments',['action'=>'add','name'=>'','email'=>'cedar@example.invalid','date'=>'2026-10-25'])->assertUnprocessable()->assertJsonPath('data.name.0','The name field is required when none of family id / assignment id are present.');
    $this->post(dutyUrl($s,$r).'/assignments',['action'=>'add','name'=>'Cedar Family','email'=>'cedar@example.invalid','date'=>'2026-10-25'])->assertOk();
});
it('DutyRota review send handoff holds school then rota locks so removal at sending must wait',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',['2026-10-18']); $svc=app(DutyRotaService::class); $svc->build($r); Artisan::call('duties:remind');
    $a=DutyRotaAssignment::first(); $connection=DB::connection(); $original=$connection->getQueryGrammar();
    $grammar=new class($connection) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
        public array $locks=[];
        public function compileSelect(\Illuminate\Database\Query\Builder $query) { if($query->lock && in_array($query->from,['masjids','duty_rotas'],true))$this->locks[]=$query->from; return parent::compileSelect($query); }
    };
    $connection->setQueryGrammar($grammar); $handoff=false; $waiting=false;
    DB::listen(function($query) use($grammar,$svc,$r,$a,&$handoff,&$waiting) {
        if(!str_starts_with($query->sql,'update "duty_rota_mails"') || ($query->bindings[0] ?? null)!=='sending')return;
        $handoff=true;
        // A separate release writer can commit here only if the worker holds no shared mutex.
        if($grammar->locks!==['masjids','duty_rotas'])$svc->officeAssignment($r,['action'=>'remove','assignment_id'=>$a->id]); else $waiting=true;
    });
    try {
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnUsing(function($mail) use($grammar,$a){expect($grammar->locks)->toBe(['masjids','duty_rotas'])->and(DB::transactionLevel())->toBeGreaterThan(1)->and($a->fresh()->released_at)->toBeNull();});
        dutyQueuedJob()->handle(); expect($handoff)->toBeTrue()->and($waiting)->toBeTrue()->and($a->fresh()->released_at)->toBeNull();
        $svc->officeAssignment($r,['action'=>'remove','assignment_id'=>$a->id]); expect($a->fresh()->released_at)->not->toBeNull();
    } finally { $connection->setQueryGrammar($original); }
});
it('DutyRota review released reminder never reaches mailer',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',['2026-10-18']); $svc=app(DutyRotaService::class); $svc->build($r); Artisan::call('duties:remind');
    $svc->officeAssignment($r,['action'=>'remove','assignment_id'=>DutyRotaAssignment::first()->id]); Mail::fake(); dutyQueuedJob()->handle(); Mail::assertNothingSent();
});
it('DutyRota review source edits refuse renamed removed type and options source in rota words',function($change) {
    [$s,$y,$f,$r]=dutySetup(); dutyOffice(); $schema=$f->schema; $schema['sections'][0]['fields'][0]['label']='Days';
    $schema['sections'][0]['fields'][]=['name'=>'other','type'=>'text','label'=>'Other'];
    if($change==='removed')array_shift($schema['sections'][0]['fields']);
    else $schema['sections'][0]['fields'][0]=array_merge($schema['sections'][0]['fields'][0],match($change){'renamed'=>['name'=>'new_days'],'type'=>['type'=>'select'],'source'=>['optionsSource'=>null,'options'=>[['value'=>'a','label'=>'A'],['value'=>'b','label'=>'B']]]});
    if($change==='type'){unset($schema['sections'][0]['fields'][0]['minSelections'],$schema['sections'][0]['fields'][0]['maxSelections']);}
    $this->putJson("/api/admin/masjids/{$s->id}/forms/{$f->id}",['schema'=>$schema])->assertUnprocessable()->assertJsonPath('data.schema.0','The Cleaning rota reads this question. Change the rota first.');
    expect($f->fresh()->schema)->toBe($f->schema);
})->with(['renamed','removed','type','source']);
it('DutyRota review unused form edits add no rota read and label edit on used source succeeds',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyOffice(); $unused=\App\Models\Form::create(['masjid_id'=>$s->id,'name'=>'Unused','slug'=>'unused','schema'=>$f->schema]);
    DB::flushQueryLog(); DB::enableQueryLog(); $this->putJson("/api/admin/masjids/{$s->id}/forms/{$unused->id}",['name'=>'Changed','schema'=>['sections'=>[['id'=>'family','fields'=>[['name'=>'days','label'=>'Days','type'=>'checkboxGroup','optionsSource'=>'school_meeting_days','minSelections'=>2,'maxSelections'=>2]]]]]])->assertOk(); $queries=DB::getQueryLog(); DB::disableQueryLog(); expect(implode(' ',array_column($queries,'query')))->not->toContain('duty_rotas');
    $schema=$f->schema; $schema['sections'][0]['fields'][0]['label']='Preferred Sundays'; $this->putJson("/api/admin/masjids/{$s->id}/forms/{$f->id}",['schema'=>$schema])->assertOk();
});
it('DutyRota review cancellation releases can be retaken by restored or new live registration',function($mode) {
    [$s,$y,$f,$r]=dutySetup(); $response=dutyResponse($f,'maple@example.invalid',['2026-10-18']); $svc=app(DutyRotaService::class); $svc->build($r); $id=DutyRotaAssignment::first()->id;
    $response->update(['status'=>'cancelled']); $svc->build($r);
    if($mode==='restore')$response->update(['status'=>'new']);else dutyResponse($f,'maple@example.invalid',['2026-10-18']);
    $svc->build($r); expect(DutyRotaAssignment::whereNull('released_at')->pluck('id')->all())->toBe([$id]);
})->with(['restore','new']);
it('DutyRota review retaken cancellation releases respect capacity and owed checks',function($limit) {
    [$s,$y,$f,$r]=dutySetup(['capacity'=>1,'turns_owed'=>1]); $response=dutyResponse($f,'maple@example.invalid',['2026-10-18']); $svc=app(DutyRotaService::class); $svc->build($r); $family=DutyRotaFamily::first();
    $response->update(['status'=>'cancelled']); $svc->build($r);
    if($limit==='capacity') dutyResponse($f,'cedar@example.invalid',['2026-10-18']); else $svc->officeAssignment($r,['action'=>'add','family_id'=>$family->id,'date'=>'2026-10-25']);
    $svc->build($r); $response->update(['status'=>'new']); $svc->build($r); expect(DutyRotaAssignment::where('family_id',$family->id)->where('duty_on','2026-10-18')->first()->released_at)->not->toBeNull();
})->with(['capacity','owed']);
it('DutyRota review source lock order school before rota for every writer and mail job',function () {
    $source=file_get_contents(app_path('Support/DutyRotaService.php'));
    foreach(['save','locked'] as $method) {
        preg_match('/public function '. $method .'\(.*?(?=\n    (?:public|private) function)/s',$source,$match);
        expect(strpos($match[0],'Masjid::'))->toBeLessThan(strpos($match[0],'DutyRota::'));
    }
    foreach(['build','office','issue','publicAction','officeAssignment'] as $method) { preg_match('/public function '.$method.'\(.*?(?=\n    (?:public|private) function|\n})/s',$source,$match); expect($match[0])->toContain('$this->locked('); }
    $formSource=file_get_contents(app_path('Support/DutyRotaMode.php'));
    preg_match('/public static function updateForm.*?(?=public static function refuseDeletion)/s',$formSource,$formMethod);
    expect(strpos($formMethod[0],'Masjid::'))->toBeLessThan(strpos($formMethod[0],'DutyRota::'));
    foreach(['Jobs/SendDutyRotaMail.php','Jobs/BuildDutyRotas.php','Support/DutyRotaMailService.php','Console/Commands/SendDutyRotaReminders.php'] as $file) { $text=file_get_contents(app_path($file)); expect($text)->not->toMatch('/DutyRota::[^;]*lockForUpdate/s'); expect($text)->toMatch('/(?:->locked\(|->build\()/'); }
});
it('DutyRota review throwing mailer records failure and does not retry',function () {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',[]); app(DutyRotaService::class)->build($r); app(DutyRotaMailService::class)->emailNeeds($r);
    Mail::shouldReceive('to')->once()->andReturnSelf(); Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('Relay unavailable'));
    $job=dutyQueuedJob(); expect(fn()=>$job->handle())->toThrow(RuntimeException::class); $job->handle(); expect(DutyRotaMail::first()->status)->toBe('failed')->and(DutyRotaMail::first()->failed_at)->not->toBeNull();
});
it('DutyRota review resolved unsafe transport sends nothing outside local testing',function($kind) {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',[]); app(DutyRotaService::class)->build($r); app(DutyRotaMailService::class)->emailNeeds($r);
    $logger=new class extends \Psr\Log\AbstractLogger { public array $messages=[]; public function log($level,string|Stringable $message,array $context=[]):void {$this->messages[]=(string)$message;} };
    $log=new \Illuminate\Mail\Transport\LogTransport($logger); $array=new \Illuminate\Mail\Transport\ArrayTransport();
    $transport=match($kind){'log'=>$log,'array'=>$array,'failover'=>new \Symfony\Component\Mailer\Transport\FailoverTransport([$log]),'roundrobin'=>new \Symfony\Component\Mailer\Transport\RoundRobinTransport([new \Symfony\Component\Mailer\Transport\FailoverTransport([$log])])};
    $mailer=app('mail.manager')->mailer(); $mailer->setSymfonyTransport($transport);
    // Config now claims SMTP; the already resolved transport is the authority.
    config(['app.env'=>'production','mail.mailers.'.config('mail.default').'.transport'=>'smtp']); app()->instance('env','production');
    dutyQueuedJob()->handle(); expect($logger->messages)->toBe([])->and($array->messages())->toHaveCount(0)->and(DutyRotaMail::first()->status)->toBe('failed')->and(DutyRotaMail::first()->failure_reason)->toBe('mail is not configured for delivery');
})->with(['log','array','failover','roundrobin']);
it('DutyRota review local testing log transport still delivers',function($env) {
    [$s,$y,$f,$r]=dutySetup(); dutyResponse($f,'maple@example.invalid',[]); app(DutyRotaService::class)->build($r); app(DutyRotaMailService::class)->emailNeeds($r);
    $logger=new class extends \Psr\Log\AbstractLogger { public array $messages=[]; public function log($level,string|Stringable $message,array $context=[]):void {$this->messages[]=(string)$message;} };
    app('mail.manager')->mailer()->setSymfonyTransport(new \Illuminate\Mail\Transport\LogTransport($logger)); config(['app.env'=>$env]); app()->instance('env',$env);
    dutyQueuedJob()->handle(); expect(implode(' ',$logger->messages))->toContain('#token=')->and(DutyRotaMail::first()->status)->toBe('sent');
})->with(['local','testing']);
