<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The TV board's language follows the organisation's website language (owner,
 * 2026-09-28; masjids.website_locale from W2 S12). tv-config carries it as
 * `website_locale`, and only when the organisation chose one of
 * Masjid::WEBSITE_LOCALES, so every organisation without a choice (all the live
 * ones) keeps exactly the payload TvConfigSnapshotTest pins, and its board stays
 * English. The board decodes the key as optional (MasjidKit TVConfig).
 */
class TvConfigWebsiteLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function organisation(?string $locale): Masjid
    {
        return Masjid::create([
            'name' => 'Locale Org ' . uniqid(),
            'email' => 'locale-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => '35.78056000', 'longitude' => '-78.63890000', 'timezone' => 'America/New_York',
            'website_locale' => $locale,
        ]);
    }

    private function config(Masjid $masjid): array
    {
        return $this->getJson("/api/mobile/masjids/{$masjid->id}/tv-config")->assertOk()->json('data');
    }

    #[Test]
    public function an_organisation_that_chose_no_language_sends_no_key(): void
    {
        $response = $this->getJson("/api/mobile/masjids/{$this->organisation(null)->id}/tv-config")->assertOk();

        $this->assertArrayNotHasKey('website_locale', $response->json('data'));
        $this->assertStringNotContainsString('website_locale', $response->getContent());
    }

    #[Test]
    public function arabic_adds_one_key_and_changes_nothing_else(): void
    {
        $plain = $this->config($this->organisation(null));
        $arabic = $this->config($this->organisation('ar'));

        $this->assertSame('ar', $arabic['website_locale']);
        unset($arabic['website_locale']);
        $this->assertSame($plain, $arabic, 'every other key, value and JSON type is the unchosen payload');
    }

    #[Test]
    public function english_is_sent_when_chosen(): void
    {
        $this->assertSame('en', $this->config($this->organisation('en'))['website_locale']);
    }

    /** A value outside WEBSITE_LOCALES (written around the validator) is not a choice. */
    #[Test]
    public function an_unknown_stored_value_is_not_sent(): void
    {
        $masjid = $this->organisation(null);
        DB::table('masjids')->where('id', $masjid->id)->update(['website_locale' => 'fr']);

        $this->assertArrayNotHasKey('website_locale', $this->config($masjid));
    }

    /**
     * tv-config is cached per organisation (TTL_SHORT). The S12 website-locale
     * PATCH flushes MobileCache::TV_CONFIG so a board picks the change up on its
     * next poll rather than up to five minutes later; this pins that the flush
     * is what makes the new language visible.
     */
    #[Test]
    public function a_language_change_reaches_the_board_once_the_tv_config_cache_is_flushed(): void
    {
        $masjid = $this->organisation(null);
        $this->assertArrayNotHasKey('website_locale', $this->config($masjid), 'cached without a language');

        DB::table('masjids')->where('id', $masjid->id)->update(['website_locale' => 'ar']);
        $this->assertArrayNotHasKey('website_locale', $this->config($masjid), 'still the cached payload');

        MobileCache::flushMasjid($masjid->id, MobileCache::TV_CONFIG);
        $this->assertSame('ar', $this->config($masjid)['website_locale']);
    }
}
