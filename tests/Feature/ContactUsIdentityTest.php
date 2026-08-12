<?php

namespace Tests\Feature;

use App\Models\ContactUsAccount;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public contact-us endpoints must not let an unauthenticated caller
 * rewrite somebody else's stored contact details.
 *
 * ## The defect
 *
 * `device_id` is an unverified string in the request body, and both controllers
 * resolved it with `MobileAppUser::where('device_id', $x)->first()` — across
 * every organisation — then OVERWROTE the resulting account's `name`, `email`
 * and `phone` with whatever the request carried.
 *
 * So knowing (or guessing) a device id was enough to deface that person's
 * record and file a message into their masjid's admin inbox under their name.
 * On the mobile route the `exists:mobile_app_users,device_id` rule did not help:
 * it is not tenant-scoped either, so a device id from masjid A validates against
 * masjid B's endpoint and then resolves to A's row.
 *
 * Two properties are pinned below, and the leak reopens if either is dropped:
 * the lookup is scoped to the tenant being written to, and an existing account
 * is never overwritten from an unauthenticated request.
 */
class ContactUsIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

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

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
    }

    #[Test]
    public function an_established_account_is_never_overwritten_by_a_public_request(): void
    {
        $victim = $this->makeDeviceWithAccount($this->masjidA, 'victim-device', [
            'name' => 'Real Person',
            'email' => 'real@example.com',
            'phone' => '+15550000001',
        ]);

        $this->postJson('/api/v1/contact-us', $this->payload('victim-device', [
            'name' => 'Attacker',
            'email' => 'attacker@evil.test',
            'phone' => '+19990000000',
        ]), ['masjid-id' => (string) $this->masjidA->id]);

        $victim->refresh();

        $this->assertSame('Real Person', $victim->name);
        $this->assertSame('real@example.com', $victim->email);
        $this->assertSame('+15550000001', $victim->phone);
    }

    #[Test]
    public function a_device_id_from_another_masjid_cannot_reach_that_masjids_record(): void
    {
        $victim = $this->makeDeviceWithAccount($this->masjidA, 'cross-tenant-device', [
            'name' => 'Masjid A Person',
            'email' => 'a-person@example.com',
            'phone' => '+15550000002',
        ]);

        // Posted at masjid B while naming a device registered to masjid A.
        $this->postJson('/api/v1/contact-us', $this->payload('cross-tenant-device', [
            'name' => 'Attacker',
            'email' => 'attacker@evil.test',
            'phone' => '+19990000000',
        ]), ['masjid-id' => (string) $this->masjidB->id]);

        $victim->refresh();

        $this->assertSame('Masjid A Person', $victim->name);
        $this->assertSame('a-person@example.com', $victim->email);

        // The device row itself must not have been re-homed either.
        $this->assertSame(
            $this->masjidA->id,
            MobileAppUser::where('device_id', 'cross-tenant-device')->first()->masjid_id
        );
    }

    #[Test]
    public function the_mobile_route_refuses_a_device_registered_to_another_masjid(): void
    {
        $victim = $this->makeDeviceWithAccount($this->masjidA, 'mobile-device', [
            'name' => 'Masjid A Person',
            'email' => 'a-person@example.com',
            'phone' => '+15550000003',
        ]);

        $response = $this->postJson(
            "/api/mobile/masjids/{$this->masjidB->id}/contact-us",
            $this->payload('mobile-device', [
                'name' => 'Attacker',
                'email' => 'attacker@evil.test',
                'phone' => '+19990000000',
            ])
        );

        // A refusal, not a 500 — the old code dereferenced null here.
        $this->assertContains($response->status(), [404, 422], 'expected a refusal, got '.$response->status());
        $this->assertNotSame(500, $response->status());

        $victim->refresh();
        $this->assertSame('Masjid A Person', $victim->name);
        $this->assertSame('a-person@example.com', $victim->email);
    }

    #[Test]
    public function a_blank_field_is_still_filled_in_so_legitimate_use_is_unaffected(): void
    {
        // The one thing the fix must NOT break: a person who first submitted
        // without a phone number and supplies one later.
        $account = $this->makeDeviceWithAccount($this->masjidA, 'blank-phone-device', [
            'name' => 'Real Person',
            'email' => 'real@example.com',
            'phone' => null,
        ]);

        $this->postJson('/api/v1/contact-us', $this->payload('blank-phone-device', [
            'name' => 'Real Person',
            'email' => 'real@example.com',
            'phone' => '+15551234567',
        ]), ['masjid-id' => (string) $this->masjidA->id])->assertOk();

        $this->assertSame('+15551234567', $account->refresh()->phone);
    }

    #[Test]
    public function a_first_time_sender_still_gets_an_account_created(): void
    {
        $this->postJson('/api/v1/contact-us', $this->payload('brand-new-device', [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'phone' => '+15557654321',
        ]), ['masjid-id' => (string) $this->masjidA->id])->assertOk();

        $device = MobileAppUser::where('device_id', 'brand-new-device')->first();

        $this->assertNotNull($device);
        $this->assertSame($this->masjidA->id, $device->masjid_id);
        $this->assertSame('New Person', ContactUsAccount::where('mobile_app_user_id', $device->id)->first()->name);
    }

    #[Test]
    public function a_tenant_less_or_unknown_organization_is_refused(): void
    {
        $this->postJson('/api/v1/contact-us', $this->payload('some-device'))
            ->assertStatus(400);

        $this->postJson('/api/v1/contact-us', $this->payload('some-device'), ['masjid-id' => '999999'])
            ->assertStatus(404);

        // Nothing was written on either refusal.
        $this->assertSame(0, MobileAppUser::count());
        $this->assertSame(0, ContactUsAccount::count());
    }

    // ------------------------------------------------------------- helpers

    /** @param array<string,mixed> $overrides */
    private function payload(string $deviceId, array $overrides = []): array
    {
        return array_merge([
            'device_id' => $deviceId,
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'phone' => '+15550000009',
            'reason_text' => 'General enquiry',
            'message' => 'Assalamu alaikum.',
        ], $overrides);
    }

    /** @param array<string,mixed> $details */
    private function makeDeviceWithAccount(Masjid $masjid, string $deviceId, array $details): ContactUsAccount
    {
        $device = MobileAppUser::create([
            'device_id' => $deviceId,
            'masjid_id' => $masjid->id,
            'user_agent' => 'test',
        ]);

        return ContactUsAccount::create(array_merge(
            ['mobile_app_user_id' => $device->id],
            $details
        ));
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Org '.uniqid(),
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
