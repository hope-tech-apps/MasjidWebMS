<?php

namespace Tests\Feature\Broadcasts;

use App\Enums\BroadcastChannel;
use App\Jobs\SendBroadcastJob;
use App\Mail\BroadcastMail;
use App\Models\Announcement;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\Notification;
use App\Models\User;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\BroadcastDispatcher;
use App\Services\Broadcast\Channels\EmailChannel;
use App\Services\Broadcast\ChannelResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The unified publish composer, end to end (T-008).
 *
 * The behaviours this suite exists to hold:
 *
 *  1. ONE post reaches four channels and produces ONE record with a per-channel
 *     outcome — and the announcement and push it produces are ordinary rows in
 *     the tables those features already owned.
 *  2. A failing channel NEVER rolls back a successful one. This is the invariant
 *     the whole design turns on: a push already on ten thousand lock screens
 *     cannot be un-sent by an email outage, so a "rollback" would only erase our
 *     record of what happened and invite the admin to send it twice.
 *  3. Validation for the announcements feed is the ANNOUNCEMENT'S OWN, borrowed
 *     rather than restated, so the two can never disagree.
 *  4. Push is refused — not silently widened — when aimed at named contacts,
 *     because devices carry no contact link.
 *
 * Nothing here sends a real push, email or SMS: Http::fake intercepts OneSignal,
 * Mail::fake intercepts the mailer, and no SMS provider exists to call.
 */
class BroadcastComposerTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private User $admin;

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

        config([
            'onesignal.api_url' => 'https://onesignal.com/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => 'rest-key-test',
        ]);

        Storage::fake('public');
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);
        Mail::fake();

        StubEmailChannel::$shouldFail = true;

        // The email channel is gated on `view contacts`; the bridged role has to
        // exist for a MasjidAdmin to hold it (.claude/rules/auth-permissions.md).
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);
    }

    private function makeMasjid(bool $crmEnabled = true): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => $crmEnabled,
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function registerDevices(Masjid $masjid, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            MobileAppUser::create([
                'masjid_id' => $masjid->id,
                'device_id' => 'device-' . $masjid->id . '-' . $i,
                'onesignal_subscription_id' => 'sub-' . $masjid->id . '-' . $i,
                // NOT NULL in the schema; the model's fillable omits no column
                // but the migration has no default.
                'user_agent' => 'test-device',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Snow closure',
            'body' => 'All programs are cancelled today because of the storm.',
            'starts_on' => Carbon::now()->toDateString(),
            'ends_on' => Carbon::now()->addDays(3)->toDateString(),
            'audience' => 'everyone',
            'channels' => ['announcement', 'push', 'signage'],
            'image' => UploadedFile::fake()->image('notice.jpg', 400, 300),
        ], $overrides);
    }

    /** POST the composer as multipart (the image makes it a file upload). */
    private function submit(array $payload, ?Masjid $masjid = null)
    {
        $masjid = $masjid ?? $this->masjid;
        $image = $payload['image'] ?? null;
        unset($payload['image']);

        return $this->call(
            'POST',
            "/api/admin/masjids/{$masjid->id}/broadcasts",
            $payload,
            [],
            $image ? ['image' => $image] : [],
            ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    // ---------- auth ----------

    #[Test]
    public function it_rejects_unauthenticated_requests(): void
    {
        $this->submit($this->payload())->assertStatus(401);
    }

    // ---------- the core promise: one post, many channels ----------

    #[Test]
    public function one_post_publishes_to_every_selected_channel(): void
    {
        $this->registerDevices($this->masjid, 3);
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'congregant@test.local']);

        Sanctum::actingAs($this->admin);

        $response = $this->submit($this->payload([
            'channels' => ['announcement', 'push', 'signage', 'email'],
        ]))->assertStatus(202);

        // ONE record of the send…
        $this->assertSame(1, Broadcast::withoutMasjidScope()->count());
        $this->assertSame(Broadcast::STATUS_SENT, $response->json('data.status'));

        // …with a per-channel outcome.
        $deliveries = BroadcastDelivery::withoutMasjidScope()->pluck('status', 'channel');
        $this->assertSame(BroadcastDelivery::STATUS_SENT, $deliveries['announcement']);
        $this->assertSame(BroadcastDelivery::STATUS_SENT, $deliveries['push']);
        $this->assertSame(BroadcastDelivery::STATUS_SENT, $deliveries['signage']);
        $this->assertSame(BroadcastDelivery::STATUS_SENT, $deliveries['email']);

        // The announcement channel produced an ORDINARY announcement row.
        $announcement = Announcement::where('masjid_id', $this->masjid->id)->first();
        $this->assertNotNull($announcement);
        $this->assertSame('Snow closure', $announcement->title);
        $this->assertSame($announcement->details, $announcement->text);

        // The push channel produced an ordinary notification row and went
        // through the existing OneSignal job.
        $notification = Notification::where('masjid_id', $this->masjid->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame('onesignal-message-id', $notification->onesignal_message_id);

        Mail::assertQueued(BroadcastMail::class);
    }

    #[Test]
    public function each_channel_is_opt_in_per_send(): void
    {
        $this->registerDevices($this->masjid, 2);

        Sanctum::actingAs($this->admin);

        $this->submit($this->payload([
            'channels' => ['push'],
            // No announcement channel, so the feed's image rule does not apply.
            'image' => null,
        ]))->assertStatus(202);

        $this->assertSame(0, Announcement::count());
        $this->assertSame(1, Notification::count());
        $this->assertSame(
            ['push'],
            BroadcastDelivery::withoutMasjidScope()->pluck('channel')->all()
        );
    }

    #[Test]
    public function the_push_delivery_records_how_many_devices_it_addressed(): void
    {
        $this->registerDevices($this->masjid, 4);

        Sanctum::actingAs($this->admin);

        $this->submit($this->payload(['channels' => ['push'], 'image' => null]))->assertStatus(202);

        $push = BroadcastDelivery::withoutMasjidScope()->where('channel', 'push')->first();
        $this->assertSame(4, $push->target_count);
    }

    #[Test]
    public function a_channel_with_nobody_to_reach_is_skipped_not_failed(): void
    {
        // No devices registered at all.
        Sanctum::actingAs($this->admin);

        $this->submit($this->payload(['channels' => ['push'], 'image' => null]))->assertStatus(202);

        $push = BroadcastDelivery::withoutMasjidScope()->where('channel', 'push')->first();
        $this->assertSame(BroadcastDelivery::STATUS_SKIPPED, $push->status);
        $this->assertSame(0, $push->target_count);
        $this->assertNotNull($push->note);

        // A skip is not a failure, so the send as a whole is not one either.
        $this->assertSame(
            Broadcast::STATUS_SENT,
            Broadcast::withoutMasjidScope()->first()->status
        );
    }

    // ---------- THE invariant: no rollback across channels ----------

    #[Test]
    public function a_failing_channel_does_not_roll_back_the_channels_that_succeeded(): void
    {
        $this->registerDevices($this->masjid, 2);
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'congregant@test.local']);

        // Force the email channel to blow up at the seam, the way a dead SMTP
        // relay would. Everything else runs for real.
        $this->app->bind(EmailChannel::class, fn () => new StubEmailChannel());

        Sanctum::actingAs($this->admin);

        $response = $this->submit($this->payload([
            'channels' => ['announcement', 'push', 'signage', 'email'],
        ]))->assertStatus(202);

        // The send is recorded as PARTIAL — a first-class, visible state.
        $this->assertSame(Broadcast::STATUS_PARTIAL, $response->json('data.status'));

        $deliveries = BroadcastDelivery::withoutMasjidScope()->get()->keyBy('channel');
        $this->assertSame(BroadcastDelivery::STATUS_FAILED, $deliveries['email']->status);
        $this->assertNotNull($deliveries['email']->error);

        // …and the other three are untouched by it.
        $this->assertSame(BroadcastDelivery::STATUS_SENT, $deliveries['announcement']->status);
        $this->assertSame(BroadcastDelivery::STATUS_SENT, $deliveries['push']->status);
        $this->assertSame(BroadcastDelivery::STATUS_SENT, $deliveries['signage']->status);

        // Crucially, the ROWS the successful channels created still exist. If the
        // fan-out were wrapped in a transaction these would be gone, while the
        // push itself had already left the building.
        $this->assertSame(1, Announcement::where('masjid_id', $this->masjid->id)->count());
        $this->assertSame(1, Notification::where('masjid_id', $this->masjid->id)->count());
        $this->assertSame(1, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_failed_channel_can_be_retried_without_re_sending_the_others(): void
    {
        $this->registerDevices($this->masjid, 1);
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'congregant@test.local']);

        $this->app->bind(EmailChannel::class, fn () => new StubEmailChannel());

        Sanctum::actingAs($this->admin);
        $this->submit($this->payload(['channels' => ['announcement', 'push', 'email']]))->assertStatus(202);

        // The relay comes back up; re-dispatch the SAME broadcast.
        StubEmailChannel::$shouldFail = false;

        $broadcast = Broadcast::withoutMasjidScope()->first();
        app(BroadcastDispatcher::class)->dispatch($broadcast);

        // The email retried and the announcement/push were NOT sent a second
        // time — a settled delivery is never re-attempted.
        $this->assertSame(1, Announcement::where('masjid_id', $this->masjid->id)->count());
        $this->assertSame(1, Notification::where('masjid_id', $this->masjid->id)->count());
        $this->assertSame(
            Broadcast::STATUS_SENT,
            Broadcast::withoutMasjidScope()->first()->status
        );
    }

    // ---------- validation ----------

    #[Test]
    public function at_least_one_channel_is_required(): void
    {
        Sanctum::actingAs($this->admin);

        $this->submit($this->payload(['channels' => []]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function the_announcements_feed_enforces_its_own_validation_rules(): void
    {
        Sanctum::actingAs($this->admin);

        // StoreAnnouncementRequest requires an image and an end date strictly
        // after the start date. The composer borrows those rules rather than
        // restating them, so both failures surface here.
        $response = $this->submit($this->payload([
            'channels' => ['announcement'],
            'image' => null,
        ]))->assertStatus(422);

        $this->assertArrayHasKey('image', $response->json('data'));

        $this->submit($this->payload([
            'channels' => ['announcement'],
            'starts_on' => '2026-09-10',
            'ends_on' => '2026-09-10',
        ]))->assertStatus(422);
    }

    #[Test]
    public function those_rules_do_not_apply_when_the_announcement_channel_is_not_selected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->submit($this->payload([
            'channels' => ['signage'],
            'image' => null,
            'starts_on' => null,
            'ends_on' => null,
        ]))->assertStatus(202);
    }

    #[Test]
    public function push_cannot_be_narrowed_to_selected_contacts(): void
    {
        $contact = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'one@test.local']);

        Sanctum::actingAs($this->admin);

        // Devices carry no contact link, so this would silently broadcast to
        // everybody. It is refused instead.
        $response = $this->submit($this->payload([
            'channels' => ['push'],
            'audience' => 'contacts',
            'contact_ids' => [$contact->id],
            'image' => null,
        ]))->assertStatus(422);

        $this->assertArrayHasKey('channels', $response->json('data'));
        $this->assertSame(0, Notification::count());
    }

    #[Test]
    public function a_contacts_audience_reaches_only_the_selected_contacts(): void
    {
        $addressed = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'email' => 'addressed@test.local',
        ]);
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'not-addressed@test.local']);

        Sanctum::actingAs($this->admin);

        $this->submit($this->payload([
            'channels' => ['email'],
            'audience' => 'contacts',
            'contact_ids' => [$addressed->id],
            'image' => null,
        ]))->assertStatus(202);

        $email = BroadcastDelivery::withoutMasjidScope()->where('channel', 'email')->first();
        $this->assertSame(1, $email->target_count);

        Mail::assertQueued(BroadcastMail::class, 1);
    }

    // ---------- the email channel's CRM gate ----------

    #[Test]
    public function the_email_channel_is_refused_when_the_crm_is_off_and_nothing_is_sent(): void
    {
        $masjid = $this->makeMasjid(crmEnabled: false);
        $admin = $this->makeAdminFor($masjid);
        $this->registerDevices($masjid, 2);

        Sanctum::actingAs($admin);

        $this->submit($this->payload([
            'channels' => ['push', 'email'],
            'image' => null,
        ]), $masjid)->assertStatus(403);

        // Authorization is all-or-nothing: the push must NOT have gone out
        // either, because the admin has to learn this before anything ships.
        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
        $this->assertSame(0, Notification::count());
    }

    #[Test]
    public function the_other_channels_still_work_when_the_crm_is_off(): void
    {
        $masjid = $this->makeMasjid(crmEnabled: false);
        $admin = $this->makeAdminFor($masjid);
        $this->registerDevices($masjid, 1);

        Sanctum::actingAs($admin);

        $this->submit($this->payload([
            'channels' => ['announcement', 'push', 'signage'],
        ]), $masjid)->assertStatus(202);

        $this->assertSame(1, Announcement::where('masjid_id', $masjid->id)->count());
    }

    // ---------- scheduling ----------

    #[Test]
    public function a_future_send_is_only_a_delayed_queue_job(): void
    {
        Queue::fake();
        $this->registerDevices($this->masjid, 1);

        Sanctum::actingAs($this->admin);

        $response = $this->submit($this->payload([
            'channels' => ['announcement', 'push'],
            'scheduled_at' => Carbon::now()->addDay()->toIso8601String(),
        ]))->assertStatus(202);

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $response->json('data.status'));

        // Nothing has been published yet.
        $this->assertSame(0, Announcement::count());
        $this->assertSame(0, Notification::count());
        $this->assertSame(
            [BroadcastDelivery::STATUS_PENDING, BroadcastDelivery::STATUS_PENDING],
            BroadcastDelivery::withoutMasjidScope()->pluck('status')->all()
        );

        Queue::assertPushed(SendBroadcastJob::class);
    }

    #[Test]
    public function the_scheduled_job_publishes_when_it_runs(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->admin);

        $this->submit($this->payload([
            'channels' => ['announcement'],
            'scheduled_at' => Carbon::now()->addDay()->toIso8601String(),
        ]))->assertStatus(202);

        $broadcast = Broadcast::withoutMasjidScope()->first();

        // Run the job the way the worker would: UNBOUND tenant context, which is
        // exactly the state .claude/rules/tenant-scoping.md guarantees.
        app(TenantContext::class)->forgetTenant();
        (new SendBroadcastJob($broadcast->id))->handle(app(BroadcastDispatcher::class));

        $this->assertSame(1, Announcement::where('masjid_id', $this->masjid->id)->count());
        $this->assertSame(
            Broadcast::STATUS_SENT,
            Broadcast::withoutMasjidScope()->first()->status
        );
    }

    // ---------- the signage board ----------

    #[Test]
    public function the_signage_board_serves_only_broadcasts_published_to_it(): void
    {
        Sanctum::actingAs($this->admin);

        $this->submit($this->payload([
            'title' => 'On the board',
            'channels' => ['signage'],
            'image' => null,
        ]))->assertStatus(202);

        $this->registerDevices($this->masjid, 1);
        $this->submit($this->payload([
            'title' => 'Push only',
            'channels' => ['push'],
            'image' => null,
        ]))->assertStatus(202);

        $board = $this->getJson("/api/mobile/masjids/{$this->masjid->id}/signage")->assertOk();

        $this->assertSame(['On the board'], array_column($board->json('data'), 'title'));
    }

    #[Test]
    public function the_signage_board_hides_a_notice_whose_window_has_closed(): void
    {
        Sanctum::actingAs($this->admin);

        $this->submit($this->payload([
            'channels' => ['signage'],
            'starts_on' => Carbon::now()->subDays(10)->toDateString(),
            'ends_on' => Carbon::now()->subDay()->toDateString(),
            'image' => null,
        ]))->assertStatus(202);

        $board = $this->getJson("/api/mobile/masjids/{$this->masjid->id}/signage")->assertOk();

        $this->assertSame([], $board->json('data'));
    }

    // ---------- the record of the send ----------

    #[Test]
    public function an_admin_can_read_back_what_happened_on_each_channel(): void
    {
        $this->registerDevices($this->masjid, 2);

        Sanctum::actingAs($this->admin);
        $this->submit($this->payload(['channels' => ['announcement', 'push']]))->assertStatus(202);

        $id = Broadcast::withoutMasjidScope()->value('id');

        $show = $this->getJson("/api/admin/masjids/{$this->masjid->id}/broadcasts/{$id}")->assertOk();

        $channels = array_column($show->json('data.deliveries'), 'channel');
        sort($channels);
        $this->assertSame(['announcement', 'push'], $channels);

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/broadcasts")
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }
}

/**
 * A stand-in for the email channel whose failure is switchable.
 *
 * Failure is injected AT THE SEAM rather than by breaking the mailer, so the
 * suite proves the dispatcher's isolation behaviour and not some incidental
 * property of Laravel's mail transport. The toggle exists so one test can watch
 * a channel fail and then recover.
 */
class StubEmailChannel implements BroadcastChannelDriver
{
    public static bool $shouldFail = true;

    public function channel(): BroadcastChannel
    {
        return BroadcastChannel::EMAIL;
    }

    public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
    {
        if (self::$shouldFail) {
            throw new RuntimeException('Mail transport unavailable.');
        }

        return ChannelResult::sent(targetCount: 1, note: 'Stubbed delivery.');
    }
}
