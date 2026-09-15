<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MobileAppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Registering the same install twice is a success, not a 500.
 *
 * `mobile_app_users.device_id` is UNIQUE. POST /api/mobile/user used to INSERT
 * unconditionally, so an install that never received its first reply (a dropped
 * connection, a 429 on the way back) hit the index on every later attempt and
 * got a 500. Android retries registration on each launch until it succeeds, so
 * that phone failed forever and spent its network's allowance every time.
 */
class MobileDeviceRegistrationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masjid = $this->makeMasjid('Home');
    }

    #[Test]
    public function registering_the_same_install_again_returns_the_same_row(): void
    {
        $first = $this->postJson('/api/mobile/user', ['masjid_id' => $this->masjid->id, 'device_id' => 'install-1'])
            ->assertOk()
            ->json('data');

        $second = $this->postJson('/api/mobile/user', ['masjid_id' => $this->masjid->id, 'device_id' => 'install-1'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, MobileAppUser::where('device_id', 'install-1')->count());
    }

    #[Test]
    public function a_repeat_registration_refreshes_activity_but_never_repoints_the_row(): void
    {
        $other = $this->makeMasjid('Other');

        $this->postJson('/api/mobile/user', ['masjid_id' => $this->masjid->id, 'device_id' => 'install-2'])->assertOk();
        MobileAppUser::where('device_id', 'install-2')->update(['last_active_at' => now()->subDays(3)]);

        $this->postJson('/api/mobile/user', ['masjid_id' => $other->id, 'device_id' => 'install-2'])->assertOk();

        $row = MobileAppUser::where('device_id', 'install-2')->firstOrFail();
        // PUT /api/mobile/user is the deliberate way to move a device; a repeat
        // registration must not silently re-point it (or its member claim).
        $this->assertSame($this->masjid->id, (int) $row->masjid_id);
        $this->assertTrue($row->last_active_at->greaterThan(now()->subMinute()));
    }

    #[Test]
    public function a_row_that_already_exists_before_the_request_is_returned_not_a_500(): void
    {
        // The state a racing duplicate leaves behind: the row is already there
        // when this request's lookup-or-create runs.
        $existing = MobileAppUser::create([
            'masjid_id' => $this->masjid->id,
            'device_id' => 'install-3',
            'user_agent' => 'first',
            'last_active_at' => now()->subHour(),
        ]);

        $this->postJson('/api/mobile/user', ['masjid_id' => $this->masjid->id, 'device_id' => 'install-3'])
            ->assertOk()
            ->assertJsonPath('data.id', $existing->id);
    }

    private function makeMasjid(string $label): Masjid
    {
        return Masjid::create([
            'name' => "{$label} Masjid ".uniqid(),
            'email' => strtolower($label).'-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }
}
