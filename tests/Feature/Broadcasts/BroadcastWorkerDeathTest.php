<?php

namespace Tests\Feature\Broadcasts;

use App\Enums\BroadcastChannel;
use App\Jobs\SendBroadcastJob;
use App\Models\Announcement;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\BroadcastComposer;
use App\Services\Broadcast\BroadcastDispatcher;
use App\Services\Broadcast\ChannelResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Real SIGKILL and independently opened SQLite connections; does not prove InnoDB locks. */
class BroadcastWorkerDeathTest extends TestCase
{
    public static function phases(): array
    {
        return [
            'after claim, before any driver' => ['before', ['not_sent', 'not_sent', 'not_sent'], 0],
            'after a recorded channel, before the next driver' => ['between', ['sent', 'not_sent', 'not_sent'], 1],
            'after an external effect, before its result' => ['during', ['sent', 'interrupted', 'not_sent'], 1],
            'framework sees the worker timeout' => ['timeout', ['sent', 'interrupted', 'not_sent'], 1],
        ];
    }

    #[Test]
    #[DataProvider('phases')]
    public function killed_process_is_terminal_and_its_external_effect_is_never_replayed(string $phase, array $expected, int $announcementCount): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Requires pcntl and posix for a real worker death.');
        }
        $scratch = tempnam(sys_get_temp_dir(), 'broadcast-death-');
        $barrier = $scratch . '.ready';
        $effect = $scratch . '.effect';
        $errors = $scratch . '.error';
        $previous = DB::getDefaultConnection();
        $pid = null;
        config(['database.connections.broadcast_death' => [
            'driver' => 'sqlite', 'database' => $scratch, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('broadcast_death');
        Carbon::setTestNow('2026-10-07 12:00:00');
        try {
            Artisan::call('migrate:fresh', ['--database' => 'broadcast_death', '--force' => true]);
            $admin = User::factory()->create(['type' => 'MasjidAdmin', 'name' => 'Test Admin', 'phone' => '+15555550102']);
            $org = Masjid::create([
                'name' => 'Test Organisation', 'email' => 'office@example.invalid',
                'phone' => '+15555550101', 'country_id' => '1', 'city_id' => '1',
                'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'user_id' => $admin->id,
            ]);
            $broadcast = app(BroadcastComposer::class)->compose($org, [
                'title' => 'Test notice', 'body' => 'Test body', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08',
            ], [BroadcastChannel::ANNOUNCEMENT, BroadcastChannel::EMAIL, BroadcastChannel::SMS]);
            if ($phase === 'timeout') {
                config(['queue.connections.database.connection' => 'broadcast_death']);
                $job = new SendBroadcastJob($broadcast->id);
                $job->timeout = 1; // Real framework timeout, kept short for the regression.
                app('queue')->connection('database')->push($job);
            }
            DB::disconnect('broadcast_death');
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $pause = function () use ($barrier): never {
                        touch($barrier);
                        // Parent deliberately kills us. A deadline prevents an orphan if it fails.
                        $deadline = microtime(true) + 20;
                        while (microtime(true) < $deadline) usleep(10000);
                        posix_kill(getmypid(), SIGKILL);
                        exit(1);
                    };
                    if (! in_array($phase, ['during', 'timeout'], true)) {
                        DB::listen(function ($query) use ($phase, $broadcast, $pause): void {
                            $sql = strtolower($query->sql);
                            if ($phase === 'before' && DB::transactionLevel() === 0
                                && str_starts_with($sql, 'select') && str_contains($sql, 'broadcast_deliveries')) {
                                $pause();
                            }
                            if ($phase === 'between' && DB::transactionLevel() > 0
                                && str_starts_with($sql, 'select') && str_contains($sql, '"broadcasts"')
                                && DB::table('broadcast_deliveries')->where('broadcast_id', $broadcast->id)->where('status', 'sent')->exists()) {
                                $pause();
                            }
                        });
                    } else {
                        $this->app->bind(BroadcastDispatcher::DRIVERS['email'], fn () => new class($effect, $pause) implements BroadcastChannelDriver {
                            public function __construct(private string $effect, private \Closure $pause) {}
                            public function channel(): BroadcastChannel { return BroadcastChannel::EMAIL; }
                            public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
                            {
                                file_put_contents($this->effect, 'external-send' . PHP_EOL, FILE_APPEND);
                                ($this->pause)();
                            }
                        });
                    }
                    if ($phase === 'timeout') {
                        app('queue.worker')->daemon('database', 'default',
                            new \Illuminate\Queue\WorkerOptions(sleep: 0, maxTries: 1, maxJobs: 1));
                    } else {
                        app(BroadcastDispatcher::class)->dispatch($broadcast);
                    }
                    file_put_contents($errors, 'Child unexpectedly finished dispatch.');
                } catch (\Throwable $e) {
                    file_put_contents($errors, $e::class . ': ' . $e->getMessage());
                }
                posix_kill(getmypid(), SIGKILL);
                exit(1);
            }
            $deadline = microtime(true) + 5;
            while (! file_exists($barrier) && ! file_exists($errors) && microtime(true) < $deadline) usleep(10000);
            $this->assertFileExists($barrier, file_exists($errors) ? file_get_contents($errors) : 'Child did not reach the kill point.');
            Carbon::setTestNow(now()->addMinutes(16));
            $this->artisan('broadcasts:settle-interrupted')->assertExitCode(0);
            $this->assertSame('sending', $broadcast->fresh()->status, 'The live process lock beats the age bound.');
            if ($phase !== 'timeout') posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            $pid = null;
            $this->assertTrue(pcntl_wifsignaled($status));
            $this->assertSame(SIGKILL, pcntl_wtermsig($status));
            if ($phase === 'timeout') {
                $this->assertSame('interrupted', $broadcast->fresh()->status, 'The framework failed callback settles before the sweep.');
            }
            $this->artisan('broadcasts:settle-interrupted')->assertExitCode(0);
            $this->assertSame('interrupted', $broadcast->fresh()->status);
            $this->assertSame($expected, $broadcast->deliveries()->orderBy('id')->pluck('status')->all());
            $job = new SendBroadcastJob($broadcast->id);
            $job->handle(app(BroadcastDispatcher::class));
            (new SendBroadcastJob($broadcast->id))->handle(app(BroadcastDispatcher::class));
            app(BroadcastDispatcher::class)->dispatch($broadcast);
            $this->assertSame($announcementCount, Announcement::count());
            if (in_array($phase, ['during', 'timeout'], true)) {
                $this->assertSame("external-send\n", file_get_contents($effect));
            }
        } finally {
            if ($pid !== null && $pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::purge('broadcast_death');
            DB::setDefaultConnection($previous);
            Carbon::setTestNow();
            foreach ([$scratch, $barrier, $effect, $errors] as $file) {
                if (file_exists($file)) unlink($file);
            }
        }
    }
}
