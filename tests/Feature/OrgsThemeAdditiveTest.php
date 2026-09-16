<?php

namespace Tests\Feature;

use App\Support\AppMenu;
use App\Support\MobileCache;
use App\Support\WcagColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * `GET /mobile/masjids/{id}/orgs` gains a theme, and gains NOTHING else.
 *
 * This one key is the only intended production payload diff in the whole S1
 * deploy (plan v3 §2.2), and the endpoint it lands on is one every installed
 * build already calls: the iPhone store build 2.5 b44, Play vc13 and the MEC
 * TestFlight all decode this list to draw the organisation switcher. So the
 * test that matters is not "the theme is correct" — it is "everything that was
 * already there is byte-for-byte what it was".
 *
 * Additive means four things, each pinned below:
 *
 *   1. the five original keys are still present, in their original order,
 *      ahead of the new one;
 *   2. their VALUES do not move — not when a theme exists, not when it is
 *      missing, not when the stored colour is unusable;
 *   3. the new key is always present, `null` rather than absent when there is
 *      no usable colour, so a client never has to tell "no theme" apart from
 *      "old server";
 *   4. the theme is the SAME object `/menu` publishes for the same
 *      organisation — the switcher's band and the drawer's band are painted
 *      seconds apart on one phone, and a member would see the drift.
 *
 * `theme` itself (which text colour reads on which band, how a colour that
 * fails on white is darkened) is WcagColorTest's subject and is not re-derived
 * here; what is checked here is that the two endpoints ask the same question
 * of the same builder.
 */
class OrgsThemeAdditiveTest extends TestCase
{
    use RefreshDatabase;
    use MakesMenuOrganisations;

    /** The five keys the installed builds decode, in the order they decode them. */
    private const LEGACY_KEYS = ['id', 'name', 'org_type', 'is_home', 'logo_url'];

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
    public function a_row_leads_with_the_five_keys_the_installed_builds_decode(): void
    {
        $home = $this->listedOrg('Muslim Education Center');
        $this->brand($home, '#0B5FA5');

        $row = $this->orgs($home->id)[0];

        $this->assertSame([...self::LEGACY_KEYS, 'theme'], array_keys($row));
        $this->assertSame($home->id, $row['id']);
        $this->assertSame('Muslim Education Center', $row['name']);
        $this->assertSame('masjid', $row['org_type']);
        $this->assertTrue($row['is_home']);
        $this->assertNull($row['logo_url']);
    }

    #[Test]
    public function the_five_legacy_values_are_identical_whether_a_theme_exists_or_not(): void
    {
        // The strongest form of "additive": build the same family twice, brand
        // one copy and not the other, and assert the legacy half of every row
        // is indistinguishable. A value that shifted — an org_type spelled
        // differently, a logo_url that became "" — would route an installed
        // build to the wrong screen, and no theme assertion would catch it.
        $plain = $this->family('Plain');
        $branded = $this->family('Branded');

        foreach ($branded as $org) {
            $this->brand($org, '#146356');
        }

        $this->assertSame(
            $this->legacyHalf($this->orgs($plain[0]->id)),
            $this->legacyHalf($this->orgs($branded[0]->id), 'Branded', 'Plain'),
        );
    }

    #[Test]
    public function an_organisation_with_no_theme_row_carries_a_null_theme_not_a_missing_one(): void
    {
        $home = $this->listedOrg('No Brand Masjid');

        $row = $this->orgs($home->id)[0];

        $this->assertArrayHasKey('theme', $row);
        $this->assertNull($row['theme']);
    }

    #[Test]
    public function a_stored_colour_that_cannot_be_used_reads_as_no_theme(): void
    {
        // A half-typed colour in the admin's theme form must not reach a phone
        // as a band it cannot paint. It reads exactly as "no brand colour".
        foreach (['', '   ', 'rebeccapurple', '#12345', 'not a colour'] as $i => $unusable) {
            $org = $this->listedOrg("Unusable {$i}");
            $this->brand($org, $unusable);

            $this->assertNull($this->orgs($org->id)[0]['theme'], "stored: '{$unusable}'");
        }
    }

    #[Test]
    public function the_theme_a_row_carries_is_the_one_the_menu_publishes(): void
    {
        $home = $this->listedOrg('Muslim Education Center');
        $this->brand($home, '#0B5FA5');

        $child = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);
        $child->setParent($home);
        $this->brand($child, '#F4C542');

        $rows = collect($this->orgs($home->id))->keyBy('id');
        $profiles = collect(AppMenu::payload($home->fresh())['profiles'])->keyBy('id');

        $this->assertSame($rows->keys()->all(), $profiles->keys()->all());

        foreach ($rows as $id => $row) {
            $this->assertSame($profiles[$id]['theme'], $row['theme'], "org {$id}");
        }

