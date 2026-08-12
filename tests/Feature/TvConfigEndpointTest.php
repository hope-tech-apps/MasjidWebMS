<?php

namespace Tests\Feature;

use App\Models\DonationLink;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/mobile/masjids/{id}/tv-config` — the endpoint the tvOS board has
 * always asked for and never had.
 *
 * ## What was broken
 *
 * `MasjidKit`'s `MasjidEndpoint.tvConfig` resolves to
 * `/mobile/masjids/{id}/tv-config` and decodes an OBJECT into `TVConfig`. The
 * backend shipped `/mobile/masjids/{id}/signage`, which returns an ARRAY of
 * broadcast slides — a different endpoint answering a different question. So
 * every tv-config fetch 404'd, `SignageStore.refreshTVConfig()` caught it and
 * kept `TVConfig.defaults`, and the signage channel still recorded the
 * broadcast as `sent` because publishing to a pull surface is a local state
 * change. Silent on both ends.
 *
 * ## The decoder is the contract, and it is strict
 *
 * `TVConfig` is a plain `Codable` struct. `is_enabled`, `carousel_interval_seconds`,
 * `show_prayer_panel`, `show_qr`, `donate_caption`, `announcement_selection` and
 * `theme` are NON-OPTIONAL, so a missing key, a null, or the wrong JSON type
 * throws — and the client's catch block turns that into "keep defaults", i.e.
 * the exact silence this endpoint exists to end. A `"10"` where an `Int` is
 * expected is therefore a real bug that looks like a working endpoint, which is
 * why the types are asserted here and not just the keys.
 *
 * `header_title`, `donate_url` and `announcement_ids` are Swift optionals and
 * may be null. Unknown keys are ignored by `Codable`, so the payload may grow.
 */
class TvConfigEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exactly the keys TVConfig declares CodingKeys for. Asserted as a set,
     * both directions, so neither a dropped key (client throws) nor a silent
     * rename goes unnoticed.
     */
    private const TV_CONFIG_KEYS = [
        'is_enabled',
        'header_title',
        'carousel_interval_seconds',
        'show_prayer_panel',
        'show_qr',
        'donate_url',
        'donate_caption',
        'announcement_selection',
        'announcement_ids',
        'theme',
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

        // Every assertion here is about a fresh computation; a shared key
        // between tests would make one test's masjid answer another's.
        Cache::flush();
    }

    // ------------------------------------------------------------- fixtures

    /** @param array<string, mixed> $attributes */
    private function makeMasjid(array $attributes = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid '.uniqid(),
            'email' => 'masjid-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => '35.78056000',
            'longitude' => '-78.63890000',
            'timezone' => 'America/New_York',
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function fetch(Masjid $masjid): array
    {
        return $this->getJson("/api/mobile/masjids/{$masjid->id}/tv-config")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('data');
    }

    // --------------------------------------------------- the endpoint exists

    #[Test]
    public function the_endpoint_the_tv_app_calls_now_answers_instead_of_404ing(): void
    {
        $masjid = $this->makeMasjid();

        $this->getJson("/api/mobile/masjids/{$masjid->id}/tv-config")->assertOk();
    }

    #[Test]
    public function it_returns_exactly_the_keys_the_swift_decoder_declares(): void
    {
        $config = $this->fetch($this->makeMasjid());

        // Set equality in both directions. A missing non-optional key makes the
        // client throw and fall back to defaults — silently, which is the whole
        // bug.
        $this->assertEqualsCanonicalizing(self::TV_CONFIG_KEYS, array_keys($config));
    }

    #[Test]
    public function every_non_optional_field_carries_the_json_type_the_decoder_requires(): void
    {
        $masjid = $this->makeMasjid();
        DonationLink::create(['masjid_id' => $masjid->id, 'link' => 'https://example.org/give']);

        $config = $this->fetch($masjid);

        // Bool, not 1/0 — PHP's habit of rendering booleans as integers through
        // a database round-trip is exactly how this breaks.
        $this->assertIsBool($config['is_enabled']);
        $this->assertIsBool($config['show_prayer_panel']);
        $this->assertIsBool($config['show_qr']);

        // Int, not "10". Swift's Int decoder rejects a JSON string.
        $this->assertIsInt($config['carousel_interval_seconds']);

        $this->assertIsString($config['donate_caption']);
        $this->assertIsString($config['announcement_selection']);
        $this->assertIsString($config['theme']);

        // The three Swift optionals may be null, but must be the right type
        // when they are not.
        $this->assertTrue($config['header_title'] === null || is_string($config['header_title']));
        $this->assertTrue($config['donate_url'] === null || is_string($config['donate_url']));
        $this->assertTrue($config['announcement_ids'] === null || is_array($config['announcement_ids']));
    }

    #[Test]
    public function the_documented_defaults_are_what_it_serves(): void
    {
        $config = $this->fetch($this->makeMasjid());

        $this->assertTrue($config['is_enabled']);
        $this->assertSame(10, $config['carousel_interval_seconds']);
        $this->assertSame('Scan to Donate', $config['donate_caption']);
        $this->assertSame('all_active', $config['announcement_selection']);
        $this->assertNull($config['announcement_ids']);
        // The client compares theme against "light" and treats everything else
        // as dark, so an unexpected value here is a silently-dark board.
        $this->assertContains($config['theme'], ['dark', 'light']);
        $this->assertSame('dark', $config['theme']);
    }

    #[Test]
    public function header_title_stays_null_so_the_client_falls_back_to_the_masjid_name(): void
    {
        $config = $this->fetch($this->makeMasjid(['name' => 'Burlington Masjid']));

        // `header_title` is an OVERRIDE. Null is "no override set", which is
        // what makes SignageStore.headerTitle resolve to masjid.name; echoing
        // the name here would report an override nobody configured.
        $this->assertNull($config['header_title']);
    }

    // ----------------------------------------------- values derived from data

    #[Test]
    public function the_prayer_panel_follows_the_tenant_vertical(): void
    {
        $masjid = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_MASJID]);
        $school = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_SCHOOL]);
        $community = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_COMMUNITY]);

        $this->assertTrue($this->fetch($masjid)['show_prayer_panel']);

        // config/verticals.php: "a school tenant never loads prayer/Qur'an/azkar".
        // A prayer board on a school's lobby screen is the punch-list item
        // docs/recon-2026-08-11.md records against this endpoint.
        $this->assertFalse($this->fetch($school)['show_prayer_panel']);
        $this->assertFalse($this->fetch($community)['show_prayer_panel']);
    }

    #[Test]
    public function an_unrecognised_org_type_is_treated_as_a_masjid(): void
    {
        $masjid = $this->makeMasjid();

        // `org_type` is NOT NULL, so drift arrives as an unrecognised STRING —
        // a value written past Eloquent, or one that predates a rename.
        // Masjid::orgType() already falls back to `masjid` for it, and the
        // board must not lose its prayer panel to a column nobody can read.
        DB::table('masjids')->where('id', $masjid->id)->update(['org_type' => 'mosque']);

        $this->assertTrue($this->fetch($masjid)['show_prayer_panel']);
    }

    #[Test]
    public function the_donate_url_is_resolved_from_the_masjids_own_donation_link(): void
    {
        $masjid = $this->makeMasjid();
        DonationLink::create([
            'masjid_id' => $masjid->id,
            'link' => 'https://give.example.org/burlington',
        ]);

        $config = $this->fetch($masjid);

        $this->assertSame('https://give.example.org/burlington', $config['donate_url']);
        $this->assertTrue($config['show_qr']);
    }

    #[Test]
    public function a_tenant_with_no_donation_link_gets_no_qr_instead_of_an_empty_one(): void
    {
        $config = $this->fetch($this->makeMasjid());

        $this->assertNull($config['donate_url']);
        $this->assertFalse($config['show_qr']);
    }

    #[Test]
    public function a_blank_donation_link_counts_as_no_link(): void
    {
        $masjid = $this->makeMasjid();
        DonationLink::create(['masjid_id' => $masjid->id, 'link' => '   ']);

        $config = $this->fetch($masjid);

        // An empty string would sail through the client's `!base.isEmpty`
        // guard as whitespace and render a QR code pointing nowhere.
        $this->assertNull($config['donate_url']);
        $this->assertFalse($config['show_qr']);
    }

    // ------------------------------------------------------------- isolation

    #[Test]
    public function one_tenants_config_never_carries_anothers_donation_link(): void
    {
        $a = $this->makeMasjid();
        $b = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_SCHOOL]);

        DonationLink::create(['masjid_id' => $a->id, 'link' => 'https://give.example.org/a']);
        DonationLink::create(['masjid_id' => $b->id, 'link' => 'https://give.example.org/b']);

        // routes/api.php never runs the tenant middleware, so TenantContext is
        // UNBOUND here and no global scope helps — isolation is the explicit
        // masjid_id lookup plus a per-masjid cache key. Fetching A first would
        // be the way a shared key leaked B's board.
        $this->assertSame('https://give.example.org/a', $this->fetch($a)['donate_url']);
        $this->assertSame('https://give.example.org/b', $this->fetch($b)['donate_url']);

        $this->assertTrue($this->fetch($a)['show_prayer_panel']);
        $this->assertFalse($this->fetch($b)['show_prayer_panel']);
    }

    #[Test]
    public function it_caches_under_its_own_per_masjid_key(): void
    {
        $masjid = $this->makeMasjid();

        $this->fetch($masjid);

        $this->assertNotNull(
            Cache::get(MobileCache::masjidKey((int) $masjid->id, MobileCache::TV_CONFIG))
        );

        // A SEPARATE key from the signage board: publishing a broadcast flushes
        // SIGNAGE, and that must not blow away the config (or vice versa).
        $this->assertNotSame(
            MobileCache::masjidKey((int) $masjid->id, MobileCache::TV_CONFIG),
            MobileCache::masjidKey((int) $masjid->id, MobileCache::SIGNAGE)
        );
    }

    #[Test]
    public function an_unknown_masjid_is_a_404_not_a_default_board(): void
    {
        $this->getJson('/api/mobile/masjids/999999/tv-config')->assertNotFound();
    }

    #[Test]
    public function it_is_rate_limited_like_every_other_public_mobile_read(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/mobile/masjids/{masjid_id}/tv-config'
        );

        $this->assertNotNull($route, 'The tv-config route is not registered.');
        $this->assertContains('throttle:mobile', $route->gatherMiddleware());
    }

    // ------------------------------------------------- the additive guarantee

    #[Test]
    public function the_signage_endpoint_is_untouched(): void
    {
        $masjid = $this->makeMasjid();

        // Still there, still the legacy envelope, and still an ARRAY of slides
        // rather than the object tv-config returns. tests/Feature/Broadcasts
        // covers its contents; this is only the "nothing moved" guard.
        $data = $this->getJson("/api/mobile/masjids/{$masjid->id}/signage")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('data');

        $this->assertIsArray($data);
        $this->assertSame([], $data);
    }
}
