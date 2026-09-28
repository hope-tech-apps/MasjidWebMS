<?php

namespace Tests\Feature\Studio;

use App\Jobs\PurgeRendererCache;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\ThemeSetting;
use App\Support\MobileCache;
use App\Support\Studio\LayoutPresets;
use App\Support\Studio\StarterFacts;
use App\Support\Studio\StarterSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `studio:apply-layout` (Studio W2 S11): an existing organisation gets a
 * starter site. Dry run by default, never overwrites, the theme layout only
 * when asked, and a renderer purge after it writes.
 */
class StudioApplyLayoutCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PRESET = 'masjid.classic';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    private function org(string $orgType = 'masjid', array $tokens = ['layout' => ['header' => 'custom', 'footer' => 'custom']]): Masjid
    {
        $org = Masjid::create([
            'name' => 'Layout Org ' . uniqid(),
            'email' => 'layout' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => $orgType,
        ]);

        ThemeSetting::create([
            'masjid_id' => $org->id,
            'primary_color' => '#01B151', 'secondary_color' => '#222222',
            'accent_color' => '#F5A623', 'background_color' => '#FFFFFF',
            'tokens' => $tokens,
        ]);

        return $org->fresh();
    }

    /** @return array{0: int, 1: string} */
    private function run_(Masjid|int $org, string $preset = self::PRESET, array $options = []): array
    {
        $code = Artisan::call('studio:apply-layout', ['masjid_id' => $org instanceof Masjid ? $org->id : $org, 'preset' => $preset] + $options);

        return [$code, Artisan::output()];
    }

    /** Every row a starter site writes, and the theme, for "nothing changed". */
    private function footprint(Masjid $org): array
    {
        return [
            'pages' => DB::table('pages')->where('masjid_id', $org->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'sections' => DB::table('sections')->where('masjid_id', $org->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'page_section' => DB::table('page_section')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'theme' => DB::table('theme_settings')->where('masjid_id', $org->id)->value('tokens'),
        ];
    }

    /** @return list<string> the preset's page slugs, in order */
    private function slugs(string $preset = self::PRESET): array
    {
        return array_column(LayoutPresets::pagesOf(LayoutPresets::find($preset)), 'slug');
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $org = $this->org();
        $before = $this->footprint($org);

        [$code, $out] = $this->run_($org);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('DRY RUN, nothing is written', $out);
        $this->assertStringContainsString('Would create ' . count($this->slugs()) . ' page(s): ' . implode(', ', $this->slugs()), $out);
        $this->assertStringContainsString('Theme layout: unchanged', $out);
        $this->assertSame($before, $this->footprint($org));
    }

    #[Test]
    public function the_dry_run_reports_exactly_what_execute_writes(): void
    {
        $org = $this->org();
        Page::create(['masjid_id' => $org->id, 'slug' => $this->slugs()[1], 'title' => 'Ours', 'is_active' => true, 'order' => 1]);

        $before = Page::withTrashed()->where('masjid_id', $org->id)->pluck('id')->all();

        $facts = StarterFacts::fromMasjid($org);
        $held = StarterSite::heldPages($org);
        $dry = StarterSite::outcome(StarterSite::plan($org, self::PRESET, $facts, $held), $held);

        $this->assertSame($dry, StarterSite::applyTo($org, self::PRESET, $facts));
        $this->assertNotSame([], $dry['created']);
        $this->assertSame([$this->slugs()[1]], $dry['skipped']);

        // What the dry run reported, against the rows the write inserted.
        $written = Page::withTrashed()->where('masjid_id', $org->id)->whereNotIn('id', $before)->get();
        $this->assertEqualsCanonicalizing($dry['created'], $written->pluck('slug')->all());

        $sectionIds = DB::table('page_section')->whereIn('page_id', $written->pluck('id'))->pluck('section_id');
        $rows = DB::table('sections')->whereIn('id', $sectionIds)->get();
        $this->assertSame($dry['sections_active'], $rows->where('is_active', 1)->count());
        $this->assertSame(count($dry['sections_inactive']), $rows->where('is_active', 0)->count());
    }

    /** The first section of a written page, raw from its row. */
    private function firstSectionOf(Masjid $org, string $slug): object
    {
        $page = Page::where('masjid_id', $org->id)->where('slug', $slug)->firstOrFail();
        $sectionId = DB::table('page_section')->where('page_id', $page->id)->orderBy('order')->value('section_id');

        return DB::table('sections')->where('id', $sectionId)->first();
    }

    #[Test]
    public function a_banner_loses_its_button_to_a_held_page_the_site_does_not_serve(): void
    {
        $preset = 'masjid.essentials';

        // A live held page: the button stays and nothing is reported.
        $live = $this->org();
        Page::create(['masjid_id' => $live->id, 'slug' => 'contact', 'title' => 'Ours', 'is_active' => true, 'order' => 1]);
        [$code, $out] = $this->run_($live, $preset, ['--execute' => true]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('contact', (string) json_decode($this->firstSectionOf($live, 'home')->content, true)['button_link']);
        $this->assertStringNotContainsString('which the organisation holds but the site does not serve', $out);

        foreach (['trashed', 'inactive'] as $state) {
            $org = $this->org();
            $held = Page::create(['masjid_id' => $org->id, 'slug' => 'contact', 'title' => 'Ours', 'is_active' => $state !== 'inactive', 'order' => 1]);
            if ($state === 'trashed') {
                $held->delete();
            }

            [$dryCode, $dryOut] = $this->run_($org, $preset);
            $this->assertSame(0, $dryCode, $dryOut);
            $this->assertStringContainsString("\"home\" links to \"contact\", which the organisation holds but the site does not serve ({$state})", $dryOut);

            [$code, $out] = $this->run_($org, $preset, ['--execute' => true]);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString("({$state})", $out);

            $content = json_decode($this->firstSectionOf($org, 'home')->content, true);
            $this->assertSame('', $content['button_link'], "a {$state} contact page must not be linked from the home banner");
            $this->assertSame('', $content['button_text']);
        }
    }

    #[Test]
    public function a_section_linking_to_an_unserved_held_page_is_written_inactive_with_the_hint(): void
    {
        $org = $this->org('school');
        Page::create(['masjid_id' => $org->id, 'slug' => 'admissions', 'title' => 'Ours', 'is_active' => false, 'order' => 1]);
        $facts = StarterFacts::fromMasjid($org);

        $cta = fn ($plan) => collect(collect($plan->pages)->firstWhere('slug', 'home')['sections'])->firstWhere('section_type', 'cta');

        $aware = $cta(StarterSite::plan($org, 'school.essentials', $facts, StarterSite::heldPages($org)));
        $this->assertNotNull($aware);
        $this->assertFalse($aware['is_active']);
        $this->assertContains(StarterSite::LINKED_PAGE_HINT, array_column($aware['placeholders'], 'hint'));

        // Blind to the held page, the plan would have kept the section active.
        $this->assertTrue($cta(StarterSite::plan($org, 'school.essentials', $facts))['is_active']);
    }

    #[Test]
    public function existing_slugs_are_skipped_never_touched(): void
    {
        $org = $this->org();
        [$first, $second] = $this->slugs();
        $live = Page::create(['masjid_id' => $org->id, 'slug' => $first, 'title' => 'Our own words', 'is_active' => true, 'order' => 7]);
        $trashed = Page::create(['masjid_id' => $org->id, 'slug' => $second, 'title' => 'Retired', 'is_active' => false, 'order' => 8]);
        $trashed->delete();
        $liveRow = (array) DB::table('pages')->where('id', $live->id)->first();
        $trashedRow = (array) DB::table('pages')->where('id', $trashed->id)->first();

        [$code, $out] = $this->run_($org, self::PRESET, ['--execute' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("Skipped \"{$first}\": the organisation already has a live page", $out);
        $this->assertStringContainsString("Skipped \"{$second}\": the organisation already has a trashed page", $out);

        $this->assertSame($liveRow, (array) DB::table('pages')->where('id', $live->id)->first(), 'the live page is untouched');
        $this->assertSame($trashedRow, (array) DB::table('pages')->where('id', $trashed->id)->first(), 'the trashed page is neither restored nor changed');
        $this->assertSame(0, DB::table('page_section')->whereIn('page_id', [$live->id, $trashed->id])->count(), 'no section was attached to a page the org held');

        $created = Page::where('masjid_id', $org->id)->whereNotIn('id', [$live->id, $trashed->id])->pluck('slug')->sort()->values()->all();
        $expected = array_values(array_diff($this->slugs(), [$first, $second]));
        sort($expected);
        $this->assertSame($expected, $created);

        // A rerun creates nothing.
        $after = $this->footprint($org);
        [$code, $out] = $this->run_($org, self::PRESET, ['--execute' => true]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Created 0 page(s): none', $out);
        $this->assertSame($after, $this->footprint($org));
    }

    #[Test]
    public function the_theme_layout_is_written_only_when_asked(): void
    {
        $custom = ['layout' => ['header' => 'custom', 'footer' => 'custom'], 'radius' => 'lg'];

        $without = $this->org('masjid', $custom);
        [$code, $out] = $this->run_($without, self::PRESET, ['--execute' => true]);
        $this->assertSame(0, $code, $out);
        $this->assertSame($custom, $without->themeSettings()->first()->tokens, 'the header and footer of a live site do not move as a side effect');

        $with = $this->org('masjid', $custom);
        [$code, $out] = $this->run_($with, self::PRESET, ['--execute' => true, '--with-theme-layout' => true]);
        $this->assertSame(0, $code, $out);
        $this->assertSame(
            ['layout' => LayoutPresets::find(self::PRESET)['theme_layout'], 'radius' => 'lg'],
            $with->themeSettings()->first()->tokens,
            'only tokens.layout changes',
        );

        // A dry run with the flag says what it would do, and does not.
        $dry = $this->org('masjid', $custom);
        [$code, $out] = $this->run_($dry, self::PRESET, ['--with-theme-layout' => true]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Theme layout: would be set to header', $out);
        $this->assertSame($custom, $dry->themeSettings()->first()->tokens);
    }

    #[Test]
    public function the_theme_layout_flushes_the_mobile_masjid_cache_only_when_written(): void
    {
        $with = $this->org();
        $without = $this->org();
        $withKey = MobileCache::masjidKey((int) $with->id, MobileCache::SHOW);
        $withoutKey = MobileCache::masjidKey((int) $without->id, MobileCache::SHOW);
        Cache::put($withKey, ['stale' => true], 600);
        Cache::put($withoutKey, ['stale' => true], 600);

        $this->run_($without, self::PRESET, ['--execute' => true]);
        $this->assertNotNull(Cache::get($withoutKey), 'pages alone do not change the mobile payload');

        $this->run_($with, self::PRESET, ['--execute' => true, '--with-theme-layout' => true]);
        $this->assertNull(Cache::get($withKey), 'tokens.layout is in the cached SHOW payload');
    }

    #[Test]
    public function a_preset_for_another_vertical_is_refused(): void
    {
        $org = $this->org('masjid');
        $before = $this->footprint($org);

        [$code, $out] = $this->run_($org, 'school.essentials', ['--execute' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('"school.essentials" is not a layout preset for a masjid organisation. Choose one of: ' . implode(', ', LayoutPresets::keysFor('masjid')), $out);
        $this->assertSame($before, $this->footprint($org));

        [$code] = $this->run_($org, 'nope', ['--execute' => true]);
        $this->assertSame(1, $code);
    }

    #[Test]
    public function a_trashed_org_is_refused(): void
    {
        $org = $this->org();
        $org->delete();

        [$code, $out] = $this->run_($org->id, self::PRESET, ['--execute' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('is archived', $out);
        $this->assertSame(0, Page::withTrashed()->where('masjid_id', $org->id)->count());

        [$code, $out] = $this->run_(999999, self::PRESET, ['--execute' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('There is no organisation 999999.', $out);
    }

    #[Test]
    public function an_org_whose_website_module_is_off_is_refused(): void
    {
        $org = $this->org();
        $org->forceFill(['capability_overrides' => ['website' => false]])->save();

        [$code, $out] = $this->run_($org, self::PRESET, ['--execute' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('has its Website module switched off', $out);
        $this->assertSame(0, Page::where('masjid_id', $org->id)->count());
    }

    #[Test]
    public function execute_schedules_a_renderer_purge(): void
    {
        config(['services.renderer' => [
            'secret' => 'test-renderer-secret-0123456789abcdef', // RendererConfig needs 32+ characters
            'preview_origin' => 'https://renderer.example.test',
            'purge_origins' => 'https://renderer.example.test',
            'admin_origins' => 'https://masjid.hopetechapps.com',
            'timeout' => 5,
        ]]);
        $org = $this->org();
        Queue::fake();
        Log::spy();

        $this->run_($org);
        Queue::assertNothingPushed();

        [$code, $out] = $this->run_($org, self::PRESET, ['--execute' => true]);

        $this->assertSame(0, $code, $out);
        Queue::assertPushed(PurgeRendererCache::class, fn (PurgeRendererCache $job) => $job->organisationId === (int) $org->id);

        // Production runs LOG_LEVEL=warning; the operator and the counts are recorded.
        Log::shouldHaveReceived('warning')
            ->with('studio:apply-layout wrote a starter site', Mockery::on(fn ($context) => $context['masjid_id'] === (int) $org->id
                && $context['preset'] === self::PRESET
                && is_string($context['operator']) && $context['operator'] !== ''
                && $context['pages_created'] === count($this->slugs())
                && $context['theme_layout_written'] === false))
            ->once();
    }
}
