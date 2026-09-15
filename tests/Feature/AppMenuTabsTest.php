<?php

namespace Tests\Feature;

use App\Support\AppMenu;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * `profiles[].tabs` — the hybrid tab bar, and the only source of it.
 *
 * Three plans proposed three mechanisms for the bar (a server array, a per-item
 * boolean, a client-side derivation from section keys). They agree on today's
 * result, which is exactly why the disagreement was dangerous: whichever one
 * was wrong would have gone unnoticed until a bar was re-ordered. The server
 * array won, so these are the assertions that have to hold for both apps.
 *
 * The bar's order is NOT the menu's order — `contact` sits in the `about`
 * section but is the third tab — and the cap belongs here so the bar can be
 * trimmed without an app release. The clients append their own "Menu" tab,
 * last, always: a payload that could omit it would make the menu unreachable.
 */
class AppMenuTabsTest extends TestCase
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
    public function a_masjid_with_everything_on_gets_todays_bar_in_todays_order(): void
    {
        // Exactly the set the installed builds already gate on (legacy ids 10,
        // 11 and 6), now labelled and switch-driven rather than pivot-driven.
        $masjid = $this->listedOrg('Burlington Masjid');

        $this->assertSame(
            ['home', 'announcements', 'contact', 'donate'],
            $this->menu($masjid->id)->assertOk()->json('data.profiles.0.tabs')
        );
    }

    #[Test]
    public function home_is_always_first_and_can_never_be_switched_away(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');
        $this->switchOff($masjid, [
            'announcements', 'events', 'contact_requests', 'donation_link', 'giving',
        ]);

        $this->assertSame(
            ['home'],
            $this->menu($masjid->id)->assertOk()->json('data.profiles.0.tabs')
        );
    }

    #[Test]
    public function a_switched_off_destination_leaves_the_bar(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');
        $this->switchOff($masjid, ['contact_requests']);

        $this->assertSame(
            ['home', 'announcements', 'donate'],
            $this->menu($masjid->id)->assertOk()->json('data.profiles.0.tabs')
        );
    }

    #[Test]
    public function a_school_with_contact_on_and_donate_off_gets_three_tabs(): void
    {
        // The default shape of every school in the fleet: the masjid screens
        // were never offered, so Donate is not there to lose.
        $school = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);

        $this->assertSame(
            ['home', 'announcements', 'contact'],
            $this->menu($school->id)->assertOk()->json('data.profiles.0.tabs')
        );
    }

    #[Test]
    public function a_community_org_given_a_donation_link_gets_the_donate_tab_back(): void
    {
        // MAS Youth: a community org, so donate is off by default, and the
        // owner's decision is that it shows once the link is switched on.
        $community = $this->listedOrg('MAS Youth', ['org_type' => 'community']);
        $this->switchOn($community, ['donation_link']);

        $this->assertSame(
            ['home', 'announcements', 'contact', 'donate'],
            $this->menu($community->id)->assertOk()->json('data.profiles.0.tabs')
        );
    }

    #[Test]
    public function every_tab_is_also_a_row_in_the_menu(): void
    {
        // A tab whose destination is not in `sections` is dropped by both
        // clients, so the bar would quietly lose an entry. And every tab
        // destination staying in the menu is what keeps it reachable at a large
        // text size, and after a profile switch that drops the tab.
        $orgs = [
            $this->listedOrg('All On'),
            $this->switchOff($this->listedOrg('Half Off'), ['contact_requests', 'giving']),
            $this->listedOrg('A School', ['org_type' => 'school']),
        ];

        foreach ($orgs as $org) {
            $profile = $this->menu($org->id)->assertOk()->json('data.profiles.0');

            foreach ($profile['tabs'] as $tab) {
                $this->assertContains(
                    $tab,
                    $this->itemKeys($profile),
                    "tab {$tab} has no row in {$org->name}'s menu"
                );
            }
        }
    }

    #[Test]
    public function only_the_four_eligible_keys_can_ever_be_tabs(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        $tabs = $this->menu($masjid->id)->assertOk()->json('data.profiles.0.tabs');

        foreach ($tabs as $tab) {
            $this->assertContains($tab, AppMenu::DEFAULT_REGISTRY['tabs']);
        }

        // Nothing from the Worship or About sections may reach the bar, however
        // the menu is configured.
        foreach (['quran', 'tasbih', 'about_us', 'gallery', 'services'] as $key) {
            $this->assertNotContains($key, $tabs);
        }
    }

    #[Test]
    public function the_bar_never_exceeds_the_cap_the_server_holds(): void
    {
        // Four plus the client's own Menu tab is five, which is iOS's limit
        // before a TabView collapses the overflow into "More".
        $masjid = $this->listedOrg('Burlington Masjid');

        $tabs = $this->menu($masjid->id)->assertOk()->json('data.profiles.0.tabs');
        $this->assertLessThanOrEqual(AppMenu::DEFAULT_REGISTRY['max_tabs'], count($tabs));

        // Trim the cap and the bar trims with it, without an app release —
        // which is the whole reason the cap lives on the server.
        $trimmed = AppMenu::DEFAULT_REGISTRY;
        $trimmed['max_tabs'] = 2;
        config(['app_menu' => $trimmed]);
        $this->forgetMenuRegistry();
        $this->flushMenuCache($masjid->id);

        $this->assertSame(
            ['home', 'announcements'],
            $this->menu($masjid->id)->assertOk()->json('data.profiles.0.tabs')
        );
    }

    #[Test]
    public function each_profile_carries_its_own_bar(): void
    {
        // The bar re-draws on a switch, so a school child must not inherit its
        // parent masjid's Donate tab.
        $home = $this->listedOrg('Muslim Education Center');
        $school = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);
        $school->setParent($home);

        $data = $this->menu($home->id)->assertOk()->json('data');

        $this->assertSame(
            ['home', 'announcements', 'contact', 'donate'],
            $this->profileFor($data, $home->id)['tabs']
        );
        $this->assertSame(
            ['home', 'announcements', 'contact'],
            $this->profileFor($data, $school->id)['tabs']
        );
    }

    private function flushMenuCache(int $masjidId): void
    {
        Cache::forget(MobileCache::masjidKey($masjidId, MobileCache::MENU));
    }
}
