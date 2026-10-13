<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MobileAppUser;
use App\Models\PushApp;
use App\Models\PushSend;
use App\Services\OneSignalProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PushRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Masjid
    {
        return Masjid::create(['name' => 'Example '.uniqid(), 'email' => uniqid().'@example.test',
            'phone' => (string) random_int(2000000000,2999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => 'Example Street', 'latitude' => 0, 'longitude' => 0]);
    }

    #[Test]
    public function migration_copies_runtime_shared_and_existing_encrypted_configuration_without_changing_it(): void
    {
        $org = $this->org();
        $publishing = MasjidAppPublishing::create(['masjid_id' => $org->id, 'onesignal_app_id' => 'example-dedicated',
            'onesignal_rest_api_key' => 'invented-dedicated-credential']);
        $ciphertext = $publishing->getRawOriginal('onesignal_rest_api_key');
        config(['onesignal.app_id' => 'example-shared', 'onesignal.app_rest_api_key' => 'invented-shared-credential']);
        $migration = require database_path('migrations/2026_10_13_150000_create_push_accounts_and_outcomes.php');
        $migration->down();
        $migration->up();
        $this->assertSame('invented-shared-credential', PushApp::where('provider_app_id','example-shared')->firstOrFail()->rest_api_key);
        $copy = PushApp::where('provider_app_id','example-dedicated')->firstOrFail();
        $this->assertSame($ciphertext, $copy->getRawOriginal('rest_api_key'));
        $this->assertSame('invented-dedicated-credential', $copy->rest_api_key);
        $this->assertSame($ciphertext, $publishing->fresh()->getRawOriginal('onesignal_rest_api_key'));
    }

    #[Test]
    public function migration_is_safe_when_no_existing_configurations_exist(): void
    {
        config(['onesignal.app_id' => '', 'onesignal.app_rest_api_key' => '']);
        $migration = require database_path('migrations/2026_10_13_150000_create_push_accounts_and_outcomes.php');
        $migration->down(); $migration->up();
        $this->assertSame(0, PushApp::count());
    }

    #[Test]
    public function provisioning_uses_explicit_apple_arguments_registers_encrypted_key_and_can_wait_to_attach(): void
    {
        config(['services.onesignal.user_auth_key' => 'invented-org-credential',
            'services.onesignal.apps_api_url' => 'https://push.example.test/apps',
            'services.onesignal.apns_p8' => 'invented-global-material', 'services.onesignal.apns_key_id' => 'global-example',
            'services.onesignal.apns_team_id' => 'global-team-example']);
        Http::fake(['*' => Http::response(['id' => 'example-new-app', 'basic_auth_key' => 'invented-new-credential'])]);
        $org = $this->org();
        $result = (new OneSignalProvisioningService)->provisionApp($org, 'test.example.phone',
            ['attach_to_organisation' => false], apnsP8: 'invented-team-material', apnsKeyId: 'key-example', apnsTeamId: 'team-example');
        $this->assertTrue($result['ok']);
        $this->assertStringNotContainsString('invented-new-credential', json_encode($result));
        Http::assertSent(fn ($r) => $r['apns_p8'] === 'invented-team-material' && $r['apns_key_id'] === 'key-example'
            && $r['apns_team_id'] === 'team-example' && $r['apns_bundle_id'] === 'test.example.phone');
        $this->assertSame('invented-new-credential', PushApp::where('provider_app_id','example-new-app')->firstOrFail()->rest_api_key);
        $this->assertDatabaseMissing('masjid_app_publishing', ['masjid_id' => $org->id]);
    }

    #[Test]
    public function provisioning_failure_does_not_log_echoed_credentials_or_exception_messages(): void
    {
        config(['services.onesignal.user_auth_key' => 'invented-org-credential']);
        Log::spy();
        Http::fake(['*' => Http::response(['errors' => ['invented-secret'], 'basic_auth_key' => 'invented-secret'], 400)]);
        $result = (new OneSignalProvisioningService)->provisionApp($this->org(), 'test.example.phone');
        $this->assertFalse($result['ok']);
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) =>
            !str_contains($message.json_encode($context),'invented-secret'));
        $this->assertStringNotContainsString('invented-secret',json_encode($result));
    }

    #[Test]
    public function staging_scrub_removes_registry_ledgers_and_all_device_routing_identity(): void
    {
        $org = $this->org();
        PushApp::create(['provider_app_id' => 'example-app', 'rest_api_key' => 'invented-credential', 'label' => 'Example']);
        $send = PushSend::create(['masjid_id' => $org->id,'kind' => 'notification']);
        $send->groups()->create(['masjid_id' => $org->id,'routing_key' => 'legacy','app_label' => 'Example']);
        MobileAppUser::create(['masjid_id' => $org->id,'device_id' => 'example-device', 'user_agent' => 'Example',
            'onesignal_subscription_id' => 'example-sub','push_app_id' => 'example-app']);
        MasjidAppPublishing::create(['masjid_id' => $org->id,'onesignal_app_id' => 'example-app','onesignal_rest_api_key' => 'invented-credential']);
        $this->app->detectEnvironment(fn () => 'staging');
        config(['app.env' => 'staging', 'database.connections.sqlite.database' => 'example_staging',
            'database.connections.sqlite.host' => 'localhost']);
        $this->artisan('staging:scrub', ['--i-understand-this-destroys-personal-data' => true])->assertExitCode(0);
        $this->assertSame(0, DB::table('push_apps')->count());
        $this->assertSame(0, DB::table('push_sends')->count());
        $this->assertSame(0, DB::table('push_send_groups')->count());
        $this->assertDatabaseHas('mobile_app_users', ['id' => 1, 'push_app_id' => null, 'onesignal_subscription_id' => null]);
        $this->assertDatabaseHas('masjid_app_publishing', ['masjid_id' => $org->id, 'onesignal_app_id' => null, 'onesignal_rest_api_key' => null]);
    }
}
