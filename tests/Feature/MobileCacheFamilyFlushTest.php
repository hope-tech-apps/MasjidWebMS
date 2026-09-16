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
 * An edit to a CHILD has to reach the payloads cached under its PARENT's id.
 *
 * `/orgs` and `/menu` are the first two endpoints in this codebase that answer
 * about a FAMILY rather than about one organisation. A parent's switcher
 * carries each published child's name, type, logo and brand colour; a parent's
 * drawer carries a whole profile per child, derived from that child's switches.
 * Every existing flush in the admin controllers forgets the organisation that
 * was edited and stops there — which, for these two keys, is the wrong
 * organisation.
 *
 * The failure it produces is the quiet kind. The write succeeds, the ledger row
 * is written, the panel shows the new state, and the phone keeps the old answer
 * for up to ten minutes — held there by an ETag, so the app does not even
 * re-download. Nothing logs. A SuperAdmin watching a handset concludes the
 * switch did not work and flips it again.
 *
 * It was already happening before the menu existed: publishing a child flushed
 * the global directory only, so a newly listed school appeared in the app's
 * organisation list at once and in its own parent's switcher ten minutes later.
 *
 * So this suite is written against the ADMIN ENDPOINTS, not against
 * MobileCache. What matters is not that the helper works — it is that every
 * path an admin can take to change what a family looks like calls it.
 */
