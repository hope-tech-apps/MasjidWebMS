<?php

namespace Tests\Feature\Studio;

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
    public function an_organisation_without_a_theme_previews_in_greys_until_four_colours_are_sent(): void
    {
        $org = Masjid::create([
            'name' => 'Bare Org', 'email' => 'bare@example.test', 'phone' => '+15550104001',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Bare St', 'latitude' => 0.0, 'longitude' => 0.0,
            'org_type' => 'community',
        ]);

        $bare = $this->preview($org, [])->assertOk()->json('data');
        $this->assertNull($bare['palette']);
        $this->assertNull($bare['web_tokens']);
        $this->assertSame([], $bare['web']['pages']);

        $painted = $this->preview($org, ['brand' => ['primary_color' => '#1B4D3E', 'secondary_color' => '#1B1B2E', 'accent_color' => '#7A3E00', 'background_color' => '#FFFFFF']])
            ->assertOk()->json('data');
        $this->assertNotNull($painted['web_tokens']);
        $this->assertFalse(ThemeSetting::where('masjid_id', $org->id)->exists(), 'still no theme: nothing was written');
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
    public function a_live_organisations_android_tabs_are_its_stored_rows_not_its_switches(): void
    {
        $this->seedAppFeatureCatalogue();

        $org = $this->liveOrg();
        // The switches say: Announcements off, Contact on, Donate on (the default).
        $org->forceFill(['capability_overrides' => ['announcements' => false]])->save();

        // The stored rows, which an installed Android build draws through GET /features, say the opposite
        // for Announcements and Contact.
        foreach (range(1, 11) as $featureId) {
            MasjidMobileAppFeature::create([
                'masjid_id' => $org->id, 'feature_id' => $featureId, 'is_available' => in_array($featureId, [6, 10], true),
            ]);
        }

        // Another organisation's rows must not leak in.
        $other = Masjid::create([
            'name' => 'Other Org', 'email' => 'other@example.test', 'phone' => '+15550104002',
            'country_id' => '1', 'city_id' => '1', 'address' => '2 Other St', 'latitude' => 0.0, 'longitude' => 0.0,
            'org_type' => 'masjid',
        ]);
        MasjidMobileAppFeature::create(['masjid_id' => $other->id, 'feature_id' => 11, 'is_available' => true]);

        $data = $this->preview($org, [])->assertOk()->json('data');

        $this->assertSame(['home', 'announcements', 'donate'], $data['app']['android']['tabs']);
        // The premise: the switches derive the opposite for both, so the old preview drew the wrong tabs.
        $derived = AppFeaturePivot::rowsFor($org->fresh());
        $this->assertFalse($derived[10]);
        $this->assertTrue($derived[11]);

        // A candidate switch does not move the Android frame: Save writes switches, not rows.
        $candidate = $this->preview($org, ['capabilities' => ['announcements' => '1']])->assertOk()->json('data');
        $this->assertSame(['home', 'announcements', 'donate'], $candidate['app']['android']['tabs']);

        // No rows at all: the installed app keeps the bar it shipped with (BottomBar.visibleTabs).
        $shipped = ['home', 'announcements', 'contact', 'donate'];
        $this->assertSame($shipped, $this->preview($this->orgWithoutRows(), [])->assertOk()->json('data.app.android.tabs'));
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
