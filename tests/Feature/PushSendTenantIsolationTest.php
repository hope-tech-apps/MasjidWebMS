<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\PushSend;
use App\Models\PushSendGroup;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PushSendTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $name): Masjid
    {
        return Masjid::create(['name' => $name, 'email' => uniqid().'@example.test', 'phone' => (string) random_int(2000000000,2999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => 'Example Street','latitude' => 0,'longitude' => 0]);
    }

    #[Test]
    public function another_tenants_outcomes_and_groups_cannot_be_read_or_updated(): void
    {
        $a = $this->makeOrg('Alpha example'); $b = $this->makeOrg('Beta example');
        $send = PushSend::create(['masjid_id' => $b->id, 'kind' => 'notification']);
        $group = PushSendGroup::create(['masjid_id' => $b->id,'push_send_id' => $send->id,'routing_key' => 'legacy','app_label' => 'Beta account']);
        app(TenantContext::class)->set($a->id);
        $this->assertNull(PushSend::find($send->id));
        $this->assertNull(PushSendGroup::find($group->id));
        $this->assertSame(0, PushSend::whereKey($send->id)->update(['status' => 'complete']));
        $this->assertSame(0, PushSendGroup::whereKey($group->id)->update(['status' => 'complete']));
    }

    #[Test]
    public function office_history_uses_the_authorized_tenant_and_never_returns_another_orgs_push(): void
    {
        $a = $this->makeOrg('Alpha example'); $b = $this->makeOrg('Beta example');
        $note = $b->notifications()->create(['title' => 'PRIVATE EXAMPLE', 'message' => 'Body']);
        PushSend::create(['masjid_id' => $b->id, 'notification_id' => $note->id, 'kind' => 'notification']);
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'name' => 'Example admin', 'phone' => '5550190001']);
        $a->forceFill(['user_id' => $admin->id])->save();
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/masjids/'.$a->id.'/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/masjids/'.$b->id.'/notifications')->assertForbidden();
    }

    #[Test]
    public function office_reads_stored_wording_for_each_state_and_does_not_follow_a_foreign_reference(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $a = $this->makeOrg('Alpha example'); $b = $this->makeOrg('Beta example');
        $admin = User::factory()->create(['name' => 'Example admin', 'type' => 'MasjidAdmin', 'phone' => '5550190001']);
        $a->forceFill(['user_id' => $admin->id])->save();
        Sanctum::actingAs($admin);
        $note = $a->notifications()->create(['title' => 'Example', 'message' => 'Body']);
        $send = PushSend::create(['masjid_id' => $a->id, 'notification_id' => $note->id, 'kind' => 'notification', 'targeted' => 74]);
        $broadcast = \App\Models\Broadcast::create(['masjid_id' => $a->id,'title' => 'Example', 'body' => 'Body', 'channels' => ['push'], 'status' => 'sent']);
        $delivery = $broadcast->deliveries()->create(['masjid_id' => $a->id,'channel' => 'push','status' => 'sent',
            'target_count' => 74,'reference_id' => $note->id]);
        $group = $send->groups()->create(['masjid_id' => $a->id, 'routing_key' => 'legacy', 'app_label' => 'Example',
            'provider_app_id' => 'example-app', 'provider_message_id' => 'accepted-example', 'targeted' => 74]);
        foreach ([['checking', null, null, 'Sent to 74 phones. Checking delivery…'],
            ['complete', 8, 40, 'Sent to 74 phones. Delivered to 8. 40 could not be delivered (iPhone). Delivery could not be confirmed for 26 phones.'],
            ['unknown', null, null, 'Sent to 74 phones. Delivery could not be confirmed for 74 phones.']] as [$status, $delivered, $errored, $wording]) {
            $group->forceFill(['status' => $status, 'delivered' => $delivered, 'errored' => $errored])->save();
            $send->forceFill(['status' => $status, 'delivered' => $delivered, 'errored' => $errored,
                'platform_counts' => $errored ? ['ios' => ['errored' => 40]] : null])->save();
            $this->getJson('/api/admin/masjids/'.$a->id.'/notifications')->assertOk()->assertJsonPath('data.0.push_outcome_text', $wording);
            $this->getJson('/api/admin/masjids/'.$a->id.'/broadcasts/'.$broadcast->id)->assertOk()->assertJsonPath('data.deliveries.0.push_outcome_text', $wording);
        }
        app(TenantContext::class)->forgetTenant();
        $foreignNote = $b->notifications()->create(['title' => 'Foreign example', 'message' => 'Body']);
        PushSend::create(['masjid_id' => $b->id,'notification_id' => $foreignNote->id,'kind' => 'notification',
            'targeted' => 999,'status' => 'complete','delivered' => 999]);
        $delivery->forceFill(['reference_id' => $foreignNote->id])->save();
        $response = $this->getJson('/api/admin/masjids/'.$a->id.'/broadcasts/'.$broadcast->id)->assertOk();
        $this->assertStringNotContainsString('999', $response->getContent());
        $this->getJson('/api/admin/masjids/'.$b->id.'/broadcasts/'.$broadcast->id)->assertForbidden();
    }

    #[Test]
    public function operator_report_narrows_by_organisation_and_date_without_printing_credentials(): void
    {
        $a = $this->makeOrg('Alpha example'); $b = $this->makeOrg('Beta example');
        $send = PushSend::create(['masjid_id' => $a->id, 'kind' => 'notification', 'status' => 'unknown', 'targeted' => 74]);
        $send->groups()->create(['masjid_id' => $a->id,'routing_key' => 'legacy','app_label' => 'Alpha account','status' => 'unknown']);
        $other = PushSend::create(['masjid_id' => $b->id, 'kind' => 'prayer_sync']);
        app(TenantContext::class)->set($b->id);
        $this->artisan('push:outcomes', ['--masjid' => (string) $a->id, '--since' => now()->subDay()->toDateString()])
            ->expectsOutputToContain('Alpha account')->doesntExpectOutputToContain('Beta account')
            ->doesntExpectOutputToContain('prayer_sync')->assertExitCode(0);
        $this->artisan('push:outcomes', ['--since' => now()->addDay()->toDateString()])
            ->doesntExpectOutputToContain('Alpha account')->assertExitCode(0);
    }
}
