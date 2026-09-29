<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\Notification;
use App\Models\SplashAnnouncement;
use App\Services\OnesignalInAppMessageService;
use App\Services\OnesignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where a send goes once an organisation has its own OneSignal app (W2 S14).
 *
 * The auth scheme travels with the key: the shared app keeps `Basic <shared key>`,
 * byte for byte, and an app Studio provisioned (onesignal_provisioned_at set), whose key
 * was minted through OneSignal's current API, is sent `Key <its key>`. A dedicated row
 * the pre-S14 route wrote (an app id and key, no onesignal_provisioned_at) keeps `Basic`
 * and the shared app's in-app messages, exactly as before. In-app messages for a Studio
 * app are managed in that app with the Organization API key (ONESIGNAL_ORG_API_KEY),
 * which is not the shared app's ONESIGNAL_USER_AUTH_KEY.
 */
class OnesignalDedicatedAppRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_URL = 'https://onesignal.test/api/v1/notifications';

    private const OWN_APP = 'a1b2c3d4-0000-4000-8000-00000000d00d';

    private const SHARED = 'shared';

    private const STUDIO = 'studio';

    private const LEGACY = 'legacy';

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
            'onesignal.api_url' => self::SHARED_URL,
            'onesignal.app_id' => 'shared-app-id',
            'onesignal.app_rest_api_key' => 'shared-rest-key',
            // The shared app's account key: sent as `Basic` by the shared app's in-app messages.
            'onesignal.user_auth_key' => 'user-auth-key-test',
            // The Organization API key: sent as `Key` by a Studio app's in-app messages.
            'services.onesignal.org_api_key' => 'org-key-test',
        ]);

        Http::preventStrayRequests();
        Http::fake(['onesignal.test/*' => Http::response(['id' => 'sent-1'])]);
    }

    /**
     * @param  string  $app  SHARED: no app of its own; STUDIO: an app Studio provisioned;
     *                       LEGACY: an app id and key with no onesignal_provisioned_at, as
     *                       the pre-S14 route wrote them
     */
    private function org(int $id, string $app): Masjid
    {
        $masjid = new Masjid([
            'name' => "Org {$id}",
            'email' => "org{$id}@test.local",
            'phone' => '+1555100'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
        $masjid->id = $id;
        $masjid->save();
        if ($app !== self::SHARED) {
            MasjidAppPublishing::create(['masjid_id' => $id])
                ->forceFill([
                    'onesignal_app_id' => self::OWN_APP,
                    'onesignal_rest_api_key' => 'own-rest-key',
                    'onesignal_provisioned_at' => $app === self::STUDIO ? now() : null,
                ])->save();
        }

        return $masjid->fresh();
    }

    private function notification(Masjid $masjid): Notification
    {
        $notification = new Notification(['title' => 'Jumu\'ah', 'message' => 'at 1:30']);
        $notification->masjid_id = $masjid->id;
        $notification->id = 7;

        return $notification;
    }

    /** Every send path, for one organisation. */
    private function sendThroughEveryPath(Masjid $masjid): void
    {
        $service = new OnesignalService();
        $service->notifyAll($masjid, $this->notification($masjid));
        $service->notifyAllOfMasjid($masjid, $this->notification($masjid), ['sub-1']);
        $service->sendDataSync(['sub-1'], [], $masjid);
        $service->sendPrayerAlert(['sub-1'], 'Fajr', 'Adhan', null, [], null, $masjid);
        $service->getNotificationDetails('sent-1', $masjid);
    }

    #[Test]
    public function every_send_path_uses_the_key_scheme_for_an_app_studio_provisioned(): void
    {
        $this->sendThroughEveryPath($this->org(42, self::STUDIO));

        $recorded = Http::recorded();
        $this->assertCount(5, $recorded);
        foreach ($recorded as [$request]) {
            $this->assertSame(['Key own-rest-key'], $request->header('Authorization'), $request->method().' '.$request->url());
            $appId = $request->method() === 'GET' ? $this->queryParam($request, 'app_id') : $request->data()['app_id'];
            $this->assertSame(self::OWN_APP, $appId);
        }
        // Its whole audience is the app: no tag filter on the broadcast.
        $broadcast = $recorded[0][0]->data();
        $this->assertSame(['Active Subscriptions'], $broadcast['included_segments']);
        $this->assertArrayNotHasKey('filters', $broadcast);
    }

    #[Test]
    public function an_org_without_its_own_app_is_unchanged_headers_included(): void
    {
        $this->sendThroughEveryPath($this->org(42, self::SHARED));

        $recorded = Http::recorded();
        $this->assertCount(5, $recorded);
        foreach ($recorded as [$request]) {
            $this->assertSame(['Basic shared-rest-key'], $request->header('Authorization'), $request->method().' '.$request->url());
            $this->assertStringStartsWith(self::SHARED_URL, $request->url());
            $appId = $request->method() === 'GET' ? $this->queryParam($request, 'app_id') : $request->data()['app_id'];
            $this->assertSame('shared-app-id', $appId);
        }
        $this->assertSame([['field' => 'tag', 'key' => 'masjid_id', 'relation' => '=', 'value' => '42']],
            $recorded[0][0]->data()['filters']);
        // The lookup without an organisation is today's call, unchanged.
        (new OnesignalService())->getNotificationDetails('sent-2');
        $last = Http::recorded()->last()[0];
        $this->assertSame(self::SHARED_URL.'/sent-2?app_id=shared-app-id', $last->url());
        $this->assertSame(['Basic shared-rest-key'], $last->header('Authorization'));
    }

    #[Test]
    public function a_legacy_dedicated_row_keeps_the_basic_scheme_on_every_send_path(): void
    {
        $this->sendThroughEveryPath($this->org(42, self::LEGACY));

        $recorded = Http::recorded();
        $this->assertCount(5, $recorded);
        foreach ($recorded as [$request]) {
            // Its own key, sent exactly as the pre-S14 code sent it.
            $this->assertSame(['Basic own-rest-key'], $request->header('Authorization'), $request->method().' '.$request->url());
            $appId = $request->method() === 'GET' ? $this->queryParam($request, 'app_id') : $request->data()['app_id'];
            $this->assertSame(self::OWN_APP, $appId);
        }
        // A dedicated app's whole audience is the app: still no tag filter on the broadcast.
        $broadcast = $recorded[0][0]->data();
        $this->assertSame(['Active Subscriptions'], $broadcast['included_segments']);
        $this->assertArrayNotHasKey('filters', $broadcast);
    }

    #[Test]
    public function in_app_messages_for_an_org_with_a_studio_app_use_that_app_and_the_organisation_key(): void
    {
        $studio = $this->splash($this->org(42, self::STUDIO));
        $shared = $this->splash($this->org(43, self::SHARED));
        $service = new OnesignalInAppMessageService();

        $service->sync($studio);
        $service->sync($shared);

        [$first, $second] = [Http::recorded()[0][0], Http::recorded()[1][0]];
        $this->assertSame('https://onesignal.test/api/v1/apps/'.self::OWN_APP.'/in_app_messages', $first->url());
        $this->assertSame(['Key org-key-test'], $first->header('Authorization'));
        // The shared app: exactly the URL and header it always had.
        $this->assertSame('https://onesignal.test/api/v1/apps/shared-app-id/in_app_messages', $second->url());
        $this->assertSame(['Basic user-auth-key-test'], $second->header('Authorization'));
    }

    #[Test]
    public function in_app_messages_for_a_legacy_dedicated_row_go_to_the_shared_app_as_before(): void
    {
        $legacy = $this->splash($this->org(42, self::LEGACY));

        (new OnesignalInAppMessageService())->sync($legacy);

        $request = Http::recorded()[0][0];
        $this->assertSame('https://onesignal.test/api/v1/apps/shared-app-id/in_app_messages', $request->url());
        $this->assertSame(['Basic user-auth-key-test'], $request->header('Authorization'));
    }

    #[Test]
    public function a_studio_apps_in_app_messages_are_skipped_without_the_organisation_key_never_sent_to_the_shared_app(): void
    {
        config(['services.onesignal.org_api_key' => null]);
        $studio = $this->splash($this->org(42, self::STUDIO));

        (new OnesignalInAppMessageService())->sync($studio);

        Http::assertNothingSent();
    }

    private function splash(Masjid $masjid): SplashAnnouncement
    {
        return SplashAnnouncement::create([
            'masjid_id' => $masjid->id,
            'title' => 'Welcome',
            'body' => 'Hello',
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
    }

    private function queryParam(Request $request, string $name): ?string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query[$name] ?? null;
    }
}
