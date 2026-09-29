<?php

namespace Tests\Feature\Studio;

use App\Models\AppMenuSetting;
use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidMobileAppFeature;
use App\Models\Page;
use App\Models\Section;
use App\Models\ThemeSetting;
use App\Support\AppFeaturePivot;
use App\Support\AppMenu;
use App\Support\DesignTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\SeedsAppFeatureCatalogue;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * POST /api/admin/studio/organisations/{id}/preview (Studio W2 S9): W1's
 * preview shape for an organisation that already exists, with the colours and
 * switches being considered applied to an in-memory copy, and nothing written.
 */
class StudioOrganisationPreviewTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAppFeatureCatalogue;
    use StudioDraftFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
        $this->actAsSuperAdmin();
    }

    private function liveOrg(): Masjid
    {
        $org = Masjid::create([
            'name' => 'Preview Masjid', 'email' => 'preview@example.test', 'phone' => '+15550104000',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Preview St', 'latitude' => 0.0, 'longitude' => 0.0,
            'org_type' => 'masjid', 'crm_enabled' => false,
        ]);
        $org->forceFill(['capability_overrides' => ['web_pages' => false]])->save();

        ThemeSetting::create([
            'masjid_id' => $org->id, 'primary_color' => '#01B151', 'secondary_color' => '#1B1B2E',
            'accent_color' => '#FFBA63', 'background_color' => '#F3F8FB',
            'tokens' => ['layout' => ['header' => 'overlay', 'footer' => 'columns']],
        ]);

        $page = Page::create(['masjid_id' => $org->id, 'slug' => 'home', 'title' => 'Home', 'is_active' => true, 'order' => 1, 'show_in_menu' => true]);
        $section = Section::create(['masjid_id' => $org->id, 'section_type' => 'text', 'title' => 'Welcome', 'content' => ['body' => 'Our words'], 'is_active' => true]);
        $page->sections()->attach($section->id, ['order' => 1]);

        return $org->fresh();
    }

    private function preview(Masjid $org, array $body)
    {
        return $this->post("/api/admin/studio/organisations/{$org->id}/preview", $body, ['Accept' => 'application/json']);
    }

    /** Every table a preview could touch, so "nothing written" is checked, not assumed. */
    private function footprint(Masjid $org): array
    {
        return [
            'masjid' => (array) DB::table('masjids')->where('id', $org->id)->first(),
            'theme' => (array) DB::table('theme_settings')->where('masjid_id', $org->id)->first(),
            'ledger' => MasjidCapabilityChange::count(),
            'pivot' => DB::table('masjid_mobile_app_features')->count(),
            'pages' => DB::table('pages')->count(),
            'sections' => DB::table('sections')->count(),
        ];
    }

    #[Test]
    public function overrides_reach_the_mockups_and_nothing_is_written(): void
    {
        $org = $this->liveOrg();
        $before = $this->footprint($org);
        $brand = ['primary_color' => '#1B4D3E', 'secondary_color' => '#1B1B2E', 'accent_color' => '#7A3E00', 'background_color' => '#FFFFFF'];

        $plain = $this->preview($org, [])->assertOk()->json('data');

        DB::flushQueryLog();
        DB::enableQueryLog();

        // Form-encoded, as the SPA sends it.
        $data = $this->preview($org, [
            'brand' => $brand,
            'capabilities' => ['events' => '0', 'announcements' => 'false'],
        ])->assertOk()->json('data');

        $writes = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $sql) === 1)
            ->values()->all();
        DB::disableQueryLog();

        $this->assertSame([], $writes, 'a preview writes nothing');
        $this->assertSame($before, $this->footprint($org));

        // The same shape as a draft's preview.
        $this->assertSame(['org', 'platforms', 'palette', 'web_tokens', 'platform_contrast', 'app', 'web', 'tvos'], array_keys($data));

        // The switches reached the app mockups: the live org answers as it would with them saved.
        $switched = clone $org;
        $switched->forceFill(['capability_overrides' => ['web_pages' => false, 'events' => false, 'announcements' => false]]);
        $this->assertSame(AppMenu::tabs($switched), $data['app']['ios']['tabs']);
        $this->assertSame(AppMenu::tabs($org), $plain['app']['ios']['tabs']);
        $this->assertNotSame($plain['app']['ios']['tabs'], $data['app']['ios']['tabs'], 'the premise: switching events and news off changes the tab bar');

        // The colours reached the web: the stored tokens with the new colours, as the renderer will paint them.
        $expected = DesignTokens::resolve(new ThemeSetting($brand + ['tokens' => ['layout' => ['header' => 'overlay', 'footer' => 'columns']]]))['color'];
        $this->assertSame($expected, $data['web_tokens']);
        $this->assertSame('#1B4D3E', $data['platform_contrast'][0]['background']);
        $this->assertNotNull($data['palette']);

        // The web mockup draws the organisation's own pages, not a starter plan.
        $this->assertSame('live', $data['web']['preset_source']);
        $this->assertSame(['home'], array_column($data['web']['pages'], 'slug'));
        $this->assertSame('Our words', $data['web']['pages'][0]['sections'][0]['content']['body']);
        $this->assertSame(['header' => 'overlay', 'footer' => 'columns'], $data['web']['theme_layout']);
        $this->assertSame('Preview Masjid', $data['org']['name']);
    }

    #[Test]
    public function a_switch_studio_could_not_save_cannot_be_previewed(): void
    {
        $org = $this->liveOrg();
        $before = $this->footprint($org);

        $this->preview($org, ['capabilities' => ['crm' => '1']])
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => ['This capability has its own switch on this screen.']]]);
        $this->preview($org, ['capabilities' => ['giving.defaults' => '1']])->assertStatus(422);
        $this->preview($org, ['capabilities' => ['events' => 'maybe']])->assertStatus(422)->assertJsonPath('status', 'failed');
        // Null or empty is refused, never previewed as off.
        $this->preview($org, ['capabilities' => ['events' => '']])->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->postJson("/api/admin/studio/organisations/{$org->id}/preview", ['capabilities' => ['events' => null]])->assertStatus(422);
        $this->preview($org, ['brand' => ['primary_color' => 'green']])->assertStatus(422);

        $this->assertSame($before, $this->footprint($org));
    }

    #[Test]
    public function an_organisation_without_a_theme_previews_the_palette_the_site_draws_with_none(): void
    {
        $org = Masjid::create([
            'name' => 'Bare Org', 'email' => 'bare@example.test', 'phone' => '+15550104001',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Bare St', 'latitude' => 0.0, 'longitude' => 0.0,
            'org_type' => 'community',
        ]);

        // No theme row: the settings carry `theme: null`, so the renderer publishes no variables and the
        // stylesheet's :root defaults show (burlington-masjid-site index.css). Never greys.
        $bare = $this->preview($org, [])->assertOk()->json('data');
        $this->assertNotNull($bare['palette']);
        $this->assertSame(
            ['primary' => '#01B151', 'secondary' => '#1B1B2E', 'accent' => '#FFBA63', 'background' => '#F3F8FB'],
            array_intersect_key($bare['web_tokens'], array_flip(['primary', 'secondary', 'accent', 'background'])),
        );
        $this->assertSame([], $bare['web']['pages']);

        $painted = $this->preview($org, ['brand' => ['primary_color' => '#1B4D3E', 'secondary_color' => '#1B1B2E', 'accent_color' => '#7A3E00', 'background_color' => '#FFFFFF']])
            ->assertOk()->json('data');
        $this->assertSame('#1B4D3E', $painted['web_tokens']['primary']);
        $this->assertFalse(ThemeSetting::where('masjid_id', $org->id)->exists(), 'still no theme: nothing was written');
    }

    #[Test]
    public function a_live_organisation_with_a_missing_or_invalid_colour_previews_what_the_site_draws_now(): void
    {
        $org = $this->liveOrg();
        // A row that lacks its secondary and holds a value the theme save would refuse for the accent.
        ThemeSetting::where('masjid_id', $org->id)->update(['secondary_color' => null, 'accent_color' => 'green']);

        $data = $this->preview($org, [])->assertOk()->json('data');

        // What the renderer is served for this row today (ThemeSettingResource -> resolvedTokens), filled by
        // DesignTokens::DEFAULTS: not greys, and not another colour of our own.
        $served = DesignTokens::resolve(ThemeSetting::where('masjid_id', $org->id)->first())['color'];
        $this->assertSame($served, $data['web_tokens']);
        $this->assertSame(DesignTokens::DEFAULTS['secondary'], $data['web_tokens']['secondary']);
        $this->assertSame(DesignTokens::DEFAULTS['accent'], $data['web_tokens']['accent']);
        $this->assertSame('#01B151', $data['web_tokens']['primary'], 'the stored colours stay as stored');
        $this->assertNotNull($data['palette'], 'a live organisation always has a palette to check');
        $this->assertSame('#01B151', $data['platform_contrast'][0]['background']);

        // A colour being considered still wins over the fill.
        $candidate = $this->preview($org, ['brand' => [
            'primary_color' => '#1B4D3E', 'secondary_color' => '#1B1B2E', 'accent_color' => '#7A3E00', 'background_color' => '#FFFFFF',
        ]])->assertOk()->json('data');
        $this->assertSame('#1B1B2E', $candidate['web_tokens']['secondary']);
        $this->assertSame('#7A3E00', $candidate['web_tokens']['accent']);
    }

    #[Test]
    public function the_web_tab_follows_the_website_switch_being_considered_not_the_saved_one(): void
    {
        // No publishing row, so the Web tab comes from the Website switch alone.
        $org = $this->liveOrg();
        $org->forceFill(['capability_overrides' => ['website' => false]])->save();

        $this->assertNotContains('web', $this->preview($org, [])->assertOk()->json('data.platforms'));
        $this->assertContains('web', $this->preview($org, ['capabilities' => ['website' => '1']])->assertOk()->json('data.platforms'),
            'switching Website on in the candidate adds the Web tab, as Save would');

        $org->forceFill(['capability_overrides' => ['website' => true]])->save();

        $this->assertContains('web', $this->preview($org, [])->assertOk()->json('data.platforms'));
        $this->assertNotContains('web', $this->preview($org, ['capabilities' => ['website' => '0']])->assertOk()->json('data.platforms'),
            'switching Website off in the candidate drops it');
    }

    #[Test]
    public function a_theme_stored_as_rgb_or_rgba_still_previews_and_can_be_sent_back_as_stored(): void
    {
        $org = $this->liveOrg();
        ThemeSetting::where('masjid_id', $org->id)->update(['primary_color' => '#FA0', 'accent_color' => '#0a3d62ff']);
        $before = $this->footprint($org);

        $data = $this->preview($org, [])->assertOk()->json('data');

        $this->assertNotNull($data['palette'], 'a stored #RGB or #RRGGBBAA does not blank the contrast report');
        $this->assertNotNull($data['web_tokens']);
        // Normalised for display only: 3 digits expanded, the alpha pair dropped.
        $this->assertSame('#FFAA00', $data['platform_contrast'][0]['background']);

        // The SPA sends every colour, the untouched ones exactly as stored; the request takes those forms.
        $sent = $this->preview($org, ['brand' => [
            'primary_color' => '#FA0', 'secondary_color' => '#1B1B2E', 'accent_color' => '#0a3d62ff', 'background_color' => '#F3F8FB',
        ]])->assertOk()->json('data');
        $this->assertSame($data['palette'], $sent['palette']);
        $this->assertSame($data['web_tokens'], $sent['web_tokens']);

        $this->preview($org, ['brand' => ['primary_color' => '#FA', 'secondary_color' => '#1B1B2E', 'accent_color' => '#0a3d62ff', 'background_color' => '#F3F8FB']])
            ->assertStatus(422);

        $this->assertSame($before, $this->footprint($org), 'the stored forms are untouched');
    }

    #[Test]
    public function a_live_organisations_android_frame_is_the_production_bar_whatever_its_switches_or_rows(): void
    {
        $this->seedAppFeatureCatalogue();

        $org = $this->liveOrg();
        // The switches say: Announcements off, Contact on, Donate on (the default).
        $org->forceFill(['capability_overrides' => ['announcements' => false]])->save();

        // The stored rows say the opposite for Announcements and Contact.
        foreach (range(1, 11) as $featureId) {
            MasjidMobileAppFeature::create([
                'masjid_id' => $org->id, 'feature_id' => $featureId, 'is_available' => in_array($featureId, [6, 10], true),
            ]);
        }

        // The Play production build is versionCode 13, whose BottomBar lists the four tabs without reading
        // /features (burlington-masjid-Android 8579eee, BottomBar.kt:28-33): neither source moves the bar.
        $shipped = ['home', 'announcements', 'contact', 'donate'];
        $data = $this->preview($org, [])->assertOk()->json('data');
        $this->assertSame($shipped, $data['app']['android']['tabs']);

        // The premise: the switches and the rows each say something else, so a bar derived from either would differ.
        $derived = AppFeaturePivot::rowsFor($org->fresh());
        $this->assertFalse($derived[10]);
        $this->assertTrue($derived[11]);

        $candidate = $this->preview($org, ['capabilities' => ['events' => '0', 'announcements' => '0']])->assertOk()->json('data');
        $this->assertSame($shipped, $candidate['app']['android']['tabs']);

        $this->assertSame($shipped, $this->preview($this->orgWithoutRows(), [])->assertOk()->json('data.app.android.tabs'));
    }

    #[Test]
    public function the_ios_frame_draws_what_the_phone_builds_from_features_while_the_menu_is_killed(): void
    {
        $this->seedAppFeatureCatalogue();

        $org = $this->liveOrg();
        // Stored rows: Qur'an, Services, Donate and Announcements on; Contact and the rest off.
        foreach (range(1, 11) as $featureId) {
            MasjidMobileAppFeature::create([
                'masjid_id' => $org->id, 'feature_id' => $featureId, 'is_available' => in_array($featureId, [1, 6, 9, 10], true),
            ]);
        }

        $live = $this->preview($org, [])->assertOk()->json('data.app.ios');
        $this->assertSame(AppMenu::tabs($org->fresh()), $live['tabs']);
        $this->assertContains('contact', $live['tabs'], 'the premise: the switches say Contact is on, the stored rows say off');
        $this->assertArrayNotHasKey('source', $live);

        AppMenuSetting::create(['menu_disabled' => true, 'reason' => 'test', 'updated_by' => 'test']);

        $killed = $this->preview($org, [])->assertOk()->json('data.app.ios');

        // LegacyMenuAdapter: Home, then the tab entries whose row is available, in bar order.
        $this->assertSame(['home', 'announcements', 'donate'], $killed['tabs']);
        $this->assertSame('features', $killed['source']);
        $this->assertSame([
            ['key' => 'main', 'items' => [
                ['key' => 'home', 'legacy_feature_id' => null],
                ['key' => 'announcements', 'legacy_feature_id' => 10],
                ['key' => 'services', 'legacy_feature_id' => 9],
                ['key' => 'donate', 'legacy_feature_id' => 6],
            ]],
            ['key' => 'worship', 'items' => [['key' => 'quran', 'legacy_feature_id' => 1]]],
        ], $killed['sections'], 'no About section: none of its rows is on, and the fallback carries no parts');

        // A candidate switch does not move it: the phone reads rows, and Save writes switches.
        $candidate = $this->preview($org, ['capabilities' => ['announcements' => '0']])->assertOk()->json('data.app.ios');
        $this->assertSame(['home', 'announcements', 'donate'], $candidate['tabs']);

        // Restored: the switches again, and the preview wrote nothing to get there.
        AppMenuSetting::query()->update(['menu_disabled' => false]);
        $restored = $this->preview($org, [])->assertOk()->json('data.app.ios');
        $this->assertSame($live['tabs'], $restored['tabs']);
        $this->assertArrayNotHasKey('source', $restored);

        // No available row at all, killed: Home alone (the fallback has no shipped bar).
        AppMenuSetting::query()->update(['menu_disabled' => true]);
        $rowless = $this->preview($this->orgWithoutRows(), [])->assertOk()->json('data.app.ios');
        $this->assertSame(['home'], $rowless['tabs']);
        $this->assertSame([['key' => 'main', 'items' => [['key' => 'home', 'legacy_feature_id' => null]]]], $rowless['sections']);
    }

    #[Test]
    public function the_web_frame_is_told_the_active_home_page_and_never_an_inactive_one(): void
    {
        $org = $this->liveOrg();
        // Listed first (order 0) but switched off: the site does not serve it.
        Page::create(['masjid_id' => $org->id, 'slug' => 'promo', 'title' => 'Promo', 'is_active' => false, 'order' => 0, 'show_in_menu' => true]);

        $data = $this->preview($org, [])->assertOk()->json('data.web');
        $this->assertSame(['promo', 'home'], array_column($data['pages'], 'slug'), 'the premise: an inactive page leads the list');
        $this->assertSame('home', $data['home_slug']);

        // The renderer draws the page whose slug is `home`, and only an active one is served.
        Page::where('masjid_id', $org->id)->where('slug', 'home')->update(['is_active' => false]);
        $this->assertNull($this->preview($org, [])->assertOk()->json('data.web.home_slug'));

        // Another active page does not stand in for it.
        Page::where('masjid_id', $org->id)->where('slug', 'promo')->update(['is_active' => true]);
        $this->assertNull($this->preview($org, [])->assertOk()->json('data.web.home_slug'));
    }

    #[Test]
    public function a_live_organisation_with_every_row_unavailable_draws_the_shipped_android_bar(): void
    {
        $this->seedAppFeatureCatalogue();

        $org = $this->liveOrg();
        $org->forceFill(['capability_overrides' => ['announcements' => false]])->save();
        foreach (range(1, 11) as $featureId) {
            MasjidMobileAppFeature::create(['masjid_id' => $org->id, 'feature_id' => $featureId, 'is_available' => false]);
        }

        $this->assertSame(
            ['home', 'announcements', 'contact', 'donate'],
            $this->preview($org, [])->assertOk()->json('data.app.android.tabs'),
        );
    }

    private function orgWithoutRows(): Masjid
    {
        return Masjid::create([
            'name' => 'Rowless Org', 'email' => 'rowless@example.test', 'phone' => '+15550104003',
            'country_id' => '1', 'city_id' => '1', 'address' => '3 Rowless St', 'latitude' => 0.0, 'longitude' => 0.0,
            'org_type' => 'masjid',
        ]);
    }
}
