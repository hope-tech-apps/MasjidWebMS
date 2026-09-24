<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S3 adds `slug` and `description` to `masjids`, and nothing a live site or an
 * installed app reads may change because of it.
 *
 * The key sets below were captured on production main (bb60da7f) BEFORE the
 * migration existed, for one fixture organisation, by the same requests this
 * test makes. They are the contract: a new column that reached any of these
 * payloads, or a key that went missing, fails here with the difference named.
 */
class PublicPayloadKeysUnchangedTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = [
        'activated_features', 'app_store_link', 'copyright_text', 'footer_logo_url', 'google_maps_key',
        'google_play_link', 'header_logo_url', 'iqama_settings', 'jumaa_settings', 'logo_url', 'masjid',
        'prayer_calculation', 'social_media', 'theme',
    ];

    private const SETTINGS_MASJID = [
        'address', 'city', 'country', 'email', 'id', 'latitude', 'longitude', 'name', 'phone', 'timezone',
    ];

    private const SHOW = [
        'address', 'app_store_link', 'city_id', 'copyright_text', 'country_id', 'created_at', 'donation_link',
        'email', 'google_play_link', 'header_image_url', 'id', 'latitude', 'listed_at', 'logo', 'longitude',
        'masjid_about', 'name', 'org_type', 'parent_id', 'phone', 'social_media_links', 'theme', 'timezone',
        'updated_at', 'website_link',
    ];

    private const DIRECTORY = [
        'address', 'app_store_link', 'city_id', 'copyright_text', 'country_id', 'created_at', 'email',
        'google_play_link', 'id', 'latitude', 'listed_at', 'logo', 'longitude', 'name', 'org_type', 'parent_id',
        'phone', 'timezone', 'updated_at', 'website_link',
    ];

    private function fixtureOrg(): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Payload Keys Org', 'email' => 'keys@test.local', 'phone' => '+15550001111',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            // Filled, so a leak would show its value as well as its key.
            'slug' => 'payload-keys-org',
            'description' => 'SENTINEL-DESCRIPTION',
        ]);
        $masjid->forceFill(['listed_at' => now()])->save();

        return $masjid->fresh();
    }

    private static function keys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }

    #[Test]
    public function the_website_settings_payload_keeps_its_keys(): void
    {
        $masjid = $this->fixtureOrg();

        $response = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $masjid->id])->assertOk();

        $this->assertSame(self::SETTINGS, self::keys($response->json('data')));
        $this->assertSame(self::SETTINGS_MASJID, self::keys($response->json('data.masjid')));
        $this->assertStringNotContainsString('SENTINEL-DESCRIPTION', $response->getContent());
    }

    #[Test]
    public function the_mobile_organisation_payload_keeps_its_keys(): void
    {
        $masjid = $this->fixtureOrg();

        $response = $this->getJson("/api/mobile/masjids/{$masjid->id}")->assertOk();

        $this->assertSame(self::SHOW, self::keys($response->json('data')));
        $this->assertStringNotContainsString('SENTINEL-DESCRIPTION', $response->getContent());
        $this->assertStringNotContainsString('payload-keys-org', $response->getContent());
    }

    #[Test]
    public function the_mobile_directory_keeps_its_keys(): void
    {
        $masjid = $this->fixtureOrg();

        $response = $this->getJson('/api/mobile/masjids')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $masjid->id);

        $this->assertNotNull($row, 'the listed fixture organisation is missing from the directory');
        $this->assertSame(self::DIRECTORY, self::keys($row));
        $this->assertStringNotContainsString('SENTINEL-DESCRIPTION', $response->getContent());
    }
}
