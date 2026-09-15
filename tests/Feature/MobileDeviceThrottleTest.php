<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The app's API is limited per PHONE and per NETWORK, not per IP.
 *
 * ## What happened
 *
 * Two limiters keyed on the IP alone stood in front of a first launch.
 * `throttle:mobile` was 60 a minute over every /api/mobile route, and
 * `throttle:device` was 10 an hour over registration, update, the heartbeat
 * and the device→masjid lookup. Every phone behind one public address shares
 * that IP: a masjid's Wi-Fi, a festival venue, carrier NAT. On 2026-09-15 a
 * refused first launch was seen stranding a new iPhone on its splash screen.
 *
 * ## What these tests hold in place
 *
 * - A crowd on one network launches the app in the same minute.
 * - Every layer refuses with a body the iPhone can decode.
 * - One looping phone costs its network a bounded amount, and the arithmetic
 *   of what is and is not counted is pinned.
 * - A script inventing device ids still meets a network ceiling.
 * - The heartbeat and lookup have their own bucket; checkout keeps its own.
 *
 * ## Why "one phone hammering" is a PUT
 *
 * `mobile_app_users.device_id` is UNIQUE. Registering an id that already
 * exists inserts nothing and answers 500, so a phone repeating one id is
 * modelled with PUT /api/mobile/user. That call is in the same `device` bucket
 * and updates the existing row.
 */
class MobileDeviceThrottleTest extends TestCase
{
    use RefreshDatabase;

    /** A masjid's Wi-Fi: every phone below shares this public address. */
    private const NETWORK = '203.0.113.10';

    private const OTHER_NETWORK = '198.51.100.20';

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masjid = Masjid::create([
            'name' => 'Throttle Masjid '.uniqid(),
            'email' => 'throttle-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $this->onNetwork(self::NETWORK);
    }

    #[Test]
    public function a_hundred_phones_launching_on_one_network_in_the_same_minute_all_get_through(): void
    {
        // 500 requests from one address inside one minute, at the default
        // numbers. The old `throttle:mobile` refused the 61st, the first
        // request of the thirteenth phone.
        for ($i = 1; $i <= 100; $i++) {
            $this->launch("crowd-phone-{$i}");
        }

        $this->assertSame(100, MobileAppUser::where('device_id', 'like', 'crowd-phone-%')->count());
    }

    #[Test]
    public function the_network_ceiling_on_app_routes_refuses_with_a_body_the_app_can_decode(): void
    {
        config(['mobile.api.per_minute_per_ip' => 30]);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/mobile/app-config')->assertOk();
        }
        $this->assertDecodableRefusal($this->getJson('/api/mobile/app-config'));

        // Another network has its own ceiling...
        $this->onNetwork(self::OTHER_NETWORK);
        $this->getJson('/api/mobile/app-config')->assertOk();

        // ...and the next minute frees this one.
        $this->onNetwork(self::NETWORK);
        $this->nextMinute();
        $this->getJson('/api/mobile/app-config')->assertOk();
    }

    #[Test]
    public function one_phone_in_a_retry_loop_is_refused_with_a_sentence_the_app_can_decode(): void
    {
        // The per-minute burst guard: ten device writes accepted, the eleventh refused.
        $this->registerAndSpendTheMinute('runaway-phone');
        $this->assertDecodableRefusal($this->updateDevice('runaway-phone'));

        // The per-hour limit: ten a minute for five more minutes brings the hour to 60.
        $this->spendTheRestOfTheHour('runaway-phone');

        $this->nextMinute();
        $this->assertDecodableRefusal($this->updateDevice('runaway-phone'));

        // One row, however hard it tried.
        $this->assertSame(1, MobileAppUser::where('device_id', 'runaway-phone')->count());
    }

    #[Test]
    public function hammering_one_phone_does_not_refuse_a_different_phone_on_the_same_network(): void
    {
        // A `device` network ceiling of exactly 60 + 10, so the arithmetic below
        // proves the calls `device` REFUSED were not counted against its network.
        config(['mobile.device.writes_per_hour_per_ip' => 70]);

        $this->registerAndSpendTheMinute('runaway-phone');
        $this->spendTheRestOfTheHour('runaway-phone');

        // It keeps going, and keeps being refused.
        for ($minute = 0; $minute < 3; $minute++) {
            $this->nextMinute();
            for ($i = 0; $i < 15; $i++) {
                $this->assertDecodableRefusal($this->updateDevice('runaway-phone'));
            }
        }

        // Ten neighbours on the same Wi-Fi are unaffected...
        $this->nextMinute();
        for ($i = 1; $i <= 10; $i++) {
            $this->register("neighbour-phone-{$i}")->assertOk();
        }

        // ...and the eleventh meets the ceiling at exactly 70: the 45 refusals
        // above cost `device`'s network ceiling nothing.
        $this->assertDecodableRefusal($this->register('neighbour-phone-11'));

        // The phone's bucket includes the network, so the same device id is
        // not locked out from somewhere else.
        $this->onNetwork(self::OTHER_NETWORK);
        $this->updateDevice('runaway-phone')->assertOk();
    }

