<?php

namespace Tests\Feature;

use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The website's settings serve the named tenant's Google Maps key, never a
 * neighbour's.
 *
 * `/api/v1/settings` returns `google_maps_key` to anonymous callers ON PURPOSE:
 * the renderer (burlington-masjid-site, app/utils/mapEmbed.ts) draws a tenant's
 * styled Maps JavaScript API map with it, and removing it would switch
 * Burlington's live map to the keyless embed. The tenant is whatever
 * `masjid-id` the caller sends and ids are public, so any caller can read any
 * tenant's key, including one whose website is off. The key's Google Cloud
 * restriction (HTTP referrers + Maps JavaScript API only), an owner action
 * outside the code, is the only protection.
 *
 * What this file pins is narrower: the header selects the row, so each tenant
 * id gets that tenant's key and never another's. It does not show that a
 * caller reaches only its own tenant, because the endpoint does not enforce
 * that. The other half of the contract, that the anonymous mobile directory
 * never publishes the key, is pinned by PublicMasjidDirectoryTest (`the_directory_never_publishes_a_credential_or_a_money_identifier`,
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
    public function the_website_settings_serve_the_named_tenants_maps_key(): void
    {
        $burlington = $this->makeMasjid('AIzaSyBURLINGTONKEY');

        $this->getJson('/api/v1/settings', ['masjid-id' => $burlington->id])
            ->assertOk()
            ->assertJsonPath('data.google_maps_key', 'AIzaSyBURLINGTONKEY');
    }

    #[Test]
    public function the_website_settings_never_serve_a_neighbours_maps_key(): void
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
