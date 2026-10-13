<?php

namespace Tests\Feature;

use App\Jobs\ReadPushOutcomeJob;
use App\Jobs\SendMasjidNotificationJob;
use App\Models\Masjid;
use App\Models\PushApp;
use App\Models\PushSend;
use App\Services\OnesignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PushOutcomesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['onesignal.api_url' => 'https://push.example.test/notifications?app_id=old-example',
            'onesignal.app_id' => 'shared-example', 'onesignal.app_rest_api_key' => 'invented-private-credential']);
    }

    private function send(): PushSend
    {
        $org = Masjid::create(['name' => 'Example', 'email' => uniqid().'@example.test', 'phone' => '5550190000',
            'country_id' => '1', 'city_id' => '1', 'address' => 'Example Street', 'latitude' => 0, 'longitude' => 0]);
        return PushSend::create(['masjid_id' => $org->id, 'kind' => 'notification', 'targeted' => 74]);
    }

    private function group(PushSend $send): void
    {
        $send->groups()->create(['masjid_id' => $send->masjid_id, 'provider_app_id' => 'shared-example',
            'routing_key' => 'legacy', 'app_label' => 'Shared example', 'legacy' => true, 'targeted' => 74,
            'provider_message_id' => 'example-message', 'status' => 'checking']);
    }

    #[Test]
    public function partial_errors_are_stored_by_platform_and_worded_honestly_and_replays_do_not_add_counts(): void
    {
        $send = $this->send();
        $this->group($send);
        Http::fake(['*' => Http::response(['successful' => 8, 'errored' => 40, 'failed' => 0, 'remaining' => 0,
            'completed_at' => 12345, 'platform_delivery_stats' => ['ios' => ['successful' => 0, 'errored' => 40],
                'android' => ['successful' => 8, 'errored' => 0]]], 200)]);
        $job = new ReadPushOutcomeJob($send->id);
        $job->handle(new OnesignalService);
        $job->handle(new OnesignalService);
        $send->refresh();
        $this->assertSame(8, $send->delivered);
        $this->assertSame(40, $send->errored);
        $this->assertSame(74, $send->targeted);
        $this->assertSame('Sent to 74 phones. Delivered to 8. 40 could not be delivered (iPhone). Delivery could not be confirmed for 26 phones.', $send->wording());
        $this->assertSame(40, $send->platform_counts['ios']['errored']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function in_progress_gets_exactly_one_later_read_then_completes(): void
    {
        $send = $this->send(); $this->group($send);
        Http::fakeSequence()->push(['successful' => 2, 'errored' => 0, 'remaining' => 72, 'completed_at' => null])
            ->push(['successful' => 74, 'errored' => 0, 'remaining' => 0, 'completed_at' => 12345]);
        $job = new ReadPushOutcomeJob($send->id);
        $job->handle(new OnesignalService);
        $this->assertSame('Sent to 74 phones. Checking delivery…', $send->fresh()->wording());
        Queue::assertPushed(ReadPushOutcomeJob::class, 1);
        $job->handle(new OnesignalService);
        Http::assertSentCount(1);
        (new ReadPushOutcomeJob($send->id, 2))->handle(new OnesignalService);
        $this->assertSame('Sent to 74 phones. Delivered to 74.', $send->fresh()->wording());
    }

    #[Test]
    public function still_pending_after_second_check_is_unknown_and_bounded(): void
    {
        $send = $this->send(); $this->group($send);
        Http::fake(['*' => Http::response(['successful' => 0, 'errored' => 0, 'remaining' => 74], 200)]);
        (new ReadPushOutcomeJob($send->id))->handle(new OnesignalService);
        (new ReadPushOutcomeJob($send->id, 2))->handle(new OnesignalService);
        (new ReadPushOutcomeJob($send->id, 2))->handle(new OnesignalService);
        Http::assertSentCount(2);
        Queue::assertPushed(ReadPushOutcomeJob::class, 1);
        $this->assertStringContainsString('Delivery could not be confirmed for 74 phones.', $send->fresh()->wording());
    }

    #[Test]
    public function provider_failure_and_malformed_success_never_become_delivery_success(): void
    {
        $send = $this->send(); $this->group($send);
        Http::fake(['*' => Http::response(['errors' => ['invented-private-credential']], 500)]);
        Log::spy();
        (new ReadPushOutcomeJob($send->id))->handle(new OnesignalService);
        $this->assertSame('unknown', $send->fresh()->status);
        $this->assertNull($send->fresh()->delivered);
        $this->assertSame('Sent to 74 phones. Delivery could not be confirmed for 74 phones.', $send->fresh()->wording());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) =>
            !str_contains($message.json_encode($context), 'invented-private-credential'));
    }

    #[Test]
    public function a_success_status_without_delivery_counts_is_unknown(): void
    {
        $send = $this->send(); $this->group($send);
        Http::fake(['*' => Http::response(['id' => 'example-message', 'completed_at' => 12345])]);
        (new ReadPushOutcomeJob($send->id))->handle(new OnesignalService);
        $this->assertSame('unknown', $send->fresh()->status);
        $this->assertNull($send->fresh()->delivered);
    }

    #[Test]
    public function provider_send_errors_never_store_or_log_the_response_and_retries_skip_accepted_groups(): void
    {
        $send = $this->send();
        $org = Masjid::findOrFail($send->masjid_id);
        foreach (['alpha', 'beta'] as $app) {
            PushApp::create(['provider_app_id' => $app, 'rest_api_key' => 'invented-private-credential', 'label' => $app]);
            \App\Models\MobileAppUser::create(['masjid_id' => $org->id, 'device_id' => $app, 'user_agent' => 'Example',
                'push_app_id' => $app, 'onesignal_subscription_id' => $app]);
        }
        $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        Http::fakeSequence()->push(['id' => 'alpha-message'])->push(['errors' => ['invented-private-credential']], 500)
            ->push(['id' => 'beta-message']);
        Log::spy();
        $job = new SendMasjidNotificationJob($note, $org, ['alpha', 'beta']);
        $job->handle(new OnesignalService);
        $job->handle(new OnesignalService);
        Http::assertSentCount(2);
        $saved = PushSend::where('notification_id', $note->id)->firstOrFail();
        $this->assertSame(['alpha-message', null], $saved->groups()->orderBy('id')->pluck('provider_message_id')->all());
        $this->assertSame('unknown', $saved->groups()->where('routing_key', 'app:beta')->firstOrFail()->status);
        $this->assertStringNotContainsString('invented-private-credential', $saved->toJson());
        $job->failed(new \RuntimeException('invented-private-credential'));
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => !str_contains($message.json_encode($context), 'invented-private-credential'));
        $this->assertNotNull($note->fresh());
    }

    #[Test]
    public function readback_resolves_each_recorded_account_and_aggregates_groups_without_tenant_context_drift(): void
    {
        $send = $this->send(); $this->group($send);
        PushApp::create(['provider_app_id' => 'another-app', 'rest_api_key' => 'invented-second-credential', 'label' => 'Second']);
        $send->groups()->create(['masjid_id' => $send->masjid_id,'provider_app_id' => 'another-app', 'routing_key' => 'app:another-app',
            'app_label' => 'Second','targeted' => 10,'provider_message_id' => 'another-message','status' => 'checking']);
        Http::fakeSequence()->push(['successful' => 8,'errored' => 40,'remaining' => 0])
            ->push(['successful' => 10,'errored' => 0,'remaining' => 0]);
        app(\App\Support\TenantContext::class)->set(9999);
        (new ReadPushOutcomeJob($send->id))->handle(new OnesignalService);
        $send->refresh();
        $this->assertSame(84, $send->targeted);
        $this->assertSame(18, $send->delivered);
        $this->assertSame(40, $send->errored);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/another-message?app_id=another-app') && $r->hasHeader('Authorization'));
        $this->assertSame(9999, app(\App\Support\TenantContext::class)->get());
    }

    #[Test]
    public function a_permanently_failed_worker_preserves_the_record_and_marks_unfinished_groups_unknown(): void
    {
        $send = $this->send();
        $org = Masjid::findOrFail($send->masjid_id);
        $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        $send->forceFill(['notification_id' => $note->id])->save();
        $this->group($send);
        $send->groups()->create(['masjid_id' => $org->id,'routing_key' => 'app:unfinished',
            'app_label' => 'Unfinished example','targeted' => 1,'status' => 'checking']);
        (new SendMasjidNotificationJob($note, $org, ['example-sub']))->failed(new \RuntimeException('invented-private-credential'));
        $this->assertSame('unknown', $send->groups()->where('routing_key','app:unfinished')->firstOrFail()->status);
        $this->assertNotNull($note->fresh());
        Queue::assertPushed(ReadPushOutcomeJob::class, 1);
    }

    #[Test]
    public function serialized_jobs_and_serialized_registry_never_contain_credentials(): void
    {
        $send = $this->send();
        $app = PushApp::create(['provider_app_id' => 'shared-example', 'rest_api_key' => 'invented-private-credential',
            'label' => 'Shared example', 'active' => true]);
        $org = Masjid::findOrFail($send->masjid_id);
        $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        $payloadQueue = new \Illuminate\Queue\DatabaseQueue(\Illuminate\Support\Facades\DB::connection(), 'jobs', 'default', 360);
        $payloadQueue->setContainer($this->app);
        foreach ([new ReadPushOutcomeJob($send->id), new \App\Jobs\SendPrayerSyncJob($org->id, ['example-sub']), new SendMasjidNotificationJob($note, $org, ['example-sub'])] as $job) {
            $this->assertStringNotContainsString('invented-private-credential', serialize($job));
            $payloadId = $payloadQueue->push($job);
            $this->assertStringNotContainsString('invented-private-credential', \Illuminate\Support\Facades\DB::table('jobs')->where('id', $payloadId)->value('payload'));
        }
        $this->assertStringNotContainsString('invented-private-credential', $app->toJson());
        $this->assertNotSame('invented-private-credential', $app->getRawOriginal('rest_api_key'));
    }
}
