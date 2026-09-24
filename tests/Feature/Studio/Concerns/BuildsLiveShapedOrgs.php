<?php

namespace Tests\Feature\Studio\Concerns;

use App\Enums\SectionType;
use App\Models\DonationLink;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\MasjidMobileAppFeature;
use App\Models\MasjidSocialMediaLink;
use App\Models\MobileAppFeature;
use App\Models\Page;
use App\Models\Section;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Organisations shaped like the live tenants (Burlington 1, MEC 13, Al-Razi 14,
 * BISS 18), built the way their rows actually look, so what the public API
 * serves for them can be recorded once and compared byte for byte after S8
 * (docs/manara-studio-w1.md S8, §3.4).
 *
 * What makes them "live-shaped" is everything S8 reads on its way out:
 *
 *  - logos in `logos`, `header_logos` and `footer_logos`, and NOTHING in the
 *    three derivative collections S8 adds (no live organisation has one), so
 *    /api/v1/settings must not grow a key;
 *  - sections whose `settings` are exactly the shapes production holds: NULL,
 *    `{}`, `{"bind":"about_text"}`, `{"bind":"mission_vision_cards"}` and a
 *    presentation key, and never a `studio` key (the §4 count on production
 *    was 0 on 2026-09-24), so the public-settings strip has nothing to take;
 *  - active and inactive pages and sections, so the payload's filtering is
 *    part of what is pinned;
 *  - an imported host row, so the by-host lookup's answer is pinned too.
 *
 * Every value that would otherwise vary between two runs is fixed: the clock,
 * the media UUIDs and the file names. The ids are the database's, and they are
 * the same on every run because the same rows are written in the same order.
 */
trait BuildsLiveShapedOrgs
{
    private int $liveUuidCounter = 0;

