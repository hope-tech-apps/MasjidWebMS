<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MobileAppUser;
use App\Services\OneSignalProvisioningService;
use App\Services\OneSignalResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OneSignalProvisioningService::ensureApp (Manara Studio W2 S14, D9).
 *
 * The OneSignal API shapes here are the current reference (documentation.onesignal.com,
 * read 2026-09-27): `Authorization: Key <Organization API key>`; POST /apps returns the
 * app without a REST key; POST /apps/{id}/auth/tokens returns `formatted_token` once;
 * PUT /apps/{id} adds a platform. Every request is faked and a stray one fails the test.
 */
class OneSignalAppProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '6f1e2d3c-4b5a-4968-8776-655443322110';

    private const TOKEN = 'os_v2_app_test_secret_never_logged';

    private const P8 = "-----BEGIN PRIVATE KEY-----\nMIGTAgEAtest\n-----END PRIVATE KEY-----";

    private const FCM = '{"type":"service_account","project_id":"test"}';

    private OneSignalProvisioningService $service;

    /** What the faked OneSignal answers; a test changes these between calls. */
    private int $createStatus = 200;

    private int $tokenStatus = 200;

    private int $updateStatus = 200;

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
            'services.onesignal.apns_p8' => self::P8,
            'services.onesignal.apns_key_id' => 'KEYID00001',
            'services.onesignal.apns_team_id' => 'TEAMID0001',
            'services.onesignal.apns_env' => 'production',
            'services.onesignal.fcm_v1_service_account_json' => self::FCM,
            'services.onesignal.never_provision' => [1, 5, 13],
        ]);

        Http::preventStrayRequests();
        $this->service = app(OneSignalProvisioningService::class);
    }

    private function org(int $id, ?string $bundleId = 'derived'): Masjid
    {
        // ios_bundle_id is unique: every organisation but 42 gets one of its own.
        if ($bundleId === 'derived') {
            $bundleId = $id === 42 ? 'com.hopetechapps.greenlane' : "com.hopetechapps.org{$id}";
        }

        $masjid = new Masjid([
            // masjids.name is unique; the app-name assertion uses organisation 42's.
            'name' => $id === 42 ? 'Green Lane Masjid' : "Org {$id}",
            'email' => "org{$id}@test.local",
            'phone' => '+1555000'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
        $masjid->id = $id;
        $masjid->save();

        if ($bundleId !== null) {
            MasjidAppPublishing::create(['masjid_id' => $id, 'ios_bundle_id' => $bundleId]);
        }

        return $masjid;
    }

    /**
     * One fake for the whole test, answering from $createStatus / $tokenStatus /
     * $updateStatus, so a test can make the next call succeed. (A second Http::fake()
     * would not: the first matching stub wins.)
     */
    private function fakeOneSignal(int $createStatus = 200, int $tokenStatus = 200, int $updateStatus = 200): void
    {
        [$this->createStatus, $this->tokenStatus, $this->updateStatus] = [$createStatus, $tokenStatus, $updateStatus];
        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($url === 'https://api.onesignal.com/apps/'.self::APP_ID.'/auth/tokens') {
                return $this->tokenStatus === 200
                    ? Http::response(['token_id' => 'tok-1', 'formatted_token' => self::TOKEN, 'name' => 'x'])
                    : Http::response(['errors' => ['no']], $this->tokenStatus);
            }
            if ($url === 'https://api.onesignal.com/apps/'.self::APP_ID) {
                return Http::response(['id' => self::APP_ID], $this->updateStatus);
            }
            if ($url === 'https://api.onesignal.com/apps') {
                if ($this->createStatus === 0) {
                    throw new \Illuminate\Http\Client\ConnectionException('OneSignal did not answer');
                }

                return $this->createStatus === 200
                    ? Http::response(['id' => self::APP_ID, 'name' => 'x', 'organization_id' => 'org-id-test'])
                    : Http::response(['errors' => ['no']], $this->createStatus);
            }

            return null; // anything else is a stray request, and preventStrayRequests fails it
        });
    }

    private function subscribedDevice(int $masjidId): void
    {
        MobileAppUser::create(['masjid_id' => $masjidId, 'device_id' => 'device-'.uniqid(), 'onesignal_subscription_id' => 'sub-1', 'user_agent' => 'test']);
    }

    #[Test]
    public function it_creates_the_app_with_the_organisation_key_and_id(): void
    {
        $this->fakeOneSignal();
        $org = $this->org(42);

        $result = $this->service->ensureApp($org, ['ios', 'android']);

        $this->assertSame(OneSignalResult::CREATED, $result->outcome);
        $this->assertSame(self::APP_ID, $result->appId);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.onesignal.com/apps'
                && $request->header('Authorization') === ['Key org-key-test']
                && $request->data() === [
                    'name' => 'Manara · Green Lane Masjid · #42',
                    'organization_id' => 'org-id-test',
                    'apns_key_id' => 'KEYID00001',
                    'apns_team_id' => 'TEAMID0001',
                    'apns_bundle_id' => 'com.hopetechapps.greenlane',
                    'apns_p8' => base64_encode(self::P8),
                    'apns_env' => 'production',
                    'fcm_v1_service_account_json' => base64_encode(self::FCM),
                ];
        });
        $publishing = MasjidAppPublishing::where('masjid_id', 42)->firstOrFail();
        $this->assertSame(self::APP_ID, $publishing->onesignal_app_id);
        $this->assertSame(['ios', 'android'], $publishing->onesignal_platforms);
        $this->assertNotNull($publishing->onesignal_provisioned_at);
    }

    #[Test]
    public function it_mints_the_rest_key_through_auth_tokens_and_stores_it_encrypted(): void
    {
        $this->fakeOneSignal();
        $org = $this->org(42);

        $result = $this->service->ensureApp($org, ['ios']);

        $this->assertTrue($result->hasKey);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.onesignal.com/apps/'.self::APP_ID.'/auth/tokens'
            && $r->header('Authorization') === ['Key org-key-test']
            && $r->data() === ['name' => 'manara-testing-masjid-42']);
        $raw = DB::table('masjid_app_publishing')->where('masjid_id', 42)->value('onesignal_rest_api_key');
        $this->assertNotSame(self::TOKEN, $raw, 'stored encrypted');
        $this->assertStringNotContainsString(self::TOKEN, (string) $raw);
        $publishing = MasjidAppPublishing::where('masjid_id', 42)->firstOrFail();
        $this->assertSame(self::TOKEN, $publishing->onesignal_rest_api_key);
        $this->assertTrue($publishing->hasOwnOnesignalApp());
        $this->assertArrayNotHasKey('onesignal_rest_api_key', $publishing->toArray());
    }

    #[Test]
    public function a_second_call_makes_no_request(): void
    {
        $this->fakeOneSignal();
        $org = $this->org(42);
        $this->service->ensureApp($org, ['ios', 'android']);
        Http::assertSentCount(2);

        $again = $this->service->ensureApp($org->fresh(), ['android', 'ios']);

        $this->assertSame(OneSignalResult::EXISTS, $again->outcome);
        $this->assertTrue($again->hasKey);
        Http::assertSentCount(2);
    }

    #[Test]
    public function an_org_with_a_subscribed_device_is_refused_before_any_request(): void
    {
        $this->fakeOneSignal();
        $org = $this->org(42);
        $this->subscribedDevice(42);
        $before = DB::table('masjid_app_publishing')->where('masjid_id', 42)->first();

        $result = $this->service->ensureApp($org, ['ios']);

        $this->assertSame(OneSignalResult::HAS_AUDIENCE, $result->outcome);
        Http::assertNothingSent();
        $this->assertEquals($before, DB::table('masjid_app_publishing')->where('masjid_id', 42)->first());
    }

    #[Test]
    public function a_device_without_a_subscription_is_not_an_audience(): void
    {
        $this->fakeOneSignal();
        $org = $this->org(42);
        MobileAppUser::create(['masjid_id' => 42, 'device_id' => 'device-no-sub', 'user_agent' => 'test']);

        $this->assertSame(OneSignalResult::CREATED, $this->service->ensureApp($org, ['ios'])->outcome);
    }

    #[Test]
    public function a_failed_key_mint_stores_the_id_only_and_sends_do_not_move(): void
    {
        $this->fakeOneSignal(tokenStatus: 500);
        $org = $this->org(42);

        $result = $this->service->ensureApp($org, ['ios']);

        $this->assertSame(OneSignalResult::CREATED, $result->outcome);
        $this->assertFalse($result->hasKey);
        $this->assertSame(500, $result->httpStatus);
        $publishing = MasjidAppPublishing::where('masjid_id', 42)->firstOrFail();
        $this->assertSame(self::APP_ID, $publishing->onesignal_app_id);
        $this->assertNull($publishing->getRawOriginal('onesignal_rest_api_key'));
        $this->assertFalse($publishing->hasOwnOnesignalApp(), 'sends stay on the shared app');

        // A retry mints the key only: no second app.
        $this->tokenStatus = 200;
        $retry = $this->service->ensureApp($org->fresh(), ['ios']);
        $this->assertSame(OneSignalResult::KEY_MINTED, $retry->outcome);
        $this->assertTrue(MasjidAppPublishing::where('masjid_id', 42)->firstOrFail()->hasOwnOnesignalApp());
        Http::assertSentCount(3); // create, the failed mint, the successful mint
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->url() === 'https://api.onesignal.com/apps'));
    }

    #[Test]
    public function without_credentials_nothing_is_sent(): void
    {
        $org = $this->org(42);
        foreach (['services.onesignal.user_auth_key', 'services.onesignal.org_id'] as $key) {
            $saved = config($key);
            config([$key => null]);
            $this->assertSame(OneSignalResult::NOT_CONFIGURED, $this->service->ensureApp($org, ['ios'])->outcome, $key);
            config([$key => $saved]);
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function ios_without_apns_configuration_is_refused(): void
    {
        $org = $this->org(42);
        config(['services.onesignal.apns_p8' => null]);
        $this->assertSame(OneSignalResult::MISSING_APNS, $this->service->ensureApp($org, ['ios'])->outcome);

        config(['services.onesignal.apns_p8' => self::P8]);
        $noBundle = $this->org(43, bundleId: null);
        $this->assertSame(OneSignalResult::MISSING_APNS, $this->service->ensureApp($noBundle, ['ios'])->outcome);

        config(['services.onesignal.fcm_v1_service_account_json' => null]);
        $this->assertSame(OneSignalResult::MISSING_FCM, $this->service->ensureApp($org, ['android'])->outcome);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_live_org_on_the_never_provision_list_is_refused_first(): void
    {
        foreach ([1, 5, 13] as $id) {
            $org = $this->org($id);
            // Refused before the credential check: with no key at all it is still refused_live_org.
            config(['services.onesignal.user_auth_key' => null]);
            $this->assertSame(OneSignalResult::REFUSED_LIVE_ORG, $this->service->ensureApp($org, ['ios'])->outcome);
            config(['services.onesignal.user_auth_key' => 'org-key-test']);
            $this->assertSame(OneSignalResult::REFUSED_LIVE_ORG, $this->service->ensureApp($org, ['ios', 'android'])->outcome);
        }
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('masjid_app_publishing')->whereNotNull('onesignal_app_id')->count());
    }

    #[Test]
    public function the_audience_guard_runs_before_a_mint_or_a_platform_add(): void
    {
        $this->fakeOneSignal();
        // An app with no key yet.
        $org = $this->org(42);
        MasjidAppPublishing::where('masjid_id', 42)->update(['onesignal_app_id' => self::APP_ID, 'onesignal_platforms' => json_encode(['ios'])]);
        $this->subscribedDevice(42);
        $this->assertSame(OneSignalResult::HAS_AUDIENCE, $this->service->ensureApp($org, ['ios'])->outcome);

        // An app with a key, asked for a platform it lacks.
        $other = $this->org(44, 'com.hopetechapps.other');
        $publishing = MasjidAppPublishing::where('masjid_id', 44)->firstOrFail();
        $publishing->forceFill(['onesignal_app_id' => self::APP_ID, 'onesignal_rest_api_key' => 'k', 'onesignal_platforms' => ['ios']])->save();
        $this->subscribedDevice(44);
        $this->assertSame(OneSignalResult::HAS_AUDIENCE, $this->service->ensureApp($other, ['ios', 'android'])->outcome);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_new_platform_is_added_to_the_existing_app(): void
    {
        $this->fakeOneSignal();
        $org = $this->org(42);
        MasjidAppPublishing::where('masjid_id', 42)->firstOrFail()
            ->forceFill(['onesignal_app_id' => self::APP_ID, 'onesignal_rest_api_key' => 'k', 'onesignal_platforms' => ['ios']])->save();

        $result = $this->service->ensureApp($org, ['ios', 'android']);

        $this->assertSame(OneSignalResult::PLATFORM_ADDED, $result->outcome);
        $this->assertSame(['ios', 'android'], $result->platforms);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'https://api.onesignal.com/apps/'.self::APP_ID
            && $r->header('Authorization') === ['Key org-key-test']
            && $r->data() === ['fcm_v1_service_account_json' => base64_encode(self::FCM)]);
        $this->assertSame(['ios', 'android'], MasjidAppPublishing::where('masjid_id', 42)->firstOrFail()->onesignal_platforms);
    }

    #[Test]
    public function pretend_sends_nothing_and_writes_nothing(): void
    {
        $org = $this->org(42, bundleId: null);
        $before = DB::table('masjid_app_publishing')->get();

        $result = $this->service->ensureApp($org, ['ios'], pretend: true, iosBundleId: 'com.hopetechapps.greenlane');

        $this->assertSame(OneSignalResult::CREATED, $result->outcome);
        $this->assertTrue($result->pretended);
        Http::assertNothingSent();
        $this->assertEquals($before, DB::table('masjid_app_publishing')->get(), 'not even the bundle id');
    }

    #[Test]
    public function a_bundle_id_is_stored_only_once_the_guards_have_passed(): void
    {
        $this->fakeOneSignal();
        $live = $this->org(1, bundleId: null);
        $this->service->ensureApp($live, ['ios'], iosBundleId: 'com.hopetechapps.live');
        $this->assertSame(0, MasjidAppPublishing::where('masjid_id', 1)->count(), 'a refused call writes nothing');

        $org = $this->org(42, bundleId: null);
        $this->assertSame(OneSignalResult::CREATED, $this->service->ensureApp($org, ['ios'], iosBundleId: 'com.hopetechapps.greenlane')->outcome);
        $this->assertSame('com.hopetechapps.greenlane', MasjidAppPublishing::where('masjid_id', 42)->value('ios_bundle_id'));
    }

    #[Test]
    public function oneSignal_refusals_and_outages_are_told_apart(): void
    {
        $this->fakeOneSignal();
        foreach ([400 => OneSignalResult::REJECTED, 401 => OneSignalResult::REJECTED,
            429 => OneSignalResult::TRANSIENT, 503 => OneSignalResult::TRANSIENT] as $status => $outcome) {
            $this->createStatus = $status;
            $org = $this->org(100 + $status);
            $result = $this->service->ensureApp($org, ['ios']);
            $this->assertSame($outcome, $result->outcome, "HTTP {$status}");
            $this->assertNull(MasjidAppPublishing::where('masjid_id', $org->id)->value('onesignal_app_id'));
        }
        // No answer at all.
        $this->createStatus = 0;
        $this->assertSame(OneSignalResult::TRANSIENT, $this->service->ensureApp($this->org(99), ['ios'])->outcome);
    }

    #[Test]
    public function the_key_never_appears_in_a_log_line(): void
    {
        Log::spy();
        $this->fakeOneSignal(tokenStatus: 500);
        $org = $this->org(42);
        $this->service->ensureApp($org, ['ios']);                       // mint fails, logs
        $this->tokenStatus = 200;
        $this->service->ensureApp($org->fresh(), ['ios']);              // mint succeeds
        $this->service->ensureApp($this->org(1), ['ios']);             // refused, logs

        Log::shouldHaveReceived('warning')->atLeast()->once();
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level, [Mockery::on(fn ($message) => str_contains(json_encode($message), self::TOKEN)
                || str_contains(json_encode($message), 'org-key-test')), Mockery::any()]);
            Log::shouldNotHaveReceived($level, [Mockery::any(), Mockery::on(fn ($context) => str_contains(json_encode($context), self::TOKEN)
                || str_contains(json_encode($context), 'org-key-test'))]);
        }
    }

    #[Test]
    public function the_command_pretends_and_reports_the_refusals(): void
    {
        foreach ([1, 5, 13] as $id) {
            $this->org($id);
            $this->artisan('onesignal:ensure-app', ['masjid_id' => $id, '--platform' => ['ios'], '--pretend' => true])
                ->expectsOutputToContain("organisation {$id}: refused_live_org")
                ->assertExitCode(1);
        }
        $this->org(42);
        $this->artisan('onesignal:ensure-app', ['masjid_id' => 42, '--pretend' => true])
            ->expectsOutputToContain('[pretend] organisation 42: created')
            ->assertExitCode(0);
        Http::assertNothingSent();
    }
}
