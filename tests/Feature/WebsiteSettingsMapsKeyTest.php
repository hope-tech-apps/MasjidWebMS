<?php

namespace Tests\Feature;

use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The website's settings serve the tenant's Google Maps key, and only its own.
 *
 * `/api/v1/settings` returns `google_maps_key` to anonymous callers ON PURPOSE:
 * the renderer (burlington-masjid-site, app/utils/mapEmbed.ts) draws a tenant's
 * styled Maps JavaScript API map with it, so the key is in every visitor's
 * browser whatever this endpoint does. Its protection is the key's Google Cloud
 * restriction (HTTP referrers + API restriction), an owner action outside the
 * code. Removing it from this payload would switch Burlington's live map to the
 * keyless embed and protect nothing.
 *
 * What this file pins is the part the code DOES own: a tenant's site gets that
 * tenant's key, never another's. The other half of the contract, that the
 * anonymous mobile directory never publishes the key, is pinned by
 * PublicMasjidDirectoryTest (`the_directory_never_publishes_a_credential_or_a_money_identifier`,
 * `the_single_organisation_endpoint_applies_the_same_rule`), not repeated here.
 */
class WebsiteSettingsMapsKeyTest extends TestCase
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
    }

    #[Test]
    public function the_website_settings_serve_the_requesting_tenants_own_maps_key(): void
    {
        $burlington = $this->makeMasjid('AIzaSyBURLINGTONKEY');

        $this->getJson('/api/v1/settings', ['masjid-id' => $burlington->id])
            ->assertOk()
            ->assertJsonPath('data.google_maps_key', 'AIzaSyBURLINGTONKEY');
    }

    #[Test]
    public function the_website_settings_never_serve_another_tenants_maps_key(): void
    {
        // Burlington exists and holds a key, so a lookup that ignored the
        // `masjid-id` header would have one to leak.
        $this->makeMasjid('AIzaSyBURLINGTONKEY');
        $other = $this->makeMasjid('AIzaSyOTHERTENANTKEY');
        $keyless = $this->makeMasjid(null);

        $otherBody = $this->getJson('/api/v1/settings', ['masjid-id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.google_maps_key', 'AIzaSyOTHERTENANTKEY')
            ->getContent();
        $this->assertStringNotContainsString('AIzaSyBURLINGTONKEY', $otherBody);

        // A tenant without a key gets none, not a neighbour's: the renderer
        // reads null as "use the keyless embed".
        $keylessBody = $this->getJson('/api/v1/settings', ['masjid-id' => $keyless->id])
            ->assertOk()
            ->assertJsonPath('data.google_maps_key', null)
            ->getContent();
        $this->assertStringNotContainsString('AIzaSyBURLINGTONKEY', $keylessBody);
        $this->assertStringNotContainsString('AIzaSyOTHERTENANTKEY', $keylessBody);
    }

    private function makeMasjid(?string $mapsKey): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Settings Masjid '.uniqid(),
            'email' => 'settings-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $masjid->forceFill(['google_maps_key' => $mapsKey])->save();

        return $masjid;
    }
}
