<?php

namespace Tests\Feature;

use App\Models\AppMenuSetting;
use App\Support\AppMenu;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * What /menu does when it is NOT simply answering: the 304, the two refusals,
 * and the lever.
 *
 * Each of these is a failure the apps have to survive without a blank drawer,
 * and each fails silently if it is wrong. A 304 that never fires just looks
 * like a slow launch. A 404 without a `data` key decodes fine on the server and
 * fails on the phone. A 500 instead of a 503 is the difference between "fall
 * back to the menu you already have" and an error card.
 */
class AppMenuCacheAndEtagTest extends TestCase
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
    public function a_second_request_with_the_tag_is_answered_304_with_no_body(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        $first = $this->menu($masjid->id)->assertOk();
        $tag = $first->headers->get('ETag');

        $this->assertSame('"' . $first->json('data.hash') . '"', $tag);

        $second = $this->menu($masjid->id, ['If-None-Match' => $tag]);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());
        $this->assertSame($tag, $second->headers->get('ETag'));
    }

    #[Test]
    public function a_weakened_tag_and_a_list_of_tags_both_match(): void
    {
        // nginx's gzip module WEAKENS an ETag it compresses, and a proxy may
        // send several. Comparing the raw header would mean the 304 silently
        // never fires in production and every launch re-downloads a menu that
        // did not change — working, and wrong, and invisible from here.
        $masjid = $this->listedOrg('Burlington Masjid');

        $hash = $this->menu($masjid->id)->assertOk()->json('data.hash');

        $this->menu($masjid->id, ['If-None-Match' => 'W/"' . $hash . '"'])->assertStatus(304);
        $this->menu($masjid->id, ['If-None-Match' => '"stale-one", W/"' . $hash . '"'])->assertStatus(304);
        $this->menu($masjid->id, ['If-None-Match' => $hash])->assertStatus(304);
    }

    #[Test]
    public function a_tag_that_does_not_match_gets_the_whole_menu(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        $this->menu($masjid->id)->assertOk();

        $this->menu($masjid->id, ['If-None-Match' => '"something-else"'])
            ->assertOk()
            ->assertJsonPath('data.home_id', $masjid->id);

        $this->menu($masjid->id, ['If-None-Match' => ''])->assertOk();
    }

    #[Test]
    public function the_body_is_cached_per_organisation(): void
    {
        $a = $this->listedOrg('Masjid A');
        $b = $this->listedOrg('Masjid B');

        $this->menu($a->id)->assertOk();

        $this->assertTrue(Cache::has(MobileCache::masjidKey($a->id, MobileCache::MENU)));
        $this->assertFalse(
            Cache::has(MobileCache::masjidKey($b->id, MobileCache::MENU)),
            "one organisation's request must not fill another's cache"
        );

        $this->menu($b->id)->assertOk();
        $this->assertTrue(Cache::has(MobileCache::masjidKey($b->id, MobileCache::MENU)));

        $this->assertNotSame(
            $this->menu($a->id)->assertOk()->json('data.hash'),
            $this->menu($b->id)->assertOk()->json('data.hash')
        );
    }

    #[Test]
    public function the_cached_body_carries_the_hash_that_becomes_the_tag(): void
    {
        // The ETag has to survive the cache round trip. Recomputing it on the
        // way out would work, right up until the derivation changed under a
        // cached body and the tag stopped describing what was sent.
        $masjid = $this->listedOrg('Burlington Masjid');

        $tag = $this->menu($masjid->id)->assertOk()->headers->get('ETag');
        $entry = Cache::get(MobileCache::masjidKey($masjid->id, MobileCache::MENU));

        $this->assertIsArray($entry);
        $this->assertSame(['hash', 'data'], array_keys($entry));
        $this->assertSame('"' . $entry['hash'] . '"', $tag);
        $this->assertSame($entry['hash'], $entry['data']['hash']);
    }

    #[Test]
    public function an_unknown_or_trashed_organisation_is_404_with_a_data_object(): void
    {
        $trashed = $this->listedOrg('Closed Masjid');
        $trashed->delete();

        foreach ([999999, $trashed->id] as $id) {
            $response = $this->menu($id)->assertStatus(404);

            $this->assertSame('failed', $response->json('status'));
            $this->assertIsString($response->json('message'));
            // The iPhone app decodes EVERY mobile body through one envelope
            // whose `data` is non-optional, and MobileErrorEnvelope covers only
            // the member routes. A refusal without this key fails to decode on
            // the device, where no server test can see it.
            $this->assertStringContainsString('"data":{}', $response->getContent());
        }
    }

    #[Test]
    public function a_schema_this_server_does_not_speak_is_404(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        $this->menu($masjid->id, [], '?schema=1')->assertOk();
        $this->menu($masjid->id, [], '?schema=2')->assertStatus(404);
        $this->menu($masjid->id, [], '?schema=99')->assertStatus(404);

        $this->assertStringContainsString(
            '"data":{}',
            $this->menu($masjid->id, [], '?schema=2')->getContent()
        );

        // Absent, or something that is not a version at all, means "the schema
        // there is" rather than a refusal.
        $this->menu($masjid->id)->assertOk();
        $this->menu($masjid->id, [], '?schema=')->assertOk();
        $this->menu($masjid->id, [], '?schema=latest')->assertOk();
    }

    #[Test]
    public function the_kill_switch_takes_the_menu_away_and_gives_it_back(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        $before = $this->menu($masjid->id)->assertOk();

        Artisan::call('app-menu:kill', ['--reason' => 'donate row missing for MEC', '--by' => 'Moneeb']);

        $killed = $this->menu($masjid->id)->assertStatus(404);
        $this->assertStringContainsString('"data":{}', $killed->getContent());

        $row = AppMenuSetting::row();
        $this->assertTrue($row->menu_disabled);
        $this->assertSame('donate row missing for MEC', $row->reason);
        $this->assertSame('Moneeb', $row->updated_by);

        Artisan::call('app-menu:restore', ['--by' => 'Moneeb']);

        $after = $this->menu($masjid->id)->assertOk();

        // The menu is derived from the switches, so nothing about it depended
        // on the lever: the same body, and the same tag the phones still hold.
        $this->assertSame($before->getContent(), $after->getContent());
        $this->assertSame($before->headers->get('ETag'), $after->headers->get('ETag'));
        $this->assertFalse(AppMenuSetting::row()->menu_disabled);
        $this->assertSame('donate row missing for MEC', AppMenuSetting::row()->reason);
    }

    #[Test]
    public function killing_it_twice_and_restoring_something_that_was_never_killed_are_both_fine(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        Artisan::call('app-menu:restore');
        $this->menu($masjid->id)->assertOk();

        Artisan::call('app-menu:kill', ['--reason' => 'first']);
        Artisan::call('app-menu:kill', ['--reason' => 'second']);

        $this->menu($masjid->id)->assertStatus(404);
        $this->assertSame('second', AppMenuSetting::row()->reason);
        $this->assertSame(1, AppMenuSetting::query()->count(), 'there is only ever one row');
    }

    #[Test]
    public function an_unreadable_kill_switch_reads_as_live(): void
    {
        // Between deploying the code and running the migration, this table does
        // not exist. The fleet must not lose its menu for that window — nor for
        // a database blip, nor for anything else this row cannot be read
        // through.
        $masjid = $this->listedOrg('Burlington Masjid');

        Schema::drop('app_menu_settings');
        Cache::forget(AppMenu::KILL_CACHE_KEY);

        $this->assertFalse(AppMenu::killed());
        $this->menu($masjid->id)->assertOk();
    }

    #[Test]
    public function a_payload_that_cannot_be_built_is_503_and_not_a_500(): void
    {
        // 503 is what both clients read as "menu unavailable" — keep the cached
        // menu, otherwise fall back to /features + /orgs. A 500 would be the
        // same to a phone, but this endpoint is deliberately NOT part of the
        // launch-critical chain, and saying so in the status code is how the
        // apps know to stop asking rather than show an error card.
        Log::spy();

        $masjid = $this->listedOrg('Burlington Masjid');

        // A real failure inside the build: the theme lookup every profile makes.
        Schema::drop('theme_settings');

        $response = $this->menu($masjid->id)->assertStatus(503);

        $this->assertSame('failed', $response->json('status'));
        $this->assertStringContainsString('"data":{}', $response->getContent());

        Log::shouldHaveReceived('warning')
            ->with('app menu payload build failed', \Mockery::type('array'));
    }

    #[Test]
    public function a_failed_build_is_not_cached_as_the_answer(): void
    {
        $masjid = $this->listedOrg('Burlington Masjid');

        Schema::drop('theme_settings');
        $this->menu($masjid->id)->assertStatus(503);

        $this->assertFalse(
            Cache::has(MobileCache::masjidKey($masjid->id, MobileCache::MENU)),
            'a failure must not sit in the cache for ten minutes'
        );
    }

    #[Test]
    public function the_cached_body_is_served_until_it_is_flushed(): void
    {
        // The cache is the reason an admin edit needs an explicit flush
        // (MobileCache::flushFamily, S1.4). Pinned here so the window is a
        // decision rather than a surprise.
        $masjid = $this->listedOrg('Burlington Masjid');

        $first = $this->menu($masjid->id)->assertOk();

        $this->switchOff($masjid, ['quran']);

        $this->assertSame($first->getContent(), $this->menu($masjid->id)->assertOk()->getContent());

        Cache::forget(MobileCache::masjidKey($masjid->id, MobileCache::MENU));

        $after = $this->menu($masjid->id)->assertOk();
        $this->assertNotSame($first->getContent(), $after->getContent());
        $this->assertNotContains('quran', $this->itemKeys($after->json('data.profiles.0')));
    }

    // ------------------------------------------- the tag covers the WHOLE body

    #[Test]
    public function the_tag_is_a_hash_of_everything_else_in_the_body(): void
    {
        // The one assertion that cannot go stale. AppMenu::payload() used to
        // build an array to hash and then re-list the same keys by hand in what
        // it returned, so the two could drift: a field returned but NOT hashed
        // would change while the tag stood still, and every phone holding the
        // old tag would be answered 304 and keep the stale value FOREVER.
        //
        // Enumerating the keys here would drift the same way. Recomputing the
        // hash over the served body instead means any future key is covered by
        // this test the moment it is added, or this test fails.
        $home = $this->listedOrg('Muslim Education Center', ['crm_enabled' => true]);
        $this->brand($home, '#01B151');
        $child = $this->listedOrg('Al-Razi School', ['org_type' => 'school']);
        $child->forceFill(['parent_id' => $home->id])->save();
        MobileCache::flushFamilyById((int) $child->id);

        $response = $this->menu($home->id)->assertOk();
        $body = $response->json('data');

        $this->assertNotEmpty($body['account']);
        $this->assertCount(2, $body['profiles'], 'a body with something in every branch');

        $served = $body['hash'];
        unset($body['hash']);

        $this->assertSame(
            sha1(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            $served,
            'every key the body serves must be inside the hash that becomes its ETag'
        );
        $this->assertSame('"' . $served . '"', $response->headers->get('ETag'));
    }

    #[Test]
    public function turning_the_member_realm_off_moves_the_tag(): void
    {
        // `account.sign_in_available` lives in the hash or it does not. If it
        // does not: a SuperAdmin turns the member realm off, the entry
        // rebuilds with sign_in_available false, the tag is unchanged, and
        // every phone that already holds it is answered 304 and keeps drawing a
        // Sign in row that leads nowhere — permanently, and invisibly from the
        // server.
        $home = $this->listedOrg('Muslim Education Center', ['crm_enabled' => true]);

        $before = $this->menu($home->id)->assertOk();
        $this->assertTrue($before->json('data.account.sign_in_available'));

        $home->forceFill(['crm_enabled' => false])->save();
        MobileCache::flushFamily($home->fresh());

        $after = $this->menu($home->id)->assertOk();

        $this->assertFalse($after->json('data.account.sign_in_available'));
        $this->assertNotSame(
            $before->headers->get('ETag'),
            $after->headers->get('ETag'),
            'the account block must be inside the hash'
        );

        // ...and the old tag no longer satisfies a conditional request.
        $this->menu($home->id, ['If-None-Match' => $before->headers->get('ETag')])
            ->assertOk()
            ->assertJsonPath('data.account.sign_in_available', false);
    }

    #[Test]
    public function a_new_brand_colour_moves_the_tag(): void
    {
        // Same shape for `profiles[].theme`. A brand colour that changes
        // without moving the tag is a band that never repaints on any device
        // that already fetched the menu.
        $home = $this->listedOrg('Burlington Masjid');
        $this->brand($home, '#01B151');

        $before = $this->menu($home->id)->assertOk();
        $this->assertSame('#01B151', $before->json('data.profiles.0.theme.primary'));

        $this->brand($home, '#2B66C2');
        MobileCache::flushFamily($home->fresh());

        $after = $this->menu($home->id)->assertOk();

        $this->assertSame('#2B66C2', $after->json('data.profiles.0.theme.primary'));
        $this->assertNotSame(
            $before->headers->get('ETag'),
            $after->headers->get('ETag'),
            'the theme must be inside the hash'
        );
    }
}
