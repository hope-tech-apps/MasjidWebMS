<?php

namespace Tests\Feature;

use App\Jobs\ReadPushOutcomeJob;
use App\Jobs\SendMasjidNotificationJob;
use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MobileAppUser;
use App\Models\Notification;
use App\Models\PushApp;
use App\Models\PushSend;
use App\Services\OnesignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PushAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['onesignal.api_url' => 'https://push.example.test/api/v1/notifications?legacy=1',
            'onesignal.app_id' => 'shared-example', 'onesignal.app_rest_api_key' => 'invented-shared-credential']);
        Queue::fake();
    }

    private function org(): Masjid
    {
        return Masjid::create(['name' => 'Example organisation '.uniqid(), 'email' => uniqid().'@example.test',
            'phone' => (string) random_int(2000000000, 2999999999), 'country_id' => '1', 'city_id' => '1', 'address' => 'Example Street',
            'latitude' => 0, 'longitude' => 0]);
    }

    private function device(Masjid $org, string $subscription, ?string $app = null): MobileAppUser
    {
        $row = MobileAppUser::create(['masjid_id' => $org->id, 'device_id' => 'install-'.$subscription,
            'onesignal_subscription_id' => $subscription, 'user_agent' => 'Example client']);
        if ($app !== null) {
            $row->forceFill(['push_app_id' => $app])->save();
        }
        return $row;
    }

    public static function legacyAccounts(): array
    {
        return ['shared' => [false], 'dedicated' => [true]];
    }

    #[Test]
    #[DataProvider('legacyAccounts')]
    public function legacy_null_devices_pin_every_transport_body_url_and_auth_presence(bool $dedicated): void
    {
        Http::fake(['*' => Http::response(['id' => 'example-message'], 200)]);
        $org = $this->org();
        $expectedApp = $dedicated ? 'dedicated-example' : 'shared-example';
        if ($dedicated) {
            MasjidAppPublishing::create(['masjid_id' => $org->id, 'onesignal_app_id' => $expectedApp,
                'onesignal_rest_api_key' => 'invented-dedicated-credential']);
        }
        $this->device($org, 'subscription-example');
        $note = $org->notifications()->create(['title' => 'Example title', 'message' => 'Example body']);
        $service = new OnesignalService;
        $service->notifyAllOfMasjid($org, $note, ['subscription-example'], 'https://example.test/image.png');
        $service->sendDataSync(['subscription-example'], ['masjid_id' => $org->id], $org);
        $service->sendPrayerAlert(['subscription-example'], 'Prayer title', 'Prayer body', 'adhan.wav',
            ['masjid_id' => $org->id], 'PRAYER_ADHAN', $org, 'prayer_adhan_v1');
        $segmentNote = $org->notifications()->create(['title' => 'Example title', 'message' => 'Example body']);
        $service->notifyAll($org, $segmentNote);
        $bodies = [
            ['app_id' => $expectedApp, 'include_subscription_ids' => ['subscription-example'],
                'headings' => ['en' => 'Example title'], 'contents' => ['en' => 'Example body'], 'target_channel' => 'push',
                'data' => ['masjid_id' => $org->id, 'notification_id' => $note->id],
                'big_picture' => 'https://example.test/image.png', 'ios_attachments' => ['id1' => 'https://example.test/image.png']],
            ['app_id' => $expectedApp, 'include_subscription_ids' => ['subscription-example'], 'target_channel' => 'push',
                'content_available' => true, 'data' => ['type' => 'prayer_sync', 'masjid_id' => $org->id]],
            ['app_id' => $expectedApp, 'include_subscription_ids' => ['subscription-example'], 'target_channel' => 'push',
                'headings' => ['en' => 'Prayer title'], 'contents' => ['en' => 'Prayer body'],
                'data' => ['type' => 'prayer_alert', 'masjid_id' => $org->id], 'ios_sound' => 'adhan.wav',
                'ios_category' => 'PRAYER_ADHAN', 'existing_android_channel_id' => 'prayer_adhan_v1', 'android_sound' => 'adhan'],
            ['app_id' => $expectedApp, 'headings' => ['en' => 'Example title'], 'contents' => ['en' => 'Example body'],
                'data' => ['masjid_id' => $org->id, 'notification_id' => $segmentNote->id],
                ...($dedicated ? ['included_segments' => ['Active Subscriptions']]
                    : ['filters' => [['field' => 'tag', 'key' => 'masjid_id', 'relation' => '=', 'value' => (string) $org->id]]])],
        ];
        Http::assertSentCount(4);
        foreach (Http::recorded() as $index => [$request]) {
            $this->assertSame(config('onesignal.api_url'), $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Basic '.($dedicated ? 'invented-dedicated-credential' : 'invented-shared-credential')));
            $this->assertEquals($bodies[$index], $request->data());
        }
    }

    #[Test]
    public function one_organisations_audience_routes_each_phone_through_its_registered_app(): void
    {
        Http::fake(['*' => Http::response(['id' => 'example-message'], 200)]);
        $org = $this->org();
        foreach (['app-alpha', 'app-beta'] as $app) {
            PushApp::create(['provider_app_id' => $app, 'rest_api_key' => 'invented-'.$app, 'label' => $app, 'active' => true]);
            $this->device($org, 'subscription-'.$app, $app);
        }
        $other = $this->org();
        $this->device($other, 'not-in-audience', 'app-alpha');
        $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        (new SendMasjidNotificationJob($note, $org, ['subscription-app-alpha', 'subscription-app-beta']))->handle(new OnesignalService);
        Http::assertSentCount(2);
        foreach (['app-alpha', 'app-beta'] as $app) {
            Http::assertSent(fn ($request) => $request['app_id'] === $app
                && $request['include_subscription_ids'] === ['subscription-'.$app] && $request->hasHeader('Authorization'));
        }
        $send = PushSend::where('notification_id', $note->id)->firstOrFail();
        $this->assertCount(2, $send->groups);
        $this->assertSame(['example-message', 'example-message'], $send->groups->pluck('provider_message_id')->all());
        Queue::assertPushed(ReadPushOutcomeJob::class, 1);
    }

    #[Test]
    public function unknown_and_inactive_apps_are_counted_without_fallback(): void
    {
        Http::fake();
        $org = $this->org();
        PushApp::create(['provider_app_id' => 'app-inactive', 'rest_api_key' => 'invented-key', 'label' => 'Paused', 'active' => false]);
        $this->device($org, 'unknown', 'app-unknown');
        $this->device($org, 'inactive', 'app-inactive');
        $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        (new SendMasjidNotificationJob($note, $org, ['unknown', 'inactive']))->handle(new OnesignalService);
        Http::assertNothingSent();
        $send = PushSend::where('notification_id', $note->id)->firstOrFail();
        $this->assertSame(2, $send->targeted);
        $this->assertSame(2, $send->unknown_app);
        $this->assertStringContainsString('could not be reached: their app is not set up for push.', $send->wording());
    }

    #[Test]
    public function registration_keeps_its_wire_keys_and_remembers_the_app_across_org_switches(): void
    {
        $org = $this->org();
        $child = $this->org();
        $child->forceFill(['parent_id' => $org->id])->save();
        $body = ['device_id' => 'example-install', 'masjid_id' => $org->id, 'push_app_id' => 'app-parent', 'onesignal_subscription_id' => 'original-sub', 'future_extra' => true];
        $response = $this->postJson('/api/mobile/user', $body)->assertOk();
        $this->assertSame(['id','device_id','masjid_id','user_agent','created_at','updated_at'], array_keys($response->json('data')));
        $this->putJson('/api/mobile/user', ['device_id' => 'example-install', 'masjid_id' => $child->id])->assertOk();
        $this->assertDatabaseHas('mobile_app_users', ['device_id' => 'example-install', 'push_app_id' => 'app-parent', 'masjid_id' => $child->id]);
        $this->postJson('/api/mobile/user/heartbeat', ['device_id' => 'example-install', 'push_app_id' => 'app-updated', 'onesignal_subscription_id' => 'original-sub'])->assertOk();
        $this->assertDatabaseHas('mobile_app_users', ['device_id' => 'example-install', 'push_app_id' => 'app-updated']);
        $this->postJson('/api/mobile/user', ['device_id' => 'old-build', 'masjid_id' => $org->id])->assertOk();
        $this->assertDatabaseHas('mobile_app_users', ['device_id' => 'old-build', 'push_app_id' => null]);
        $this->postJson('/api/mobile/user', $body + [])->assertOk();
        $body['push_app_id'] = 'bad/app?';
        $this->postJson('/api/mobile/user', $body)->assertOk();
        $this->assertDatabaseHas('mobile_app_users', ['device_id' => 'example-install', 'push_app_id' => 'app-parent']);
    }

    #[Test]
    public function child_and_cross_app_selection_work_with_the_same_audience_and_no_inheritance(): void
    {
        Http::fake(fn () => Http::response(['id' => 'example-message-'.uniqid()]));
        $parent = $this->org(); $child = $this->org();
        $child->setParent($parent);
        PushApp::create(['provider_app_id' => 'parent-app', 'rest_api_key' => 'invented-parent-key', 'label' => 'Parent']);
        PushApp::create(['provider_app_id' => 'another-app', 'rest_api_key' => 'invented-another-key', 'label' => 'Another']);
        MasjidAppPublishing::create(['masjid_id' => $parent->id, 'onesignal_app_id' => 'parent-app', 'onesignal_rest_api_key' => 'invented-parent-key']);
        $this->device($child, 'child-phone', 'parent-app');
        $this->device($parent, 'cross-app-phone', 'another-app');
        foreach ([[$child, 'child-phone', 'parent-app'], [$parent, 'cross-app-phone', 'another-app']] as [$org, $id, $app]) {
            $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
            (new SendMasjidNotificationJob($note, $org, [$id]))->handle(new OnesignalService);
            Http::assertSent(fn ($r) => $r['app_id'] === $app && $r['include_subscription_ids'] === [$id]
                && $r['data']['masjid_id'] === $org->id);
        }
        Http::assertSentCount(2);
    }

    #[Test]
    public function null_and_explicit_phones_on_one_usable_account_share_one_request(): void
    {
        Http::fake(['*' => Http::response(['id' => 'example-message'])]);
        $org = $this->org();
        PushApp::create(['provider_app_id' => 'shared-example', 'rest_api_key' => 'invented-shared-key', 'label' => 'Shared']);
        $this->device($org, 'old-sub'); $this->device($org, 'new-sub', 'shared-example');
        $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        (new SendMasjidNotificationJob($note, $org, ['old-sub', 'new-sub']))->handle(new OnesignalService);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['include_subscription_ids'] === ['old-sub', 'new-sub']);
    }

    #[Test]
    public function queued_audience_keeps_the_phones_account_after_it_selects_another_organisation(): void
    {
        Http::fake(['*' => Http::response(['id' => 'example-message'])]);
        $org = $this->org(); $other = $this->org();
        PushApp::create(['provider_app_id' => 'phone-app', 'rest_api_key' => 'invented-phone-key', 'label' => 'Phone account']);
        $phone = $this->device($org, 'phone-sub', 'phone-app');
        $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        $job = new SendMasjidNotificationJob($note, $org, ['phone-sub']);
        $phone->forceFill(['masjid_id' => $other->id])->save();
        $this->device($other, 'other-sub', 'phone-app');
        $job->handle(new OnesignalService);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['app_id'] === 'phone-app' && $r['include_subscription_ids'] === ['phone-sub']
            && $r['data']['masjid_id'] === $org->id);
        $this->assertSame('Phone account', PushSend::where('notification_id', $note->id)->firstOrFail()->groups->first()->app_label);
    }

    #[Test]
    public function an_inactive_registry_account_blocks_null_phones_too_without_changing_the_fallback_choice(): void
    {
        Http::fake();
        $org = $this->org(); $this->device($org, 'phone-sub');
        PushApp::create(['provider_app_id' => 'shared-example', 'rest_api_key' => 'invented-key', 'label' => 'Paused shared', 'active' => false]);
        $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        (new SendMasjidNotificationJob($note, $org, ['phone-sub']))->handle(new OnesignalService);
        Http::assertNothingSent();
        $send = PushSend::where('notification_id', $note->id)->firstOrFail();
        $this->assertSame(1, $send->unknown_app);
        $this->assertSame('Paused shared', $send->groups->first()->app_label);
        $this->assertSame('1 phone could not be reached: their app is not set up for push.', $send->wording());
    }

    #[Test]
    public function partial_migration_refuses_all_phones_until_attempt_claims_can_be_stored(): void
    {
        Http::fake(['*' => Http::response(['id' => 'example-message'])]);
        $org = $this->org(); $this->device($org, 'legacy-sub');
        \Illuminate\Support\Facades\Schema::drop('push_send_groups');
        $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        $service = new OnesignalService;
        $service->notifyAllOfMasjid($org, $note, ['legacy-sub']);
        Http::assertNothingSent();
        $this->device($org, 'aware-sub', 'another-app');
        $result = $service->notifyAllOfMasjid($org, $note, ['aware-sub']);
        Http::assertNothingSent();
        $this->assertTrue($result['not_sent']);
        $this->assertNull($result['id']);
    }

    #[Test]
    public function details_url_appends_path_before_query_and_uses_the_groups_account(): void
    {
        Http::fake(['*' => Http::response(['successful' => 8], 200)]);
        (new OnesignalService)->getNotificationDetails('message-example');
        Http::assertSent(fn ($r) => $r->url() === 'https://push.example.test/api/v1/notifications/message-example?legacy=1&app_id=shared-example'
            && $r->hasHeader('Authorization'));
    }
}