    #[Test]
    public function a_looping_phone_that_names_itself_costs_its_network_at_most_its_per_phone_allowance(): void
    {
        // `throttle:mobile` runs FIRST. Its per-phone layer is 60 a minute
        // (default); the network ceiling is lowered to 100 so the neighbours'
        // share can be counted exactly.
        config(['mobile.api.per_minute_per_ip' => 100]);

        // 200 device writes in one minute: 1 POST + 199 PUTs.
        //  - 60 pass `mobile` (60 counted against the network).
        //    - 10 of those pass `device`'s burst guard and succeed.
        //    - 50 are refused by `device`. They WERE counted by `mobile`.
        //  - 140 are refused by `mobile`'s per-phone layer, counted nowhere else.
        $responses = [$this->register('looping-phone')];
        for ($i = 0; $i < 199; $i++) {
            $responses[] = $this->updateDevice('looping-phone');
        }

        $accepted = 0;
        foreach ($responses as $response) {
            if ($response->status() === 200) {
                $accepted++;
            } else {
                $this->assertDecodableRefusal($response);
            }
        }
        $this->assertSame(10, $accepted);

        // The network has 100 - 60 = 40 left this minute for everyone else. A
        // neighbour's read names no device, so only the ceiling applies.
        for ($i = 0; $i < 40; $i++) {
            $this->getJson('/api/mobile/app-config')->assertOk();
        }
        $this->assertDecodableRefusal($this->getJson('/api/mobile/app-config'));
    }

    #[Test]
    public function a_device_id_header_gives_a_read_route_its_own_per_phone_bucket(): void
    {
        config([
            'mobile.api.per_minute_per_ip' => 100,
            'mobile.api.per_minute_per_device' => 5,
        ]);

        // A phone naming itself on a read: five accepted, the rest refused
        // without touching the network ceiling.
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/mobile/app-config', ['X-Device-Id' => 'header-phone'])->assertOk();
        }
        for ($i = 0; $i < 45; $i++) {
            $this->assertDecodableRefusal($this->getJson('/api/mobile/app-config', ['X-Device-Id' => 'header-phone']));
        }

