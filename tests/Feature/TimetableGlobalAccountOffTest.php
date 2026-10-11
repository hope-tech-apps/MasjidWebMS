<?php

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('Timetable global archive and delete match base SQL with no retained data even before migration', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    fake()->seed(5173);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-10 16:00:00', 'UTC'));
    $super = User::factory()->create(['type'=>'SuperAdmin','name'=>'Practice Operator','phone'=>'+15555550600']);
    Sanctum::actingAs($super, ['staff']);
    \App\Models\Masjid::create(['name'=>'Practice School','email'=>'globaloff@example.invalid','phone'=>'+15555550900','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    $actual = [];
    foreach ([false, true] as $beforeMigration) {
        if ($beforeMigration) {
            $hints=base_path('database/migrations/2026_10_11_050000_add_timetable_retention_hints.php');
            if (file_exists($hints)) (require $hints)->down();
            $path = base_path('database/migrations/2026_10_11_040000_create_school_timetable_tables.php');
            if (file_exists($path)) (require $path)->down();
        }
        foreach (['MasjidAdmin', 'Teacher'] as $type) {
            foreach (['archive', 'delete'] as $action) {
                $user = User::factory()->create(['type'=>$type,'name'=>'Practice Account','phone'=>'+15555550601']);
                app(TenantContext::class)->forgetTenant();
                DB::flushQueryLog(); DB::enableQueryLog();
                $r = $this->deleteJson('/api/admin/users/'.$user->id.($action === 'archive' ? '/trash' : ''))->assertOk();
                $actual[$beforeMigration ? 'before' : 'after'][$type.'-'.$action] = ['sql'=>array_column(DB::getQueryLog(), 'query'), 'payload'=>$r->json()];
                DB::disableQueryLog();
            }
        }
    }
    expect($actual)->toBe(json_decode(file_get_contents(base_path('tests/fixtures/timetable-global-accounts-off-30cef6d4.json')), true));
});

it('Timetable unused OFF school teacher removal matches literal base SQL', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class); fake()->seed(7234);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-10 16:00:00', 'UTC'));
    $school=\App\Models\Masjid::create(['name'=>'Practice School','email'=>'practiceoff@example.invalid','phone'=>'+15555550700','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
    $super=User::factory()->create(['type'=>'SuperAdmin','name'=>'Practice Operator','phone'=>'+15555550701']);
    $teacher=User::factory()->create(['type'=>'Teacher','name'=>'Practice Teacher','phone'=>'+15555550702']);
    \App\Models\MasjidUser::create(['masjid_id'=>$school->id,'user_id'=>$teacher->id,'role'=>'teacher','is_default'=>true]);
    Sanctum::actingAs($super,['staff']); DB::flushQueryLog(); DB::enableQueryLog();
    $r=$this->deleteJson("/api/admin/masjids/{$school->id}/teachers/{$teacher->id}")->assertOk();
    $actual=['sql'=>array_column(DB::getQueryLog(),'query'),'payload'=>$r->json()]; DB::disableQueryLog();
    expect($actual)->toBe(json_decode(file_get_contents(base_path('tests/fixtures/timetable-teacher-removal-off-30cef6d4.json')),true));
});
