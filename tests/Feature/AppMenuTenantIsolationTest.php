<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\User;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * /menu is PUBLIC and unauthenticated, and it names other organisations.
 *
 * That combination is the whole risk in this endpoint. It has to answer an
 * anonymous phone with a list of the organisations that phone may switch into —
 * which is the same public identity the organisation directory already serves —
 * without ever handing over an organisation nobody published, a sibling that
 * belongs to somebody else's family, a column that was classified as internal,
 * or an answer that differs by who is asking.
 *
 * The last one is not paranoia: a body that varied by caller would be cached by
 * the first phone to ask and served to the next, and the ETag would make it
 * stick. The assertions here are what makes the 304 safe.
 */
class AppMenuTenantIsolationTest extends TestCase
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
    public function a_home_offers_itself_and_the_children_it_published_and_nothing_else(): void
    {
        $home = $this->listedOrg('Muslim Education Center');

        $published = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);
        $published->setParent($home);

        $draft = $this->menuOrg('Half Built Academy');
        $draft->setParent($home);

        $trashed = $this->listedOrg('Closed Academy');
        $trashed->setParent($home);
        $trashed->delete();

        $strangersHome = $this->listedOrg('Another Masjid');
        $strangersChild = $this->listedOrg('Another Academy');
        $strangersChild->setParent($strangersHome);

        $data = $this->menu($home->id)->assertOk()->json('data');
        $ids = array_column($data['profiles'], 'id');

        $this->assertSame([$home->id, $published->id], $ids);
        $this->assertNotContains($draft->id, $ids, 'an unpublished child must not appear');
        $this->assertNotContains($trashed->id, $ids, 'a trashed child must not appear');
        $this->assertNotContains($strangersChild->id, $ids, "another family's child must not appear");
        $this->assertTrue($data['profiles'][0]['is_home']);
        $this->assertFalse($data['profiles'][1]['is_home']);
    }

    #[Test]
    public function asking_for_a_child_returns_only_that_child(): void
    {
        // A child has nothing of its own to publish, and its parent is not its
        // to offer: an app built FOR the child is an app for the child.
        $home = $this->listedOrg('Muslim Education Center');
        $child = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);
        $sibling = $this->listedOrg('MAS Youth', ['org_type' => 'community']);
        $child->setParent($home);
        $sibling->setParent($home);

        $data = $this->menu($child->id)->assertOk()->json('data');

        $this->assertSame([$child->id], array_column($data['profiles'], 'id'));
        $this->assertSame($child->id, $data['home_id']);
        $this->assertTrue($data['profiles'][0]['is_home']);
    }

    #[Test]
    public function a_profile_carries_the_nine_public_keys_and_no_classified_column(): void
    {
        $home = $this->listedOrg('Muslim Education Center', [
            'crm_enabled' => true,
            'stripe_account_id' => 'acct_should_never_appear',
            'google_maps_key' => 'key_should_never_appear',
        ]);
        $this->switchOff($home, ['gallery']);

        $response = $this->menu($home->id)->assertOk();
        $body = $response->getContent();

        $this->assertSame(
            ['id', 'name', 'org_type', 'is_home', 'logo_url', 'theme', 'home', 'tabs', 'sections'],
            array_keys($response->json('data.profiles.0'))
        );

        foreach (Masjid::PUBLIC_DIRECTORY_DENYLIST as $column) {
            $this->assertStringNotContainsString(
                $column,
                $body,
                "{$column} is classified and must not reach an anonymous caller"
            );
        }

        $this->assertStringNotContainsString('acct_should_never_appear', $body);
        $this->assertStringNotContainsString('key_should_never_appear', $body);
        // The SuperAdmin's decisions are what this endpoint DERIVES from, never
        // what it publishes.
        $this->assertStringNotContainsString('capability_overrides', $body);
        $this->assertStringNotContainsString('override', $body);
    }

    #[Test]
    public function the_answer_never_depends_on_who_is_asking(): void
    {
        // No per-user data anywhere is what makes this body cacheable and
        // ETag-able. A body that varied by caller would be filled by the first
        // phone to ask, handed to the next, and pinned there by the tag.
        $home = $this->listedOrg('Muslim Education Center', ['crm_enabled' => true]);

        $anonymous = $this->menu($home->id)->assertOk();
        $withToken = $this->menu($home->id, [
            'Authorization' => 'Bearer 42|somebody-elses-token',
        ])->assertOk();

        $this->assertSame($anonymous->getContent(), $withToken->getContent());
        $this->assertSame(
            $anonymous->headers->get('ETag'),
            $withToken->headers->get('ETag')
        );

        foreach ([$anonymous, $withToken] as $response) {
            $this->assertNull($response->headers->get('Vary'), 'a Vary would mean the body does depend on the caller');
            $this->assertEmpty($response->headers->getCookies(), 'a cookie would make this a per-caller response');
            $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        }
    }

    #[Test]
    public function one_organisations_tag_can_never_satisfy_anothers_request(): void
    {
        // home_id is inside the hash for exactly this reason. Two organisations
        // with identical switches would otherwise share a tag, and the second
        // one to ask would get a 304 and render the first one's menu.
        $a = $this->listedOrg('Masjid A');
        $b = $this->listedOrg('Masjid B');

        $tagForA = $this->menu($a->id)->assertOk()->headers->get('ETag');

        $this->menu($b->id, ['If-None-Match' => $tagForA])
            ->assertOk()
            ->assertJsonPath('data.home_id', $b->id);
    }

    #[Test]
    public function a_switch_on_one_organisation_leaves_another_byte_identical(): void
    {
        $a = $this->listedOrg('Masjid A');
        $b = $this->listedOrg('Masjid B');

        $before = $this->menu($a->id)->assertOk();
        $tagBefore = $before->headers->get('ETag');

        Sanctum::actingAs($this->superAdmin());

        foreach (['quran', 'gallery', 'donation_link', 'giving'] as $module) {
            $this->patchJson("/api/admin/masjids/{$b->id}/capabilities/{$module}", ['enabled' => '0'])
                ->assertOk();
        }

        // A's cached menu was not even touched — the family walk goes UP from
        // the organisation that was edited, and B is not in A's family.
        $this->assertNotNull(Cache::get(MobileCache::masjidKey($a->id, MobileCache::MENU)));

        // ...and the derivation agrees: rebuilt from scratch, A's body and tag
        // are the ones it had before anybody touched B.
        Cache::forget(MobileCache::masjidKey($a->id, MobileCache::MENU));

        $after = $this->menu($a->id)->assertOk();

        $this->assertSame($before->getContent(), $after->getContent());
        $this->assertSame($tagBefore, $after->headers->get('ETag'));
    }

    #[Test]
    public function a_switch_on_a_child_changes_the_parents_menu(): void
    {
        // The parent's payload CONTAINS the child's sections, so the parent's
        // hash has to move when the child's switches do — and it has to move on
        // the NEXT request, not ten minutes later. The switch is flipped the
        // way a SuperAdmin flips it, through the admin endpoint, so what is
        // pinned here is the whole path: the derivation sees the change, the
        // hash moves, and MobileCache::flushFamily reached the parent's key
        // from a write to the child.
        $home = $this->listedOrg('Muslim Education Center');
        $child = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);
        $child->setParent($home);

        $before = $this->menu($home->id)->assertOk();

        Sanctum::actingAs($this->superAdmin());
        $this->patchJson("/api/admin/masjids/{$child->id}/capabilities/gallery", ['enabled' => '0'])
            ->assertOk();

        $after = $this->menu($home->id)->assertOk();

        $this->assertNotSame($before->getContent(), $after->getContent());
        $this->assertNotSame($before->headers->get('ETag'), $after->headers->get('ETag'));
        $this->assertNotContains(
            'gallery',
            $this->itemKeys($this->profileFor($after->json('data'), $child->id))
        );
    }

    #[Test]
    public function trashing_a_child_takes_it_off_the_parents_list(): void
    {
        $home = $this->listedOrg('Muslim Education Center');
        $child = $this->listedOrg('Closing Academy', ['org_type' => 'school']);
        $child->setParent($home);

        $this->assertCount(2, $this->menu($home->id)->assertOk()->json('data.profiles'));

        // Archived through the admin endpoint, so the flush that takes it off
        // the parent's list is the real one and not the test's.
        Sanctum::actingAs($this->superAdmin());
        $this->deleteJson("/api/admin/masjids/{$child->id}/trash")->assertOk();

        $this->assertSame(
            [$home->id],
            array_column($this->menu($home->id)->assertOk()->json('data.profiles'), 'id')
        );
    }

    #[Test]
    public function sign_in_follows_the_home_org_even_when_a_child_disagrees(): void
    {
        // The account block belongs to the app's HOME. A child with CRM on must
        // not put a Sign in row in an app whose home has no member realm.
        $home = $this->listedOrg('No CRM Home', ['crm_enabled' => false]);
        $child = $this->listedOrg('CRM Child', ['org_type' => 'school', 'crm_enabled' => true]);
        $child->setParent($home);

        $this->assertFalse($this->menu($home->id)->assertOk()->json('data.account.sign_in_available'));

        // ...and an app built FOR the child, asking as its own home, does.
        $this->assertTrue($this->menu($child->id)->assertOk()->json('data.account.sign_in_available'));
    }

    #[Test]
    public function the_menu_and_the_switcher_never_disagree_about_which_orgs_exist(): void
    {
        // They are fetched separately and cached separately, so the only thing
        // holding them together is App\Support\AppOrgs. If they drifted, a
        // member would pick a name out of the switcher that the menu has no
        // profile for.
        $home = $this->listedOrg('Muslim Education Center');

        foreach (['Zaytuna Academy', 'Al-Bayan School', 'MAS Youth'] as $name) {
            $this->listedOrg($name)->setParent($home);
        }

        $this->menuOrg('Unpublished Academy')->setParent($home);

        $menuIds = array_column($this->menu($home->id)->assertOk()->json('data.profiles'), 'id');
        $orgsRows = $this->getJson("/api/mobile/masjids/{$home->id}/orgs")->assertOk()->json('data');

        $this->assertSame($menuIds, array_column($orgsRows, 'id'));

        // And /orgs still leads with exactly what the installed builds decode,
        // in their order, with `theme` appended and nothing else (S1.3). That
        // the five legacy keys keep their VALUES under every theme state is
        // OrgsThemeAdditiveTest's job; what is pinned here is that the two
        // lists built from one query still describe the same organisations.
        foreach ($orgsRows as $row) {
            $this->assertSame(['id', 'name', 'org_type', 'is_home', 'logo_url', 'theme'], array_keys($row));
        }
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ])->fresh();
    }
}
