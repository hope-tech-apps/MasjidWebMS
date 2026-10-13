<?php

namespace Tests\Feature;

use App\Jobs\SendMasjidNotificationJob;
use App\Jobs\SendPrayerSyncJob;
use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MobileAppUser;
use App\Models\Notification;
use App\Models\User;
use App\Services\OnesignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** End-to-end sender request pins; the same tests are also run against the main transport in scratch. */
class PushSenderContractsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $org;
    private string $appId;

    public static function accounts(): array
    {
        return ['shared' => [false], 'dedicated' => [true]];
    }

    private function prepare(bool $dedicated): void
    {
        Queue::fake();
        Http::fake(fn () => Http::response(['id' => 'example-message-'.uniqid()]));
        config(['onesignal.api_url' => 'https://push.example.test/notifications?legacy=yes',
            'onesignal.app_id' => 'shared-example', 'onesignal.app_rest_api_key' => 'invented-shared-key']);
        $admin = User::factory()->create(['name' => 'Example admin', 'type' => 'MasjidAdmin', 'phone' => '5550190001']);
        $this->org = Masjid::create(['name' => 'Example organisation', 'user_id' => $admin->id,
            'email' => 'office@example.test', 'phone' => '5550190002', 'country_id' => '1','city_id' => '1',
            'address' => 'Example Street','latitude' => 0,'longitude' => 0]);
        $this->appId = $dedicated ? 'dedicated-example' : 'shared-example';
        if ($dedicated) {
            MasjidAppPublishing::create(['masjid_id' => $this->org->id,'onesignal_app_id' => $this->appId,
                'onesignal_rest_api_key' => 'invented-dedicated-key']);
        }
        MobileAppUser::create(['masjid_id' => $this->org->id,'device_id' => 'example-phone',
            'onesignal_subscription_id' => 'example-sub','user_agent' => 'Example']);
        Sanctum::actingAs($admin);
    }

    private function assertNotificationRequest(Notification $note): void
    {
        Http::assertSent(fn ($r) => $r->url() === config('onesignal.api_url') && $r->hasHeader('Authorization', 'Basic '.($this->appId === 'shared-example' ? 'invented-shared-key' : 'invented-dedicated-key'))
            && $r->data() === ['app_id' => $this->appId, 'include_subscription_ids' => ['example-sub'],
                'headings' => ['en' => $note->title], 'contents' => ['en' => $note->message], 'target_channel' => 'push',
                'data' => ['masjid_id' => $this->org->id,'notification_id' => $note->id]]);
    }

    private function runNotificationJob(): Notification
    {
        $job = Queue::pushed(SendMasjidNotificationJob::class)->last();
        $job->handle(new OnesignalService);
        return Notification::findOrFail($job->notificationId);
    }

    #[Test]
    #[DataProvider('accounts')]
    public function direct_office_notification_and_combined_publish_keep_the_main_requests(bool $dedicated): void
    {
        $this->prepare($dedicated);
        $this->postJson('/api/admin/masjids/'.$this->org->id.'/notifications', ['title' => 'Example title','message' => 'Example body'])->assertStatus(202);
        $note = $this->runNotificationJob();
        $this->assertNotificationRequest($note);
        $this->postJson('/api/admin/masjids/'.$this->org->id.'/broadcasts', ['title' => 'Published example', 'body' => 'Example body',
            'audience' => 'everyone', 'channels' => ['push']])->assertSuccessful();
        $note = $this->runNotificationJob();
        $this->assertNotificationRequest($note);
        Http::assertSentCount(2);
    }

    #[Test]
    #[DataProvider('accounts')]
    public function nightly_resync_keeps_the_main_request(bool $dedicated): void
    {
        $this->prepare($dedicated);
        $this->artisan('prayers:daily-resync')->assertExitCode(0);
        $job = Queue::pushed(SendPrayerSyncJob::class)->last();
        $job->handle(new OnesignalService);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === config('onesignal.api_url') && $r->hasHeader('Authorization', 'Basic '.($this->appId === 'shared-example' ? 'invented-shared-key' : 'invented-dedicated-key'))
            && $r->data() === ['app_id' => $this->appId, 'include_subscription_ids' => ['example-sub'], 'target_channel' => 'push',
                'content_available' => true, 'data' => ['type' => 'prayer_sync', 'masjid_id' => $this->org->id]]);
    }

    #[Test]
    #[DataProvider('accounts')]
    public function iqama_save_queues_the_same_silent_request_in_the_clients_encoding(bool $dedicated): void
    {
        $this->prepare($dedicated);
        $this->post('/api/admin/masjids/'.$this->org->id.'/iqama', ['iqama_type' => 'minutes_after_adhan',
            'fajr' => '10', 'dhuhr' => '10', 'asr' => '10', 'maghrib' => '10', 'isha' => '10', 'show_iqama_times' => 'true'])->assertSuccessful();
        $job = Queue::pushed(SendPrayerSyncJob::class)->last();
        $job->handle(new OnesignalService);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === config('onesignal.api_url') && $r->hasHeader('Authorization', 'Basic '.($this->appId === 'shared-example' ? 'invented-shared-key' : 'invented-dedicated-key'))
            && $r->data() === ['app_id' => $this->appId, 'include_subscription_ids' => ['example-sub'], 'target_channel' => 'push',
                'content_available' => true, 'data' => ['type' => 'prayer_sync', 'masjid_id' => $this->org->id]]);
    }

    #[Test]
    #[DataProvider('accounts')]
    public function prayer_test_command_keeps_the_full_main_payload(bool $dedicated): void
    {
        $this->prepare($dedicated);
        $this->artisan('prayers:test-push', ['device_id' => 'example-phone'])->assertExitCode(0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === config('onesignal.api_url') && $r->hasHeader('Authorization', 'Basic '.($this->appId === 'shared-example' ? 'invented-shared-key' : 'invented-dedicated-key'))
            && $r->data() === ['app_id' => $this->appId, 'include_subscription_ids' => ['example-sub'], 'target_channel' => 'push',
                'headings' => ['en' => "It's time for Test"],
                'contents' => ['en' => 'Phase-3 backstop test — if you see this with the adhan sound, the server-side path works.'],
                'data' => ['type' => 'prayer_test'], 'ios_sound' => 'adhan.wav','ios_category' => 'PRAYER_ADHAN',
                'existing_android_channel_id' => 'prayer_adhan_v1','android_sound' => 'adhan']);
    }

    #[Test]
    #[DataProvider('accounts')]
    public function prayer_backstop_keeps_the_main_adhan_and_iqama_requests(bool $dedicated): void
    {
        $this->prepare($dedicated);
        $now = \Carbon\Carbon::parse('2026-09-13 10:30:00', 'UTC');
        $this->travelTo($now);
        \App\Models\IqamaTimeSetting::create(['masjid_id' => $this->org->id,
            'fajr' => 0,'dhuhr' => 0,'asr' => 0,'maghrib' => 0,'isha' => 0]);
        \App\Models\Prayer::create(['masjid_id' => $this->org->id,'date' => $now->toDateString(),
            'prayers_data' => ['fajr' => $now->toIso8601String()]]);
        MobileAppUser::where('masjid_id', $this->org->id)->update(['last_active_at' => $now->copy()->subDays(30)]);
        $this->artisan('prayers:send-due')->assertExitCode(0);
        Http::assertSentCount(2);
        foreach (['adhan', 'iqama'] as $kind) {
            $iqama = $kind === 'iqama';
            Http::assertSent(fn ($r) => $r->url() === config('onesignal.api_url') && $r->hasHeader('Authorization', 'Basic '.($this->appId === 'shared-example' ? 'invented-shared-key' : 'invented-dedicated-key'))
                && $r->data() === ['app_id' => $this->appId, 'include_subscription_ids' => ['example-sub'], 'target_channel' => 'push',
                    'headings' => ['en' => $iqama ? 'Iqama time for Fajr' : "It's time for Fajr"],
                    'contents' => ['en' => $iqama ? 'The iqama time for Fajr has arrived' : 'The time for Fajr prayer has arrived'],
                    'data' => ['type' => 'prayer_alert', 'masjid_id' => $this->org->id,'prayer' => 'fajr','kind' => $kind],
                    'ios_sound' => $iqama ? 'iqamah.wav' : 'adhan.wav',
                    ...($iqama ? [] : ['ios_category' => 'PRAYER_ADHAN']),
                    'existing_android_channel_id' => $iqama ? 'prayer_iqama_v1' : 'prayer_adhan_v1',
                    'android_sound' => $iqama ? 'iqamah' : 'adhan']);
        }
        $this->travelBack();
    }

    #[Test]
    #[DataProvider('accounts')]
    public function summer_content_seed_push_keeps_the_main_request(bool $dedicated): void
    {
        $this->prepare($dedicated);
        $this->artisan('events:seed-summer-2026', ['--masjid' => $this->org->id, '--push' => true])->assertExitCode(0);
        $note = $this->runNotificationJob();
        Http::assertSentCount(1);
        $this->assertNotificationRequest($note);
    }
}
