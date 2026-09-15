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
 * The app's device endpoints are limited per PHONE and per NETWORK, not per IP.
 *
 * ## What happened
 *
 * `throttle:device` was 10 per hour per IP over registration, update, the
 * heartbeat and the device→masjid lookup. Every phone behind one public
 * address shares that IP: a masjid's Wi-Fi, a festival venue, carrier NAT. The
 * eleventh phone to open the app on such a network in an hour was refused, and
 * on 2026-09-15 a refused first-launch registration was seen stranding a new
 * iPhone on its splash screen.
 *
 * ## What these tests hold in place
 *
 * - A crowd on one network registers.
 * - One looping phone is refused with a body the iPhone can decode.
 * - That loop does not spend its neighbours' allowance.
 * - A script inventing device ids still meets a network ceiling.
 * - The heartbeat and lookup have their own bucket.
 *
 * `throttle:mobile` (60 per minute per IP) wraps the whole /api/mobile group
 * and still applies. The tests move the clock between minutes so that limiter
 * is never the one answering; see DECISIONS.md 2026-09-15 for why it is the
 * next shared-network ceiling.
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
    public function sixty_phones_on_one_network_can_all_register_and_then_check_in(): void
    {
        // The same minute. Under the old limit phone 11 was refused here.
        for ($i = 1; $i <= 60; $i++) {
            $this->register("crowd-phone-{$i}")->assertOk();
        }

        // The crowd keeps arriving: sixty more in the next minute.
        $this->nextMinute();
        for ($i = 61; $i <= 120; $i++) {
            $this->register("crowd-phone-{$i}")->assertOk();
        }

        $this->assertSame(120, MobileAppUser::where('device_id', 'like', 'crowd-phone-%')->count());

        // And the first sixty launch: their heartbeats are not spent by the
        // registrations above.
        $this->nextMinute();
        for ($i = 1; $i <= 60; $i++) {
            $this->heartbeat("crowd-phone-{$i}")->assertOk();
        }
    }

    #[Test]
    public function one_phone_in_a_retry_loop_is_refused_with_a_sentence_the_app_can_decode(): void
    {
        // The per-minute burst guard: ten accepted, the eleventh refused.
        for ($i = 0; $i < 10; $i++) {
            $this->register('runaway-phone')->assertOk();
        }
        $this->assertDecodableRefusal($this->register('runaway-phone'));

        // The per-hour limit: ten a minute for five more minutes brings the hour to 60.
        $this->spendHourlyWrites('runaway-phone', alreadyThisHour: 10);

        $this->nextMinute();
        $this->assertDecodableRefusal($this->register('runaway-phone'));

        // Refused calls wrote nothing.
        $this->assertSame(60, MobileAppUser::where('device_id', 'runaway-phone')->count());
    }

    #[Test]
    public function hammering_one_phone_does_not_refuse_a_different_phone_on_the_same_network(): void
    {
        // A network ceiling of exactly 60 + 10, so the arithmetic below proves the
        // runaway's REFUSED calls were not counted against the network.
        config(['mobile.device.writes_per_hour_per_ip' => 70]);

        $this->spendHourlyWrites('runaway-phone', alreadyThisHour: 0);

        // It keeps going, and keeps being refused.
        for ($minute = 0; $minute < 3; $minute++) {
            $this->nextMinute();
            for ($i = 0; $i < 15; $i++) {
                $this->assertDecodableRefusal($this->register('runaway-phone'));
            }
        }

        // Ten neighbours on the same Wi-Fi are unaffected...
        $this->nextMinute();
        for ($i = 1; $i <= 10; $i++) {
            $this->register("neighbour-phone-{$i}")->assertOk();
        }

        // ...and the eleventh meets the ceiling at exactly 70: the 45 refusals
        // above cost the network nothing.
        $this->assertDecodableRefusal($this->register('neighbour-phone-11'));

        // The phone's bucket includes the network, so the same device id is
        // not locked out from somewhere else.
        $this->onNetwork(self::OTHER_NETWORK);
        $this->register('runaway-phone')->assertOk();
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
        for ($i = 0; $i < 10; $i++) {
            $this->post('/api/mobile/user', ['masjid_id' => (string) $this->masjid->id, 'device_id' => 'form-phone-a'])
                ->assertOk();
        }
        $this->assertDecodableRefusal(
            $this->post('/api/mobile/user', ['masjid_id' => (string) $this->masjid->id, 'device_id' => 'form-phone-a'])
        );

        $this->post('/api/mobile/user', ['masjid_id' => (string) $this->masjid->id, 'device_id' => 'form-phone-b'])
            ->assertOk();
    }

    #[Test]
    public function heartbeat_and_masjid_lookup_follow_their_own_looser_bucket(): void
    {
        // Use up this phone's registration burst.
        for ($i = 0; $i < 10; $i++) {
            $this->register('launching-phone')->assertOk();
        }
        $this->assertDecodableRefusal($this->register('launching-phone'));

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

    private function onNetwork(string $ip): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function nextMinute(): void
    {
        $this->travel(61)->seconds();
    }

    private function register(string $deviceId): TestResponse
    {
        return $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->masjid->id,
            'device_id' => $deviceId,
        ]);
    }

    private function heartbeat(string $deviceId): TestResponse
    {
        return $this->postJson('/api/mobile/user/heartbeat', ['device_id' => $deviceId]);
    }

    /**
     * Registers `$deviceId` ten times a minute until 60 have been accepted this
     * hour, starting with a fresh minute when some were already accepted.
     */
    private function spendHourlyWrites(string $deviceId, int $alreadyThisHour): void
    {
        $accepted = $alreadyThisHour;

        while ($accepted < 60) {
            if ($accepted > 0) {
                $this->nextMinute();
            }
            for ($i = 0; $i < 10; $i++) {
                $this->register($deviceId)->assertOk();
            }
            $accepted += 10;
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
            ->assertJsonPath('message', AppServiceProvider::DEVICE_THROTTLE_MESSAGE)
            ->assertHeader('Retry-After');

        $this->assertStringContainsString('"data":{}', $response->getContent());
    }
}
