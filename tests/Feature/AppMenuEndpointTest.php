<?php

namespace Tests\Feature;

use App\Support\AppMenu;
use App\Support\WcagColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * GET /api/mobile/masjids/{id}/menu — the menu one organisation's switches
 * produce.
 *
 * This is the endpoint that replaces the Mobile App Features pivot as the thing
 * that decides what the app's drawer lists. Everything pinned here is something
 * a member would SEE go wrong: a section in the wrong place, a row for a screen
 * that was switched off, a school being offered a prayer table it has no times
 * for, an unreadable name on a brand-coloured band.
 *
 * What is deliberately absent from the payload is pinned too. Labels and icons
 * are the clients' — a server-driven icon list is what emptied the drawer on
 * every phone on 2026-08-28 — and the catalogue's labels are the ADMIN's words,
 * which must never be the ones a member reads.
 */
class AppMenuEndpointTest extends TestCase
{
    use RefreshDatabase;
    use MakesMenuOrganisations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();
        $this->forgetMenuRegistry();
    }

    protected function tearDown(): void
    {
        $this->forgetMenuRegistry();

        parent::tearDown();
    }

    #[Test]
    public function a_masjid_with_nothing_switched_off_lists_every_entry_in_registry_order(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        $data = $this->menu($masjid->id)->assertOk()->json('data');

        $this->assertSame(1, $data['schema_version']);
        $this->assertSame($masjid->id, $data['home_id']);
        $this->assertIsString($data['hash']);
        $this->assertNotSame('', $data['hash']);

        $profile = $data['profiles'][0];

        $this->assertSame(['main', 'worship', 'about'], $this->sectionKeys($profile));
        $this->assertSame([
            'home', 'announcements', 'services', 'donate',
            'quran', 'hadith', 'adhkar', 'qibla', 'tasbih',
            'about_us', 'gallery', 'contact',
        ], $this->itemKeys($profile));
    }

    #[Test]
    public function a_profile_carries_exactly_the_nine_documented_keys_in_order(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        $profile = $this->menu($masjid->id)->assertOk()->json('data.profiles.0');

        $this->assertSame(
            ['id', 'name', 'org_type', 'is_home', 'logo_url', 'theme', 'home', 'tabs', 'sections'],
            array_keys($profile)
        );
        $this->assertTrue($profile['is_home']);
        $this->assertSame('masjid', $profile['org_type']);
        $this->assertNull($profile['logo_url']);
    }

    #[Test]
    public function a_switched_off_module_loses_its_row(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');
        $this->switchOff($masjid, ['quran', 'gallery', 'services']);

        $keys = $this->itemKeys($this->menu($masjid->id)->assertOk()->json('data.profiles.0'));

        $this->assertNotContains('quran', $keys);
        $this->assertNotContains('gallery', $keys);
        $this->assertNotContains('services', $keys);

        // and nothing else moved
        $this->assertContains('hadith', $keys);
        $this->assertContains('about_us', $keys);
        $this->assertContains('home', $keys);
    }

    #[Test]
    public function a_section_with_nothing_left_in_it_is_omitted_entirely(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');
        $this->switchOff($masjid, ['quran', 'hadith', 'adhkar', 'qibla', 'tasbih']);

        $profile = $this->menu($masjid->id)->assertOk()->json('data.profiles.0');

        $this->assertSame(['main', 'about'], $this->sectionKeys($profile));
        $this->assertNotContains('worship', $this->sectionKeys($profile));
    }

    #[Test]
    public function a_school_has_no_worship_section_and_no_masjid_only_rows(): void
    {
        // Not a decision anybody made about this school: the five worship
        // switches and the masjid screens are offered to masjids only, so a
        // school reaches them only when a SuperAdmin switches one on.
        $school = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);

        $profile = $this->menu($school->id)->assertOk()->json('data.profiles.0');

        $this->assertSame(['main', 'about'], $this->sectionKeys($profile));
        $this->assertSame(
            ['home', 'announcements', 'about_us', 'gallery', 'contact'],
            $this->itemKeys($profile)
        );
    }

    #[Test]
    public function switching_a_worship_module_on_for_a_school_gives_it_the_row(): void
    {
        $school = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);
        $this->switchOn($school, ['quran', 'tasbih']);

        $profile = $this->menu($school->id)->assertOk()->json('data.profiles.0');

        $this->assertContains('worship', $this->sectionKeys($profile));
        $this->assertSame(['quran', 'tasbih'], array_values(array_filter(
            $this->itemKeys($profile),
            fn (string $key) => in_array($key, ['quran', 'hadith', 'adhkar', 'qibla', 'tasbih'], true)
        )));
    }

    #[Test]
    public function announcements_survives_on_events_alone_and_says_which_half_is_on(): void
    {
        // The one documented asymmetry against the legacy list: the drawer
        // entry opens both, so Events alone keeps it. Legacy id 10 means
        // Announcements alone, and the client parity check allows this case by
        // name.
        $masjid = $this->listedOrg('Burlington Masjid');
        $this->switchOff($masjid, ['announcements']);

        $profile = $this->menu($masjid->id)->assertOk()->json('data.profiles.0');

        $announcements = $this->item($profile, 'announcements');

        $this->assertNotNull($announcements, 'Events alone must keep the entry');
        $this->assertSame(10, $announcements['legacy_feature_id']);
        $this->assertSame(['announcements' => false, 'events' => true], $announcements['parts']);
    }

    #[Test]
    public function announcements_goes_only_when_both_halves_are_off(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');
        $this->switchOff($masjid, ['announcements', 'events']);

        $this->assertNull($this->item(
            $this->menu($masjid->id)->assertOk()->json('data.profiles.0'),
            'announcements'
        ));
    }

    #[Test]
    public function donate_shows_for_a_donation_link_or_for_giving_and_goes_when_both_are_off(): void
    {
        $linkOnly = $this->listedOrg('Link Only');
        $this->switchOff($linkOnly, ['giving']);

        $givingOnly = $this->listedOrg('Giving Only');
        $this->switchOff($givingOnly, ['donation_link']);

        $neither = $this->listedOrg('Neither');
        $this->switchOff($neither, ['donation_link', 'giving']);

        $first = $this->item($this->menu($linkOnly->id)->assertOk()->json('data.profiles.0'), 'donate');
        $this->assertNotNull($first);
        $this->assertSame(6, $first['legacy_feature_id']);
        $this->assertSame(['donation_link' => true, 'giving' => false], $first['parts']);

        $second = $this->item($this->menu($givingOnly->id)->assertOk()->json('data.profiles.0'), 'donate');
        $this->assertNotNull($second);
        $this->assertSame(['donation_link' => false, 'giving' => true], $second['parts']);

        $this->assertNull($this->item(
            $this->menu($neither->id)->assertOk()->json('data.profiles.0'),
            'donate'
        ));
    }

    #[Test]
    public function parts_are_emitted_for_the_two_split_entries_and_nowhere_else(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        $profile = $this->menu($masjid->id)->assertOk()->json('data.profiles.0');

        foreach ($profile['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if (in_array($item['key'], ['announcements', 'donate'], true)) {
                    $this->assertArrayHasKey('parts', $item, "{$item['key']} must carry both halves");
                    $this->assertCount(2, $item['parts']);
                } else {
                    $this->assertArrayNotHasKey('parts', $item, "{$item['key']} must carry no parts");
                }
            }
        }
    }

    #[Test]
    public function only_a_masjid_is_told_it_shows_a_prayer_table(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');
        $school = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);
        $community = $this->listedOrg('MAS Youth', ['org_type' => 'community']);

        // The switch is ON for all three by default, and switched ON explicitly
        // here: the org-type floor still holds, because a school has no iqama
        // times and inventing them is worse than the switch reading optimistic.
        $this->switchOn($school, ['prayer_times']);
        $this->switchOn($community, ['prayer_times']);

        $this->assertTrue($this->menu($masjid->id)->assertOk()->json('data.profiles.0.home.prayer_times'));
        $this->assertFalse($this->menu($school->id)->assertOk()->json('data.profiles.0.home.prayer_times'));
        $this->assertFalse($this->menu($community->id)->assertOk()->json('data.profiles.0.home.prayer_times'));
    }

    #[Test]
    public function a_masjid_that_switched_prayer_times_off_shows_no_prayer_table(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');
        $this->switchOff($masjid, ['prayer_times']);

        $this->assertFalse($this->menu($masjid->id)->assertOk()->json('data.profiles.0.home.prayer_times'));
    }

    #[Test]
    public function sign_in_follows_the_home_organisations_crm_switch(): void
    {
        $off = $this->listedOrg('No CRM', ['crm_enabled' => false]);
        $on = $this->listedOrg('With CRM', ['crm_enabled' => true]);

        $this->assertFalse($this->menu($off->id)->assertOk()->json('data.account.sign_in_available'));
        $this->assertTrue($this->menu($on->id)->assertOk()->json('data.account.sign_in_available'));
    }

    #[Test]
    public function the_account_block_hands_over_the_live_deletion_page(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        $account = $this->menu($masjid->id)->assertOk()->json('data.account');

        $this->assertSame(['sign_in_available', 'deletion_page_url'], array_keys($account));

        // A LITERAL, built from config, not from a helper recomputed in this
        // process. `assertSame(url('/account-deletion'), …)` was the assertion
        // here and it proved nothing: url() reads the request's Host, so the
        // expectation moved with the value and the test passed for every
        // possible host — including a poisoned one.
        config(['app.url' => 'https://masjid.hopetechapps.com']);

        $this->assertSame(
            'https://masjid.hopetechapps.com/account-deletion',
            $this->menu($this->listedOrg('Another Masjid')->id)
                ->assertOk()
                ->json('data.account.deletion_page_url')
        );
    }

    #[Test]
    public function the_deletion_page_survives_a_trailing_slash_in_the_configured_url(): void
    {
        // APP_URL is written by hand in a .env on a box, and a trailing slash
        // is the ordinary typo. `https://host//account-deletion` is a URL both
        // apps would open and most servers would answer, so this would not fail
        // loudly — it would just be the address every phone is handed.
        config(['app.url' => 'https://masjid.hopetechapps.com/']);

        $this->assertSame(
            'https://masjid.hopetechapps.com/account-deletion',
            $this->menu($this->listedOrg('Slash Masjid')->id)
                ->assertOk()
                ->json('data.account.deletion_page_url')
        );
    }

    #[Test]
    public function the_band_text_colour_is_decided_here_for_every_brand_colour_in_play(): void
    {
        foreach (['#01B151' => '#111827', '#47953A' => '#111827', '#2E9E4E' => '#111827', '#2B66C2' => '#FFFFFF'] as $primary => $expected) {
            $org = $this->listedOrg('Org ' . $primary);
            $this->brand($org, $primary);

            $theme = $this->menu($org->id)->assertOk()->json('data.profiles.0.theme');

            $this->assertSame(
                ['primary', 'on_primary', 'primary_on_surface', 'band_text_large_only'],
                array_keys($theme),
                'all four keys or none — a client with primary but no on_primary is back to guessing'
            );
            $this->assertSame(strtoupper($primary), $theme['primary']);
            $this->assertSame($expected, $theme['on_primary']);
            $this->assertFalse($theme['band_text_large_only']);
            $this->assertGreaterThanOrEqual(
                4.5,
                WcagColor::ratio($theme['primary_on_surface'], '#FFFFFF'),
                "{$primary} produced brand text under 4.5:1 on white"
            );
        }
    }

    #[Test]
    public function an_organisation_with_no_theme_row_or_an_unusable_colour_carries_no_theme(): void
    {
        $none = $this->listedOrg('Unthemed');
        $broken = $this->listedOrg('Mistyped');
        $this->brand($broken, 'forest green');

        $this->assertNull($this->menu($none->id)->assertOk()->json('data.profiles.0.theme'));
        // and one organisation's typo never takes the endpoint down for it
        $this->assertNull($this->menu($broken->id)->assertOk()->json('data.profiles.0.theme'));
    }

    #[Test]
    public function the_payload_carries_no_labels_and_no_icons(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        $body = $this->menu($masjid->id)->assertOk()->getContent();

        foreach (['label', 'icon', 'title', 'description'] as $forbidden) {
            $this->assertStringNotContainsString(
                '"' . $forbidden . '"',
                $body,
                "the client owns {$forbidden}s; the payload must not carry one"
            );
        }

        // The catalogue's wording is what an ADMIN reads on the switch panel.
        $this->assertStringNotContainsString('Qur’an', $body);
        $this->assertStringNotContainsString('The Qur’an reader', $body);
    }

    #[Test]
    public function a_stale_config_file_cannot_take_rows_off_the_menu(): void
    {
        // What a deploy looks like for the ninety seconds before the config
        // cache is rebuilt: the loaded file predates the worship keys. The code
        // copy answers, so nobody loses their Qur'an row.
        $masjid = $this->listedOrg('Burlington Masjid');

        $stale = AppMenu::DEFAULT_REGISTRY;
        unset($stale['sections']['worship']);
        foreach (['quran', 'hadith', 'adhkar', 'qibla', 'tasbih'] as $key) {
            unset($stale['items'][$key]);
        }

        config(['app_menu' => $stale]);
        $this->forgetMenuRegistry();

        $profile = $this->menu($masjid->id)->assertOk()->json('data.profiles.0');

        $this->assertContains('worship', $this->sectionKeys($profile));
        $this->assertContains('quran', $this->itemKeys($profile));
    }

    #[Test]
    public function a_garbage_config_file_cannot_take_rows_off_the_menu_either(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        config(['app_menu' => ['schema_version' => 'one', 'sections' => 'nope']]);
        $this->forgetMenuRegistry();

        $profile = $this->menu($masjid->id)->assertOk()->json('data.profiles.0');

        $this->assertSame(['main', 'worship', 'about'], $this->sectionKeys($profile));
        $this->assertCount(12, $this->itemKeys($profile));
    }

    /** One item out of a profile, or null when it is not listed. */
    private function item(array $profile, string $key): ?array
    {
        foreach ($profile['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['key'] === $key) {
                    return $item;
                }
            }
        }

        return null;
    }
}
