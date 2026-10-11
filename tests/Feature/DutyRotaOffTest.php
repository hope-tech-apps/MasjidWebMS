<?php

use App\Models\{Form, Masjid, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, DB};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('DutyRota OFF pins literal public submit office list SQL payload and existing school scheduled output at 52daab37', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    fake()->seed(1417);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-11 18:00:00', 'UTC'));
    \Illuminate\Support\Str::createUuidsUsing(fn () => '11111111-1111-4111-8111-111111111111');
    $school = Masjid::create(['name'=>'Practice School','email'=>'school@example.invalid','phone'=>'+15555550100','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    $form = Form::create(['masjid_id'=>$school->id,'name'=>'Practice registration','slug'=>'practice-registration','is_active'=>true,'schema'=>['sections'=>[['id'=>'family','fields'=>[['name'=>'name','label'=>'Name','type'=>'text','required'=>true],['name'=>'email','label'=>'Email','type'=>'email']]]]],'settings'=>['identity'=>['name'=>'name','email'=>'email']]]);
    \App\Support\FormAnswersText::forget();
    $actual=[];
    DB::flushQueryLog(); DB::enableQueryLog();
    $r=$this->withHeader('masjid-id', $school->id)->postJson("/api/v1/forms/{$form->id}/responses", ['data'=>['name'=>'Maple Family','email'=>'maple@example.invalid']])->assertSuccessful();
    $actual['submit']=['sql'=>array_column(DB::getQueryLog(),'query'),'payload'=>$r->json()]; DB::disableQueryLog();
    Sanctum::actingAs(User::factory()->create(['type'=>'SuperAdmin','name'=>'Practice Office','email'=>'office@example.invalid','phone'=>'+15555550101']), ['staff']);
    DB::flushQueryLog(); DB::enableQueryLog();
    $r=$this->getJson("/api/admin/masjids/{$school->id}/forms/{$form->id}/responses")->assertOk();
    $actual['list']=['sql'=>array_column(DB::getQueryLog(),'query'),'payload'=>$r->json()]; DB::disableQueryLog();
    foreach (['points:weekly-report','bucks:mint','bucks:expire'] as $command) {
        Artisan::call($command); $actual[$command]=Artisan::output();
    }
    \Illuminate\Support\Str::createUuidsNormally();
    expect($actual)->toBe(json_decode(file_get_contents(base_path('tests/fixtures/duty-rota-off-52daab37.json')), true));
});
