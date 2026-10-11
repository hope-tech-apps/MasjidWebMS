<?php

use App\Models\{DutyRotaFamily, DutyRotaAssignment, Form, MasjidUser, User, Contact};
use App\Support\{DutyRotaService, TenantContext, CapabilityWriter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Support/DutyRotaFixtures.php';
uses(RefreshDatabase::class);
beforeEach(function () { $this->travelTo(\Carbon\Carbon::parse('2026-10-11 18:00:00','UTC'));Queue::fake();$this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class); });
afterEach(fn()=>app(TenantContext::class)->forgetTenant());
function dutyOfficeRoutes($s,$r): array {
    $base="/api/admin/masjids/{$s->id}/duty-rotas";
    return [['get',$base,[]],['post',$base,[]],['get',"$base/{$r->id}",[]],['put',"$base/{$r->id}",[]],['post',"$base/{$r->id}/preview",[]],['post',"$base/{$r->id}/build",['confirmed'=>true]],['post',"$base/{$r->id}/email",['confirmed'=>true]],['post',"$base/{$r->id}/families/1/link",[]],['post',"$base/{$r->id}/assignments",[]],['get',"$base/{$r->id}/export",[]]];
}
it('DutyRota all office routes fence teachers other school office and family tokens',function () {
    [$s,$y,$f,$r]=dutySetup(); $other=dutySchool();
    $teacher=User::factory()->create(['type'=>'Teacher','phone'=>'+15555550104']);MasjidUser::create(['masjid_id'=>$s->id,'user_id'=>$teacher->id,'role'=>'teacher','is_default'=>true]);
    $office=User::factory()->create(['type'=>'MasjidAdmin','phone'=>'+15555550105']);MasjidUser::create(['masjid_id'=>$other->id,'user_id'=>$office->id,'role'=>'admin','is_default'=>true]);
    foreach([$teacher,$office] as $user){Sanctum::actingAs($user,['staff']);foreach(dutyOfficeRoutes($s,$r) as [$method,$url,$body]) $this->{$method.'Json'}($url,$body)->assertStatus($user->type==='Teacher'?401:403);}
    $contact=Contact::factory()->create(['masjid_id'=>$s->id]);Sanctum::actingAs($contact,['family']);foreach(dutyOfficeRoutes($s,$r) as [$method,$url,$body]) $this->{$method.'Json'}($url,$body)->assertStatus(401);
});
it('DutyRota every office route is 404 off including operator and public endpoints cannot use a login as claim token',function () {
    [$s,$y,$f,$r]=dutySetup();DutyRotaFamily::create(['rota_id'=>$r->id,'masjid_id'=>$s->id,'identity_hash'=>hash('sha256','practice'),'name'=>'Maple Family']);dutyOffice();CapabilityWriter::apply($s,['family_duty_rota'=>false],null);
    foreach(dutyOfficeRoutes($s,$r) as [$method,$url,$body])$this->{$method.'Json'}($url,$body)->assertNotFound();
    foreach(['page','claim','give-back'] as $action)$this->postJson(dutyPublic($s,$r)."/$action",['token'=>'bad'])->assertNotFound();$this->get('/duty/'.$s->id.'/'.$r->id)->assertNotFound();
});
it('DutyRota same school office accesses calendar and every rota action while foreign rota family and assignment ids 404',function () {
    [$s,$y,$f,$r]=dutySetup();dutyResponse($f,'maple@example.invalid',['2026-10-18']);app(DutyRotaService::class)->build($r);$family=DutyRotaFamily::first();
    $office=User::factory()->create(['type'=>'MasjidAdmin','phone'=>'+15555550105']);MasjidUser::create(['masjid_id'=>$s->id,'user_id'=>$office->id,'role'=>'admin','is_default'=>true]);Sanctum::actingAs($office,['staff']);
    $this->getJson(dutyUrl($s,$r))->assertOk();$this->getJson("/api/admin/masjids/{$s->id}/duty-rotas")->assertOk();$this->postJson(dutyUrl($s,$r).'/preview',[])->assertOk();$this->postJson(dutyUrl($s,$r).'/build',['confirmed'=>true])->assertOk();$this->postJson(dutyUrl($s,$r).'/email',['confirmed'=>true,'family_id'=>$family->id])->assertOk();$this->postJson(dutyUrl($s,$r).'/families/'.$family->id.'/link',[])->assertOk();$this->getJson(dutyUrl($s,$r).'/export')->assertOk();
    $this->getJson("/api/admin/masjids/{$s->id}/duty-rotas/999999")->assertNotFound();$this->postJson(dutyUrl($s,$r).'/families/999999/link',[])->assertNotFound();$this->postJson(dutyUrl($s,$r).'/assignments',['action'=>'add','family_id'=>999999,'date'=>'2026-10-25'])->assertNotFound();$this->postJson(dutyUrl($s,$r).'/assignments',['action'=>'move','assignment_id'=>999999,'date'=>'2026-10-25'])->assertNotFound();
});
it('DutyRota grant requires calendar and source must be this school meeting checkbox',function () {
    $s=dutySchool(false);$s->forceFill(['capability_overrides'=>[]])->save();expect(fn()=>CapabilityWriter::apply($s,['family_duty_rota'=>true],null))->toThrow(\Illuminate\Validation\ValidationException::class);
    [$s,$y,$f,$r]=dutySetup();dutyOffice();$data=$r->toArray();$data['source_field']='unknown';$this->postJson("/api/admin/masjids/{$s->id}/duty-rotas",$data)->assertStatus(422);
    expect(fn()=>CapabilityWriter::apply($s,['school_calendar'=>false],null))->toThrow(\Illuminate\Validation\ValidationException::class);
});
it('DutyRota OFF used form never queues build and unused retention guards make no rota queries',function () {
    [$s,$y,$f,$r]=dutySetup();CapabilityWriter::apply($s,['family_duty_rota'=>false],null);Queue::fake();dutyResponse($f,'maple@example.invalid',[]);Queue::assertNothingPushed();
    $plain=Form::create(['masjid_id'=>$s->id,'name'=>'Practice plain','slug'=>'plain','schema'=>[]]);DB::flushQueryLog();DB::enableQueryLog();$plain->delete();$sql=implode(' ',array_column(DB::getQueryLog(),'query'));DB::disableQueryLog();expect($sql)->not->toContain('duty_rotas');
});