        // And the dark-on-yellow / white-on-blue split is real, so the check
        // above is comparing two decided answers, not two nulls.
        $this->assertSame(WcagColor::LIGHT, $rows[$home->id]['theme']['on_primary']);
        $this->assertSame(WcagColor::DARK, $rows[$child->id]['theme']['on_primary']);
    }

    #[Test]
    public function every_row_in_a_family_carries_its_own_theme(): void
    {
        // The switcher paints the TARGET organisation's band, so a child's
        // theme has to travel in the parent's list. A single home-only theme
        // would leave every child's band painted in the home's colour.
        $home = $this->listedOrg('Muslim Education Center');
        $this->brand($home, '#0B5FA5');

        $branded = $this->listedOrg('Al-Bayan School', ['org_type' => 'school']);
        $branded->setParent($home);
        $this->brand($branded, '#7A1F2B');

        $unbranded = $this->listedOrg('MAS Youth', ['org_type' => 'community']);
        $unbranded->setParent($home);

        $rows = collect($this->orgs($home->id))->keyBy('id');

        $this->assertSame('#0B5FA5', $rows[$home->id]['theme']['primary']);
        $this->assertSame('#7A1F2B', $rows[$branded->id]['theme']['primary']);
        $this->assertNull($rows[$unbranded->id]['theme']);
    }

    #[Test]
    public function the_theme_keys_are_the_four_the_clients_read(): void
    {
        // iOS reads bandTextLargeOnly off THIS payload when no menu is cached,
        // and Android's SwitchOverlayPalette reads the same flag. A row that
        // carried three of the four would make that rule silently never fire.
        $home = $this->listedOrg('Muslim Education Center');
        $this->brand($home, '#0B5FA5');

        $theme = $this->orgs($home->id)[0]['theme'];

        $this->assertSame(
            ['primary', 'on_primary', 'primary_on_surface', 'band_text_large_only'],
            array_keys($theme)
        );
        $this->assertIsBool($theme['band_text_large_only']);
    }

    #[Test]
    public function the_list_is_still_public_and_still_cached_per_organisation(): void
    {
        // Additive must not have made the endpoint per-caller or uncacheable:
        // it is answered anonymously, and the theme rides inside the SAME
        // cached array, so branding an organisation after the fact is a flush
        // question (MobileCache::flushFamily), never a per-request lookup.
        $home = $this->listedOrg('Muslim Education Center');

        $this->assertNull($this->orgs($home->id)[0]['theme']);

        $this->brand($home, '#0B5FA5');
        $this->assertNull($this->orgs($home->id)[0]['theme'], 'the cached body should still be served');

        Cache::forget(MobileCache::masjidKey($home->id, MobileCache::ORGS));
        $this->assertSame('#0B5FA5', $this->orgs($home->id)[0]['theme']['primary']);
    }

    #[Test]
    public function a_pre_s1_cache_entry_can_never_answer_the_new_shape(): void
    {
        // The one failure in this slice no other test can reach, because every
        // test starts with a cold cache and production will not.
        //
        // bin/deploy runs migrate and re-caches config and routes; it does NOT
        // run cache:clear, and this store is the database. So at the instant S1
        // goes live there are live `orgs` entries written by the OLD code, and
        // if the new code read the same key it would serve them — five-key rows
        // with `theme` missing ENTIRELY, for up to ten minutes, to every phone.
        // "null, never absent" invites a client to declare the field
        // non-optional, and such a client fails to decode the whole array and
        // shows an EMPTY organisation switcher during exactly the window the
        // team is watching the deploy.
        //
        // The key carries a version for that reason. This test is what keeps it
        // carrying one.
        $home = $this->listedOrg('Muslim Education Center');

        $this->assertNotSame(
            'orgs',
            MobileCache::ORGS,
            'the /orgs shape changed in S1, so its cache key must not be the one the old code wrote'
        );

        // A pre-S1 entry, verbatim: the five keys and no theme.
        Cache::put(MobileCache::masjidKey($home->id, 'orgs'), [[
            'id' => $home->id,
            'name' => $home->name,
            'org_type' => 'masjid',
            'is_home' => true,
            'logo_url' => null,
        ]], 600);

        $row = $this->orgs($home->id)[0];

        $this->assertArrayHasKey('theme', $row, 'the stale entry must be unreachable, not served');
        $this->assertSame(['id', 'name', 'org_type', 'is_home', 'logo_url', 'theme'], array_keys($row));
    }

    /** A home with two published children, the shape a switcher actually draws. */
    private function family(string $prefix): array
    {
        $home = $this->listedOrg("{$prefix} Center");

        $school = $this->listedOrg("{$prefix} School", ['org_type' => 'school']);
        $school->setParent($home);

        $youth = $this->listedOrg("{$prefix} Youth", ['org_type' => 'community']);
        $youth->setParent($home);

        return [$home->fresh(), $school->fresh(), $youth->fresh()];
    }

    /**
     * Every row's five legacy keys, with ids and the naming prefix normalised
     * away so two structurally identical families compare equal.
     */
    private function legacyHalf(array $rows, string $search = '', string $replace = ''): array
    {
        return array_map(function (array $row) use ($search, $replace) {
            $legacy = array_intersect_key($row, array_flip(self::LEGACY_KEYS));
            $legacy['id'] = 'normalised';
            $legacy['name'] = $search === '' ? $legacy['name'] : str_replace($search, $replace, $legacy['name']);

            return $legacy;
        }, $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function orgs(int $masjidId): array
    {
        return $this->getJson("/api/mobile/masjids/{$masjidId}/orgs")->assertOk()->json('data');
    }
}
