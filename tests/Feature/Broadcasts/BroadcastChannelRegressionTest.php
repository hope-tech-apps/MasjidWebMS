<?php

namespace Tests\Feature\Broadcasts;

use App\Jobs\SendMasjidNotificationJob;
use App\Models\Announcement;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The composer is PURELY ADDITIVE — proof (T-008).
 *
 * The composer orchestrates channels that already existed. The risk in a slice
 * like this is not that the new endpoint fails; it is that the announcements
 * screen, the push screen or the mobile feed quietly change shape because the
 * composer needed something from them. So each of those paths is exercised HERE,
 * on the branch that adds the composer, asserting it behaves exactly as it did
 * before — same status codes, same envelope, same rows, same job — and that
 * using it creates no broadcast bookkeeping behind the admin's back.
 *
 * It also re-pins Permission::count() === 8: the composer mints NO permission
 * (.claude/rules/auth-permissions.md), and this suite fails loudly if a later
 * change tries to.
 */
class BroadcastChannelRegressionTest extends TestCase
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

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    // ---------- the announcements endpoint ----------

    #[Test]
    public function the_existing_announcements_endpoint_is_unchanged(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->call(
            'POST',
            "/api/admin/masjids/{$this->masjid->id}/announcements",
            [
                'title' => 'Ordinary announcement',
                'summary' => 'A summary',
                'details' => 'The details',
                'text' => 'The text',
                'start_date' => Carbon::now()->toDateString(),
                'end_date' => Carbon::now()->addWeek()->toDateString(),
            ],
            [],
            ['image' => UploadedFile::fake()->image('flyer.jpg', 300, 200)],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        // Same status and same envelope as before the composer existed.
        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertSame('Ordinary announcement', $response->json('data.title'));

        $this->assertSame(1, Announcement::where('masjid_id', $this->masjid->id)->count());

        // And no composer bookkeeping was invented behind the admin's back.
        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
        $this->assertSame(0, BroadcastDelivery::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_announcements_endpoint_still_enforces_its_own_rules(): void
    {
        Sanctum::actingAs($this->admin);

        // No image: the rule the composer BORROWS still lives here and still
        // rejects, in the legacy 422 envelope.
        $this->postJson("/api/admin/masjids/{$this->masjid->id}/announcements", [
            'title' => 'Missing image',
            'details' => 'The details',
            'text' => 'The text',
            'start_date' => Carbon::now()->toDateString(),
            'end_date' => Carbon::now()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function the_public_announcements_feed_is_unchanged(): void
    {
        Sanctum::actingAs($this->admin);

        $this->call(
            'POST',
            "/api/admin/masjids/{$this->masjid->id}/announcements",
            [
                'title' => 'On the feed',
                'details' => 'The details',
                'text' => 'The text',
                'start_date' => Carbon::now()->toDateString(),
                'end_date' => Carbon::now()->addWeek()->toDateString(),
            ],
            [],
            ['image' => UploadedFile::fake()->image('flyer.jpg', 300, 200)],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk();

        $this->getJson("/api/mobile/masjids/{$this->masjid->id}/announcements")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.0.title', 'On the feed');
    }

    // ---------- the notifications (push) endpoint ----------

    #[Test]
    public function the_existing_push_endpoint_is_unchanged(): void
    {
        Queue::fake();

        MobileAppUser::create([
            'masjid_id' => $this->masjid->id,
            'device_id' => 'device-1',
            'onesignal_subscription_id' => 'sub-1',
            'user_agent' => 'test-device',
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/notifications", [
            'title' => 'Ordinary push',
            'message' => 'Sent the old way',
        ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'success');

        $this->assertSame(1, Notification::where('masjid_id', $this->masjid->id)->count());

        // Still the same job, dispatched the same way.
        Queue::assertPushed(SendMasjidNotificationJob::class);

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_public_notifications_feed_is_unchanged(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/notifications", [
            'title' => 'In the inbox',
            'message' => 'Sent the old way',
        ])->assertStatus(202);

        $this->getJson("/api/mobile/masjids/{$this->masjid->id}/notifications")
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    // ---------- nothing was minted ----------

    #[Test]
    public function the_composer_mints_no_permission(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->assertSame(8, Permission::count());
    }
}
