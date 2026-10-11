<?php

use App\Models\{Group, GroupStaff, Masjid, MasjidUser, User};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class TimetableOffMemoProbe
{
    public function handle($request, $next)
    {
        $response = $next($request); $count = count(DB::getQueryLog());
        expect(\App\Support\ClassSubjectMode::timetableEnabled($request->route('masjid_id')))->toBeFalse();
        expect(count(DB::getQueryLog()))->toBe($count);
        return $response;
    }
}

uses(RefreshDatabase::class);
afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('timetable OFF keeps literal 30cef6d4 office class settings capabilities and teacher list SQL and payloads', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    fake()->seed(9152);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-10 16:00:00', 'UTC'));
    $school = Masjid::create(['name'=>'Practice School', 'email'=>'practice@example.invalid', 'phone'=>'+15555550100', 'country_id'=>'1', 'city_id'=>'1', 'address'=>'Practice', 'latitude'=>0, 'longitude'=>0, 'org_type'=>'school', 'crm_enabled'=>true]);
    $group = Group::factory()->create(['masjid_id'=>$school->id, 'kind'=>'class', 'name'=>'Practice Class', 'slug'=>'practice-class']);
    $office = User::factory()->create(['type'=>'SuperAdmin', 'name'=>'Practice Office', 'email'=>'office@example.invalid', 'phone'=>'+15555550101']);
    $teacher = User::factory()->create(['type'=>'Teacher', 'name'=>'Practice Teacher', 'email'=>'teacher@example.invalid', 'phone'=>'+15555550102']);
    MasjidUser::create(['masjid_id'=>$school->id, 'user_id'=>$teacher->id, 'role'=>'teacher', 'is_default'=>true]);
    GroupStaff::create(['masjid_id'=>$school->id, 'group_id'=>$group->id, 'user_id'=>$teacher->id, 'subjects'=>null]);
    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (str_contains($route->uri(), 'masjids/{masjid_id}')) $route->middleware(TimetableOffMemoProbe::class);
    }
    \App\Support\StudentAge::forget();
    $actual = [];
    foreach (['office-class'=>[$office,"/api/admin/masjids/{$school->id}/groups/{$group->id}"], 'settings'=>[$office,"/api/admin/masjids/{$school->id}"], 'capabilities'=>[$office,"/api/admin/masjids/{$school->id}/capabilities"], 'teacher-list'=>[$teacher,"/api/teacher/masjids/{$school->id}/groups"]] as $name=>[$user,$url]) {
        Sanctum::actingAs($user, ['staff']); DB::flushQueryLog(); DB::enableQueryLog();
        $r = $this->getJson($url)->assertOk();
        $actual[$name] = ['sql'=>array_column(DB::getQueryLog(), 'query'), 'payload'=>$r->json()]; DB::disableQueryLog();
    }
    expect($actual)->toBe(json_decode(file_get_contents(base_path('tests/fixtures/school-timetable-off-30cef6d4.json')), true));
});


it('Timetable OFF account changes and deletes add no timetable query in the bound school', function () {
    $school=Masjid::create(['name'=>'Practice School','email'=>'practice@example.invalid','phone'=>'+15555550400','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    $teacher=User::factory()->create(['type'=>'Teacher','name'=>'Practice Teacher','phone'=>'+15555550401']);
    app(TenantContext::class)->set($school->id);
    expect(\App\Support\ClassSubjectMode::timetableEnabled($school->id))->toBeFalse();
    DB::flushQueryLog(); DB::enableQueryLog();
    $teacher->forceFill(['type'=>'MasjidAdmin'])->save(); $teacher->delete();
    $queries=array_column(DB::getQueryLog(),'query'); DB::disableQueryLog();
    expect(array_values(array_filter($queries,fn($sql)=>str_contains($sql,'timetable_'))))->toBe([]);
});
