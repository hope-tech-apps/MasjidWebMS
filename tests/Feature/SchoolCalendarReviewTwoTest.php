<?php

namespace Tests\Feature;

use App\Models\{Form, FormResponse, Masjid, SchoolYear, User};
use App\Support\{SchoolCalendarRequestMode, SchoolDateAuthority};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\{Test, DataProvider};
use Tests\TestCase;

// Serialized by DatabaseQueue and executed by CallQueuedHandler in the real daemon.
class CalendarModeProbeJob implements ShouldQueue
{
    public function __construct(public int $org, public bool $flip) {}

    public function handle(): void
    {
        $enabled = SchoolCalendarRequestMode::enabled($this->org);
        $days = SchoolDateAuthority::for($this->org)->meetingDays(SchoolYear::where('masjid_id', $this->org)->firstOrFail());
        app('calendar.probes')->push([$enabled, $days, spl_object_id(request())]);
        if ($this->flip) DB::table('masjids')->where('id', $this->org)->update(['capability_overrides' => '{"school_calendar":true,"school_calendar_terms":true}']);
    }
}

class SchoolCalendarReviewTwoTest extends TestCase
{
    use RefreshDatabase;

    private function school(bool $on = true): Masjid
    {
        $org = Masjid::create(['name' => 'Review school', 'email' => 'review@example.invalid', 'phone' => '+15550007801', 'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
        $org->forceFill(['capability_overrides' => ['school_calendar' => true, 'school_calendar_terms' => $on]])->save();
        return $org;
    }

    public static function edits(): array
    {
        return [
            'weekday checkbox array' => ['2026-10-18', ['first_day' => '2026-10-12', 'last_day' => '2026-10-26', 'meeting_weekdays' => [1]], 'school_meeting_days', 'checkboxGroup', true],
            'first bound select scalar' => ['2026-10-11', ['first_day' => '2026-10-12'], 'school_meeting_days', 'select', true],
            'last bound' => ['2026-10-25', ['last_day' => '2026-10-19'], 'school_meeting_days', 'checkboxGroup', true],
            'retained answer' => ['2026-10-18', ['last_day' => '2026-10-19'], 'school_meeting_days', 'checkboxGroup', false],
            'own date source' => ['2026-10-18', ['first_day' => '2026-10-12', 'last_day' => '2026-10-26', 'meeting_weekdays' => [1]], 'reservable_dates', 'checkboxGroup', false],
        ];
    }

    #[Test, DataProvider('edits')]
    public function on_year_edits_protect_only_sourced_answers_that_would_leave_the_calendar(string $answer, array $edit, string $source, string $type, bool $refused): void
    {
        $org = $this->school();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550007802']));
        $year = SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-26', 'meeting_weekdays' => [0, 1]]);
        $form = Form::create(['masjid_id' => $org->id, 'slug' => 'review-days', 'name' => 'Days', 'schema' => ['sections' => [['id' => 'days', 'title' => 'Days', 'fields' => [['name' => 'days', 'label' => 'Days', 'type' => $type, 'optionsSource' => $source]]]]]]);
        FormResponse::create(['submitted_at' => now(), 'masjid_id' => $org->id, 'form_id' => $form->id, 'data' => ['days' => $type === 'select' ? $answer : [$answer]]]);
        $before = $year->fresh()->getRawOriginal();
        $body = $edit + ['label' => 'Changed', 'first_day' => '2026-10-11', 'last_day' => '2026-10-26', 'meeting_weekdays' => [0, 1], 'term_system' => null];
        $response = $this->putJson('/api/admin/masjids/'.$org->id.'/school-calendar/years/'.$year->id, $body);
        if ($refused) {
            $field = isset($edit['meeting_weekdays']) ? 'meeting_weekdays' : 'first_day';
            $response->assertUnprocessable()->assertJsonPath('data.'.$field.'.0', 'This change would remove a school day named by 1 form answer. Keep those days or change those answers first.');
            $this->assertSame($before, $year->fresh()->getRawOriginal());
        } else $response->assertOk();
        $this->assertSame(1, FormResponse::count());
    }

    #[Test]
    public function two_serialized_jobs_through_the_real_worker_reset_see_a_flip_with_the_same_console_request(): void
    {
        $org = $this->school(false);
        SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-19', 'meeting_weekdays' => [0, 1]]);
        $this->app->instance('calendar.probes', collect());
        $this->app->scoped('calendar.reset.probe', fn () => new \stdClass);
        $scopes = [];
        $this->app['events']->listen(\Illuminate\Queue\Events\JobProcessing::class, function () use (&$scopes) { $scopes[] = app('calendar.reset.probe'); });
        config(['queue.connections.calendar_test' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'calendar', 'retry_after' => 90, 'after_commit' => false]]);
        $queue = app('queue')->connection('calendar_test');
        $queue->push(new CalendarModeProbeJob($org->id, true));
        $queue->push(new CalendarModeProbeJob($org->id, false));
        $worker = app('queue.worker');
        $worker->setCache(app('cache')->store('array'));
        $status = $worker->daemon('calendar_test', 'calendar', new WorkerOptions(memory: 8192, sleep: 0, maxTries: 1, force: true, stopWhenEmpty: true, maxJobs: 2));
        $this->assertSame(0, $status);
        $this->assertCount(2, $scopes);
        $this->assertNotSame($scopes[0], $scopes[1], 'The framework reset ran between jobs');
        $probes = app('calendar.probes')->all();
        $this->assertCount(2, $probes);
        $this->assertSame([false, ['2026-10-11', '2026-10-18']], array_slice($probes[0], 0, 2));
        $this->assertSame([true, ['2026-10-11', '2026-10-12', '2026-10-18', '2026-10-19']], array_slice($probes[1], 0, 2));
        $this->assertSame($probes[0][2], $probes[1][2], 'Console Request survives the worker reset');
        $this->assertFalse(request()->attributes->has('school_calendar_terms_decisions'));
        $this->assertFalse(request()->attributes->has('school_calendar_terms_rows'));
    }

    #[Test]
    public function console_and_scheduled_calls_resolve_fresh_even_without_a_worker_reset(): void
    {
        $org = $this->school(false);
        $this->assertFalse(SchoolCalendarRequestMode::enabled($org->id));
        DB::table('masjids')->where('id', $org->id)->update(['capability_overrides' => '{"school_calendar_terms":true}']);
        $this->assertTrue(SchoolCalendarRequestMode::enabled($org->id));
        $this->assertFalse(request()->attributes->has('school_calendar_terms_decisions'));
        $this->assertFalse(request()->attributes->has('school_calendar_terms_rows'));
    }
    #[Test]
    public function a_long_lived_http_kernel_clears_the_memo_even_when_reusing_its_request_object(): void
    {
        $org = $this->school(false);
        $reads = [];
        \Illuminate\Support\Facades\Route::get('/api/calendar-mode-probe', function () use ($org, &$reads) {
            $reads[] = SchoolCalendarRequestMode::enabled($org->id);
            DB::table('masjids')->where('id', $org->id)->update(['capability_overrides' => '{"school_calendar_terms":true}']);
            $reads[] = SchoolCalendarRequestMode::enabled($org->id);
            return response('ok');
        });
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $request = \Illuminate\Http\Request::create('/api/calendar-mode-probe', server: ['HTTP_ACCEPT' => 'application/json']);
        for ($i = 0; $i < 2; $i++) {
            $response = $kernel->handle($request);
            $this->assertSame(200, $response->getStatusCode(), $response->getContent());
            $kernel->terminate($request, $response);
            $this->assertFalse($request->attributes->has('school_calendar_terms_decisions'));
            $this->assertFalse($request->attributes->has('school_calendar_terms_rows'));
            $this->assertFalse($request->attributes->has('school_calendar_terms_http'));
        }
        $this->assertSame([false, false, true, true], $reads);
    }

}
