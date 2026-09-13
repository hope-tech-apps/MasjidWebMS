<?php

namespace Tests\Feature;

use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\Prayer;
use App\Services\OnesignalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The prayer backstop's Android half.
 *
 * ## The defect
 *
 * `OnesignalService::sendPrayerAlert` set `ios_sound` and `ios_category` and
 * nothing else, while `prayers:send-due` targets devices by OneSignal
 * subscription id with NO platform filter. Every Android handset in the fleet
 * was therefore in the audience and received the adhan reminder as the default
 * system ping — the one push in the product whose whole point is the sound it
 * makes. It was invisible from the server: OneSignal accepted the send and
 * reported it delivered.
 *
 * ## Why the assertions are on the OUTGOING PAYLOAD
 *
 * On Android 8+ a notification's sound belongs to a CHANNEL the app created, so
 * the only thing the server can get right or wrong is the channel id string in
 * the request body — and OneSignal accepts an id that matches no channel on the
 * handset and silently falls back to the default tone. There is no error to
 * assert on, at either end. What the request carried is the whole of the
 * server's contribution, so that is what is pinned here.
 *
 * Two ways to get it wrong are pinned by name:
 *
 *  - `android_channel_id` instead of `existing_android_channel_id`. The two sit
 *    one line apart in OneSignal's reference and the first takes a DASHBOARD
 *    uuid; an app channel id sent there is accepted and ignored.
 *  - adhan and iqama collapsing onto one channel, which would play the adhan
 *    at iqama time.
 */
class PrayerBackstopAndroidChannelTest extends TestCase
{
    use RefreshDatabase;

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
            'onesignal.api_url' => 'https://onesignal.com/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => 'rest-key-test',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_backstop_sends_adhan_and_iqama_on_their_own_android_channels(): void
    {
        // Faked per test, never in setUp: Http::fake() MERGES stubs, so a
        // catch-all registered once for the class keeps answering over anything
        // a later test adds (tests/CLAUDE.md).
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);

        $now = Carbon::parse('2026-09-13 10:30:00', 'UTC');
        Carbon::setTestNow($now);

        // A ZERO iqama offset puts both instants in the same minute on purpose,
        // so one run of the command exercises both branches and can show they do
        // not collapse onto the same channel. The offsets are what production
        // varies; the branch is not.
        $masjid = $this->org();
        IqamaTimeSetting::create([
            'masjid_id' => $masjid->id,
            'fajr' => 0, 'dhuhr' => 0, 'asr' => 0, 'maghrib' => 0, 'isha' => 0,
        ]);

        Prayer::create([
            'masjid_id' => $masjid->id,
            'date' => $now->format('Y-m-d'),
            'prayers_data' => ['fajr' => $now->toIso8601String()],
        ]);

        // A DARK device: it has heartbeated once (so it runs a build that can),
        // but not for longer than STALE_DAYS. Active devices are deliberately
        // never targeted by the backstop, so a fresh timestamp here would send
        // nothing and the test would pass on an empty assertion.
        MobileAppUser::create([
            'masjid_id' => $masjid->id,
            'device_id' => 'dark-android-handset',
            'onesignal_subscription_id' => 'sub-1',
            'user_agent' => 'test',
            'last_active_at' => $now->copy()->subDays(30),
        ]);

        $this->artisan('prayers:send-due')->assertExitCode(0);

        Http::assertSentCount(2);

        Http::assertSent(fn (Request $request) => $this->payloadMatches($request, [
            'existing_android_channel_id' => 'prayer_adhan_v1',
            'android_sound' => 'adhan',
            'ios_sound' => 'adhan.wav',
            'ios_category' => 'PRAYER_ADHAN',
        ]));

        Http::assertSent(fn (Request $request) => $this->payloadMatches($request, [
            'existing_android_channel_id' => 'prayer_iqama_v1',
            'android_sound' => 'iqamah',
            'ios_sound' => 'iqamah.wav',
        ]));

        // Said separately because the assertion above is satisfied by ANY one
        // request: if both sends carried the adhan channel, the iqama assertion
        // would simply not match — but if both carried the IQAMA channel, the
        // adhan one would not either, and neither failure names the real
        // problem. This one does.
        $channels = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['existing_android_channel_id'] ?? null)
            ->all();

        $this->assertEqualsCanonicalizing(
            ['prayer_adhan_v1', 'prayer_iqama_v1'],
            $channels,
            'adhan and iqama must not share a channel — a channel owns its sound for life'
        );
    }

    #[Test]
    public function the_manual_test_push_sends_on_the_same_channels(): void
    {
        // `prayers:test-push` is the command someone runs while holding the
        // handset, to hear whether the sound works. If IT names no channel then
        // a correct server path reports a default ping, which is the most
        // expensive false negative this fix could produce — the verification
        // tool telling you the fix failed.
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);

        $masjid = $this->org();
        MobileAppUser::create([
            'masjid_id' => $masjid->id,
            'device_id' => 'bench-handset',
            'onesignal_subscription_id' => 'sub-9',
            'user_agent' => 'test',
        ]);

        $this->artisan('prayers:test-push', ['device_id' => 'bench-handset'])->assertExitCode(0);
        $this->artisan('prayers:test-push', ['device_id' => 'bench-handset', '--iqama' => true])->assertExitCode(0);

        Http::assertSent(fn (Request $request) => $this->payloadMatches($request, [
            'existing_android_channel_id' => 'prayer_adhan_v1',
            'android_sound' => 'adhan',
        ]));

        Http::assertSent(fn (Request $request) => $this->payloadMatches($request, [
            'existing_android_channel_id' => 'prayer_iqama_v1',
            'android_sound' => 'iqamah',
        ]));
    }

    #[Test]
    public function the_channel_goes_in_the_field_that_takes_an_app_channel_id(): void
    {
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);

        (new OnesignalService())->sendPrayerAlert(
            ['sub-1'],
            "It's time for Fajr",
            'The time for Fajr prayer has arrived',
            'adhan.wav',
            [],
            'PRAYER_ADHAN',
            null,
            'prayer_adhan_v1',
        );

        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            $this->assertSame('prayer_adhan_v1', $payload['existing_android_channel_id'] ?? null);

            // The wrong twin. It takes a OneSignal dashboard uuid, and an app
            // channel id put there is accepted and then ignored — a default-tone
            // push with a 200 response and nothing in any log.
            $this->assertArrayNotHasKey('android_channel_id', $payload);

            // Bare resource name: Android resolves res/raw by name, so
            // "adhan.mp3" resolves to nothing.
            $this->assertSame('adhan', $payload['android_sound'] ?? null);

            return true;
        });
    }

    #[Test]
    public function a_caller_that_names_no_channel_sends_no_android_fields(): void
    {
        // The parameter is last and defaults to null so that every existing
        // caller keeps working unchanged. "Keeps working" means the request body
        // is the one it used to send, not merely that PHP accepts the call.
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);

        (new OnesignalService())->sendPrayerAlert(
            ['sub-1'],
            'title',
            'body',
            'adhan.wav',
        );

        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            $this->assertArrayNotHasKey('existing_android_channel_id', $payload);
            $this->assertArrayNotHasKey('android_sound', $payload);
            $this->assertSame('adhan.wav', $payload['ios_sound'] ?? null);

            return true;
        });
    }

    // ------------------------------------------------------------- helpers

    /** @param array<string,string> $expected */
    private function payloadMatches(Request $request, array $expected): bool
    {
        $payload = $request->data();

        foreach ($expected as $key => $value) {
            if (($payload[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function org(): Masjid
    {
        return Masjid::create([
            'name' => 'Backstop Test Org',
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }
}
