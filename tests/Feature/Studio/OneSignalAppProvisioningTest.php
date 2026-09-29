<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MobileAppUser;
use App\Services\OneSignalProvisioningService;
use App\Services\OneSignalResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OneSignalProvisioningService::ensureApp (Manara Studio W2 S14, D9).
 *
 * The OneSignal API shapes here are the current reference (documentation.onesignal.com,
 * read 2026-09-27): `Authorization: Key <Organization API key>`; GET /apps lists the
 * organisation's apps as a JSON array; POST /apps returns the app without a REST key;
 * POST /apps/{id}/auth/tokens returns `formatted_token` once; PUT /apps/{id} adds a
 * platform. Every request is faked and a stray one fails the test.
 *
 * The provisioning key is ONESIGNAL_ORG_API_KEY (services.onesignal.org_api_key). The
 * shared app's ONESIGNAL_USER_AUTH_KEY is set to a different value throughout, so a
 * request that carried it would show.
 */
class OneSignalAppProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '6f1e2d3c-4b5a-4968-8776-655443322110';

    private const OTHER_APP_ID = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    private const APPS_URL = 'https://api.onesignal.com/apps';

    private const TOKEN = 'os_v2_app_test_secret_never_logged';

    private const P8 = "-----BEGIN PRIVATE KEY-----\nMIGTAgEAtest\n-----END PRIVATE KEY-----";

    private const FCM = '{"type":"service_account","project_id":"test"}';

    private OneSignalProvisioningService $service;

    /** What the faked OneSignal answers; a test changes these between calls. */
    private int $createStatus = 200;

    private int $tokenStatus = 200;

    private int $updateStatus = 200;

    /** GET /apps: 200, another status, or 0 for no answer. */
    private int $listStatus = 200;

    /** @var list<array{id: string, name: string}> the apps the faked OneSignal organisation holds */
    private array $apps = [];

    /** @var array<string, int> "METHOD url" => requests the fake saw */
    private array $seen = [];

    /** A create that gets no answer (createStatus 0) still made the app, as a timeout can. */
    private bool $createdOnNoAnswer = false;

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
            'services.onesignal.org_api_key' => 'org-key-test',
            // The shared app's key: provisioning must never send it.
            'services.onesignal.user_auth_key' => 'user-auth-key-test',
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

    private function org(int $id, ?string $bundleId = 'derived', ?string $name = null): Masjid
    {
        // ios_bundle_id is unique: every organisation but 42 gets one of its own.
        if ($bundleId === 'derived') {
            $bundleId = $id === 42 ? 'com.hopetechapps.greenlane' : "com.hopetechapps.org{$id}";
        }

        $masjid = new Masjid([
            // masjids.name is unique; the app-name assertion uses organisation 42's.
            'name' => $name ?? ($id === 42 ? 'Green Lane Masjid' : "Org {$id}"),
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
     * $updateStatus / $listStatus and holding $apps, so a test can make the next call
     * succeed. (A second Http::fake() would not: the first matching stub wins.)
     */
    private function fakeOneSignal(int $createStatus = 200, int $tokenStatus = 200, int $updateStatus = 200): void
    {
        [$this->createStatus, $this->tokenStatus, $this->updateStatus] = [$createStatus, $tokenStatus, $updateStatus];
        Http::fake(function (Request $request) {
            $url = $request->url();
            $this->seen[$request->method().' '.$url] = ($this->seen[$request->method().' '.$url] ?? 0) + 1;
            if (preg_match('#^'.preg_quote(self::APPS_URL, '#').'/[^/]+/auth/tokens$#', $url)) {
                return $this->tokenStatus === 200
                    ? Http::response(['token_id' => 'tok-1', 'formatted_token' => self::TOKEN, 'name' => 'x'])
                    : Http::response(['errors' => ['no']], $this->tokenStatus);
            }
            if (preg_match('#^'.preg_quote(self::APPS_URL, '#').'/([^/]+)$#', $url, $match)) {
                return Http::response(['id' => $match[1]], $this->updateStatus);
            }
            if ($url === self::APPS_URL && $request->method() === 'GET') {
                if ($this->listStatus === 0) {
                    throw new ConnectionException('OneSignal did not answer');
                }

                return $this->listStatus === 200
                    ? Http::response($this->apps)
                    : Http::response(['errors' => ['no']], $this->listStatus);
            }
            if ($url === self::APPS_URL) {
                if ($this->createStatus === 0) {
                    if ($this->createdOnNoAnswer) {
                        $this->apps[] = $this->appNamed(self::APP_ID, $request->data()['name']);
                    }
                    throw new ConnectionException('OneSignal did not answer');
                }
                if ($this->createStatus !== 200) {
                    return Http::response(['errors' => ['no']], $this->createStatus);
                }
                $this->apps[] = $this->appNamed(self::APP_ID, $request->data()['name']);

                return Http::response(['id' => self::APP_ID, 'name' => $request->data()['name'], 'organization_id' => 'org-id-test']);
            }

            return null; // anything else is a stray request, and preventStrayRequests fails it
        });
    }

    /** @return array{id: string, name: string} */
    private function appNamed(string $id, string $name): array
    {
        return ['id' => $id, 'name' => $name];
    }

    /**
     * The requests of one method to one URL that reached the fake. Counted there, not
     * with Http::recorded(): a request the fake answers by throwing a
     * ConnectionException (a create that never answered) is not recorded.
     */
    private function sentTo(string $method, string $url): int
    {
        return $this->seen[$method.' '.$url] ?? 0;
    }

    private function subscribedDevice(int $masjidId): void
    {
        MobileAppUser::create(['masjid_id' => $masjidId, 'device_id' => 'device-'.uniqid(), 'onesignal_subscription_id' => 'sub-1', 'user_agent' => 'test']);
    }

    #[Test]
    public function it_lists_then_creates_the_app_with_the_organisation_key_and_id(): void
    {
        $this->fakeOneSignal();
        $org = $this->org(42);

        $result = $this->service->ensureApp($org, ['ios', 'android']);

        $this->assertSame(OneSignalResult::CREATED, $result->outcome);
        $this->assertSame(self::APP_ID, $result->appId);
        // The list comes first: a retry must find an app an earlier attempt made but never heard back about.
        $this->assertSame(['GET', 'POST', 'POST'], Http::recorded()->map(fn ($pair) => $pair[0]->method())->all());
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.onesignal.com/apps'
                && $request->header('Authorization') === ['Key org-key-test']
                && $request->data() === [
                    // Outside production the app's name carries the environment (tests boot as "testing").
                    'name' => 'Manara [testing] · Green Lane Masjid · #42',
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
        Http::assertSentCount(3); // list, create, mint

        $again = $this->service->ensureApp($org->fresh(), ['android', 'ios']);

        $this->assertSame(OneSignalResult::EXISTS, $again->outcome);
        $this->assertTrue($again->hasKey);
        Http::assertSentCount(3);
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
    public function a_failed_mint_after_create_is_key_pending_and_the_next_call_mints_it(): void
    {
        $this->fakeOneSignal(tokenStatus: 500);
        $org = $this->org(42);

        $result = $this->service->ensureApp($org, ['ios']);

        $this->assertSame(OneSignalResult::KEY_PENDING, $result->outcome);
        $this->assertFalse($result->succeeded(), 'an app without its key is not provisioned');
        $this->assertFalse($result->hasKey);
        $this->assertSame(self::APP_ID, $result->appId);
        $this->assertSame(500, $result->httpStatus);
        $this->assertStringContainsString('Run the call again', $result->message);
        $publishing = MasjidAppPublishing::where('masjid_id', 42)->firstOrFail();
        $this->assertSame(self::APP_ID, $publishing->onesignal_app_id);
        $this->assertSame(['ios'], $publishing->onesignal_platforms);
        $this->assertNull($publishing->getRawOriginal('onesignal_rest_api_key'));
        $this->assertFalse($publishing->hasOwnOnesignalApp(), 'sends stay on the shared app');

        // The next call mints the key only: no second app.
        $this->tokenStatus = 200;
        $retry = $this->service->ensureApp($org->fresh(), ['ios']);
        $this->assertSame(OneSignalResult::KEY_MINTED, $retry->outcome);
        $publishing = MasjidAppPublishing::where('masjid_id', 42)->firstOrFail();
        $this->assertTrue($publishing->hasOwnOnesignalApp());
        $this->assertTrue($publishing->hasStudioProvisionedOnesignalApp());
        Http::assertSentCount(4); // list, create, the failed mint, the successful mint
        $this->assertSame(1, $this->sentTo('POST', self::APPS_URL));
    }

    #[Test]
    public function a_refused_mint_says_to_check_the_organisation_key(): void
    {
        $this->fakeOneSignal(tokenStatus: 401);
        $org = $this->org(42);

        $result = $this->service->ensureApp($org, ['ios']);

        $this->assertSame(OneSignalResult::KEY_PENDING, $result->outcome);
        $this->assertSame(401, $result->httpStatus);
        $this->assertStringContainsString('Organization API key', $result->message);
    }

    #[Test]
    public function without_credentials_nothing_is_sent(): void
    {
        $org = $this->org(42);
        // ONESIGNAL_USER_AUTH_KEY stays set throughout: it is the shared app's key and never stands in.
        $this->assertNotEmpty(config('services.onesignal.user_auth_key'));
        foreach (['services.onesignal.org_api_key', 'services.onesignal.org_id'] as $key) {
            $saved = config($key);
            config([$key => null]);
            $this->assertSame(OneSignalResult::NOT_CONFIGURED, $this->service->ensureApp($org, ['ios'])->outcome, $key);
            config([$key => '']);
            $this->assertSame(OneSignalResult::NOT_CONFIGURED, $this->service->ensureApp($org, ['ios'])->outcome, $key.' blank');
            config([$key => $saved]);
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function provisioning_requests_carry_the_organisation_key_never_the_user_auth_key(): void
    {
        $this->fakeOneSignal();
        $org = $this->org(42);
        $this->service->ensureApp($org, ['ios']);                       // list, create, mint
        $this->service->ensureApp($org->fresh(), ['ios', 'android']);   // adds android

        $recorded = Http::recorded();
        $this->assertSame(['GET', 'POST', 'POST', 'PUT'], $recorded->map(fn ($pair) => $pair[0]->method())->all());
        foreach ($recorded as [$request]) {
            $this->assertSame(['Key org-key-test'], $request->header('Authorization'), $request->method().' '.$request->url());
        }
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
            config(['services.onesignal.org_api_key' => null]);
            $this->assertSame(OneSignalResult::REFUSED_LIVE_ORG, $this->service->ensureApp($org, ['ios'])->outcome);
            config(['services.onesignal.org_api_key' => 'org-key-test']);
            $this->assertSame(OneSignalResult::REFUSED_LIVE_ORG, $this->service->ensureApp($org, ['ios', 'android'])->outcome);
        }
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('masjid_app_publishing')->whereNotNull('onesignal_app_id')->count());
    }

    #[Test]
    public function the_audience_guard_runs_before_a_mint_or_a_platform_add(): void
    {
        $this->fakeOneSignal();
        // An app with no key yet: minting one is what would move the organisation's sends onto it.
        $org = $this->org(42);
        MasjidAppPublishing::where('masjid_id', 42)->update(['onesignal_app_id' => self::APP_ID, 'onesignal_platforms' => json_encode(['ios'])]);
        $this->subscribedDevice(42);
        $this->assertSame(OneSignalResult::HAS_AUDIENCE, $this->service->ensureApp($org, ['ios'])->outcome);
        Http::assertNothingSent();

        // An app that already has its key already receives the organisation's sends: adding a
        // platform to it moves nothing, so its devices do not stop that.
        $other = $this->org(44, 'com.hopetechapps.other');
        $publishing = MasjidAppPublishing::where('masjid_id', 44)->firstOrFail();
        $publishing->forceFill(['onesignal_app_id' => self::APP_ID, 'onesignal_rest_api_key' => 'k', 'onesignal_platforms' => ['ios']])->save();
        $this->subscribedDevice(44);
        $result = $this->service->ensureApp($other, ['ios', 'android']);
        $this->assertSame(OneSignalResult::PLATFORM_ADDED, $result->outcome);
        $this->assertSame(['ios', 'android'], MasjidAppPublishing::where('masjid_id', 44)->firstOrFail()->onesignal_platforms);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::APPS_URL.'/'.self::APP_ID);
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

    #[Test]
    public function a_retry_adopts_the_app_an_unanswered_create_made(): void
    {
        $this->fakeOneSignal(createStatus: 0);
        $this->createdOnNoAnswer = true; // the app was made; only the answer was lost
        $org = $this->org(42);

        $first = $this->service->ensureApp($org, ['ios']);

        $this->assertSame(OneSignalResult::TRANSIENT, $first->outcome);
        $this->assertNull(MasjidAppPublishing::where('masjid_id', 42)->value('onesignal_app_id'), 'its id never arrived');
        $this->assertCount(1, $this->apps);

        $this->createStatus = 200;
        $retry = $this->service->ensureApp($org->fresh(), ['ios']);

        $this->assertSame(OneSignalResult::ADOPTED, $retry->outcome);
        $this->assertTrue($retry->succeeded());
        $this->assertSame(self::APP_ID, $retry->appId);
        $this->assertTrue($retry->hasKey);
        $this->assertSame(1, $this->sentTo('POST', self::APPS_URL), 'the first attempt made the only app');
        $publishing = MasjidAppPublishing::where('masjid_id', 42)->firstOrFail();
        $this->assertSame(self::APP_ID, $publishing->onesignal_app_id);
        $this->assertSame(['ios'], $publishing->onesignal_platforms);
        $this->assertSame(self::TOKEN, $publishing->onesignal_rest_api_key, 'its key was minted');
        $this->assertTrue($publishing->hasStudioProvisionedOnesignalApp(), 'and it is marked as Studio\'s');
        // The requested platform is set on the adopted app, as it would be on any app on file.
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === self::APPS_URL.'/'.self::APP_ID
            && $r->data()['apns_bundle_id'] === 'com.hopetechapps.greenlane');
    }

    #[Test]
    public function an_app_is_adopted_by_the_organisation_id_not_by_its_name(): void
    {
        $this->fakeOneSignal();
        $this->apps = [
            $this->appNamed(self::OTHER_APP_ID, 'Manara [testing] · Green Lane Masjid · #142'),
            $this->appNamed('11111111-1111-4111-8111-111111111111', 'Manara [testing] · Someone · #4'),
            $this->appNamed('22222222-2222-4222-8222-222222222222', 'An unrelated app'),
            // The organisation was renamed since the first attempt: only the id suffix still matches.
            $this->appNamed(self::APP_ID, 'Manara [testing] · Green Lane Islamic Centre · #42'),
        ];
        $org = $this->org(42);

        $result = $this->service->ensureApp($org, ['ios']);

        $this->assertSame(OneSignalResult::ADOPTED, $result->outcome);
        $this->assertSame(self::APP_ID, $result->appId);
        $this->assertSame(0, $this->sentTo('POST', self::APPS_URL), 'no second app');
    }

    #[Test]
    public function two_apps_named_for_the_organisation_are_refused_and_nothing_is_stored(): void
    {
        $this->fakeOneSignal();
        $this->apps = [
            $this->appNamed(self::APP_ID, 'Manara [testing] · Green Lane Masjid · #42'),
            $this->appNamed(self::OTHER_APP_ID, 'Manara [testing] · Green Lane · #42'),
        ];
        $org = $this->org(42);
        $before = DB::table('masjid_app_publishing')->get();

        $result = $this->service->ensureApp($org, ['ios']);

        $this->assertSame(OneSignalResult::AMBIGUOUS_APP, $result->outcome);
        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString(self::APP_ID, $result->message);
        $this->assertStringContainsString(self::OTHER_APP_ID, $result->message);
        Http::assertSentCount(1); // the list, and nothing after it
        $this->assertEquals($before, DB::table('masjid_app_publishing')->get());
    }

    #[Test]
    public function a_list_that_cannot_be_read_stops_the_create(): void
    {
        $this->fakeOneSignal();
        foreach ([503 => OneSignalResult::TRANSIENT, 429 => OneSignalResult::TRANSIENT, 401 => OneSignalResult::REJECTED] as $status => $outcome) {
            $this->listStatus = $status;
            $org = $this->org(200 + $status);
            $result = $this->service->ensureApp($org, ['ios']);
            $this->assertSame($outcome, $result->outcome, "HTTP {$status}");
            $this->assertSame($status, $result->httpStatus);
            $this->assertNull(MasjidAppPublishing::where('masjid_id', $org->id)->value('onesignal_app_id'));
        }

        // No answer at all.
        $this->listStatus = 0;
        $org = $this->org(299);
        $this->assertSame(OneSignalResult::TRANSIENT, $this->service->ensureApp($org, ['ios'])->outcome);
        $this->assertNull(MasjidAppPublishing::where('masjid_id', 299)->value('onesignal_app_id'));

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    #[Test]
    public function a_list_in_an_unexpected_shape_stops_the_create(): void
    {
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['unexpected' => 'shape'])
            : null);
        $org = $this->org(42);

        $result = $this->service->ensureApp($org, ['ios']);

        $this->assertSame(OneSignalResult::REJECTED, $result->outcome);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    #[Test]
    public function a_production_named_app_is_not_adopted_from_another_environment(): void
    {
        $this->fakeOneSignal();
        // Staging is a copy of production (same organisation ids) and may share its OneSignal organisation.
        $this->apps = [$this->appNamed(self::OTHER_APP_ID, 'Manara · Green Lane Masjid · #42')];
        $org = $this->org(42);

        $result = $this->service->ensureApp($org, ['ios']);

        $this->assertSame(OneSignalResult::CREATED, $result->outcome);
        $this->assertSame(self::APP_ID, $result->appId, 'a new app, not production\'s');
        $this->assertSame(1, $this->sentTo('POST', self::APPS_URL));
    }

    #[Test]
    public function production_does_not_adopt_another_environments_app_and_names_its_own_without_a_marker(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->fakeOneSignal();
        $this->apps = [$this->appNamed(self::OTHER_APP_ID, 'Manara [staging] · Green Lane Masjid · #42')];
        $org = $this->org(42);

        $result = $this->service->ensureApp($org, ['ios']);

        $this->assertSame(OneSignalResult::CREATED, $result->outcome);
        $this->assertSame(self::APP_ID, $result->appId);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::APPS_URL
            && $r->data()['name'] === 'Manara · Green Lane Masjid · #42');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/auth/tokens')
            && $r->data() === ['name' => 'manara-production-masjid-42']);
    }

    #[Test]
    public function a_long_name_is_cut_to_128_characters_but_keeps_the_id_suffix_a_retry_finds_it_by(): void
    {
        $this->fakeOneSignal(createStatus: 0);
        $this->createdOnNoAnswer = true;
        $org = $this->org(42, name: str_repeat('Very Long Name ', 15));

        $this->assertSame(OneSignalResult::TRANSIENT, $this->service->ensureApp($org, ['ios'])->outcome);

        $name = $this->apps[0]['name'];
        $this->assertSame(128, mb_strlen($name));
        $this->assertStringStartsWith('Manara [testing] · Very Long Name', $name);
        $this->assertStringEndsWith(' · #42', $name);

        $this->createStatus = 200;
        $this->assertSame(OneSignalResult::ADOPTED, $this->service->ensureApp($org->fresh(), ['ios'])->outcome);
        $this->assertSame(1, $this->sentTo('POST', self::APPS_URL));
    }

    #[Test]
    public function a_held_lock_makes_the_call_transient_and_sends_nothing(): void
    {
        $this->fakeOneSignal();
        $org = $this->org(42);
        $held = Cache::lock('onesignal:ensure-app:42', 120);
        $this->assertTrue($held->get());

        // The call waits 15 seconds for the lock: fake that wait instead of sitting through it.
        Sleep::fake(syncWithCarbon: true);
        try {
            $result = $this->service->ensureApp($org, ['ios']);
        } finally {
            Sleep::fake(false);
            $this->travelBack();
        }

        $this->assertSame(OneSignalResult::TRANSIENT, $result->outcome);
        $this->assertStringContainsString('Another provisioning call', $result->message);
        Http::assertNothingSent();
        $this->assertNull(MasjidAppPublishing::where('masjid_id', 42)->value('onesignal_app_id'));

        // Once the holder is done the same call goes through, so the lock was what stopped it.
        $held->release();
        $this->assertSame(OneSignalResult::CREATED, $this->service->ensureApp($org->fresh(), ['ios'])->outcome);

        // And a finished call leaves the lock free.
        $free = Cache::lock('onesignal:ensure-app:42', 120);
        $this->assertTrue($free->get());
        $free->release();
    }

    #[Test]
    public function pretending_takes_no_lock(): void
    {
        $org = $this->org(42);
        $held = Cache::lock('onesignal:ensure-app:42', 120);
        $this->assertTrue($held->get());

        $result = $this->service->ensureApp($org, ['ios'], pretend: true);

        $this->assertSame(OneSignalResult::CREATED, $result->outcome);
        $this->assertTrue($result->pretended);
        Http::assertNothingSent();
        $held->release();
    }

    #[Test]
    public function the_command_takes_a_bundle_id_so_a_new_organisation_can_be_pretend_checked(): void
    {
        $this->org(50, bundleId: null);

        // Without one the organisation has no bundle id for iOS to use.
        $this->artisan('onesignal:ensure-app', ['masjid_id' => 50, '--platform' => ['ios'], '--pretend' => true])
            ->expectsOutputToContain('organisation 50: missing_apns')
            ->assertExitCode(1);

        $this->artisan('onesignal:ensure-app', ['masjid_id' => 50, '--platform' => ['ios'], '--bundle-id' => 'com.hopetechapps.x', '--pretend' => true])
            ->expectsOutputToContain('[pretend] organisation 50: created')
            ->assertExitCode(0);

        $this->assertSame(0, MasjidAppPublishing::where('masjid_id', 50)->count(), 'pretending wrote nothing, not even the bundle id');
        Http::assertNothingSent();
    }

    #[Test]
    public function the_command_refuses_a_bundle_id_another_organisation_holds(): void
    {
        $this->org(51, 'com.hopetechapps.taken');
        $this->org(52, bundleId: null);

        $this->artisan('onesignal:ensure-app', ['masjid_id' => 52, '--platform' => ['ios'], '--bundle-id' => 'com.hopetechapps.taken', '--pretend' => true])
            ->assertExitCode(1);

        $this->assertSame(0, MasjidAppPublishing::where('masjid_id', 52)->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function the_command_exits_1_when_the_key_is_pending(): void
    {
        $this->fakeOneSignal(tokenStatus: 500);
        $this->org(42);

        $this->artisan('onesignal:ensure-app', ['masjid_id' => 42, '--platform' => ['ios']])
            ->expectsOutputToContain('organisation 42: key_pending')
            ->assertExitCode(1);

        $this->assertSame(self::APP_ID, MasjidAppPublishing::where('masjid_id', 42)->value('onesignal_app_id'));

        $this->tokenStatus = 200;
        $this->artisan('onesignal:ensure-app', ['masjid_id' => 42, '--platform' => ['ios']])
            ->expectsOutputToContain('organisation 42: key_minted')
            ->assertExitCode(0);
        $this->assertSame(1, $this->sentTo('POST', self::APPS_URL), 'no second app');
    }
}
