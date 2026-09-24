<?php

namespace Tests\Feature\Studio;

use App\Models\StudioDraft;
use App\Models\ThemeSetting;
use App\Support\DesignTokens;
use App\Support\Studio\PaletteContrast;
use App\Support\WcagColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\SeedsAppFeatureCatalogue;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * POST /api/admin/studio/drafts/{draft_id}/preview: Step 2's one derivation
 * (docs/manara-studio-w1.md S4, R16, R19). The mockups must not lie, so each
 * value is checked against the code that serves the real thing, and the
 * preview must write nothing at all.
 */
class StudioPreviewTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAppFeatureCatalogue;
    use StudioDraftFixtures;

    private const BRAND = [
        'primary_color' => '#01B151',
        'secondary_color' => '#1B1B2E',
        'accent_color' => '#FFBA63',
        'background_color' => '#F3F8FB',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
        $this->seedAppFeatureCatalogue();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function masjid_defaults(): void
    {
        $data = $this->preview($this->draft(['identity' => ['org_type' => 'masjid', 'name' => 'Al-Noor Masjid']]))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('data');

        $this->assertSame(['home', 'announcements', 'contact', 'donate'], $data['app']['ios']['tabs']);
        $this->assertContains('worship', array_column($data['app']['ios']['sections'], 'key'));
        $this->assertSame(['home', 'announcements', 'contact', 'donate'], $data['app']['android']['tabs']);

        $this->assertTrue($data['tvos']['show_prayer_panel']);
        $this->assertSame('Al-Noor Masjid', $data['tvos']['header_title']);

        // No preset chosen yet, so Step 2 starts on the masjid default.
        $this->assertSame('masjid.classic', $data['web']['preset']);
        $this->assertSame('default', $data['web']['preset_source']);
        $this->assertFalse($data['web']['approved']);
        $this->assertSame(['header' => 'default', 'footer' => 'columns'], $data['web']['theme_layout']);
        $this->assertContains('prayer_times', $this->webTypes($data));
        $this->assertSame('Al-Noor Masjid', $data['web']['pages'][0]['sections'][0]['content']['title']);

        // No colours chosen: nothing is graded or painted in Burlington's green (R25).
        $this->assertNull($data['palette']);
        $this->assertNull($data['web_tokens']);
        $this->assertNull($data['platform_contrast']);
    }

    #[Test]
    public function school_has_no_worship_and_no_prayer_panel(): void
    {
        $data = $this->preview($this->draft(['identity' => ['org_type' => 'school', 'name' => 'Al-Noor Academy']]))
            ->assertOk()
            ->json('data');

        $this->assertNotContains('worship', array_column($data['app']['ios']['sections'], 'key'));
        $this->assertFalse($data['tvos']['show_prayer_panel']);
        $this->assertNotContains('prayer_times', $this->webTypes($data));
        $this->assertSame('school.essentials', $data['web']['preset']);

        // Donate is a masjid screen: neither app offers it to a school.
        $this->assertNotContains('donate', $data['app']['ios']['tabs']);
        $this->assertSame(['home', 'announcements', 'contact'], $data['app']['android']['tabs']);
    }

    #[Test]
    public function switches_change_the_tabs(): void
    {
        $id = $this->draft([
            'identity' => ['org_type' => 'masjid', 'name' => 'Al-Noor Masjid', 'donation_link' => 'https://give.example/alnoor'],
        ]);

        $before = $this->preview($id)->json('data');
        $this->assertContains('announcements', $before['app']['ios']['tabs']);
        $this->assertTrue($before['tvos']['show_qr']);

        // The overrides stand in for the saved sections, for this preview only.
        $after = $this->preview($id, [
            'features' => ['capabilities' => ['announcements' => false, 'events' => false]],
            'identity' => ['org_type' => 'masjid', 'name' => 'Al-Noor Masjid', 'donation_link' => ''],
        ])->assertOk()->json('data');

        $this->assertNotContains('announcements', $after['app']['ios']['tabs']);
        $this->assertNotContains('announcements', $after['app']['android']['tabs']);
        $this->assertNotContains('events', $this->webTypes($after));
        $this->assertFalse($after['tvos']['show_qr']);

        // Form-encoded switches (the SPA's axios default) read as booleans.
        $encoded = $this->post(self::DRAFTS . "/{$id}/preview", [
            'answers' => ['features' => ['capabilities' => ['announcements' => 'false', 'events' => 'false']]],
        ], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertNotContains('announcements', $encoded['app']['ios']['tabs']);

        // The saved draft was not touched by either.
        $this->assertSame([], StudioDraft::findOrFail($id)->section('features'));
    }

    #[Test]
    public function contrast_values_come_from_WcagColor(): void
    {
        $id = $this->draft([
            'identity' => ['org_type' => 'masjid', 'name' => 'Al-Noor Masjid'],
            'brand' => self::BRAND,
            'layout' => ['preset' => 'masjid.gathering'],
        ]);

        $data = $this->preview($id)->assertOk()->json('data');
        $rows = array_column($data['platform_contrast'], null, 'key');

        $this->assertSame(
            ['ios.home_header', 'android.home_header', 'app.menu_band', 'ios.selected_tab', 'android.selected_tab', 'web.primary_button', 'tvos.header'],
            array_keys($rows),
        );

        $ratio = WcagColor::ratio('#FFFFFF', '#01B151');
        $this->assertSame(round($ratio, 2), (float) $rows['ios.home_header']['ratio']);
        $this->assertFalse($rows['ios.home_header']['aa_normal'], 'white on this green is below 4.5:1');
        $this->assertSame(WcagColor::primaryOnSurface('#01B151'), $rows['ios.selected_tab']['foreground']);
        $this->assertSame(WcagColor::onPrimary('#01B151'), $rows['app.menu_band']['foreground']);
        $this->assertSame('#00AA55', $rows['android.selected_tab']['foreground']);
        $this->assertSame(round(WcagColor::ratio('#FFFFFF', '#0F0F0F'), 2), (float) $rows['tvos.header']['ratio']);

        // Advisory, every one (R16): only the palette's pairs block.
        $this->assertSame([false], array_values(array_unique(array_column($rows, 'blocking'))));

        $palette = PaletteContrast::report(self::BRAND);
        $this->assertEquals($palette, $data['palette']);

        $webTokens = DesignTokens::resolve(new ThemeSetting(self::BRAND + [
            'tokens' => ['color' => $palette['tokens']['color'], 'layout' => ['header' => 'overlay', 'footer' => 'columns']],
        ]))['color'];
        $this->assertSame($webTokens, $data['web_tokens']);

        // The web button and Android's header read the auto-ink the palette
        // chose, as the provisioned theme will serve it.
        $this->assertSame($webTokens['onPrimary'], $rows['web.primary_button']['foreground']);
        $this->assertSame('#111827', $webTokens['onPrimary']);
    }

    #[Test]
    public function preview_writes_nothing(): void
    {
        $id = $this->draft([
            'identity' => ['org_type' => 'school', 'name' => 'Al-Noor Academy', 'slug' => 'alnoor'],
            'brand' => self::BRAND,
            'layout' => ['preset' => 'school.prospectus', 'approved_at' => '2026-09-24T10:00:00Z'],
        ]);

        // Old enough that any write touching the timestamp would show.
        StudioDraft::whereKey($id)->update(['updated_at' => now()->subDay()]);
        $draft = StudioDraft::findOrFail($id);

        $tables = ['masjids', 'theme_settings', 'studio_drafts', 'pages', 'sections', 'page_section', 'masjid_mobile_app_features', 'masjid_domains', 'forms'];
        $counts = array_combine($tables, array_map(fn ($t) => DB::table($t)->count(), $tables));

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $data = $this->preview($id, ['features' => ['capabilities' => ['events' => false]]])->assertOk()->json('data');

        $this->assertSame([], $writes, 'the preview wrote to the database');
        $this->assertSame($counts, array_combine($tables, array_map(fn ($t) => DB::table($t)->count(), $tables)));

        $after = StudioDraft::findOrFail($id);
        $this->assertSame($draft->updated_at->toIso8601String(), $after->updated_at->toIso8601String());
        $this->assertSame($draft->lock_version, $after->lock_version);
        $this->assertSame($draft->answers, $after->answers);

        // It did compute the draft's own choice.
        $this->assertSame('school.prospectus', $data['web']['preset']);
        $this->assertSame('draft', $data['web']['preset_source']);
        $this->assertTrue($data['web']['approved']);
        $this->assertSame('alnoor.' . config('cloudflare.managed_suffix'), $data['org']['host']);
    }

    #[Test]
    public function a_section_without_a_renderer_is_flagged(): void
    {
        // No preset uses one, so a test preset does: a block of the one type
        // SectionType::withoutRenderer() lists.
        config(['studio_layouts.blocks.offering_probe' => [
            'type' => 'offering',
            'title' => ['label' => 'page.home'],
            'content' => ['offering_id' => null, 'title' => '', 'intro' => '', 'show_fee_plans' => null, 'button_text' => '', 'background_color' => ''],
        ]]);
        // Set whole: a preset key holds a dot, so config()'s dot path cannot reach into it.
        $presets = config('studio_layouts.presets');
        $presets['masjid.classic']['pages'][0]['blocks'] = ['hero', 'offering_probe'];
        config(['studio_layouts.presets' => $presets]);

        $data = $this->preview($this->draft(['identity' => ['org_type' => 'masjid', 'name' => 'Al-Noor Masjid']]))->assertOk()->json('data');

        $sections = array_column($data['web']['pages'][0]['sections'], 'has_renderer', 'slot');
        $this->assertSame(['home/hero' => true, 'home/offering_probe' => false], $sections);
    }

    #[Test]
    public function the_vibe_never_reaches_the_preview_and_the_description_does(): void
    {
        $id = $this->draft(['identity' => [
            'org_type' => 'community',
            'name' => 'Al-Noor Centre',
            'description' => 'DESCRIPTION-SENTINEL-4b2e',
            'vibe' => 'VIBE-SENTINEL-4b2e',
        ]]);

        $body = $this->preview($id)->assertOk()->getContent();

        // R12: the description is public copy, the vibe is internal.
        $this->assertStringContainsString('DESCRIPTION-SENTINEL-4b2e', $body);
        $this->assertStringNotContainsString('VIBE-SENTINEL-4b2e', $body);
    }

    #[Test]
    public function preview_answers_are_held_to_the_autosave_rules(): void
    {
        $id = $this->draft(['identity' => ['org_type' => 'masjid', 'name' => 'Al-Noor Masjid']]);

        $this->preview($id, ['platforms' => ['apps' => ['ios' => ['account_mode' => 'byo', 'asc_key_p8' => 'secret']]]])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->preview($id, ['nonsense' => ['a' => 1]])->assertStatus(422);

        $this->postJson(self::DRAFTS . '/999999/preview')->assertNotFound();
    }

    // ------------------------------------------------------------------ helpers

    /** A draft saved with these sections, through the autosave endpoint. */
    private function draft(array $answers): int
    {
        $id = $this->newDraft()['id'];
        $this->patchDraft($id, 0, $answers)->assertOk();

        return $id;
    }

    private function preview(int $id, ?array $answers = null): TestResponse
    {
        return $this->postJson(self::DRAFTS . "/{$id}/preview", $answers === null ? [] : ['answers' => $answers]);
    }

    /** @return list<string> every section type in the web plan */
    private function webTypes(array $data): array
    {
        $types = [];

        foreach ($data['web']['pages'] as $page) {
            $types = array_merge($types, array_column($page['sections'], 'section_type'));
        }

        return $types;
    }
}
