<?php

namespace Tests\Unit;

use App\Services\OnesignalService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OnesignalService on a deployment that was given no OneSignal credentials —
 * i.e. every environment that is not production (T-040 staging).
 *
 * The behaviour under test is a fail-CLOSED no-op, and it replaces a
 * constructor that threw. The throw was not merely noisy: `prayers:send-due` is
 * scheduled every minute and type-hints this service in `handle()`, so a blank
 * deployment raised an unhandled exception sixty times an hour and the
 * scheduler never completed cleanly.
 *
 * Every test asserts BOTH halves — the shaped result AND `Http::assertNothingSent()`.
 * A "not sent" return value that still posted to OneSignal would be the exact
 * accident this slice exists to prevent, and only the second assertion can see
 * it.
 */
class OnesignalUnconfiguredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Blank, not merely absent: a staging .env carries the keys with empty
        // values, which is the shape that used to trip the constructor.
        config([
            'onesignal.api_url' => '',
            'onesignal.app_id' => '',
            'onesignal.app_rest_api_key' => '',
        ]);
    }

    /**
     * Faked per test rather than in setUp: `Http::fake()` MERGES stub callbacks
     * instead of replacing them, so a catch-all registered in setUp would keep
     * winning over the specific 200-with-a-body stub the "still sends" case
     * needs, and that case would silently assert against an empty response.
     */
    private function fakeEveryRequest(): void
    {
        // Any outbound call at all is a failure in the unconfigured cases; the
        // fake is what makes that observable rather than a real request from CI.
        Http::fake();
    }

    #[Test]
    public function it_constructs_with_blank_credentials(): void
    {
        $service = new OnesignalService();

        $this->assertInstanceOf(OnesignalService::class, $service);
    }

    #[Test]
    public function the_container_can_resolve_it_with_blank_credentials(): void
    {
        // The path that actually mattered: `prayers:send-due` does not `new` the
        // service, it type-hints it into handle() and lets the container build
        // it. A constructor throw surfaced there, before any command code ran.
        $this->assertInstanceOf(OnesignalService::class, app(OnesignalService::class));
    }

    #[Test]
    public function is_configured_is_false_when_credentials_are_blank(): void
    {
        $this->assertFalse((new OnesignalService())->isConfigured());
    }

    #[Test]
    public function is_configured_is_false_when_only_some_credentials_are_present(): void
    {
        // Partial configuration is not configuration — the REST key is the one
        // that authorises the send, and without it every call 401s.
        config([
            'onesignal.api_url' => 'https://onesignal.com/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => '',
        ]);

        $this->assertFalse((new OnesignalService())->isConfigured());
    }

    #[Test]
    public function send_prayer_alert_returns_the_not_sent_shape_and_sends_nothing(): void
    {
        $this->fakeEveryRequest();

        $result = (new OnesignalService())->sendPrayerAlert(
            ['sub-1', 'sub-2'],
            "It's time for Fajr",
            'The time for Fajr prayer has arrived',
            'adhan.wav',
        );

        $this->assertIsArray($result);
        $this->assertNull($result['id'], 'id must be PRESENT and null: callers read this key to decide whether OneSignal accepted the send.');
        $this->assertTrue($result['not_sent']);
        $this->assertSame('onesignal_not_configured', $result['reason']);
        $this->assertSame(2, $result['recipients']);

        Http::assertNothingSent();
    }

    #[Test]
    public function send_data_sync_returns_the_not_sent_shape_and_sends_nothing(): void
    {
        $this->fakeEveryRequest();

        $result = (new OnesignalService())->sendDataSync(['sub-1'], ['masjid_id' => 1]);

        $this->assertIsArray($result);
        $this->assertNull($result['id']);
        $this->assertTrue($result['not_sent']);
        $this->assertSame('onesignal_not_configured', $result['reason']);
        $this->assertSame(1, $result['recipients']);

        Http::assertNothingSent();
    }

    #[Test]
    public function an_empty_recipient_list_still_returns_null_exactly_as_before(): void
    {
        // The pre-existing "nothing to do" contract, unchanged: callers that
        // treat null as "no devices" must keep working, and an idle per-minute
        // sweep must not log a warning about dropping zero recipients.
        $this->fakeEveryRequest();

        $service = new OnesignalService();

        $this->assertNull($service->sendDataSync([]));
        $this->assertNull($service->sendPrayerAlert([], 'title', 'body'));

        Http::assertNothingSent();
    }

    #[Test]
    public function get_notification_details_returns_an_empty_array_and_sends_nothing(): void
    {
        // Not a send, so it does not carry the not-sent shape — but it must not
        // build a request against a blank api_url either.
        $this->fakeEveryRequest();

        $this->assertSame([], (new OnesignalService())->getNotificationDetails('msg-1'));

        Http::assertNothingSent();
    }

    #[Test]
    public function configured_credentials_still_send(): void
    {
        // The other half of the guarantee: this slice must change NOTHING where
        // credentials are present. Same service, same call, real outbound post.
        config([
            'onesignal.api_url' => 'https://onesignal.com/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => 'rest-key-test',
        ]);

        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);

        $service = new OnesignalService();

        $this->assertTrue($service->isConfigured());

        $result = $service->sendPrayerAlert(['sub-1'], 'title', 'body');

        $this->assertSame('onesignal-message-id', $result['id']);
        $this->assertArrayNotHasKey('not_sent', $result);

        Http::assertSentCount(1);
    }
}