class MobileCacheFamilyFlushTest extends TestCase
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
    public function switching_a_module_on_a_child_clears_the_parents_menu_and_switcher(): void
    {
        [$home, $child] = $this->family();
        $this->warm($home, $child);

        $this->asSuper()
            ->patchJson("/api/admin/masjids/{$child->id}/capabilities/gallery", ['enabled' => '0'])
            ->assertOk();

        $this->assertForgotten($home, [MobileCache::MENU, MobileCache::ORGS]);
        $this->assertForgotten($child, [MobileCache::MENU, MobileCache::ORGS, MobileCache::FEATURES]);
    }

    #[Test]
    public function publishing_a_child_clears_the_parents_switcher(): void
    {
        // The ST-9 regression, and the one that predates the menu entirely:
        // setDirectoryListing flushed the global directory and nothing else.
        $home = $this->listedOrg('Muslim Education Center');
        $child = $this->menuOrg('Half Built Academy', ['org_type' => 'school']);
        $child->setParent($home);

        $this->warm($home, $child);

        $this->asSuper()
            ->patchJson("/api/admin/masjids/{$child->id}/directory-listing", ['listed' => '1'])
            ->assertOk();

        $this->assertForgotten($home, [MobileCache::MENU, MobileCache::ORGS]);
    }

    #[Test]
    public function unpublishing_a_child_clears_it_too(): void
    {
        [$home, $child] = $this->family();
        $this->warm($home, $child);

        $this->asSuper()
            ->patchJson("/api/admin/masjids/{$child->id}/directory-listing", ['listed' => '0'])
            ->assertOk();

        $this->assertForgotten($home, [MobileCache::MENU, MobileCache::ORGS]);
    }

    #[Test]
    public function the_crm_switch_clears_the_menu_that_reads_it(): void
    {
        // `account.sign_in_available` is derived from crm_enabled, so this
        // switch decides whether the drawer draws a Sign in row at all.
        [$home, $child] = $this->family();
        $this->warm($home, $child);

        $this->asSuper()
            ->patchJson("/api/admin/masjids/{$child->id}/crm-access", ['enabled' => '1'])
            ->assertOk();

        $this->assertForgotten($home, [MobileCache::MENU, MobileCache::ORGS]);
        $this->assertForgotten($child, [MobileCache::MENU]);
    }

    #[Test]
    public function renaming_a_child_clears_the_list_that_prints_its_name(): void
    {
        [$home, $child] = $this->family();
        $this->warm($home, $child);

        $this->asSuper()
            ->postJson("/api/admin/masjids/{$child->id}/details", [
                'name' => 'Renamed Academy',
                'email' => $child->email,
                'phone' => $child->phone,
                'timezone' => 'America/Toronto',
                'latitude' => 0.0,
                'longitude' => 0.0,
            ])
            ->assertOk();

        $this->assertSame('Renamed Academy', $child->fresh()->name);

        $this->assertForgotten($home, [MobileCache::MENU, MobileCache::ORGS]);
    }

    #[Test]
    public function rebranding_a_child_clears_the_band_its_parent_paints(): void
    {
        // The theme controller flushed SHOW only, which was right until the
        // brand colour became something the switcher and the drawer draw.
        [$home, $child] = $this->family();
        $this->warm($home, $child);

        $this->asSuper()
            ->postJson("/api/admin/masjids/{$child->id}/theme", ['primary_color' => '#7A1F2B'])
            ->assertOk();

        $this->assertForgotten($home, [MobileCache::MENU, MobileCache::ORGS]);
        $this->assertForgotten($child, [MobileCache::MENU, MobileCache::ORGS, MobileCache::SHOW]);
    }

    #[Test]
    public function archiving_and_restoring_a_child_both_clear_the_parent(): void
    {
        [$home, $child] = $this->family();

        $this->warm($home, $child);
        $this->asSuper()->deleteJson("/api/admin/masjids/{$child->id}/trash")->assertOk();
        $this->assertForgotten($home, [MobileCache::MENU, MobileCache::ORGS]);

        $this->warm($home, $child);
        $this->asSuper()->postJson("/api/admin/masjids/{$child->id}/restore")->assertOk();
        $this->assertForgotten($home, [MobileCache::MENU, MobileCache::ORGS]);
    }

    #[Test]
    public function re_parenting_clears_the_family_joined_and_the_family_left(): void
    {
        // Two families change, and only one of them is reachable from the model
        // after the write. An old parent left listing a child it no longer has
        // is the same stale switcher, arrived at from the other direction.
        $oldHome = $this->listedOrg('Old Center');
        $newHome = $this->listedOrg('New Center');
        $child = $this->listedOrg('Moving Academy', ['org_type' => 'school']);
        $child->setParent($oldHome);

        $this->warm($oldHome, $newHome, $child);

        $child->fresh()->setParent($newHome);

        $this->assertForgotten($oldHome, [MobileCache::MENU, MobileCache::ORGS]);
        $this->assertForgotten($newHome, [MobileCache::MENU, MobileCache::ORGS]);
        $this->assertForgotten($child, [MobileCache::MENU, MobileCache::ORGS]);
    }

    #[Test]
    public function detaching_a_child_clears_the_parent_it_left(): void
    {
        [$home, $child] = $this->family();
        $this->warm($home, $child);

        $child->fresh()->setParent(null);

        $this->assertForgotten($home, [MobileCache::MENU, MobileCache::ORGS]);
        $this->assertForgotten($child, [MobileCache::MENU, MobileCache::ORGS]);
    }

    #[Test]
    public function permanently_deleting_a_child_clears_the_parent_that_still_lists_it(): void
    {
        // The last chance to flush: after this there is no row left to walk up
        // from, and the parent's cached list would name an organisation that no
        // longer exists.
        [$home, $child] = $this->family();
        $this->warm($home, $child);

        $child->fresh()->forceDelete();

        $this->assertForgotten($home, [MobileCache::MENU, MobileCache::ORGS]);
    }

    #[Test]
    public function the_walk_climbs_every_generation_not_just_the_first(): void
    {
        $grandparent = $this->listedOrg('Grandparent Center');
        $parent = $this->listedOrg('Parent Academy', ['org_type' => 'school']);
        $parent->setParent($grandparent);
        $child = $this->listedOrg('Child Program', ['org_type' => 'community']);
        $child->setParent($parent);

        $this->warm($grandparent, $parent, $child);

        MobileCache::flushFamily($child->fresh());

        $this->assertForgotten($grandparent, [MobileCache::MENU, MobileCache::ORGS]);
        $this->assertForgotten($parent, [MobileCache::MENU, MobileCache::ORGS]);
        $this->assertForgotten($child, [MobileCache::MENU, MobileCache::ORGS]);
    }

    #[Test]
    public function an_ancestors_own_payloads_are_left_alone(): void
    {
        // A parent's SHOW and FEATURES are about ITSELF and cannot have changed
        // because a child was edited. Forgetting them would turn one switch
        // flip into a cache stampede across a family.
        [$home, $child] = $this->family();

        $this->warmKey($home, MobileCache::SHOW);
        $this->warmKey($home, MobileCache::FEATURES);
        $this->warmKey($home, MobileCache::ANNOUNCEMENTS);

        MobileCache::flushFamily($child->fresh());

        $this->assertKept($home, [MobileCache::SHOW, MobileCache::FEATURES, MobileCache::ANNOUNCEMENTS]);
    }

    #[Test]
    public function the_legacy_features_last_good_answer_is_never_forgotten(): void
    {
        // `features.lastgood` is the legacy /features contract's last line of
        // defence: the answer it falls back to when the live derivation and the
        // pivot have both failed. A cache flush is the moment it matters most,
        // and it sits under a key that STARTS with `features` — so anything
        // here that matched a prefix instead of naming keys would quietly take
        // the safety net away.
        [$home, $child] = $this->family();

        foreach ([$home, $child] as $org) {
            Cache::forever(MobileCache::masjidKey($org->id, MobileCache::FEATURES . '.lastgood'), ['kept']);
        }

        MobileCache::flushFamily($child->fresh());
        MobileCache::flushFamilyById($home->id);
        MobileCache::flushMasjidAll($child->id);

        foreach ([$home, $child] as $org) {
            $this->assertSame(
                ['kept'],
                Cache::get(MobileCache::masjidKey($org->id, MobileCache::FEATURES . '.lastgood')),
                "features.lastgood was forgotten for {$org->id}"
            );
        }
    }

    #[Test]
    public function a_cycle_in_the_parent_column_cannot_hang_the_write_that_hit_it(): void
    {
        // setParent refuses to create one, so this can only come from a repair
        // script writing the column directly. The flush still has to return —
        // an admin saving a phone number must not be the request that spins.
        $a = $this->listedOrg('Cycle A');
        $b = $this->listedOrg('Cycle B');

        Masjid::withTrashed()->whereKey($a->id)->update(['parent_id' => $b->id]);
        Masjid::withTrashed()->whereKey($b->id)->update(['parent_id' => $a->id]);

        $this->warm($a, $b);

        MobileCache::flushFamily($a->fresh());

        $this->assertForgotten($a, [MobileCache::MENU, MobileCache::ORGS]);
        $this->assertForgotten($b, [MobileCache::MENU, MobileCache::ORGS]);
    }

    #[Test]
    public function a_chain_longer_than_the_cap_stops_at_the_cap(): void
    {
        // The bound is what makes the cycle case above safe even when the
        // seen-set cannot help — a very long chain rather than a loop.
        $orgs = [];
        $previous = null;

        for ($i = 0; $i < MobileCache::FAMILY_HOPS + 3; $i++) {
            $org = $this->listedOrg("Chain {$i}");

            if ($previous !== null) {
                Masjid::withTrashed()->whereKey($org->id)->update(['parent_id' => $previous->id]);
            }

            $orgs[] = $org;
            $previous = $org;
        }

        foreach ($orgs as $org) {
            $this->warmKey($org, MobileCache::MENU);
        }

        MobileCache::flushFamily($orgs[count($orgs) - 1]->fresh());

        // The deepest organisation and the cap's worth of ancestors above it.
        $this->assertForgotten($orgs[count($orgs) - 1], [MobileCache::MENU]);
        $this->assertForgotten($orgs[count($orgs) - 1 - MobileCache::FAMILY_HOPS], [MobileCache::MENU]);
        $this->assertKept($orgs[0], [MobileCache::MENU]);
    }

    #[Test]
    public function flush_masjid_all_now_includes_the_two_family_keys(): void
    {
        // ORGS was missing from that list, which is why a broad update could
        // leave an organisation's own switcher stale.
        $org = $this->listedOrg('Muslim Education Center');

        foreach ([MobileCache::ORGS, MobileCache::MENU, MobileCache::SHOW] as $resource) {
            $this->warmKey($org, $resource);
        }

        MobileCache::flushMasjidAll($org->id);

        $this->assertForgotten($org, [MobileCache::ORGS, MobileCache::MENU, MobileCache::SHOW]);
    }

    #[Test]
    public function an_organisation_with_no_parent_flushes_only_itself(): void
    {
        $alone = $this->listedOrg('Standalone Masjid');
        $stranger = $this->listedOrg('Unrelated Masjid');

        $this->warm($alone, $stranger);

        MobileCache::flushFamily($alone->fresh());

        $this->assertForgotten($alone, [MobileCache::MENU, MobileCache::ORGS]);
        $this->assertKept($stranger, [MobileCache::MENU, MobileCache::ORGS]);
    }

    #[Test]
    public function the_menu_a_parent_serves_is_right_on_the_very_next_request(): void
    {
        // The end-to-end form of all of the above, through the endpoints a
        // phone actually calls. Everything else here asserts on keys; this
        // asserts on what a member would see.
        [$home, $child] = $this->family();
        $this->brand($child, '#0B5FA5');

        $before = $this->menu($home->id)->assertOk();
        $this->assertContains('gallery', $this->itemKeys($this->profileFor($before->json('data'), $child->id)));

        $this->asSuper()
            ->patchJson("/api/admin/masjids/{$child->id}/capabilities/gallery", ['enabled' => '0'])
            ->assertOk();

        $after = $this->menu($home->id)->assertOk();

        $this->assertNotContains('gallery', $this->itemKeys($this->profileFor($after->json('data'), $child->id)));
        $this->assertNotSame($before->headers->get('ETag'), $after->headers->get('ETag'));

        $orgs = $this->getJson("/api/mobile/masjids/{$home->id}/orgs")->assertOk()->json('data');
        $this->assertSame('#0B5FA5', collect($orgs)->firstWhere('id', $child->id)['theme']['primary']);
    }

    /** A published home with one published school under it. */
    private function family(): array
    {
        $home = $this->listedOrg('Muslim Education Center');
        $child = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);
        $child->setParent($home);

        return [$home->fresh(), $child->fresh()];
    }

    /** Put a sentinel under every family key of each organisation given. */
    private function warm(Masjid ...$orgs): void
    {
        foreach ($orgs as $org) {
            foreach ([MobileCache::MENU, MobileCache::ORGS, MobileCache::FEATURES, MobileCache::SHOW] as $resource) {
                $this->warmKey($org, $resource);
            }
        }
    }

    private function warmKey(Masjid $org, string $resource): void
    {
        Cache::forever(MobileCache::masjidKey($org->id, $resource), ['sentinel', $org->id, $resource]);
    }

    /** @param array<int, string> $resources */
    private function assertForgotten(Masjid $org, array $resources): void
    {
        foreach ($resources as $resource) {
            $this->assertNull(
                Cache::get(MobileCache::masjidKey($org->id, $resource)),
                "{$resource} was still cached for {$org->name}"
            );
        }
    }

    /** @param array<int, string> $resources */
    private function assertKept(Masjid $org, array $resources): void
    {
        foreach ($resources as $resource) {
            $this->assertNotNull(
                Cache::get(MobileCache::masjidKey($org->id, $resource)),
                "{$resource} should not have been forgotten for {$org->name}"
            );
        }
    }

    private function asSuper(): static
    {
        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ])->fresh());

        return $this;
    }
}