it('DutyRota actual bearer family teacher and office tokens fence every route and do not replace a private claim token',function () {
    [$s,$y,$f,$r]=dutySetup();dutyResponse($f,'maple@example.invalid',[]);app(DutyRotaService::class)->build($r);$other=dutySchool();
    $teacher=User::factory()->create(['type'=>'Teacher','phone'=>'+15555550111']);\App\Models\MasjidUser::create(['masjid_id'=>$s->id,'user_id'=>$teacher->id,'role'=>'teacher','is_default'=>true]);
    $office=User::factory()->create(['type'=>'MasjidAdmin','phone'=>'+15555550112']);\App\Models\MasjidUser::create(['masjid_id'=>$other->id,'user_id'=>$office->id,'role'=>'admin','is_default'=>true]);
    $family=Contact::factory()->create(['masjid_id'=>$s->id]);
    foreach([[$teacher,['teacher'],401],[$office,['staff'],403],[$family,Contact::FAMILY_TOKEN_ABILITIES,401]] as [$person,$abilities,$status]) {
        $token=$person->createToken('practice-duty-test',$abilities)->plainTextToken;
        foreach(dutyOfficeRoutes($s,$r) as [$method,$url,$body]){\Illuminate\Support\Facades\Auth::forgetGuards();$this->withHeader('Authorization','Bearer '.$token)->{$method.'Json'}($url,$body)->assertStatus($status);}
        foreach(['page','claim','give-back'] as $action){\Illuminate\Support\Facades\Auth::forgetGuards();$this->postJson(dutyPublic($s,$r)."/$action",['token'=>$token])->assertStatus(410);}
    }
});
it('DutyRota HTTP form and year deletion are readable refusals and year edits never strand rota history',function () {
    [$s,$y,$f,$r]=dutySetup();dutyOffice();
    $this->deleteJson("/api/admin/masjids/{$s->id}/forms/{$f->id}")->assertStatus(422);
    $this->deleteJson("/api/admin/masjids/{$s->id}/school-calendar/years/{$y->id}")->assertStatus(422);
    $this->putJson("/api/admin/masjids/{$s->id}/school-calendar/years/{$y->id}",['label'=>'Practice year','first_day'=>'2026-10-04','last_day'=>'2026-11-22'])->assertStatus(422);
});
