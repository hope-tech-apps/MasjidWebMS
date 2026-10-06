<?php

namespace Tests\Feature;

use App\Enums\GroupNotificationEvent;
use App\Jobs\ProcessFlyerCutout;
use App\Jobs\SendBroadcastJob;
use App\Jobs\SendGroupNotificationJob;
use App\Jobs\SendMasjidNotificationJob;
use App\Jobs\SendPrayerSyncJob;
use App\Models\Masjid;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class QueueRetryWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'queue.connections.database.connection' => 'sqlite',
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'default',
        ]);

        $this->travelTo(now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public static function queuedJobs(): array
    {
        return [
            'broadcast overrides the service timeout' => [SendBroadcastJob::class, 300],
            'group notification overrides the service timeout' => [SendGroupNotificationJob::class, 120],
            'cutout resolves its timeout from config' => [ProcessFlyerCutout::class, 80],
            'push has a shorter timeout' => [SendMasjidNotificationJob::class, 30],
            'prayer sync inherits the service timeout' => [SendPrayerSyncJob::class, 90],
        ];
    }

    #[Test]
    #[DataProvider('queuedJobs')]
    public function a_live_reservation_cannot_be_taken_until_after_its_worker_timeout(string $class, int $expectedTimeout): void
    {
        $queue = app('queue')->connection('database');
        $job = $this->job($class);
        $id = $queue->push($job);
        $first = $queue->pop();
        $this->assertInstanceOf(DatabaseJob::class, $first);
        $this->assertSame(1, $first->attempts());

        // Resolve through the installed worker and the serialized payload: the
        // service's --timeout is only the fallback, not a cap on job timeouts.
        $timeout = $this->effectiveTimeout($first);
        $this->assertSame($expectedTimeout, $timeout);
        $reservedAt = now();

        foreach (array_unique([min(90, $timeout), $timeout]) as $elapsed) {
            $this->travelTo($reservedAt->copy()->addSeconds($elapsed));
            $this->assertTrue($queue->pop() === null, "{$class} was reservable at {$elapsed}s while its worker could still run it.");
        }

        $retryAfter = (int) config('queue.connections.database.retry_after');
        $this->travelTo($reservedAt->copy()->addSeconds($retryAfter - 1));
        $this->assertTrue($queue->pop() === null);

        // Recovery still works if the first worker died without deleting its
        // reservation. No real sends, waits, queue fakes or external database.
        $this->travelTo($reservedAt->copy()->addSeconds($retryAfter));
        $retry = $queue->pop();
        $this->assertInstanceOf(DatabaseJob::class, $retry);
        $this->assertEquals($id, $retry->getJobId());
        $this->assertSame(2, $retry->attempts());
    }

    #[Test]
    public function the_old_window_allows_a_second_consumer_to_fail_a_still_reserved_one_try_job(): void
    {
        config(['queue.connections.database.retry_after' => 90]);
        $queue = app('queue')->connection('database');
        $id = $queue->push(new SendBroadcastJob(999999));
        $first = $queue->pop();
        $this->assertSame(300, $this->effectiveTimeout($first));

        $this->travel(89)->seconds();
        $this->assertNull($queue->pop());
        $this->travel(1)->seconds();
        $second = $queue->pop();
        $this->assertInstanceOf(DatabaseJob::class, $second);
        $this->assertEquals($id, $second->getJobId());
        $this->assertSame(2, $second->attempts());

        // Both fan-out jobs use tries=1. The second worker fails the job before
        // handle(), rather than necessarily sending everything a second time.
        try {
            app('queue.worker')->process('database', $second, $this->workerOptions());
            $this->fail('The second attempt must exceed the broadcast job retry policy.');
        } catch (MaxAttemptsExceededException) {
            $this->assertTrue($second->hasFailed());
            $this->assertFalse($first->isDeleted());
            $this->assertSame(0, DB::table('jobs')->count());
        }
    }

    #[Test]
    public function one_worker_can_finish_after_the_old_window_without_a_second_attempt(): void
    {
        config(['queue.connections.database.retry_after' => 90]);
        $queue = app('queue')->connection('database');
        $queue->push(new SendBroadcastJob(999999));
        $first = $queue->pop();
        $this->travel(100)->seconds();

        // A reservation does not expire actively: only a subsequent pop can
        // take it. This job's nonexistent broadcast makes handle() a safe no-op.
        app('queue.worker')->process('database', $first, $this->workerOptions());

        $this->assertSame(1, $first->attempts());
        $this->assertFalse($first->hasFailed());
        $this->assertTrue($first->isDeleted());
        $this->assertNull($queue->pop());
    }

    #[Test]
    public function every_declared_application_job_timeout_and_the_worker_fallback_fit_inside_the_window(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $this->assertGreaterThan($this->workerOptions()->timeout, $retryAfter);

        // Every queued class under app/, wherever it lives: the next long job
        // will not necessarily be added to Jobs/ or Mail/.
        $checked = 0;
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php' || ! str_contains($file->getContents(), 'ShouldQueue')) {
                continue;
            }

            $class = 'App\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $checked++;
            $timeout = $reflection->getDefaultProperties()['timeout'] ?? null;
            if ($timeout !== null) {
                $this->assertGreaterThan(0, $timeout, "{$class} must have a finite timeout.");
                $this->assertGreaterThan($timeout, $retryAfter, "{$class} timeout must be below database retry_after.");
            }
        }

        // A scan that finds nothing passes for the wrong reason.
        $this->assertGreaterThan(5, $checked, 'The scan found too few queued classes to be believed.');
    }

    private function job(string $class): ShouldQueue
    {
        return match ($class) {
            SendBroadcastJob::class => new SendBroadcastJob(999999),
            SendGroupNotificationJob::class => new SendGroupNotificationJob(999999, 999999, GroupNotificationEvent::CLASS_STORY),
            ProcessFlyerCutout::class => new ProcessFlyerCutout(999999),
            SendMasjidNotificationJob::class => new SendMasjidNotificationJob(new Notification, new Masjid, []),
            SendPrayerSyncJob::class => new SendPrayerSyncJob(999999, []),
        };
    }

    private function effectiveTimeout(DatabaseJob $job): int
    {
        return (new ReflectionMethod(Worker::class, 'timeoutForJob'))
            ->invoke(app('queue.worker'), $job, $this->workerOptions());
    }

    private function workerOptions(): WorkerOptions
    {
        $service = File::get(base_path('deploy/masjid-queue.service'));
        $this->assertSame(1, preg_match('/^ExecStart=.*--timeout=(\d+)/m', $service, $match));

        return new WorkerOptions(timeout: (int) $match[1], maxTries: 3);
    }
}
