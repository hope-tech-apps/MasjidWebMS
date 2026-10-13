<?php

namespace Tests\Feature;

use App\Jobs\ReadPushOutcomeJob;
use App\Jobs\SendMasjidNotificationJob;
use App\Jobs\SendPrayerSyncJob;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\PushApp;
use App\Models\PushSend;
use App\Models\PushSendGroup;
use App\Services\OneSignalProvisioningService;
use App\Services\OnesignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PushReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Masjid
    {
        Queue::fake();
        config(['onesignal.api_url' => 'https://push.example.test/notifications', 'onesignal.app_id' => 'shared-example',
            'onesignal.app_rest_api_key' => 'invented-shared-key', 'services.onesignal.user_auth_key' => 'invented-org-key']);
        return Masjid::create(['name' => 'Example', 'email' => uniqid().'@example.test', 'phone' => '5550190000',
            'country_id' => '1', 'city_id' => '1', 'address' => 'Example Street', 'latitude' => 0, 'longitude' => 0]);
    }

    private function phone(Masjid $org, string $id, ?string $app = null): MobileAppUser
    {
        return MobileAppUser::create(['masjid_id' => $org->id, 'device_id' => $id, 'user_agent' => 'Example',
            'onesignal_subscription_id' => $id, 'push_app_id' => $app]);
    }

    public static function senders(): array
    {
        return ['office' => [false], 'nightly' => [true]];
    }

    private function job(Masjid $org, bool $sync, array $ids): SendMasjidNotificationJob|SendPrayerSyncJob
    {
        if ($sync) {
            $this->artisan('prayers:daily-resync')->assertExitCode(0);
            $job = Queue::pushed(SendPrayerSyncJob::class)->last();
            $this->assertEqualsCanonicalizing($ids, $job->subscriptionIds);
            $job->subscriptionIds = $ids;
            return $job;
        }
        return new SendMasjidNotificationJob($org->notifications()->create(['title' => 'Example', 'message' => 'Body']), $org, $ids);
    }

    #[Test]
    #[DataProvider('senders')]
    public function accepted_post_then_ledger_failure_never_posts_again(bool $sync): void
    {
        $org = $this->org(); $this->phone($org, 'a');
        $job = $this->job($org, $sync, ['a']);
        Http::fake(fn () => Http::response(['id' => 'accepted-message']));
        PushSendGroup::updating(function ($group) {
            if ($group->isDirty('provider_message_id')) {
                throw new \RuntimeException('Invented ledger outage');
            }
        });
        try {
            $job->handle(new OnesignalService);
        } catch (\RuntimeException $e) {
            $this->assertSame('Invented ledger outage', $e->getMessage());
        } finally {
            PushSendGroup::flushEventListeners();
        }
        unserialize(serialize($job))->handle(new OnesignalService);
        Http::assertSentCount(1);
        $send = PushSend::latest('id')->firstOrFail();
        $this->assertSame('unknown', $send->groups->first()->status);
        $this->assertNotNull($send->groups->first()->attempted_at);
        $this->assertSame('Delivery could not be confirmed for 1 phone.', $send->wording());
    }

    #[Test]
    #[DataProvider('senders')]
    public function registration_change_after_partial_attempt_cannot_move_a_phone(bool $sync): void
    {
        $org = $this->org(); $a = $this->phone($org, 'a'); $this->phone($org, 'b', 'second-app');
        foreach (['shared-example', 'second-app'] as $app) {
            PushApp::create(['provider_app_id' => $app, 'rest_api_key' => 'invented-key', 'label' => 'Example']);
        }
        $job = $this->job($org, $sync, ['a', 'b']);
        Http::fakeSequence()->push(['id' => 'accepted-a'])->push([], 500)->push(['id' => 'duplicate-a']);
        try { $job->handle(new OnesignalService); } catch (\RuntimeException $e) {}
        $a->forceFill(['push_app_id' => 'shared-example'])->save();
        unserialize(serialize($job))->handle(new OnesignalService);
        Http::assertSentCount(2);
        $this->assertSame(1, PushSend::count());
        $send = PushSend::firstOrFail();
        $this->assertSame(2, $send->targeted);
        $this->assertSame([['a'], ['b']], $send->groups()->orderBy('id')->get()->pluck('subscription_ids')->all());
    }

    #[Test]
    public function a_vetoed_attempt_claim_prevents_the_post(): void
    {
        $org = $this->org(); $this->phone($org, 'a'); $job = $this->job($org, false, ['a']);
        Http::fake(['*' => Http::response(['id' => 'accepted-a'])]);
        PushSendGroup::updating(fn ($group) => $group->status !== 'sending');
        try { try { $job->handle(new OnesignalService); } catch (\RuntimeException $e) {} }
        finally { PushSendGroup::flushEventListeners(); }
        Http::assertNothingSent();
        $this->assertSame('pending', PushSendGroup::firstOrFail()->status);
    }

    #[Test]
    public function sweep_finalizes_an_abandoned_silent_sync_claim_without_posting_again(): void
    {
        $org = $this->org(); $this->phone($org, 'a'); $job = $this->job($org, true, ['a']);
        Http::fake(['*' => Http::response(['id' => 'accepted-a'])]);
        PushSendGroup::updating(function ($group) {
            if ($group->isDirty('provider_message_id')) { throw new \RuntimeException('Invented ledger outage'); }
        });
        try { $job->handle(new OnesignalService); } finally { PushSendGroup::flushEventListeners(); }
        $this->travel(26)->minutes();
        $this->artisan('push:sweep-outcomes')->assertExitCode(0);
        $this->assertSame('unknown', PushSendGroup::firstOrFail()->status);
        $this->assertSame('Delivery could not be confirmed for 1 phone.', PushSend::firstOrFail()->wording());
        Http::assertSentCount(1);
    }

    #[Test]
    public function enqueue_outage_is_recovered_by_sweep_without_resending(): void
    {
        $org = $this->org(); $this->phone($org, 'a'); $job = $this->job($org, false, ['a']);
        Http::fake(['*' => Http::response(['id' => 'accepted-a'])]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Invented queue outage'));
        try { $job->handle(new OnesignalService); } catch (\RuntimeException $e) {}
        $send = PushSend::firstOrFail();
        $this->assertNull($send->readback_scheduled_at);
        Bus::swap(new \Illuminate\Bus\Dispatcher($this->app, fn ($connection = null) => $this->app['queue']->connection($connection)));
        $this->artisan('push:sweep-outcomes')->assertExitCode(0);
        $this->artisan('push:sweep-outcomes')->assertExitCode(0);
        Queue::assertPushed(ReadPushOutcomeJob::class, 1);
        $this->assertNotNull($send->fresh()->readback_scheduled_at);
        Http::assertSentCount(1);
    }

    public static function processingCompletionTimes(): array
    {
        return [[null], [0], [12345]];
    }

    #[Test]
    #[DataProvider('processingCompletionTimes')]
    public function null_remaining_is_not_final(?int $completedAt): void
    {
        $org = $this->org();
        $send = PushSend::create(['masjid_id' => $org->id, 'kind' => 'notification']);
        $send->groups()->create(['masjid_id' => $org->id, 'routing_key' => 'legacy', 'app_label' => 'Example',
            'provider_app_id' => 'shared-example', 'legacy' => true, 'targeted' => 1, 'provider_message_id' => 'accepted-a']);
        Http::fake(['*' => Http::response(['successful' => 0, 'errored' => 0, 'remaining' => null, 'completed_at' => $completedAt])]);
        (new ReadPushOutcomeJob($send->id))->handle(new OnesignalService);
        $this->assertSame('checking', $send->groups()->first()->status);
        Queue::assertPushed(ReadPushOutcomeJob::class, 1);
        (new ReadPushOutcomeJob($send->id))->handle(new OnesignalService);
        (new ReadPushOutcomeJob($send->id, 2))->handle(new OnesignalService);
        Http::assertSentCount(2);
        $this->assertSame('unknown', $send->groups()->first()->status);
    }

    #[Test]
    public function later_check_enqueue_failure_leaves_the_group_recoverable(): void
    {
        $org = $this->org();
        $send = PushSend::create(['masjid_id' => $org->id, 'kind' => 'notification']);
        $send->groups()->create(['masjid_id' => $org->id, 'routing_key' => 'legacy', 'app_label' => 'Example',
            'provider_app_id' => 'shared-example', 'legacy' => true, 'targeted' => 1, 'provider_message_id' => 'accepted-a']);
        Http::fake(['*' => Http::response(['successful' => 0, 'errored' => 0, 'remaining' => null])]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Invented queue outage'));
        $job = new ReadPushOutcomeJob($send->id);
        try { $job->handle(new OnesignalService); } catch (\Throwable $e) { $job->failed($e); }
        $this->assertSame('checking', $send->groups()->first()->status);
        $this->assertNull($send->fresh()->readback_scheduled_at);
        Bus::swap(new \Illuminate\Bus\Dispatcher($this->app, fn ($connection = null) => $this->app['queue']->connection($connection)));
        $this->artisan('push:sweep-outcomes')->assertExitCode(0);
        Queue::assertPushed(ReadPushOutcomeJob::class, fn ($job) => $job->check === 2);
    }

    public static function mixtures(): array
    {
        $cases = [];
        for ($mask = 1; $mask < 16; $mask++) {
            $groups = []; $words = [];
            foreach ([['complete',8,'Sent to 8 phones. Delivered to 8.'], ['rejected',40,'Could not be sent to 40 phones (the push service refused the request).'],
                ['unknown',3,'Delivery could not be confirmed for 3 phones.'], ['unknown_app',2,'2 phones could not be reached: their app is not set up for push.']] as $i => $entry) {
                if ($mask & (1 << $i)) { $groups[] = $entry; $words[] = $entry[2]; }
            }
            $cases[(string) $mask] = [$groups, implode(' ', $words)];
        }
        return $cases;
    }

    #[Test]
    #[DataProvider('mixtures')]
    public function office_words_distinguish_every_mixture(array $groups, string $expected): void
    {
        $org = $this->org(); $send = PushSend::create(['masjid_id' => $org->id, 'kind' => 'notification']);
        foreach ($groups as [$state, $count]) {
            $send->groups()->create(['masjid_id' => $org->id, 'routing_key' => $state, 'app_label' => 'Example', 'status' => $state,
                'targeted' => $count, 'provider_message_id' => $state === 'complete' ? 'accepted' : null,
                'delivered' => $state === 'complete' ? 8 : null]);
        }
        $send->aggregate();
        $this->assertSame(array_sum(array_column($groups, 1)), $send->targeted);
        $this->assertSame($expected, $send->wording());
    }

    #[Test]
    public function definitive_provider_rejection_is_not_sent_or_retried(): void
    {
        $org = $this->org(); $this->phone($org, 'a'); $job = $this->job($org, false, ['a']);
        Http::fake(['*' => Http::response(['errors' => ['Refused']], 400)]);
        try { $job->handle(new OnesignalService); } catch (\RuntimeException $e) {}
        $job->handle(new OnesignalService);
        Http::assertSentCount(1);
        $this->assertSame('Could not be sent to 1 phone (the push service refused the request).', PushSend::first()->wording());
    }

    #[Test]
    public function device_id_alone_or_wrong_subscription_cannot_change_app_on_any_verb(): void
    {
        $org = $this->org(); $phone = $this->phone($org, 'victim', 'real-app');
        foreach (['post', 'put', 'heartbeat'] as $verb) {
            foreach ([[], ['onesignal_subscription_id' => 'wrong']] as $extra) {
                $body = ['device_id' => 'victim', 'masjid_id' => $org->id, 'push_app_id' => 'attacker-app'] + $extra;
                $verb === 'heartbeat' ? $this->postJson('/api/mobile/user/heartbeat', $body)->assertOk()
                    : $this->{$verb.'Json'}('/api/mobile/user', $body)->assertOk();
                $this->assertSame('real-app', $phone->fresh()->push_app_id);
            }
            $phone->forceFill(['onesignal_subscription_id' => 'victim'])->save();
            $body = ['device_id' => 'victim', 'masjid_id' => $org->id, 'push_app_id' => 'genuine-app', 'onesignal_subscription_id' => 'victim'];
            $verb === 'heartbeat' ? $this->postJson('/api/mobile/user/heartbeat', $body)->assertOk()
                : $this->{$verb.'Json'}('/api/mobile/user', $body)->assertOk();
            $this->assertSame('genuine-app', $phone->fresh()->push_app_id);
            $phone->refresh()->forceFill(['push_app_id' => 'real-app'])->save();
        }
        $phone->forceFill(['push_app_id' => null])->save();
        $this->postJson('/api/mobile/user/heartbeat', ['device_id' => 'victim', 'push_app_id' => 'attacker-app'])->assertOk();
        $this->assertNull($phone->fresh()->push_app_id);
        $this->postJson('/api/mobile/user', ['device_id' => 'new', 'masjid_id' => $org->id, 'push_app_id' => 'genuine-app', 'onesignal_subscription_id' => 'new-sub'])->assertOk();
        $this->assertDatabaseHas('mobile_app_users', ['device_id' => 'new', 'push_app_id' => 'genuine-app', 'onesignal_subscription_id' => 'new-sub']);
    }

    #[Test]
    public function caller_without_proof_is_not_rejected_over_the_new_field(): void
    {
        $org = $this->org(); $this->phone($org, 'victim', 'real-app');
        foreach (['post', 'put', 'heartbeat'] as $verb) {
            $body = ['device_id' => 'victim', 'masjid_id' => $org->id, 'push_app_id' => ['invalid']];
            $verb === 'heartbeat' ? $this->postJson('/api/mobile/user/heartbeat', $body)->assertOk()
                : $this->{$verb.'Json'}('/api/mobile/user', $body)->assertOk();
            $this->assertDatabaseHas('mobile_app_users', ['device_id' => 'victim', 'push_app_id' => 'real-app']);
        }
    }

    #[Test]
    public function no_field_heartbeat_keeps_the_single_existing_update(): void
    {
        $org = $this->org(); $this->phone($org, 'a'); $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'mobile_app_users')) { $queries[] = $query->sql; }
        });
        $this->postJson('/api/mobile/user/heartbeat', ['device_id' => 'a'])->assertOk();
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('update', strtolower($queries[0]));
    }

    #[Test]
    public function a_database_after_commit_setting_cannot_stamp_an_unenqueued_check(): void
    {
        $org = $this->org();
        $send = PushSend::create(['masjid_id' => $org->id, 'kind' => 'notification']);
        $send->groups()->create(['masjid_id' => $org->id, 'routing_key' => 'legacy', 'app_label' => 'Example',
            'provider_app_id' => 'shared-example', 'legacy' => true, 'targeted' => 1, 'provider_message_id' => 'accepted-a']);
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);
        $manager = new \Illuminate\Queue\QueueManager($this->app);
        (new \Illuminate\Queue\QueueServiceProvider($this->app))->registerConnectors($manager);
        Queue::swap($manager);
        Schema::drop('jobs');
        try { app(\App\Services\PushOutcomeScheduler::class)->schedule($send->id); } catch (\Throwable $e) {}
        $this->assertNull($send->fresh()->readback_scheduled_at);
    }

    #[Test]
    public function an_incomplete_ledger_refuses_null_phones_before_posting(): void
    {
        $org = $this->org(); $this->phone($org, 'a');
        Schema::drop('push_send_groups'); Http::fake();
        $note = $org->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        $result = (new OnesignalService)->notifyAllOfMasjid($org, $note, ['a']);
        Http::assertNothingSent();
        $this->assertTrue($result['not_sent']);
    }

    #[Test]
    public function a_provider_created_app_without_a_returned_key_requires_cleanup(): void
    {
        $org = $this->org(); Http::fake(['*' => Http::response(['id' => 'new-app'])]);
        $result = (new OneSignalProvisioningService)->provisionApp($org, 'org.example.app', ['attach_to_organisation' => false]);
        $this->assertFalse($result['ok']);
        $this->assertSame('new-app', $result['app_id']);
        $this->assertStringContainsString('created at the provider but not stored: do not use; delete it there', $result['error']);
    }

    #[Test]
    public function production_never_posts_inside_an_enclosing_rollbackable_transaction(): void
    {
        $org = $this->org(); $this->phone($org, 'a'); $job = $this->job($org, false, ['a']);
        Http::fake(['*' => Http::response(['id' => 'accepted-a'])]);
        $this->app->detectEnvironment(fn () => 'local');
        try {
            try { $job->handle(new OnesignalService); } catch (\RuntimeException $e) {}
        } finally { $this->app->detectEnvironment(fn () => 'testing'); }
        Http::assertNothingSent();
    }

    #[Test]
    public function legacy_sync_queue_replay_uses_payload_uuid_even_when_the_queue_row_id_changes(): void
    {
        $org = $this->org(); $this->phone($org, 'a');
        $job = $this->job($org, true, ['a']); $job->requestKey = null;
        $payload = serialize($job);
        Http::fake(['*' => Http::response(['id' => 'accepted-a'])]);
        foreach (['first-row', 'replayed-row'] as $queueRowId) {
            $retry = unserialize($payload);
            $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
            $queueJob->shouldReceive('uuid')->andReturn('invented-stable-job-uuid');
            $queueJob->shouldReceive('getJobId')->andReturn($queueRowId);
            $retry->setJob($queueJob);
            $retry->handle(new OnesignalService);
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('push_sends', 1);
    }

    #[Test]
    public function unwritable_registry_refuses_before_contacting_the_provider(): void
    {
        $org = $this->org();
        Schema::table('push_apps', fn ($table) => $table->string('required_unavailable_value'));
        Http::fake();
        $result = (new OneSignalProvisioningService)->provisionApp($org, 'org.example.app', ['attach_to_organisation' => false]);
        $this->assertFalse($result['ok']); Http::assertNothingSent();
    }

    #[Test]
    public function a_vetoed_registry_write_cannot_report_provisioning_success(): void
    {
        $org = $this->org(); Http::fake(['*' => Http::response(['id' => 'new-app', 'basic_auth_key' => 'invented-key'])]);
        PushApp::creating(fn () => false);
        try {
            $result = (new OneSignalProvisioningService)->provisionApp($org, 'org.example.app', ['attach_to_organisation' => false]);
        } finally { PushApp::flushEventListeners(); }
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('created at the provider but not stored: do not use; delete it there', $result['error']);
        $this->assertDatabaseCount('push_apps', 0);
    }

    #[Test]
    public function registry_only_provisioning_refuses_missing_storage_before_post(): void
    {
        $org = $this->org(); Schema::drop('push_apps'); Http::fake();
        $result = (new OneSignalProvisioningService)->provisionApp($org, 'org.example.app', ['attach_to_organisation' => false]);
        $this->assertFalse($result['ok']); Http::assertNothingSent();
    }

    #[Test]
    public function provider_created_but_storage_failed_has_explicit_cleanup_result(): void
    {
        $org = $this->org(); Http::fake(['*' => Http::response(['id' => 'new-app', 'basic_auth_key' => 'invented-key'])]);
        PushApp::creating(fn () => throw new \RuntimeException('Invented storage outage'));
        try {
            $result = (new OneSignalProvisioningService)->provisionApp($org, 'org.example.app', ['attach_to_organisation' => false]);
        } finally { PushApp::flushEventListeners(); }
        $this->assertFalse($result['ok']);
        $this->assertSame('new-app', $result['app_id']);
        $this->assertStringContainsString('created at the provider but not stored: do not use; delete it there', $result['error']);
        $this->assertDatabaseCount('push_apps', 0);
    }
}
