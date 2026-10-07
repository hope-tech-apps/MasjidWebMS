<?php

namespace Tests\Feature\Broadcasts;

use App\Enums\BroadcastChannel;
use App\Jobs\SendBroadcastJob;
use App\Models\Broadcast;
use App\Models\ContactTag;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\BroadcastComposer;
use App\Services\Broadcast\BroadcastDispatcher;
use App\Services\Broadcast\ChannelResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BroadcastInterruptionTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $organisation;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'name' => 'Test Admin', 'phone' => '+15555550102']);
        $this->organisation = Masjid::create([
            'name' => 'Test Organisation', 'email' => 'office@example.invalid',
            'phone' => '+15555550101', 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0,
            'user_id' => $this->admin->id, 'crm_enabled' => true,
        ]);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function broadcast(): Broadcast
    {
        return app(BroadcastComposer::class)->compose($this->organisation, [
            'title' => 'Test notice', 'body' => 'Test body', 'scheduled_at' => now()->addHour(),
        ], [BroadcastChannel::ANNOUNCEMENT, BroadcastChannel::EMAIL, BroadcastChannel::SMS], authorId: $this->admin->id);
    }

    private function claimed(Broadcast $broadcast, SendBroadcastJob $job): void
    {
        // Simulate the durable claim after the sender created its process-lock inode.
        $directory = storage_path('framework/broadcast-send-locks');
        if (! is_dir($directory)) mkdir($directory, 0775, true);
        touch($directory . '/' . $broadcast->id . '.lock');
        $broadcast->forceFill([
            'status' => 'sending', 'sending_started_at' => now(), 'send_claim_token' => $job->claimToken,
        ])->save();
    }

    private function observeDrivers(): object
    {
        $calls = (object) ['channels' => []];
        foreach (BroadcastDispatcher::DRIVERS as $channel => $driver) {
            $this->app->bind($driver, fn () => new class($channel, $calls) implements BroadcastChannelDriver {
                public function __construct(private string $name, private object $calls) {}
                public function channel(): BroadcastChannel { return BroadcastChannel::from($this->name); }
                public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
                {
                    $this->calls->channels[] = $this->name;
                    return ChannelResult::sent(1);
                }
            });
        }
        return $calls;
    }

    public static function deaths(): array
    {
        return [
            'before any channel' => [[], ['not_sent', 'not_sent', 'not_sent']],
            'during first channel' => [['announcement' => 'sending'], ['interrupted', 'not_sent', 'not_sent']],
            'after one finished' => [['announcement' => 'sent'], ['sent', 'not_sent', 'not_sent']],
            'during second channel' => [['announcement' => 'sent', 'email' => 'sending'], ['sent', 'interrupted', 'not_sent']],
        ];
    }

    #[Test]
    #[DataProvider('deaths')]
    public function failed_job_settles_without_replaying_any_channel(array $outcomes, array $expected): void
    {
        $broadcast = $this->broadcast();
        $job = new SendBroadcastJob($broadcast->id);
        $this->claimed($broadcast, $job);
        foreach ($outcomes as $channel => $status) {
            $broadcast->deliveries()->where('channel', $channel)->update([
                'status' => $status,
                ...($status === 'sent' ? ['target_count' => 9, 'reference' => 'test-reference', 'delivered_at' => now()] : []),
            ]);
        }
        $finished = $broadcast->deliveries()->where('status', 'sent')->get()->toArray();
        $calls = $this->observeDrivers();
        $job->failed(new \RuntimeException('Worker ended'));
        $this->assertSame('interrupted', $broadcast->fresh()->status);
        $this->assertSame($expected, $broadcast->deliveries()->orderBy('id')->pluck('status')->all());
        $this->assertSame($finished, $broadcast->deliveries()->where('status', 'sent')->get()->toArray());
        $job->failed(null); // Idempotent, including timestamps and notes.
        $before = $broadcast->fresh()->load('deliveries')->toArray();
        $job->handle(app(BroadcastDispatcher::class));
        (new SendBroadcastJob($broadcast->id))->handle(app(BroadcastDispatcher::class));
        app(BroadcastDispatcher::class)->dispatch($broadcast); // stale model
        $this->assertSame([], $calls->channels);
        $this->assertSame($before, $broadcast->fresh()->load('deliveries')->toArray());
    }

    #[Test]
    public function channel_start_is_committed_before_driver_and_live_sends_survive_the_sweep(): void
    {
        $broadcast = $this->broadcast();
        $calls = $this->observeDrivers();
        $test = $this;
        $this->app->bind(BroadcastDispatcher::DRIVERS['announcement'], fn () => new class($test, $calls) implements BroadcastChannelDriver {
            public function __construct(private object $test, private object $calls) {}
            public function channel(): BroadcastChannel { return BroadcastChannel::ANNOUNCEMENT; }
            public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
            {
                $this->test->assertSame('sending', $broadcast->deliveries()->where('channel', 'announcement')->value('status'));
                $this->test->assertNotNull($broadcast->fresh()->sending_started_at);
                // Immediate sends have no queue timeout. A live process must win even at 16 minutes.
                Carbon::setTestNow(now()->addMinutes(16));
                $this->test->artisan('broadcasts:settle-interrupted')->assertExitCode(0);
                $this->test->assertSame('sending', $broadcast->fresh()->status);
                $this->calls->channels[] = 'announcement';
                return ChannelResult::sent(1);
            }
        });
        $result = app(BroadcastDispatcher::class)->dispatch($broadcast);
        $this->assertSame('sent', $result->status);
        $this->assertSame(['announcement', 'email', 'sms'], $calls->channels);
    }

    #[Test]
    public function sweep_settles_only_stale_sending_claims_and_is_scheduled(): void
    {
        $old = $this->broadcast();
        $fresh = $this->broadcast();
        $scheduled = $this->broadcast();
        $this->claimed($old, new SendBroadcastJob($old->id));
        Carbon::setTestNow(now()->addMinutes(15));
        $this->claimed($fresh, new SendBroadcastJob($fresh->id));
        $this->artisan('broadcasts:settle-interrupted')->assertExitCode(0);
        $this->assertSame('sending', $old->fresh()->status, 'Exactly the bound is still fresh.');
        Carbon::setTestNow(now()->addSecond());
        $this->artisan('broadcasts:settle-interrupted')->assertExitCode(0);
        $this->assertSame('interrupted', $old->fresh()->status);
        $this->assertSame('sending', $fresh->fresh()->status);
        $this->assertSame('scheduled', $scheduled->fresh()->status);
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $event = $events->first(fn ($event) => str_contains($event->command ?? '', 'broadcasts:settle-interrupted'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    #[Test]
    public function legacy_claims_have_unknown_pending_outcomes_and_never_replay(): void
    {
        $broadcast = $this->broadcast();
        $broadcast->forceFill(['status' => 'sending', 'updated_at' => now()->subHour()])->save();
        $broadcast->deliveries()->where('channel', 'announcement')->update(['status' => 'sent']);
        $this->artisan('broadcasts:settle-interrupted')->assertExitCode(0);
        $this->assertSame('interrupted', $broadcast->fresh()->status);
        $this->assertSame(['sent', 'interrupted', 'interrupted'], $broadcast->deliveries()->orderBy('id')->pluck('status')->all());
    }

    public static function finishedOutcomes(): array
    {
        return [
            'all sent' => [['sent', 'sent', 'sent'], 'interrupted'],
            'some failed' => [['sent', 'failed', 'skipped'], 'partial'],
            'all failed' => [['failed', 'failed', 'failed'], 'interrupted'],
            'all skipped' => [['skipped', 'skipped', 'skipped'], 'interrupted'],
        ];
    }

    #[Test]
    #[DataProvider('finishedOutcomes')]
    public function death_after_all_results_is_terminal_with_an_honest_partial_rollup(array $statuses, string $expected): void
    {
        $broadcast = $this->broadcast();
        $job = new SendBroadcastJob($broadcast->id);
        $this->claimed($broadcast, $job);
        foreach ($broadcast->deliveries()->orderBy('id')->get() as $i => $delivery) {
            $delivery->forceFill(['status' => $statuses[$i], 'delivered_at' => now()])->save();
        }
        $job->failed(null);
        $this->assertSame($expected, $broadcast->fresh()->status);
        $calls = $this->observeDrivers();
        app(BroadcastDispatcher::class)->dispatch($broadcast);
        $this->assertSame([], $calls->channels, 'A recovered claim is terminal even if rollup is failed or partial.');
    }

    #[Test]
    public function unrelated_failure_and_non_sending_rows_are_untouched(): void
    {
        $broadcast = $this->broadcast();
        (new SendBroadcastJob($broadcast->id))->failed(null);
        $this->assertSame('scheduled', $broadcast->fresh()->status);
        $job = new SendBroadcastJob($broadcast->id);
        $this->claimed($broadcast, $job);
        (new SendBroadcastJob($broadcast->id))->failed(null);
        $this->assertSame('sending', $broadcast->fresh()->status);
        (new SendBroadcastJob(PHP_INT_MAX))->failed(null);
    }

    public static function unsafeDeliveries(): array
    {
        return ['unknown' => ['interrupted'], 'never sent' => ['not_sent'], 'active' => ['sending']];
    }

    #[Test]
    #[DataProvider('unsafeDeliveries')]
    public function even_a_wrong_parent_state_cannot_reopen_terminal_deliveries(string $status): void
    {
        $broadcast = $this->broadcast();
        $broadcast->deliveries()->where('channel', 'email')->update(['status' => $status]);
        $calls = $this->observeDrivers();
        app(BroadcastDispatcher::class)->dispatch($broadcast);
        $this->assertSame([], $calls->channels);
        $this->assertSame('scheduled', $broadcast->fresh()->status);
    }

    #[Test]
    public function interrupted_history_detail_cancel_and_tag_deletion_agree(): void
    {
        $tag = ContactTag::create(['masjid_id' => $this->organisation->id, 'name' => 'Test audience']);
        $broadcast = $this->broadcast();
        $broadcast->forceFill(['status' => 'interrupted', 'audience' => 'tag', 'audience_tag_id' => $tag->id])->save();
        $broadcast->deliveries()->where('channel', 'email')->update(['status' => 'interrupted']);
        $broadcast->deliveries()->where('channel', 'sms')->update(['status' => 'not_sent']);
        $url = "/api/admin/masjids/{$this->organisation->id}/broadcasts";
        $this->getJson($url)->assertOk()->assertJsonPath('data.data.0.status', 'interrupted')->assertJsonPath('data.data.0.cancellable', false);
        $this->getJson("$url/{$broadcast->id}")->assertOk()->assertJsonPath('data.deliveries.1.status', 'interrupted')->assertJsonPath('data.deliveries.2.status', 'not_sent');
        $this->postJson("$url/{$broadcast->id}/cancel")->assertStatus(409)->assertJsonPath('message',
            'This broadcast was interrupted and cannot be cancelled. Check each channel before composing a replacement.');
        $this->deleteJson("/api/admin/masjids/{$this->organisation->id}/contact-tags/{$tag->id}")->assertOk();
        $this->assertNull($broadcast->fresh()->audience_tag_id);
    }
}
