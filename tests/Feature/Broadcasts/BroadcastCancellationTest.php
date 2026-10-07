<?php

namespace Tests\Feature\Broadcasts;

use App\Enums\BroadcastChannel;
use App\Jobs\SendBroadcastJob;
use App\Models\Announcement;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Masjid;
use App\Models\Notification;
use App\Models\User;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\BroadcastComposer;
use App\Services\Broadcast\BroadcastDispatcher;
use App\Services\Broadcast\ChannelResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BroadcastCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $organisation;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        Carbon::setTestNow('2026-10-06 12:00:00');
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'name' => 'Test Admin', 'phone' => '+15555550102']);
        $this->organisation = Masjid::create([
            'name' => 'Test Organisation', 'email' => 'office@example.invalid',
            'phone' => '+15555550101', 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0,
            'user_id' => $this->admin->id,
        ]);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scheduled(): Broadcast
    {
        return app(BroadcastComposer::class)->send($this->organisation, [
            'title' => 'Test notice', 'body' => 'Test body',
            'scheduled_at' => now()->addHour(),
        ], BroadcastChannel::cases(), authorId: $this->admin->id);
    }

    private function url(Broadcast $broadcast): string
    {
        return "/api/admin/masjids/{$this->organisation->id}/broadcasts/{$broadcast->id}/cancel";
    }

    public static function cancellationTiming(): array
    {
        return ['future schedule' => [false], 'overdue job has not run' => [true]];
    }

    #[Test]
    #[DataProvider('cancellationTiming')]
    public function cancellation_prevents_the_delayed_job_and_a_stale_dispatch_on_every_channel(bool $overdue): void
    {
        $broadcast = $this->scheduled();
        Queue::assertPushed(SendBroadcastJob::class, fn ($job) => $job->broadcastId === $broadcast->id);
        Queue::fake();
        if ($overdue) {
            Carbon::setTestNow(now()->addHours(2));
        }

        // Every driver is observed as well as all outbound transports / local content.
        // Even a channel with no recipients must never be entered after cancellation.
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

        // Also exercise form encoding. Actor/time cannot be supplied by the client.
        $this->post($this->url($broadcast), ['cancelled_by_user_id' => 999, 'cancelled_at' => '2000-01-01'],
            ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('message', 'Broadcast cancelled. Nothing will be sent.')
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellable', false);
        $fresh = $broadcast->fresh();
        $this->assertSame($this->admin->id, $fresh->cancelled_by_user_id);
        $this->assertTrue($fresh->cancelled_at->equalTo(now()));
        $this->assertNull($fresh->dispatched_at);
        $this->assertSame(['cancelled'], $fresh->deliveries()->pluck('status')->unique()->values()->all());

        Carbon::setTestNow(now()->addHours(2));
        (new SendBroadcastJob($broadcast->id))->handle(app(BroadcastDispatcher::class));
        app(BroadcastDispatcher::class)->dispatch($broadcast); // still holds status scheduled

        $this->assertSame([], $calls->channels);
        $this->assertSame('cancelled', $broadcast->fresh()->status);
        $this->assertNull($broadcast->fresh()->dispatched_at);
        $this->assertSame(0, Announcement::count());
        $this->assertSame(0, Notification::count());
        $this->getJson("/api/mobile/masjids/{$this->organisation->id}/signage")
            ->assertOk()->assertJsonCount(0, 'data');
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function once_the_first_channel_starts_cancel_is_refused_and_the_other_channels_continue(): void
    {
        $broadcast = $this->scheduled();
        $seen = (object) ['status' => null, 'refusal' => null, 'duplicate' => null];
        $test = $this;
        $this->app->bind(BroadcastDispatcher::DRIVERS['announcement'], fn () => new class($test, $seen) implements BroadcastChannelDriver {
            public function __construct(private object $test, private object $seen) {}
            public function channel(): BroadcastChannel { return BroadcastChannel::ANNOUNCEMENT; }
            public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
            {
                $this->seen->status = $broadcast->fresh()->status;
                $this->seen->refusal = $this->test->postJson("/api/admin/masjids/{$masjid->id}/broadcasts/{$broadcast->id}/cancel");
                $this->seen->duplicate = app(BroadcastDispatcher::class)->dispatch($broadcast)->status;
                return ChannelResult::sent(1);
            }
        });
        // Re-entrant dispatch must stop before it can enter the first channel again.
        // Without the new sending guard this case is red at the status assertion below.
        $this->app->bind(BroadcastDispatcher::DRIVERS['signage'], fn () => new class($seen) implements BroadcastChannelDriver {
            public function __construct(private object $seen) {}
            public function channel(): BroadcastChannel { return BroadcastChannel::SIGNAGE; }
            public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
            {
                $this->seen->continued = true;
                return ChannelResult::sent(1);
            }
        });
        // Only these two channels, so no provider is needed for the send-first case.
        $broadcast->deliveries()->whereNotIn('channel', ['announcement', 'signage'])->delete();
        // Bound the double to one entry on old code too, avoiding infinite recursion.
        $entered = false;
        $driver = app(BroadcastDispatcher::DRIVERS['announcement']);
        $this->app->bind(BroadcastDispatcher::DRIVERS['announcement'], function () use (&$entered, $driver) {
            if ($entered) {
                throw new \RuntimeException('Duplicate fan-out entered a driver.');
            }
            $entered = true;
            return $driver;
        });
        (new SendBroadcastJob($broadcast->id))->handle(app(BroadcastDispatcher::class));
        $this->assertSame('sending', $seen->status);
        $seen->refusal->assertStatus(409)->assertJsonPath('message', 'This broadcast is sending and cannot be cancelled. Refresh to see the delivery status.');
        $this->assertSame('sending', $seen->duplicate);
        $this->assertTrue($seen->continued);
        $this->assertSame('sent', $broadcast->fresh()->status);
        $this->assertNull($broadcast->fresh()->cancelled_at);
    }

    #[Test]
    public function cancel_twice_preserves_the_first_actor_and_time(): void
    {
        $broadcast = $this->scheduled();
        $this->postJson($this->url($broadcast))->assertOk();
        $first = $broadcast->fresh();
        Carbon::setTestNow(now()->addMinute());
        $secondAdmin = User::factory()->create(['type' => 'SuperAdmin', 'name' => 'Test Operator', 'phone' => '+15555550103']);
        Sanctum::actingAs($secondAdmin);
        $this->postJson($this->url($broadcast))->assertStatus(409)
            ->assertJsonPath('message', 'This broadcast is already cancelled. Nothing will be sent.')
            ->assertJsonPath('data.cancellable', false);
        $this->assertSame($first->cancelled_at->toISOString(), $broadcast->fresh()->cancelled_at->toISOString());
        $this->assertSame($this->admin->id, $broadcast->fresh()->cancelled_by_user_id);
    }

    public static function states(): array
    {
        return [
            'future scheduled' => ['scheduled', 60, true],
            'due scheduled' => ['scheduled', 0, true],
            'overdue scheduled' => ['scheduled', -60, true],
            'no scheduled time' => ['scheduled', null, false],
            'pending' => ['pending', 60, false],
            'sending' => ['sending', 60, false],
            'sent' => ['sent', 60, false],
            'partial' => ['partial', 60, false],
            'failed' => ['failed', 60, false],
            'cancelled' => ['cancelled', 60, false],
        ];
    }

    #[Test]
    #[DataProvider('states')]
    public function list_and_detail_cancellability_agree_with_the_cancel_endpoint(string $status, ?int $minutes, bool $allowed): void
    {
        $broadcast = $this->scheduled();
        $broadcast->forceFill(['status' => $status, 'scheduled_at' => $minutes === null ? null : now()->addMinutes($minutes)])->save();
        $this->getJson("/api/admin/masjids/{$this->organisation->id}/broadcasts")
            ->assertOk()->assertJsonPath('data.data.0.cancellable', $allowed);
        $this->getJson("/api/admin/masjids/{$this->organisation->id}/broadcasts/{$broadcast->id}")
            ->assertOk()->assertJsonPath('data.cancellable', $allowed);
        $response = $this->postJson($this->url($broadcast))->assertStatus($allowed ? 200 : 409);
        if (! $allowed) {
            $this->assertNotEmpty($response->json('message'));
            if ($status === 'scheduled' || $status === 'pending') {
                $response->assertJsonPath('message', 'Only a scheduled broadcast can be cancelled. Refresh the list to see its current status.');
            }
            $this->assertSame($status, $broadcast->fresh()->status);
        }
    }

    public static function priorAttempts(): array
    {
        return [
            'sent' => [['status' => 'sent']],
            'failed' => [['status' => 'failed']],
            'skipped' => [['status' => 'skipped']],
            'cancelled delivery' => [['status' => 'cancelled']],
            'unknown status' => [['status' => 'interrupted']],
            'pending with recipients' => [['target_count' => 1]],
            'pending with local reference' => [['reference_id' => 123]],
            'pending with provider reference' => [['reference' => 'provider-id']],
            'pending with note' => [['note' => 'Already attempted']],
            'pending with error' => [['error' => 'Attempt failed']],
            'pending with delivery time' => [['delivered_at' => '2026-10-06 11:00:00']],
        ];
    }

    #[Test]
    #[DataProvider('priorAttempts')]
    public function a_scheduled_parent_with_evidence_of_an_earlier_attempt_cannot_be_cancelled(array $outcome): void
    {
        $broadcast = $this->scheduled();
        // Simulate an old worker settling one delivery but dying before parent rollup.
        $broadcast->load('deliveries');
        $broadcast->deliveries()->where('channel', 'email')->update($outcome);
        $before = $broadcast->deliveries()->orderBy('id')->get()->toArray();
        Carbon::setTestNow(now()->addHours(2));

        $response = $this->postJson($this->url($broadcast))->assertStatus(409)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.cancellable', false)
            ->assertJsonPath('message', 'This broadcast cannot be cancelled because an earlier attempt is recorded. Some channels may already have gone out; check the channel outcomes.');
        $this->assertStringNotContainsString('Nothing will be sent', $response->json('message'));
        $this->assertNull($broadcast->fresh()->cancelled_at);
        $this->assertNull($broadcast->fresh()->cancelled_by_user_id);
        $this->assertSame($before, $broadcast->deliveries()->orderBy('id')->get()->toArray());
        $this->getJson("/api/admin/masjids/{$this->organisation->id}/broadcasts")
            ->assertOk()->assertJsonPath('data.data.0.cancellable', false);
        $this->getJson("/api/admin/masjids/{$this->organisation->id}/broadcasts/{$broadcast->id}")
            ->assertOk()->assertJsonPath('data.cancellable', false);
    }

    #[Test]
    public function history_checks_prior_attempts_using_one_delivery_query_for_the_whole_page(): void
    {
        $untouched = $this->scheduled();
        $started = $this->scheduled();
        $started->deliveries()->where('channel', 'email')->update(['status' => BroadcastDelivery::STATUS_SENT]);
        $this->scheduled();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'broadcast_deliveries')) {
                $queries[] = $query->sql;
            }
        });

        $response = $this->getJson("/api/admin/masjids/{$this->organisation->id}/broadcasts")->assertOk();
        $this->assertCount(1, $queries, 'Deliveries must stay eager-loaded, with no per-row attempt query.');
        $rows = collect($response->json('data.data'))->keyBy('id');
        $this->assertFalse($rows[$started->id]['cancellable']);
        $this->assertTrue($rows[$untouched->id]['cancellable']);
    }

    #[Test]
    public function another_organisations_broadcast_is_a_404_even_for_a_super_admin(): void
    {
        $broadcast = $this->scheduled();
        $other = $this->organisation->replicate();
        $other->name = 'Other Test Organisation';
        $other->phone = '+15555550105';
        $other->email = 'other@example.invalid';
        $other->user_id = User::factory()->create(['type' => 'MasjidAdmin', 'name' => 'Other Test Admin', 'phone' => '+15555550104'])->id;
        $other->save();
        $broadcast->forceFill(['masjid_id' => $other->id])->save();
        foreach ([$this->admin, User::factory()->create(['type' => 'SuperAdmin', 'name' => 'Test Operator', 'phone' => '+15555550103'])] as $user) {
            Sanctum::actingAs($user);
            $this->postJson($this->url($broadcast))->assertNotFound();
        }
        $this->assertSame('scheduled', Broadcast::withoutMasjidScope()->findOrFail($broadcast->id)->status);
    }

    #[Test]
    public function the_existing_broadcast_capability_and_auth_gates_apply(): void
    {
        $broadcast = $this->scheduled();
        $this->organisation->forceFill(['capability_overrides' => ['broadcasts' => false]])->save();
        $this->postJson($this->url($broadcast))->assertForbidden()
            ->assertJsonPath('message', 'Broadcasts is switched off for this organisation.');
        $this->assertSame('scheduled', $broadcast->fresh()->status);
    }

    #[Test]
    public function a_stale_request_copy_cannot_cancel_a_row_that_started_sending(): void
    {
        $broadcast = $this->scheduled();
        // The service receives the identity only; a stale model never decides eligibility.
        Broadcast::whereKey($broadcast->id)->update(['status' => 'sending']);
        $this->postJson($this->url($broadcast))->assertStatus(409);
        $this->assertSame('sending', $broadcast->fresh()->status);
    }

    #[Test]
    public function unauthenticated_cancellation_is_refused(): void
    {
        $broadcast = $this->scheduled();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->url($broadcast))->assertUnauthorized();
        $this->assertSame('scheduled', $broadcast->fresh()->status);
    }
}
