<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An organisation's privacy policy address (masjids.privacy_policy_url, 2026-10-08): typed on
 * General Settings beside the store links, sent to the apps on the organisation payload.
 *
 * The apps draw a "Privacy Policy" row only when that payload carries an address they will
 * open: absolute, https, no sign-in details in it. Anything else they drop without a word, so
 * the office's screen is the one place a wrong address can be explained, and these pin that
 * the server refuses what the apps would drop.
 */
class PrivacyPolicyLinkTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = 'https://www.meccharlotte.org/privacy';

    private Masjid $masjid;

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

        $this->masjid = Masjid::create([
            'name' => 'Policy Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'listed_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]));
    }

    #[Test]
    public function an_organisation_that_named_no_policy_sends_the_apps_a_null_and_they_show_no_row(): void
    {
        $payload = $this->app_payload()->json('data');

        $this->assertArrayHasKey('privacy_policy_url', $payload);
        $this->assertNull($payload['privacy_policy_url']);
    }

    #[Test]
    public function an_address_saved_on_general_settings_is_what_the_apps_are_sent_at_once(): void
    {
        // Read once first, so the cached app payload has to be refreshed by the save.
        $this->assertNull($this->app_payload()->json('data.privacy_policy_url'));

        $this->postJson($this->settingsUrl(), ['copyright_text' => 'x', 'privacy_policy_url' => self::POLICY])
            ->assertOk()
            ->assertJsonPath('data.privacy_policy_url', self::POLICY);

        $this->assertSame(self::POLICY, $this->app_payload()->json('data.privacy_policy_url'));
        $this->assertSame(self::POLICY, $this->getJson($this->settingsUrl())->assertOk()->json('data.privacy_policy_url'), 'what the office reads back');
    }

    #[Test]
    public function an_address_the_apps_would_silently_drop_is_refused_where_the_office_can_see_why(): void
    {
        $this->masjid->forceFill(['privacy_policy_url' => self::POLICY])->save();

        $refused = [
            'http://www.meccharlotte.org/privacy',          // not https
            'www.meccharlotte.org/privacy',                 // no scheme
            '/privacy',                                     // relative
            'javascript:alert(1)',
            'https://office:secret@www.meccharlotte.org/',  // sign-in details in the address
            'https://www.meccharlotte.org/' . str_repeat('a', 240), // longer than the column
            'https://例え.テスト/privacy',                    // raw Unicode host: Android finds no host in it
            'https://mec_charlotte.org/privacy',            // an underscore is not a host name
            'https://www.meccharlotte.org:123456/privacy',  // not a port
            'https://www.meccharlotte.org/our privacy',     // a space inside the address
            'null',                                         // what a careless form sends for "nothing"
            'undefined',
        ];

        foreach ($refused as $address) {
            $errors = $this->postJson($this->settingsUrl(), ['copyright_text' => 'x', 'privacy_policy_url' => $address])
                ->assertStatus(422)
                ->json('data');

            $this->assertArrayHasKey('privacy_policy_url', $errors, $address);
        }

        $this->assertSame(self::POLICY, $this->masjid->fresh()->privacy_policy_url, 'a refused save changes nothing');
    }

    #[Test]
    public function an_address_is_stored_the_way_both_apps_open_it(): void
    {
        $accepted = [
            // typed on a phone: a capital first letter, a space after it
            ' Https://www.meccharlotte.org/privacy ' => 'https://www.meccharlotte.org/privacy',
            'HTTPS://www.MECCharlotte.org/Privacy' => 'https://www.MECCharlotte.org/Privacy',
            'https://meccharlotte.org' => 'https://meccharlotte.org',
            'https://www.meccharlotte.org:8443/privacy?lang=en#contact' => 'https://www.meccharlotte.org:8443/privacy?lang=en#contact',
            'https://xn--r8jz45g.xn--zckzah/privacy' => 'https://xn--r8jz45g.xn--zckzah/privacy',
        ];

        foreach ($accepted as $typed => $stored) {
            $this->postJson($this->settingsUrl(), ['copyright_text' => 'x', 'privacy_policy_url' => $typed])->assertOk();

            $this->assertSame($stored, $this->masjid->fresh()->privacy_policy_url, $typed);
            $this->assertSame($stored, $this->app_payload()->json('data.privacy_policy_url'), $typed);
        }
    }

    #[Test]
    public function a_save_from_a_screen_that_has_no_such_field_keeps_the_address_and_an_emptied_field_removes_it(): void
    {
        $this->masjid->forceFill(['privacy_policy_url' => self::POLICY])->save();

        // An office tab opened before the field existed sends the form without it.
        $this->postJson($this->settingsUrl(), ['copyright_text' => 'still here', 'app_store_link' => 'https://apps.apple.com/app/id1'])
            ->assertOk();

        $this->assertSame(self::POLICY, $this->masjid->fresh()->privacy_policy_url);
        $this->assertSame(self::POLICY, $this->app_payload()->json('data.privacy_policy_url'));

        // The field emptied on purpose: the form sends it empty.
        $this->postJson($this->settingsUrl(), ['copyright_text' => 'still here', 'privacy_policy_url' => ''])->assertOk();

        $this->assertNull($this->masjid->fresh()->privacy_policy_url);
        $this->assertArrayHasKey('privacy_policy_url', $this->app_payload()->json('data'));
        $this->assertNull($this->app_payload()->json('data.privacy_policy_url'));
    }

    private function settingsUrl(): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/general-settings";
    }

    private function app_payload(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/mobile/masjids/{$this->masjid->id}")->assertOk();
    }
}
