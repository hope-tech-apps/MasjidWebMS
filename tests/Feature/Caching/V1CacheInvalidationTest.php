<?php

namespace Tests\Feature\Caching;

use App\Models\Announcement;
use App\Models\DonationLink;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\Page;
use App\Models\Section;
use App\Models\Service;
use App\Models\User;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Web V1 (Nuxt-facing) caching + invalidation contract.
 *
 * Two things are asserted for each V1 read endpoint:
 *   (a) the endpoint CACHES — the MobileCache key is populated after the first
 *       request, and a second request is served from cache;
 *   (b) an admin edit FLUSHES the cache — after the admin save/update/delete,
 *       the very NEXT V1 request reflects the change (no redeploy, no TTL wait).
 *
 * The cache store under test is `array` (phpunit.xml CACHE_STORE=array), which
 * supports Cache::has/forget and the version-counter scheme used for the
 * paginated V1 lists — identical invalidation semantics to the production
 * `database` driver.
 *
 * Setup mirrors the ContentUnification suite: boot the app first, then point
 * the default connection at an isolated sqlite :memory: DB and migrate it, with
 * the utf8mb4_bin collation shim the masjids migration needs under sqlite.
 */
class V1CacheInvalidationTest extends TestCase
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

        \DB::purge('sqlite');
        \DB::reconnect('sqlite');

        $pdo = \DB::connection('sqlite')->getPdo();
        if (method_exists($pdo, 'sqliteCreateCollation')) {
            $pdo->sqliteCreateCollation('utf8mb4_bin', static fn($a, $b) => strcmp($a, $b));
        }

        $this->artisan('migrate', ['--database' => 'sqlite'])->run();

        // Start each test from a clean cache so key-presence assertions are exact.
        Cache::flush();
    }

    protected function tearDown(): void
    {
        \DB::purge('sqlite');
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    /** GET a V1 endpoint with the masjid-id header the surface resolves from. */
    private function v1(Masjid $masjid, string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('masjid-id', (string) $masjid->id)->getJson($uri);
    }

    /* ================================================================== *
     |  SETTINGS  (/v1/settings)                                          |
     * ================================================================== */

    #[Test]
    public function settings_endpoint_populates_its_cache_key(): void
    {
        $masjid = $this->makeMasjid(['name' => 'Original Name']);

        $this->v1($masjid, '/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.masjid.name', 'Original Name');

        $this->assertTrue(
            Cache::has(MobileCache::masjidKey($masjid->id, MobileCache::V1_SETTINGS)),
            '/v1/settings should populate MobileCache::V1_SETTINGS.'
        );
    }

    #[Test]
    public function settings_is_served_from_cache_until_flushed(): void
    {
        $masjid = $this->makeMasjid(['name' => 'First Name']);

        // Prime the cache.
        $this->v1($masjid, '/api/v1/settings')->assertJsonPath('data.masjid.name', 'First Name');

        // Mutate the row directly (no flush) — the cached payload should win.
        $masjid->update(['name' => 'Changed Out Of Band']);
        $this->v1($masjid, '/api/v1/settings')->assertJsonPath('data.masjid.name', 'First Name');

        // Now flush as the admin controller would, and the new value appears.
        MobileCache::flushSettings($masjid->id);
        $this->v1($masjid, '/api/v1/settings')->assertJsonPath('data.masjid.name', 'Changed Out Of Band');
    }

    #[Test]
    public function admin_update_general_settings_flushes_v1_settings(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        // Prime.
        $this->v1($masjid, '/api/v1/settings')->assertJsonPath('data.copyright_text', null);

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/masjids/{$masjid->id}/general-settings", [
            'copyright_text' => 'Brand new footer',
        ])->assertOk();

        // Next V1 request must reflect the admin edit.
        $this->v1($masjid, '/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.copyright_text', 'Brand new footer');
    }

    /* ================================================================== *
     |  SERVICES  (/v1/services — versioned, paginated)                   |
     * ================================================================== */

    #[Test]
    public function services_endpoint_populates_a_versioned_cache_key(): void
    {
        $masjid = $this->makeMasjid();
        Service::create(['masjid_id' => $masjid->id, 'title' => 'Cached Service', 'description' => 'd', 'text' => 't']);

        $this->v1($masjid, '/api/v1/services')
            ->assertOk()
            ->assertJsonPath('data.items.0.title', 'Cached Service');

        $variantKey = MobileCache::masjidVariantKey($masjid->id, MobileCache::V1_SERVICES, 'pp3_p1');
        $this->assertTrue(Cache::has($variantKey), '/v1/services should populate its versioned variant key.');
    }

    #[Test]
    public function admin_create_service_flushes_v1_services_on_next_request(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();
        Service::create(['masjid_id' => $masjid->id, 'title' => 'Existing', 'description' => 'd', 'text' => 't']);

        // Prime: one service.
        $this->v1($masjid, '/api/v1/services')->assertJsonPath('data.pagination.total', 1);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/masjids/{$masjid->id}/services", [
            'title' => 'Brand New Service',
            'description' => 'desc',
            'text' => 'text',
        ])->assertOk();

        // Next request: the version bump orphans the old cached page; total is now 2.
        $this->v1($masjid, '/api/v1/services')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    #[Test]
    public function service_edit_flushes_home_latest_services_too(): void
    {
        $masjid = $this->makeMasjid();
        $service = Service::create(['masjid_id' => $masjid->id, 'title' => 'Home V1', 'description' => 'd', 'text' => 't']);

        $this->v1($masjid, '/api/v1/home')->assertJsonPath('data.services.0.title', 'Home V1');

        // Edit the model + flush exactly as the admin ServicesController::update does.
        $service->update(['title' => 'Home V2']);
        MobileCache::flushServices($masjid->id);

        $this->v1($masjid, '/api/v1/home')->assertJsonPath('data.services.0.title', 'Home V2');
    }

    /* ================================================================== *
     |  ANNOUNCEMENTS  (/v1/announcements — versioned, paginated)         |
     * ================================================================== */

    #[Test]
    public function admin_create_announcement_flushes_v1_announcements(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();
        Announcement::create([
            'masjid_id' => $masjid->id, 'title' => 'A1', 'details' => 'd', 'text' => 't',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]);

        $this->v1($masjid, '/api/v1/announcements')->assertJsonPath('data.pagination.total', 1);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/masjids/{$masjid->id}/announcements", [
            'title' => 'A2', 'details' => 'd2', 'text' => 't2',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertOk();

        $this->v1($masjid, '/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    /* ================================================================== *
     |  GALLERY, HOME-about, PAGES                                        |
     * ================================================================== */

    #[Test]
    public function home_about_block_reflects_masjid_about_edit_after_flush(): void
    {
        $masjid = $this->makeMasjid();
        $about = MasjidAbout::create([
            'masjid_id' => $masjid->id,
            'about' => 'About v1',
            'mission' => 'Mission v1',
            'vision' => 'Vision v1',
        ]);

        $this->v1($masjid, '/api/v1/home')->assertJsonPath('data.sections.about_us.text', 'About v1');

        // Edit + flush as admin MasjidAboutUsController::save does.
        $about->update(['about' => 'About v2']);
        MobileCache::flushAbout($masjid->id);

        $this->v1($masjid, '/api/v1/home')->assertJsonPath('data.sections.about_us.text', 'About v2');
    }

    #[Test]
    public function admin_about_save_flushes_home_and_pages(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();
        MasjidAbout::create(['masjid_id' => $masjid->id, 'about' => 'Before', 'mission' => 'm', 'vision' => 'v']);

        $this->v1($masjid, '/api/v1/home')->assertJsonPath('data.sections.about_us.text', 'Before');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/masjids/{$masjid->id}/about", [
            'about' => 'After', 'mission' => 'm', 'vision' => 'v',
        ])->assertOk();

        $this->v1($masjid, '/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.sections.about_us.text', 'After');
    }

    #[Test]
    public function pages_show_is_cached_and_flushed_by_about_edit_via_binder(): void
    {
        // about_us section binds to MasjidAbout at read time (SectionContentBinder),
        // so editing About must invalidate the cached page.
        $masjid = $this->makeMasjid();
        $about = MasjidAbout::create(['masjid_id' => $masjid->id, 'about' => 'Bound v1', 'mission' => 'm', 'vision' => 'v']);

        $page = Page::create([
            'masjid_id' => $masjid->id, 'slug' => 'home-page', 'title' => 'Home',
            'is_active' => true, 'order' => 1,
        ]);
        $section = Section::create([
            'masjid_id' => $masjid->id, 'section_type' => 'about_us',
            'title' => 'About', 'content' => ['title' => 'About', 'text' => 'stored', 'button_text' => ''],
            'is_active' => true,
        ]);
        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        // Prime the per-slug cache.
        $this->v1($masjid, "/api/v1/pages/{$page->slug}")
            ->assertOk()
            ->assertJsonPath('data.sections.0.content.text', 'Bound v1');

        $this->assertTrue(
            Cache::has(MobileCache::masjidVariantKey($masjid->id, MobileCache::V1_PAGE_SHOW, md5($page->slug))),
            '/v1/pages/{slug} should populate its versioned per-slug key.'
        );

        // Edit About + flush as the admin About controller does.
        $about->update(['about' => 'Bound v2']);
        MobileCache::flushAbout($masjid->id);

        $this->v1($masjid, "/api/v1/pages/{$page->slug}")
            ->assertOk()
            ->assertJsonPath('data.sections.0.content.text', 'Bound v2');
    }

    #[Test]
    public function admin_page_create_flushes_pages_list(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();
        Page::create([
            'masjid_id' => $masjid->id, 'slug' => 'p1', 'title' => 'P1',
            'is_active' => true, 'order' => 1,
        ]);

        $this->v1($masjid, '/api/v1/pages')->assertJsonCount(1, 'data');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/masjids/{$masjid->id}/pages", [
            'slug' => 'p2', 'title' => 'P2', 'is_active' => true, 'order' => 2,
        ])->assertStatus(201);

        $this->v1($masjid, '/api/v1/pages')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function donation_edit_flushes_cached_donation_page_section(): void
    {
        // donation section binds to DonationLink at read time, so a donation edit
        // must invalidate the cached page (via flushDonation -> flushPages).
        $masjid = $this->makeMasjid();
        $donation = DonationLink::create([
            'masjid_id' => $masjid->id, 'link' => 'https://give.test/1',
            'title' => 'Give v1', 'message' => 'msg v1',
        ]);

        $page = Page::create([
            'masjid_id' => $masjid->id, 'slug' => 'donate', 'title' => 'Donate',
            'is_active' => true, 'order' => 1,
        ]);
        $section = Section::create([
            'masjid_id' => $masjid->id, 'section_type' => 'donation',
            'title' => 'Donate', 'content' => ['title' => 'stored', 'subtitle' => 'stored', 'button_text' => 'Give'],
            'is_active' => true,
        ]);
        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        $this->v1($masjid, "/api/v1/pages/{$page->slug}")
            ->assertOk()
            ->assertJsonPath('data.sections.0.content.title', 'Give v1');

        $donation->update(['title' => 'Give v2']);
        MobileCache::flushDonation($masjid->id);

        $this->v1($masjid, "/api/v1/pages/{$page->slug}")
            ->assertOk()
            ->assertJsonPath('data.sections.0.content.title', 'Give v2');
    }

    /* ================================================================== *
     |  Version-counter mechanics (unit-ish, through the helper)          |
     * ================================================================== */

    #[Test]
    public function bumping_a_resource_version_orphans_old_variant_keys(): void
    {
        $masjid = $this->makeMasjid();

        $keyBefore = MobileCache::masjidVariantKey($masjid->id, MobileCache::V1_SERVICES, 'pp3_p1');
        Cache::put($keyBefore, ['stale'], 600);
        $this->assertTrue(Cache::has($keyBefore));

        MobileCache::bumpVersion($masjid->id, MobileCache::V1_SERVICES);

        // The freshly-computed key now embeds v2, so the stale v1 key is unreachable.
        $keyAfter = MobileCache::masjidVariantKey($masjid->id, MobileCache::V1_SERVICES, 'pp3_p1');
        $this->assertNotSame($keyBefore, $keyAfter);
        $this->assertFalse(Cache::has($keyAfter));
    }
}
