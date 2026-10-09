<?php

use App\Models\{ClassAssignment, Group, GroupStaff, LessonPlan, Masjid, MasjidUser, SchoolSubject, User};
use App\Support\{ClassSubjectInitializer, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class SubjectWorkOffMemoProbe
{
    public function handle($request, $next)
    {
        $response = $next($request);
        $count = count(DB::getQueryLog());
        expect(\App\Support\ClassSubjectMode::workEnabled($request->route('masjid_id')))->toBeFalse();
        expect(count(DB::getQueryLog()))->toBe($count);
        return $response;
    }
}

uses(RefreshDatabase::class);

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('work OFF preserves the literal b504a492 bootstrap lesson and gradebook SQL and payloads', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    fake()->seed(8291);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-09 16:00:00', 'UTC'));
    $org = Masjid::create(['name' => 'Practice School', 'email' => 'off@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $group = Group::factory()->create(['masjid_id' => $org->id, 'kind' => 'class', 'name' => 'Practice', 'slug' => 'practice']);
    $teacher = User::factory()->create(['type' => 'Teacher', 'name' => 'Practice Teacher', 'email' => 'teacher@example.invalid', 'phone' => '+15555550101']);
    MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
    GroupStaff::create(['masjid_id' => $org->id, 'group_id' => $group->id, 'user_id' => $teacher->id, 'subjects' => null]);
    SchoolSubject::create(['masjid_id' => $org->id, 'name' => 'Science']);
    ClassSubjectInitializer::run($org, false, true);
    LessonPlan::create(['masjid_id' => $org->id, 'group_id' => $group->id, 'body' => 'Practice', 'subject' => 'Science', 'session_date' => '2026-10-09']);
    ClassAssignment::create(['masjid_id' => $org->id, 'group_id' => $group->id, 'title' => 'Practice', 'subject' => 'Science', 'assigned_on' => '2026-10-09', 'scale' => 'points', 'points_possible' => 10]);
    Sanctum::actingAs($teacher, ['staff']);
    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/teacher') && in_array($route->getActionMethod(), ['show', 'index'], true)) {
            $route->middleware(SubjectWorkOffMemoProbe::class);
        }
    }
    // Start the schema probe in the same cold state as the literal parent capture.
    \App\Support\StudentAge::forget();
    $actual = [];
    foreach (['bootstrap' => '', 'lesson-list' => '/lesson-plans', 'gradebook-list' => '/assignments'] as $key => $suffix) {
        DB::flushQueryLog(); DB::enableQueryLog();
        $response = $this->getJson("/api/teacher/masjids/{$org->id}/groups/{$group->id}".$suffix)->assertOk();
        $actual[$key] = ['sql' => array_column(DB::getQueryLog(), 'query'), 'payload' => $response->json()];
        DB::disableQueryLog();

    }
    $file = base_path('tests/fixtures/subject-work-off-b504a492.json');
    expect($actual)->toBe(json_decode(file_get_contents($file), true));
});
