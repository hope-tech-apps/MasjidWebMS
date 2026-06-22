<?php

namespace Tests\Feature\Splash;

use App\Models\Masjid;
use App\Models\SplashAnnouncement;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mobile public read endpoint tests for /api/mobile/masjids/{id}/splash.
 *
 * Contract:
 *   - 204 No Content when no splash is active for the masjid
 *   - 200 with payload when there is one — the Nuxt composable renders the
 *     modal off this payload
 *   - Uses MobileCache::SPLASH key so admin mutations can flush it
 *   - Tied rows: highest priority wins; priority tie -> latest created_at
 */
class MobileSplashAnnouncementsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        parent::setUp();
    }

    protected function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    #[Test]
    public function returns_204_when_no_splash_is_active(): void
    {
        $masjid = $this->makeMasjid();

        // No splash rows at all.
        $this->getJson("/api/mobile/masjids/{$masjid->id}/splash")
            ->assertStatus(204);
    }

    #[Test]
    public function returns_204_when_only_inactive_splashes_exist(): void
    {
        $masjid = $this->makeMasjid();
        SplashAnnouncement::factory()->inactive()->create(['masjid_id' => $masjid->id]);

        $this->getJson("/api/mobile/masjids/{$masjid->id}/splash")
            ->assertStatus(204);
    }

    #[Test]
    public function returns_200_with_active_splash_payload(): void
    {
        $masjid = $this->makeMasjid();
        $splash = SplashAnnouncement::factory()->create([
            'masjid_id' => $masjid->id,
            'title' => 'Eid Mubarak',
        ]);

        $this->getJson("/api/mobile/masjids/{$masjid->id}/splash")
            ->assertOk()
            ->assertJsonPath('data.id', $splash->id)
            ->assertJsonPath('data.title', 'Eid Mubarak');
    }

    #[Test]
    public function returns_highest_priority_splash_when_multiple_active(): void
    {
        $masjid = $this->makeMasjid();
        SplashAnnouncement::factory()->create([
            'masjid_id' => $masjid->id,
            'title' => 'Low priority',
            'priority' => 1,
        ]);
        $high = SplashAnnouncement::factory()->create([
            'masjid_id' => $masjid->id,
            'title' => 'High priority',
            'priority' => 99,
        ]);

        $this->getJson("/api/mobile/masjids/{$masjid->id}/splash")
            ->assertOk()
            ->assertJsonPath('data.id', $high->id);
    }

    #[Test]
    public function breaks_priority_ties_by_latest_created_at(): void
    {
        $masjid = $this->makeMasjid();
        SplashAnnouncement::factory()->create([
            'masjid_id' => $masjid->id,
            'title' => 'Older',
            'priority' => 5,
            'created_at' => Carbon::now()->subDay(),
        ]);
        $newer = SplashAnnouncement::factory()->create([
            'masjid_id' => $masjid->id,
            'title' => 'Newer',
            'priority' => 5,
            'created_at' => Carbon::now(),
        ]);

        $this->getJson("/api/mobile/masjids/{$masjid->id}/splash")
            ->assertOk()
            ->assertJsonPath('data.id', $newer->id);
    }

    #[Test]
    public function caches_result_under_mobile_cache_splash_key(): void
    {
        $masjid = $this->makeMasjid();
        SplashAnnouncement::factory()->create(['masjid_id' => $masjid->id]);

        $this->getJson("/api/mobile/masjids/{$masjid->id}/splash")->assertOk();

        $this->assertTrue(
            Cache::has(MobileCache::masjidKey($masjid->id, MobileCache::SPLASH)),
            'Mobile splash endpoint should populate the MobileCache::SPLASH key.'
        );
    }
}