    protected function freezeLiveShapedWorld(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));

        Str::createUuidsUsing(function () {
            $this->liveUuidCounter++;

            return Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', $this->liveUuidCounter));
        });

        Storage::fake('public');

        DB::table('countries')->insertOrIgnore(['id' => 1, 'name' => 'Canada', 'code' => 'CA']);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'Burlington', 'country_id' => 1]);

        if (MobileAppFeature::query()->count() === 0) {
            foreach (["qur\u{2019}an", 'hadith', 'adhkar', 'qibla', 'tasbih', 'donate', 'about_us', 'gallery', 'services', 'announcements', 'contact_us'] as $key) {
                MobileAppFeature::create(['name' => ucfirst($key), 'key' => $key]);
            }
        }
    }

    protected function unfreezeLiveShapedWorld(): void
    {
        Carbon::setTestNow();
        Str::createUuidsNormally();
    }

    /**
     * Burlington's shape: every logo collection, a theme with stored tokens,
     * About/Mission/Vision, a donation link, social links, the feature pivot,
     * and a home page mixing every live `settings` shape.
     */
    protected function burlingtonShaped(): Masjid
    {
        $m = $this->liveOrg('Live Masjid', 'live-masjid@example.test', Masjid::ORG_TYPE_MASJID, '+15550001000');

        foreach (['logos' => 'logo.png', 'header_logos' => 'header.png', 'footer_logos' => 'footer.png'] as $collection => $file) {
            $this->liveMedia($m, $collection, $file);
        }

        $m->themeSettings()->create([
            'primary_color' => '#01B151',
            'secondary_color' => '#1B1B2E',
            'accent_color' => '#FFBA63',
            'background_color' => '#F3F8FB',
            'tokens' => ['layout' => ['header' => 'default', 'footer' => 'columns']],
        ]);
        $m->masjidAbout()->create(['about' => 'Live about text.', 'mission' => 'Live mission.', 'vision' => 'Live vision.']);
        $m->prayerCalculationSettings()->create(['method' => 'NorthAmerica', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight']);
        IqamaTimeSetting::create(['masjid_id' => $m->id, 'iqama_type' => 'minutes_after_adhan', 'show_iqama_times' => true,
            'fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 5, 'isha' => 10]);
        $m->jumaaSettings()->create(['iqama' => '13:30', 'athans' => ['13:00', '14:00']]);
        DonationLink::create(['masjid_id' => $m->id, 'link' => 'https://give.example.test/live', 'title' => 'Give', 'message' => 'Support the masjid']);
        MasjidSocialMediaLink::create(['masjid_id' => $m->id, 'type' => 'Facebook', 'value' => 'https://facebook.example.test/live']);

        foreach (MobileAppFeature::query()->orderBy('id')->get() as $feature) {
            MasjidMobileAppFeature::create(['masjid_id' => $m->id, 'feature_id' => $feature->id, 'is_available' => $feature->id !== 9]);
        }

        $home = $this->livePage($m, 'home', 'Home', 1, true);
        $about = $this->livePage($m, 'about-us', 'About Us', 2, true);
        $this->livePage($m, 'draft-page', 'Draft', 3, false);
        $donate = $this->livePage($m, 'donate', 'Donate', 4, true, showInMenu: false, showAsButton: true);

        $this->liveSection($home, 1, SectionType::PAGE_TITLE, 'Welcome', ['title' => 'Live Masjid', 'subtitle' => 'Welcome', 'layout' => 'hero'], null);
        $this->liveSection($home, 2, SectionType::PRAYER_TIMES, 'Prayer Times', ['title' => 'Prayer Times', 'subtitle' => '', 'image_url' => null], '{}');
        $this->liveSection($home, 3, SectionType::IMAGE_TEXT_GRID, 'Our Story', ['title' => 'Our Story', 'subtitle' => '', 'text' => 'Stored story.', 'image_url' => null], '{"bind":"about_text"}');
        $this->liveSection($home, 4, SectionType::GRID_CARDS, 'Mission and Vision', ['items' => [['title' => 'Our Mission', 'text' => 'stored'], ['title' => 'Our Vision', 'text' => 'stored']]], '{"bind":"mission_vision_cards"}');
        $this->liveSection($home, 5, SectionType::CONTACT_FORM, 'Contact', ['title' => 'Contact Us', 'subtitle' => '', 'button_text' => 'Send', 'show_map' => true], '{"background":"#ffffff","padding":"lg"}');
        $this->liveSection($home, 6, SectionType::ANNOUNCEMENTS_LIST, 'Hidden', ['title' => 'Announcements'], null, active: false);
        $this->liveSection($about, 1, SectionType::ABOUT_US, 'About', ['title' => 'About Us', 'text' => 'stored', 'subtitle' => '', 'image_url' => null, 'button_text' => ''], null);
        $this->liveSection($about, 2, SectionType::MISSION_VISION, 'Mission', ['heading' => 'Mission', 'items' => [], 'layout' => 'side_by_side'], '{}');
        $this->liveSection($donate, 1, SectionType::DONATION, 'Donate', ['title' => '', 'subtitle' => '', 'image_url' => null, 'button_text' => 'Donate'], null);

        MasjidDomain::create([
            'masjid_id' => $m->id, 'host' => 'www.live-masjid.example.test', 'kind' => MasjidDomain::KIND_CUSTOM,
            'zone_apex' => 'live-masjid.example.test', 'status' => MasjidDomain::STATUS_MANUAL, 'source' => MasjidDomain::SOURCE_IMPORTED,
            'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(), 'serving_confirmed_at' => now(),
        ]);

        return $m->fresh();
    }

    /** Al-Razi's shape: a school with a logo only, no theme row and one page. */
    protected function schoolShaped(): Masjid
    {
        $m = $this->liveOrg('Live School', 'live-school@example.test', Masjid::ORG_TYPE_SCHOOL, '+15550001002');
        $this->liveMedia($m, 'logos', 'school-logo.png');

        $home = $this->livePage($m, 'home', 'Home', 1, true);
        $this->liveSection($home, 1, SectionType::PAGE_TITLE, 'Welcome', ['title' => 'Live School', 'layout' => 'hero'], null);
        $this->liveSection($home, 2, SectionType::CTA, 'Admissions', ['heading' => 'Admissions', 'button_text' => 'Apply', 'button_link' => '/admissions', 'layout' => 'gradient'], '{}');

        MasjidDomain::create([
            'masjid_id' => $m->id, 'host' => 'live-school.manara.hopetechapps.com', 'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN,
            'zone_apex' => 'hopetechapps.com', 'status' => MasjidDomain::STATUS_MANUAL, 'source' => MasjidDomain::SOURCE_IMPORTED,
            'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(), 'serving_confirmed_at' => now(),
        ]);

        return $m->fresh();
    }

    /** BISS's shape: a child organisation with nothing but its row. */
    protected function childShaped(Masjid $parent): Masjid
    {
        $m = $this->liveOrg('Live Sunday School', 'live-child@example.test', Masjid::ORG_TYPE_SCHOOL, '+15550001003');
        $m->forceFill(['parent_id' => $parent->id])->save();

        return $m->fresh();
    }

    /**
     * Every public GET a live renderer or installed app makes that S8 could
     * touch, for one organisation: url => raw body.
     *
     * @return array<string, string>
     */
    protected function livePayloads(Masjid $m, array $hosts = []): array
    {
        $headers = ['masjid-id' => (string) $m->id];
        $out = [];

        $get = function (string $url, array $h = []) use (&$out) {
            $response = $this->get($url, $h + ['Accept' => 'application/json']);
            $out["{$response->getStatusCode()} {$url}"] = (string) $response->getContent();
        };

        $get('/api/v1/settings', $headers);
        $get('/api/v1/pages', $headers);
        $get('/api/v1/pages/menu', $headers);

        foreach (Page::query()->where('masjid_id', $m->id)->orderBy('order')->pluck('slug') as $slug) {
            $get("/api/v1/pages/{$slug}", $headers);
        }

        foreach ($hosts as $host) {
            $get('/api/v1/organizations/by-host?host=' . urlencode($host));
        }

        $get("/api/mobile/masjids/{$m->id}");
        $get("/api/mobile/masjids/{$m->id}/features");

        return $out;
    }

    /**
     * Compare against, or with LIVE_PAYLOAD_RECORD=1 write, a committed
     * recording. A recording run never passes, so it cannot be mistaken for a
     * checked one; recordings are made only against code whose output is the
     * one to keep (the base S8 branched from).
     *
     * @param  array<string, string>  $payloads
     */
    protected function assertMatchesLiveRecording(string $case, array $payloads): void
    {
        $path = base_path("tests/fixtures/live-public-payloads/{$case}.json");
        $actual = json_encode($payloads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        if (getenv('LIVE_PAYLOAD_RECORD') === '1') {
            file_put_contents($path, $actual);
            $this->fail("Recorded {$path}. A recording run never passes: run again without LIVE_PAYLOAD_RECORD.");
        }

        $this->assertFileExists($path, "No recording for '{$case}'.");
        $recorded = json_decode((string) file_get_contents($path), true);

        $this->assertSame(array_keys($recorded), array_keys($payloads), "{$case}: a different set of requests or statuses than was recorded");

        foreach ($recorded as $request => $body) {
            $this->assertSame($body, $payloads[$request], "{$case}: {$request} no longer answers byte for byte what was recorded");
        }
    }

    private function liveOrg(string $name, string $email, string $orgType, string $phone): Masjid
    {
        $m = Masjid::create([
            'name' => $name, 'email' => $email, 'phone' => $phone, 'org_type' => $orgType,
            'country_id' => 1, 'city_id' => 1, 'address' => '1 Live St', 'latitude' => 43.3, 'longitude' => -79.8,
            'timezone' => 'America/Toronto', 'crm_enabled' => true,
        ]);
        $m->forceFill(['listed_at' => now()])->save();

        return $m;
    }

    private function liveMedia(Masjid $m, string $collection, string $fileName): void
    {
        $image = imagecreatetruecolor(4, 4);
        $path = tempnam(sys_get_temp_dir(), 'live-media-');
        imagepng($image, $path);

        $m->addMedia($path)->usingFileName($fileName)->usingName(pathinfo($fileName, PATHINFO_FILENAME))->toMediaCollection($collection);
    }

    private function livePage(Masjid $m, string $slug, string $title, int $order, bool $active, bool $showInMenu = true, bool $showAsButton = false): Page
    {
        return Page::create([
            'masjid_id' => $m->id, 'slug' => $slug, 'title' => $title, 'page_title' => null, 'is_active' => $active,
            'order' => $order, 'show_in_menu' => $showInMenu, 'show_as_button' => $showAsButton, 'meta_description' => null,
        ]);
    }

    /** `$settings` is the raw JSON production holds, written as-is. */
    private function liveSection(Page $page, int $order, SectionType $type, string $title, array $content, ?string $settings, bool $active = true): Section
    {
        $section = Section::create([
            'masjid_id' => $page->masjid_id, 'section_type' => $type, 'title' => $title, 'content' => $content, 'is_active' => $active,
        ]);
        DB::table('sections')->where('id', $section->id)->update(['settings' => $settings]);
        $page->sections()->attach($section->id, ['order' => $order, 'platforms' => null]);

        return $section;
    }
}
