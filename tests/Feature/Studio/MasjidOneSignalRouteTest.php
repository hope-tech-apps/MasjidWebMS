<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MobileAppUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /api/admin/masjids/{id}/onesignal/provision now goes through ensureApp (W2 S14):
 * before, any SuperAdmin call could give a live organisation an empty app of its own and
 * cut its pushes off at once (apps-plane recon R1), and a second call orphaned the first.
 */
class MasjidOneSignalRouteTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '6f1e2d3c-4b5a-4968-8776-655443322110';

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
            'services.onesignal.user_auth_key' => 'org-key-test',
            'services.onesignal.org_id' => 'org-id-test',
            'services.onesignal.apps_api_url' => 'https://api.onesignal.com/apps',
            'services.onesignal.apns_p8' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----",
            'services.onesignal.apns_key_id' => 'KEYID00001',
            'services.onesignal.apns_team_id' => 'TEAMID0001',
            'services.onesignal.never_provision' => [1, 5, 13],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.onesignal.com/apps/'.self::APP_ID.'/auth/tokens' => Http::response(['formatted_token' => 'os_v2_app_route_test']),
            'https://api.onesignal.com/apps' => Http::response(['id' => self::APP_ID, 'organization_id' => 'org-id-test']),
        ]);
    }

    private function org(int $id): Masjid
    {
        $masjid = new Masjid([
            'name' => "Org {$id}",
            'email' => "org{$id}@test.local",
            'phone' => '+1555200'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
        $masjid->id = $id;
        $masjid->save();

        return $masjid;
    }

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15559990001']));
    }

    #[Test]
    public function the_legacy_route_is_refused_for_an_org_with_an_audience(): void
    {
        $this->org(42);
        MobileAppUser::create(['masjid_id' => 42, 'device_id' => 'device-1', 'onesignal_subscription_id' => 'sub-1', 'user_agent' => 'test']);
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/masjids/42/onesignal/provision', ['bundle_id' => 'com.hopetechapps.org42'])
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'has_audience');

        Http::assertNothingSent();
        $this->assertNull(MasjidAppPublishing::where('masjid_id', 42)->value('onesignal_app_id'));
    }

    #[Test]
    public function the_legacy_route_is_refused_for_a_live_org_and_writes_nothing_to_it(): void
    {
        $this->org(1);
        MasjidAppPublishing::create(['masjid_id' => 1]);
        $before = DB::table('masjid_app_publishing')->where('masjid_id', 1)->first();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/masjids/1/onesignal/provision', ['bundle_id' => 'com.hopetechapps.burlington'])
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'refused_live_org');

        Http::assertNothingSent();
        $this->assertEquals($before, DB::table('masjid_app_publishing')->where('masjid_id', 1)->first());
    }

    #[Test]
    public function a_new_org_gets_its_app_and_a_second_call_makes_none(): void
    {
        $this->org(42);
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/masjids/42/onesignal/provision', ['bundle_id' => 'com.hopetechapps.org42'])
            ->assertStatus(201)
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('data.onesignal_app_id', self::APP_ID)
            ->assertJsonPath('data.has_onesignal_key', true)
            ->assertJsonPath('data.onesignal_platforms', ['ios'])
            ->assertJsonMissingPath('data.onesignal_rest_api_key');
        $this->assertSame('com.hopetechapps.org42', MasjidAppPublishing::where('masjid_id', 42)->value('ios_bundle_id'));

        $this->postJson('/api/admin/masjids/42/onesignal/provision', ['bundle_id' => 'com.hopetechapps.org42'])
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'exists');
        Http::assertSentCount(2); // one create, one mint

        $this->getJson('/api/admin/masjids/42/onesignal')
            ->assertOk()
            ->assertJsonPath('data.has_onesignal_key', true)
            ->assertJsonPath('data.onesignal_platforms', ['ios'])
            ->assertJsonMissingPath('data.onesignal_rest_api_key');
    }

    #[Test]
    public function a_bundle_id_another_org_holds_is_refused(): void
    {
        $this->org(41);
        MasjidAppPublishing::create(['masjid_id' => 41, 'ios_bundle_id' => 'com.hopetechapps.taken']);
        $this->org(42);
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/masjids/42/onesignal/provision', ['bundle_id' => 'com.hopetechapps.taken'])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_masjid_admin_cannot_call_it(): void
    {
        $this->org(42);
        Sanctum::actingAs(User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15559990002']));

        $this->postJson('/api/admin/masjids/42/onesignal/provision', ['bundle_id' => 'com.hopetechapps.org42'])
            ->assertStatus(403);
        Http::assertNothingSent();
    }
}
