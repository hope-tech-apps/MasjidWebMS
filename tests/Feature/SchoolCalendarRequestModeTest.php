<?php

namespace Tests\Feature;

use App\Models\{Masjid, User};
use App\Support\SchoolCalendarRequestMode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchoolCalendarRequestModeTest extends TestCase
{
    use RefreshDatabase;

    private function school(): Masjid
    {
        return Masjid::create(['name' => 'Mode school', 'email' => 'mode@example.invalid', 'phone' => '+15550007701', 'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    }

    private function http(callable $checks): void
    {
        $request = Request::create('/mode');
        $this->app->instance('request', $request);
        (new \App\Http\Middleware\SchoolCalendarHttpRequest)->handle($request, function () use ($checks) {
            $checks();
            return response('ok');
        });
        $this->assertFalse($request->attributes->has('school_calendar_terms_decisions'));
        $this->assertFalse($request->attributes->has('school_calendar_terms_rows'));
    }

    #[Test]
    public function a_loaded_full_row_is_reused_but_a_partial_projection_is_not(): void
    {
        $org = $this->school();
        $this->http(function () use ($org) {
            Masjid::findOrFail($org->id);
            DB::flushQueryLog(); DB::enableQueryLog();
            for ($i = 0; $i < 10; $i++) $this->assertFalse(SchoolCalendarRequestMode::enabled($org->id));
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
        });
        $this->http(function () use ($org) {
            Masjid::select('id', 'name')->findOrFail($org->id);
            DB::flushQueryLog(); DB::enableQueryLog();
            for ($i = 0; $i < 10; $i++) $this->assertFalse(SchoolCalendarRequestMode::enabled($org->id));
            $this->assertCount(1, DB::getQueryLog());
            DB::disableQueryLog();
        });
    }

    #[Test]
    public function missing_rows_are_memoised_and_decisions_do_not_cross_request_boundaries(): void
    {
        $org = $this->school();
        $this->http(function () use ($org) {
            DB::flushQueryLog(); DB::enableQueryLog();
            for ($i = 0; $i < 10; $i++) $this->assertFalse(SchoolCalendarRequestMode::enabled($org->id + 100));
            $this->assertCount(1, DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertFalse(SchoolCalendarRequestMode::enabled($org->id));
            DB::table('masjids')->where('id', $org->id)->update(['capability_overrides' => '{"school_calendar_terms":true}']);
            $this->assertFalse(SchoolCalendarRequestMode::enabled($org->id), 'An in-flight OFF request keeps its decision');
        });
        $this->http(fn () => $this->assertTrue(SchoolCalendarRequestMode::enabled($org->id)));
    }

    #[Test]
    public function missing_calendar_school_keeps_mains_empty_response_without_a_second_lookup(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550007702']));
        DB::flushQueryLog(); DB::enableQueryLog();
        $response = $this->getJson('/api/admin/masjids/9999/school-calendar');
        $sql = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();
        $response->assertOk()->assertJsonPath('data.years', [])->assertJsonPath('data.timezone', 'UTC');
        $this->assertCount(2, $sql);
        $this->assertStringContainsString('from "masjids"', $sql[0]);
        $this->assertStringContainsString('from "school_years"', $sql[1]);
        $this->postJson('/api/admin/masjids/9999/school-calendar/years', [])->assertUnprocessable()->assertJsonValidationErrors('label', 'data');
        $this->deleteJson('/api/admin/masjids/9999/school-calendar/years/9999')->assertNotFound();
    }

    #[Test]
    public function a_soft_deleted_row_loaded_with_trashed_does_not_activate_a_missing_school(): void
    {
        $org = $this->school();
        $org->forceFill(['capability_overrides' => ['school_calendar_terms' => true]])->save();
        $org->delete();
        $this->http(function () use ($org) {
            Masjid::withTrashed()->findOrFail($org->id);
            $this->assertFalse(SchoolCalendarRequestMode::enabled($org->id));
        });
    }

    #[Test]
    public function optional_lookup_failure_does_not_replace_mains_validation_response(): void
    {
        $org = $this->school();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550007703']));
        $failures = 0;
        DB::connection()->beforeExecuting(function ($sql) use (&$failures) {
            if ($failures || ! str_starts_with($sql, 'select * from "masjids"')) return;
            $failures++;
            throw new QueryException('sqlite', $sql, [], new \PDOException('Optional lookup failed'));
        });
        $this->postJson('/api/admin/masjids/'.$org->id.'/school-calendar/years', [])->assertUnprocessable()->assertJsonValidationErrors('label', 'data');
        $this->assertSame(1, $failures);
    }
}