        // So the network still has 95 for everyone else.
        for ($i = 0; $i < 95; $i++) {
            $this->getJson('/api/mobile/app-config')->assertOk();
        }
        $this->assertDecodableRefusal($this->getJson('/api/mobile/app-config'));
    }

    #[Test]
    public function a_script_inventing_device_ids_still_meets_the_network_ceiling(): void
    {
        config([
            'mobile.device.writes_per_hour_per_ip' => 5,
            'mobile.device.activity_per_hour_per_ip' => 3,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->register("invented-{$i}")->assertOk();
        }
        $this->assertDecodableRefusal($this->register('invented-6'));
        $this->assertSame(5, MobileAppUser::where('device_id', 'like', 'invented-%')->count());

        for ($i = 1; $i <= 3; $i++) {
            $this->heartbeat("invented-heartbeat-{$i}")->assertOk();
        }
        $this->assertDecodableRefusal($this->heartbeat('invented-heartbeat-4'));

        // A different network has its own ceiling.
        $this->onNetwork(self::OTHER_NETWORK);
        $this->register('invented-7')->assertOk();
    }

    #[Test]
    public function a_request_without_a_usable_device_id_is_counted_against_its_network(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/mobile/user', ['masjid_id' => $this->masjid->id])->assertStatus(422);
        }
        $this->assertDecodableRefusal($this->postJson('/api/mobile/user', ['masjid_id' => $this->masjid->id]));

        // An ARRAY device id is "no device id" to the limiter: same bucket, and a
        // 429 rather than a 500 raised inside the limiter by string conversion.
        $this->assertDecodableRefusal(
            $this->postJson('/api/mobile/user', ['masjid_id' => $this->masjid->id, 'device_id' => ['x']])
        );

        // A real phone on that network has its own bucket.
        $this->register('real-phone')->assertOk();
    }

    #[Test]
    public function an_array_device_id_is_a_validation_failure_not_a_server_error(): void
    {
        $this->postJson('/api/mobile/user', ['masjid_id' => $this->masjid->id, 'device_id' => ['x']])
            ->assertStatus(422);
        $this->postJson('/api/mobile/user/heartbeat', ['device_id' => ['x']])
            ->assertStatus(422);
    }

    #[Test]
    public function the_device_id_is_read_from_a_form_encoded_body_too(): void
    {
        // If the limiter could only see a JSON body, both phones here would fall
        // back to the IP key, and phone B would be refused along with phone A.
        $form = fn (string $deviceId) => ['masjid_id' => (string) $this->masjid->id, 'device_id' => $deviceId];

        $this->post('/api/mobile/user', $form('form-phone-a'))->assertOk();
        for ($i = 0; $i < 9; $i++) {
            $this->put('/api/mobile/user', $form('form-phone-a'))->assertOk();
        }
        $this->assertDecodableRefusal($this->put('/api/mobile/user', $form('form-phone-a')));

        $this->post('/api/mobile/user', $form('form-phone-b'))->assertOk();
    }

    #[Test]
    public function heartbeat_and_masjid_lookup_follow_their_own_looser_bucket(): void
    {
        // Use up this phone's device-write burst.
        $this->registerAndSpendTheMinute('launching-phone');
        $this->assertDecodableRefusal($this->updateDevice('launching-phone'));

        // Its heartbeat and lookup are untouched by that: twenty in the same
        // minute, the heartbeat and the lookup sharing one bucket.
        for ($i = 0; $i < 19; $i++) {
            $this->heartbeat('launching-phone')->assertOk();
        }
        $this->getJson('/api/mobile/user/masjid?device_id=launching-phone')
            ->assertOk()
            ->assertJsonPath('data.id', $this->masjid->id);

        // The 21st and 22nd in that minute meet the burst guard, whichever route.
        $this->assertDecodableRefusal($this->heartbeat('launching-phone'));
        $this->assertDecodableRefusal($this->getJson('/api/mobile/user/masjid?device_id=launching-phone'));

        // The hour: twenty a minute for five more minutes brings it to 120.
        for ($minute = 1; $minute <= 5; $minute++) {
            $this->nextMinute();
            for ($i = 0; $i < 20; $i++) {
                $this->heartbeat('launching-phone')->assertOk();
            }
        }

        $this->nextMinute();
        $this->assertDecodableRefusal($this->heartbeat('launching-phone'));
        $this->assertDecodableRefusal($this->getJson('/api/mobile/user/masjid?device_id=launching-phone'));

        // Another phone on the same network still checks in.
        $this->heartbeat('other-phone')->assertOk();
    }

    #[Test]
    public function donation_checkout_keeps_its_own_limit_under_the_raised_ceiling(): void
    {
        // Every checkout writes a pending donation and opens a Stripe session, so
        // it must not inherit the crowd-sized `mobile` ceiling.
        config(['mobile.api.checkouts_per_minute_per_ip' => 3]);

        $checkout = "/api/mobile/masjids/{$this->masjid->id}/donations/checkout";

        // The limiter runs before validation, so an empty body shows which one
        // answered: 422 while allowed, then the limiter's 429.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson($checkout, [])->assertStatus(422);
        }
        $this->assertDecodableRefusal($this->postJson($checkout, []));

        // The rest of the app on that network is unaffected.
        $this->getJson('/api/mobile/app-config')->assertOk();
    }

    private function onNetwork(string $ip): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function nextMinute(): void
    {
        $this->travel(61)->seconds();
    }

    /**
     * The launch requests the limiters see from one phone. The splash's
     * app-config gate, registration, masjid and features, then the heartbeat.
     */
    private function launch(string $deviceId): void
    {
        $this->getJson('/api/mobile/app-config')->assertOk();
        $this->register($deviceId)->assertOk();
        $this->getJson("/api/mobile/masjids/{$this->masjid->id}")->assertOk();
        $this->getJson("/api/mobile/masjids/{$this->masjid->id}/features")->assertOk();
        $this->heartbeat($deviceId)->assertOk();
    }

    private function register(string $deviceId): TestResponse
    {
        return $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->masjid->id,
            'device_id' => $deviceId,
        ]);
    }

    /** PUT /api/mobile/user: same `device` bucket as registration, no insert. */
    private function updateDevice(string $deviceId): TestResponse
    {
        return $this->putJson('/api/mobile/user', [
            'masjid_id' => $this->masjid->id,
            'device_id' => $deviceId,
        ]);
    }

    private function heartbeat(string $deviceId): TestResponse
    {
        return $this->postJson('/api/mobile/user/heartbeat', ['device_id' => $deviceId]);
    }

    /** Registers the phone and updates it nine times: ten device writes this minute. */
    private function registerAndSpendTheMinute(string $deviceId): void
    {
        $this->register($deviceId)->assertOk();

        for ($i = 0; $i < 9; $i++) {
            $this->updateDevice($deviceId)->assertOk();
        }
    }

    /** Ten accepted updates a minute for five more minutes: the phone's hour reaches 60. */
    private function spendTheRestOfTheHour(string $deviceId): void
    {
        for ($minute = 1; $minute <= 5; $minute++) {
            $this->nextMinute();

            for ($i = 0; $i < 10; $i++) {
                $this->updateDevice($deviceId)->assertOk();
            }
        }
    }

    /**
     * A 429 the iPhone can decode: the legacy envelope, the sentence, and an
     * empty `data` OBJECT. `Response<T>.data` is non-optional, and the empty
     * payload decodes into a struct, which `[]` would fail.
     */
    private function assertDecodableRefusal(TestResponse $response): void
    {
        $response->assertStatus(429)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', AppServiceProvider::MOBILE_THROTTLE_MESSAGE)
            ->assertHeader('Retry-After');

        $this->assertStringContainsString('"data":{}', $response->getContent());
    }
}
