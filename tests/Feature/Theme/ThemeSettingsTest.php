<?php

namespace Tests\Feature\Theme;

use App\Models\Masjid;
use App\Models\ThemeSetting;
use App\Models\User;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Per-masjid color theme feature.
 *
 * Asserts the IDENTICAL { primary, secondary, accent, background } shape appears
 * on both surfaces and that the admin update flushes the mobile cache:
 *
 *   - web   : GET /api/v1/settings          -> data.theme
 *   - mobile: GET /api/mobile/masjids/{id}  -> data.theme
 *   - admin : POST /api/admin/masjids/{id}/theme flushes MobileCache::SHOW
 *
 * When no theme_settings row exists, both surfaces return theme = null so
 * clients fall back to their built-in defaults (zero regression).
 */
class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    // The seeded brand palette the migration writes for masjid 1.
    private const SEED = [
        'primary_color' => '#01b151',
        'secondary_color' => '#1b1b2e',
        'accent_color' => '#ffba63',
        'background_color' => '#f3f8fb',
    ];

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

    /**
     * Create masjid id 1 (the seeded/live masjid) with the brand theme so the
     * tests exercise the exact values the migration seeds. RefreshDatabase gives
     * each test a fresh empty DB, so the migration's "only if masjid 1 exists"
     * seed never runs here — we recreate that row explicitly.
     */
    protected function makeSeededMasjid(): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Seed Masjid',
            'email' => 'seed-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        ThemeSetting::create(array_merge(['masjid_id' => $masjid->id], self::SEED));

        return $masjid;
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

    protected function makeAdmin(): User
    {
        // phone is NOT NULL in users; the default factory omits it.
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    // ---------- migration ----------

    #[Test]
    public function migration_creates_theme_settings_table(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('theme_settings'),
            'theme_settings table should be created by the migration.'
        );
    }

    // ---------- web (/api/v1/settings) ----------

    #[Test]
    public function v1_settings_returns_theme_with_seeded_values(): void
    {
        $masjid = $this->makeSeededMasjid();

        $this->getJson('/api/v1/settings', ['masjid-id' => $masjid->id])
            ->assertOk()
            ->assertJsonPath('data.theme.primary', '#01b151')
            ->assertJsonPath('data.theme.secondary', '#1b1b2e')
            ->assertJsonPath('data.theme.accent', '#ffba63')
            ->assertJsonPath('data.theme.background', '#f3f8fb');
    }

    #[Test]
    public function v1_settings_returns_null_theme_when_absent(): void
    {
        $masjid = $this->makeMasjid(); // no theme row

        $this->getJson('/api/v1/settings', ['masjid-id' => $masjid->id])
            ->assertOk()
            ->assertJsonPath('data.theme', null);
    }

    // ---------- mobile (/api/mobile/masjids/{id}) ----------

    #[Test]
    public function mobile_show_returns_theme_with_seeded_values(): void
    {
        $masjid = $this->makeSeededMasjid();

        $this->getJson("/api/mobile/masjids/{$masjid->id}")
            ->assertOk()
            ->assertJsonPath('data.theme.primary', '#01b151')
            ->assertJsonPath('data.theme.secondary', '#1b1b2e')
            ->assertJsonPath('data.theme.accent', '#ffba63')
            ->assertJsonPath('data.theme.background', '#f3f8fb');
    }

    #[Test]
    public function mobile_show_returns_null_theme_when_absent(): void
    {
        $masjid = $this->makeMasjid(); // no theme row

        $this->getJson("/api/mobile/masjids/{$masjid->id}")
            ->assertOk()
            ->assertJsonPath('data.theme', null);
    }

    #[Test]
    public function web_and_mobile_theme_shapes_are_identical(): void
    {
        $masjid = $this->makeSeededMasjid();

        $web = $this->getJson('/api/v1/settings', ['masjid-id' => $masjid->id])
            ->assertOk()->json('data.theme');
        $mobile = $this->getJson("/api/mobile/masjids/{$masjid->id}")
            ->assertOk()->json('data.theme');

        $this->assertSame(
            $web,
            $mobile,
            'The theme object must be identical on the web and mobile surfaces.'
        );
    }

    // ---------- admin update + cache flush ----------

    #[Test]
    public function admin_update_persists_and_flushes_mobile_cache(): void
    {
        $masjid = $this->makeMasjid();
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        // Warm the mobile SHOW cache for this masjid.
        $this->getJson("/api/mobile/masjids/{$masjid->id}")->assertOk();
        $this->assertTrue(
            Cache::has(MobileCache::masjidKey($masjid->id, MobileCache::SHOW)),
            'Mobile SHOW cache should be warm before the admin update.'
        );

        // Admin sets a theme.
        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", [
            'primary_color' => '#01b151',
            'secondary_color' => '#1b1b2e',
            'accent_color' => '#ffba63',
            'background_color' => '#f3f8fb',
        ])->assertOk()->assertJsonPath('status', 'success');

        // Persisted.
        $this->assertDatabaseHas('theme_settings', [
            'masjid_id' => $masjid->id,
            'primary_color' => '#01b151',
            'background_color' => '#f3f8fb',
        ]);

        // Cache flushed by the update.
        $this->assertFalse(
            Cache::has(MobileCache::masjidKey($masjid->id, MobileCache::SHOW)),
            'Admin theme update must flush the mobile SHOW cache.'
        );

        // And the mobile surface now reflects the new theme.
        $this->getJson("/api/mobile/masjids/{$masjid->id}")
            ->assertOk()
            ->assertJsonPath('data.theme.primary', '#01b151');
    }

    #[Test]
    public function admin_update_rejects_unauthenticated_requests(): void
    {
        $masjid = $this->makeMasjid();

        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", [
            'primary_color' => '#01b151',
        ])->assertStatus(401);
    }

    #[Test]
    public function admin_update_rejects_invalid_hex(): void
    {
        $masjid = $this->makeMasjid();
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", [
            'primary_color' => 'not-a-color',
        ])->assertStatus(422);
    }
}
