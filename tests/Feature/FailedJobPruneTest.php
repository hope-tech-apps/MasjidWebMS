<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FailedJobPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['queue.failed.driver' => 'database-uuids', 'queue.failed.database' => 'sqlite']);
        $this->travelTo(now()->startOfSecond());
    }

    private function pruneEvent(): Event
    {
        Artisan::call('schedule:list');
        $events = array_values(array_filter(app(Schedule::class)->events(),
            fn (Event $event) => str_contains($event->command ?? '', 'queue:prune-failed')));
        $this->assertCount(1, $events, 'Failed-job retention must be scheduled exactly once.');

        return $events[0];
    }

    private function failedJob(int $daysAgo): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{"fixture":"synthetic personal data"}',
            'exception' => 'Synthetic failure',
            'failed_at' => now()->subDays($daysAgo),
        ]);

        return $uuid;
    }

    #[Test]
    public function the_default_daily_schedule_keeps_thirty_days(): void
    {
        $this->assertSame(30, config('queue.failed.retention_days'));
        $event = $this->pruneEvent();
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--hours=720', $event->command);
    }

    #[Test]
    public function the_scheduled_command_prunes_old_failures_and_never_touches_jobs(): void
    {
        $old = $this->failedJob(31);
        $recent = $this->failedJob(29);
        $boundary = $this->failedJob(30);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{"fixture":"queued work"}',
            'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subDays(31)->timestamp,
            'created_at' => now()->subDays(31)->timestamp,
        ]);
        $jobs = DB::table('jobs')->get()->toArray();

        // Execute the scheduled command in-process so it sees the in-memory fixtures.
        $this->assertSame(0, Artisan::call(strstr($this->pruneEvent()->command, 'queue:prune-failed')));

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $old]);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $recent]);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $boundary]);
        $this->assertEquals($jobs, DB::table('jobs')->get()->toArray());
    }

    #[Test]
    public function the_schedule_uses_the_configured_retention_window(): void
    {
        config(['queue.failed.retention_days' => 60]);
        // Console routes were loaded at application boot, before this override.
        app()->instance(Schedule::class, new Schedule(config('app.timezone')));
        \Illuminate\Support\Facades\Schedule::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');
        $event = $this->pruneEvent();
        $this->assertStringContainsString('--hours=1440', $event->command);

        $kept = $this->failedJob(31);
        $removed = $this->failedJob(61);
        $this->assertSame(0, Artisan::call(strstr($event->command, 'queue:prune-failed')));

        $this->assertDatabaseHas('failed_jobs', ['uuid' => $kept]);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $removed]);
    }
}
