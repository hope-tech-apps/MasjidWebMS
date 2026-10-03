<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\TvConfigController;
use App\Models\DonationLink;
use App\Models\Masjid;
use App\Models\MasjidTvSetting;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\MobileCache;
use App\Support\TenantContext;
use App\Support\TvBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE "TV DISPLAY" PAGE (owner, 2026-10-03): an organisation chooses six things
 * about its lobby TV board, which until now were constants nobody could change.
 *
 * Three promises are pinned here, each one a way this could ship broken with
 * every other test green:
 *
 *  1. AN ORGANISATION THAT CHOSE NOTHING GETS THE SAME BYTES AS BEFORE. No row,
 *     a row of nulls, and "opened the page and pressed Save" all serve the body
 *     recorded in tests/fixtures/tv-config-snapshot.json, byte for byte.
 *  2. WHATEVER IS STORED, THE BOARD CAN DECODE THE ANSWER. The tvOS decoder is
 *     strict and its failure is silent: one wrong type and the screen keeps its
 *     old settings while the server logs a 200. So the RAW public body is
 *     checked, including for a row edited by hand into nonsense.
 *  3. ONE ORGANISATION NEVER GETS ANOTHER'S SETTINGS. The public route binds no
 *     tenant, and the hourly tenancy canary cannot see a leak inside tv-config
 *     (the body carries no id), so only these tests would notice.
 *
 * Also: a save reaches the board inside the cache window, a donation link edit
 * does too, the deploy window answers the defaults without keeping them, and
 * the page's "on the screen now" is the same computation the board is sent.
 */
class TvDisplaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__ . '/../fixtures/tv-config-snapshot.json';

    /** tv-config's keys, in the order the endpoint has always sent them. */
    private const KEYS = [
        'is_enabled', 'header_title', 'carousel_interval_seconds', 'show_prayer_panel', 'show_qr',
        'donate_url', 'donate_caption', 'announcement_selection', 'announcement_ids', 'theme',
    ];

    private Masjid $masjid;

    private Masjid $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Cache::flush();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeOrg('masjid', 'Burlington Test Masjid');
        $this->giveLink($this->masjid, 'https://example.org/give');
        $this->other = $this->makeOrg('masjid', 'Other Test Masjid');
        $this->giveLink($this->other, 'https://example.org/other');

        $this->admin = $this->adminFor($this->masjid);
        $this->adminFor($this->other);
    }

    // ============================================================ byte identity

    #[Test]
    public function an_organisation_that_chose_nothing_serves_the_recorded_bytes_with_or_without_a_row(): void
    {
        $snapshot = $this->snapshot();

        foreach ($this->snapshotCases() as $name => $org) {
            $this->assertSame($snapshot[$name], $this->rawBoard($org), "{$name}: no row");

            // A row whose every setting is null is the same as no row.
            $row = new MasjidTvSetting();
            $row->masjid_id = $org->id;
            $row->save();
            $this->forgetBoard($org);

            $this->assertSame($snapshot[$name], $this->rawBoard($org), "{$name}: a row of nulls");
        }
    }

    #[Test]
    public function opening_the_page_and_saving_what_it_showed_changes_nothing_on_the_board(): void
    {
        $snapshot = $this->snapshot();

        foreach ($this->snapshotCases() as $name => $org) {
            Sanctum::actingAs($this->adminFor($org));
            $this->forgetTenant();

            $shown = $this->getJson($this->url($org))->assertOk()->json('data.settings');
            $this->assertSame(array_fill_keys(TvBoard::SETTINGS, null), $shown, "{$name}: nothing is stored before the first save");

            $this->postJson($this->url($org), $shown)->assertOk();

            $this->assertSame($snapshot[$name], $this->rawBoard($org), "{$name}: saved exactly what the page showed");
        }
    }

    #[Test]
    public function a_switch_left_on_is_stored_as_not_chosen_so_the_derived_values_keep_following_the_organisation(): void
    {
        // The school has no donation link and is not a masjid: both values are derived OFF.
        $school = $this->makeOrg('school', 'A School');
        Sanctum::actingAs($this->adminFor($school));

        $this->postJson($this->url($school), ['is_enabled' => true, 'show_prayer_panel' => true, 'show_qr' => true])
            ->assertOk()
            ->assertJsonPath('data.settings.is_enabled', null)
            ->assertJsonPath('data.settings.show_prayer_panel', null)
            ->assertJsonPath('data.settings.show_qr', null)
            // ON cannot force what the organisation is not.
            ->assertJsonPath('data.effective.show_prayer_panel', false)
            ->assertJsonPath('data.effective.show_qr', false);

        $this->assertDatabaseHas('masjid_tv_settings', [
            'masjid_id' => $school->id, 'is_enabled' => null, 'show_prayer_panel' => null, 'show_qr' => null,
        ]);

        // A donation link added LATER brings the code with it: nothing was frozen by that save.
        $this->giveLink($school, 'https://example.org/school');
        $this->forgetBoard($school);

        $this->assertTrue($this->board($school)['show_qr']);
        $this->assertFalse($this->board($school)['show_prayer_panel']);
    }

    // ================================================= what the board is sent

    #[Test]
    public function each_setting_reaches_the_board_in_the_type_the_decoder_requires(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), [
            'is_enabled' => false,
            'header_title' => 'Welcome to the masjid',
            'carousel_interval_seconds' => 20,
            'show_prayer_panel' => false,
            'show_qr' => false,
            'donate_caption' => 'Give here',
        ])->assertOk();

        $this->assertSame([
            'is_enabled' => false,
            'header_title' => 'Welcome to the masjid',
            'carousel_interval_seconds' => 20,
            'show_prayer_panel' => false,
            'show_qr' => false,
            // The link is still sent: hiding the code is the switch, not a missing URL.
            'donate_url' => 'https://example.org/give',
            'donate_caption' => 'Give here',
            'announcement_selection' => TvConfigController::ANNOUNCEMENT_SELECTION,
            'announcement_ids' => null,
            'theme' => TvConfigController::THEME,
        ], $this->board($this->masjid));
    }

    #[Test]
    public function the_raw_body_is_decodable_whatever_the_row_holds(): void
    {
        $states = [
            'no row' => null,
            'a row of nulls' => [],
            'every setting chosen' => [
                'is_enabled' => 0, 'header_title' => 'Friday', 'carousel_interval_seconds' => 45,
                'show_prayer_panel' => 0, 'show_qr' => 0, 'donate_caption' => 'Support us',
            ],
            // Written past the model and the request, as a hand edit or an old row would be.
            'nonsense written by hand' => [
                'is_enabled' => 2, 'header_title' => "   \n  ", 'carousel_interval_seconds' => 'abc',
                'show_prayer_panel' => 2, 'show_qr' => 2, 'donate_caption' => '',
            ],
            'out of range written by hand' => [
                'is_enabled' => 1, 'header_title' => str_repeat('T', 300), 'carousel_interval_seconds' => 0,
                'show_prayer_panel' => 1, 'show_qr' => 1, 'donate_caption' => str_repeat('C', 300),
            ],
            'too slow written by hand' => ['carousel_interval_seconds' => 999],
        ];

        foreach ($states as $name => $row) {
            DB::table('masjid_tv_settings')->where('masjid_id', $this->masjid->id)->delete();
            if ($row !== null) {
                DB::table('masjid_tv_settings')->insert(['masjid_id' => $this->masjid->id] + $row);
            }
            $this->forgetBoard($this->masjid);

            $raw = $this->rawBoard($this->masjid);
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['data'];

            // The ten keys, in order, and nothing else: no id, no masjid_id, no timestamps.
            $this->assertSame(self::KEYS, array_keys($data), $name);

            // The decoder's seven non-optional keys, each in its own JSON type.
            foreach (['is_enabled', 'show_prayer_panel', 'show_qr'] as $flag) {
                $this->assertIsBool($data[$flag], "{$name}: {$flag}");
                $this->assertMatchesRegularExpression('/"' . $flag . '":(true|false)[,}]/', $raw, "{$name}: {$flag} on the wire");
            }
            $this->assertIsInt($data['carousel_interval_seconds'], $name);
            $this->assertGreaterThanOrEqual(TvBoard::CAROUSEL_INTERVAL_MIN, $data['carousel_interval_seconds'], $name);
            $this->assertLessThanOrEqual(TvBoard::CAROUSEL_INTERVAL_MAX, $data['carousel_interval_seconds'], $name);
            $this->assertIsString($data['donate_caption'], $name);
            $this->assertNotSame('', trim($data['donate_caption']), "{$name}: an empty caption is not a caption");
            $this->assertLessThanOrEqual(TvBoard::DONATE_CAPTION_MAX, mb_strlen($data['donate_caption']), $name);

            // The title: null (the board shows the organisation's name) or real text.
            // An EMPTY string would hide the header.
            if ($data['header_title'] !== null) {
                $this->assertIsString($data['header_title'], $name);
                $this->assertNotSame('', trim($data['header_title']), $name);
                $this->assertLessThanOrEqual(TvBoard::HEADER_TITLE_MAX, mb_strlen($data['header_title']), $name);
                $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $data['header_title'], $name);
            }
        }

        // And nonsense resolves to what an organisation that chose nothing gets.
        DB::table('masjid_tv_settings')->where('masjid_id', $this->masjid->id)->delete();
        DB::table('masjid_tv_settings')->insert([
            'masjid_id' => $this->masjid->id, 'header_title' => '  ', 'carousel_interval_seconds' => 'abc', 'donate_caption' => '',
        ]);
        $this->forgetBoard($this->masjid);

        $this->assertNull($this->board($this->masjid)['header_title']);
        $this->assertSame(TvConfigController::CAROUSEL_INTERVAL_SECONDS, $this->board($this->masjid)['carousel_interval_seconds']);
        $this->assertSame(TvConfigController::DONATE_CAPTION, $this->board($this->masjid)['donate_caption']);
    }

    #[Test]
    public function text_in_any_script_reaches_the_board_as_it_was_typed(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['مسجد برلنغتون يرحب بكم', '<script>alert(1)</script> & "quotes"'] as $title) {
            $this->postJson($this->url(), ['header_title' => $title])->assertOk();

            $response = $this->getJson("/api/mobile/masjids/{$this->masjid->id}/tv-config")->assertOk();

            // Valid JSON that decodes back to exactly the typed text.
            $this->assertSame($title, json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['header_title']);
        }
    }

    #[Test]
    public function the_two_derived_switches_can_hide_and_cannot_force(): void
    {
        Sanctum::actingAs($this->admin);

        // A masjid with a link: both shown until turned off.
        $this->assertTrue($this->board($this->masjid)['show_prayer_panel']);
        $this->assertTrue($this->board($this->masjid)['show_qr']);

        $this->postJson($this->url(), ['show_prayer_panel' => false, 'show_qr' => false])->assertOk();
        $this->assertFalse($this->board($this->masjid)['show_prayer_panel']);
        $this->assertFalse($this->board($this->masjid)['show_qr']);

        // Back to "not chosen": they follow the organisation again.
        $this->postJson($this->url(), ['show_prayer_panel' => null, 'show_qr' => null])->assertOk();
        $this->assertTrue($this->board($this->masjid)['show_prayer_panel']);
        $this->assertTrue($this->board($this->masjid)['show_qr']);

        // A masjid with NO link cannot show a code, whatever is posted.
        $bare = $this->makeOrg('masjid', 'No Link Masjid');
        Sanctum::actingAs($this->adminFor($bare));
        $this->postJson($this->url($bare), ['show_qr' => true])->assertOk();
        $this->assertFalse($this->board($bare)['show_qr']);
        $this->assertNull($this->board($bare)['donate_url']);
    }

    #[Test]
    public function a_stored_true_cannot_force_what_the_organisation_is_not(): void
    {
        // The page never stores `true` (a switch left on is null), so this row is a hand
        // edit or an older client. A school has no prayer times and no donation link.
        $school = $this->makeOrg('school', 'A School');
        DB::table('masjid_tv_settings')->insert(['masjid_id' => $school->id, 'show_prayer_panel' => 1, 'show_qr' => 1]);

        $board = $this->board($school);

        // Forced on, the prayer panel would sit on "Loading prayer times" for ever.
        $this->assertFalse($board['show_prayer_panel']);
        $this->assertFalse($board['show_qr']);
        $this->assertNull($board['donate_url']);
    }

    // ============================================================ the cache

    #[Test]
    public function a_save_reaches_the_board_inside_the_cache_window(): void
    {
        // The board has asked, so the answer is cached for five minutes.
        $this->assertNull($this->board($this->masjid)['header_title']);
        $this->assertTrue(Cache::has(MobileCache::masjidKey($this->masjid->id, MobileCache::TV_CONFIG)));

        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['header_title' => 'Eid Mubarak'])->assertOk();

        // No Cache::flush() here: the save itself must have dropped the entry.
        $this->assertSame('Eid Mubarak', $this->board($this->masjid)['header_title']);
    }

    #[Test]
    public function a_donation_link_edit_reaches_the_board_inside_the_cache_window(): void
    {
        $this->assertSame('https://example.org/give', $this->board($this->masjid)['donate_url']);

        Sanctum::actingAs($this->admin);
        $this->post("/api/admin/masjids/{$this->masjid->id}/donation-link", [
            'link' => 'https://example.org/new-giving-page',
            'title' => 'Give',
            'message' => 'Give today',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('https://example.org/new-giving-page', $this->board($this->masjid)['donate_url']);
    }

    // ============================================================ isolation

    #[Test]
    public function the_board_never_gets_another_organisations_settings(): void
    {
        $snapshotLike = $this->rawBoard($this->masjid);

        // Only the OTHER organisation has a row. The public route binds no tenant, so a
        // lookup without its own masjid_id filter would hand this row to everyone.
        $theirs = new MasjidTvSetting(['header_title' => 'Other Org Only', 'is_enabled' => false, 'carousel_interval_seconds' => 99]);
        $theirs->masjid_id = $this->other->id;
        $theirs->save();
        Cache::flush();

        $this->assertSame($snapshotLike, $this->rawBoard($this->masjid), 'an organisation with no row answers as before');
        $this->assertSame('Other Org Only', $this->board($this->other)['header_title']);

        // Both have rows: each board carries its own.
        $ours = new MasjidTvSetting(['header_title' => 'Ours']);
        $ours->masjid_id = $this->masjid->id;
        $ours->save();
        Cache::flush();

        $this->assertSame('Ours', $this->board($this->masjid)['header_title']);
        $this->assertTrue($this->board($this->masjid)['is_enabled']);
        $this->assertSame('Other Org Only', $this->board($this->other)['header_title']);
        $this->assertFalse($this->board($this->other)['is_enabled']);

        // And the first organisation created is not special: the other order.
        MasjidTvSetting::withoutMasjidScope()->whereKey($theirs->id)->delete();
        Cache::flush();

        $this->assertNull($this->board($this->other)['header_title']);
        $this->assertSame('Ours', $this->board($this->masjid)['header_title']);
    }

    #[Test]
    public function another_organisations_tv_settings_are_refused_invisible_and_untouched(): void
    {
        $theirs = new MasjidTvSetting(['header_title' => 'Other Org Only']);
        $theirs->masjid_id = $this->other->id;
        $theirs->save();

        Sanctum::actingAs($this->admin);

        // Naming the other organisation in the route: refused by the tenant middleware.
        $this->getJson($this->url($this->other))->assertForbidden();
        $this->postJson($this->url($this->other), ['header_title' => 'Taken over'])->assertForbidden();
        $this->forgetTenant();

        // Our own page neither shows nor changes their row, and our save is stamped ours.
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.settings.header_title', null);
        $this->postJson($this->url(), ['header_title' => 'Ours', 'masjid_id' => $this->other->id])->assertOk();

        $this->assertDatabaseHas('masjid_tv_settings', ['id' => $theirs->id, 'masjid_id' => $this->other->id, 'header_title' => 'Other Org Only']);
        $this->assertDatabaseHas('masjid_tv_settings', ['masjid_id' => $this->masjid->id, 'header_title' => 'Ours']);
        $this->assertDatabaseMissing('masjid_tv_settings', ['masjid_id' => $this->other->id, 'header_title' => 'Ours']);
        $this->assertSame(2, MasjidTvSetting::withoutMasjidScope()->count());

        // The model layer, with our tenant bound: their row does not exist.
        app(TenantContext::class)->set($this->masjid->id);
        $this->assertNull(MasjidTvSetting::find($theirs->id));
        $this->assertSame(1, MasjidTvSetting::query()->count());
        $this->assertSame(0, MasjidTvSetting::query()->whereKey($theirs->id)->update(['header_title' => 'Changed']));
        $this->assertSame(0, MasjidTvSetting::query()->whereKey($theirs->id)->delete());
        $this->forgetTenant();

        $this->assertDatabaseHas('masjid_tv_settings', ['id' => $theirs->id, 'header_title' => 'Other Org Only']);
    }

    #[Test]
    public function a_super_admin_reads_and_saves_the_organisation_named_in_the_route_and_only_that_one(): void
    {
        // BOTH organisations have a row, so "the first row" and "the route's row" are different things.
        foreach ([[$this->masjid, 'Ours'], [$this->other, 'Other Org Only']] as [$org, $title]) {
            $row = new MasjidTvSetting(['header_title' => $title]);
            $row->masjid_id = $org->id;
            $row->save();
        }

        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        Sanctum::actingAs($super);

        $this->getJson($this->url($this->other))->assertOk()
            ->assertJsonPath('data.settings.header_title', 'Other Org Only')
            ->assertJsonPath('data.context.organisation_name', 'Other Test Masjid');

        $this->forgetTenant();
        $this->postJson($this->url($this->other), ['header_title' => 'Set by the platform'])->assertOk();

        $this->assertDatabaseHas('masjid_tv_settings', [
            'masjid_id' => $this->other->id, 'header_title' => 'Set by the platform', 'updated_by_user_id' => $super->id,
        ]);
        $this->assertDatabaseHas('masjid_tv_settings', ['masjid_id' => $this->masjid->id, 'header_title' => 'Ours']);
        $this->assertSame(2, MasjidTvSetting::withoutMasjidScope()->count());
    }

    #[Test]
    public function only_an_administrator_of_the_organisation_reaches_the_page(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->postJson($this->url(), ['header_title' => 'Anonymous'])->assertUnauthorized();

        foreach (['Teacher' => 'teacher', User::TYPE_LUNCH_STAFF => 'lunch-staff'] as $type => $role) {
            $staff = User::factory()->create(['type' => $type, 'phone' => '+1' . random_int(1000000000, 9999999999)]);
            MasjidUser::create(['masjid_id' => $this->masjid->id, 'user_id' => $staff->id, 'role' => $role, 'is_default' => true]);

            Auth::forgetGuards();
            $this->forgetTenant();
            Sanctum::actingAs($staff);

            // Refused by the ADMIN gate, as a signed-in member of staff: its own body, which an
            // unauthenticated 401 does not carry. Without this the test could not tell a staff
            // login that was turned away from a request that never signed in.
            $this->getJson($this->url())->assertUnauthorized()->assertJsonPath('data', 'Unauthorized.');
            $this->postJson($this->url(), ['header_title' => "Set by a {$type}"])->assertUnauthorized()->assertJsonPath('data', 'Unauthorized.');
        }

        $this->assertSame(0, MasjidTvSetting::withoutMasjidScope()->count());
        $this->assertSame(8, Permission::count(), 'the page mints no permission');
    }

    // ============================================================ the admin API

    #[Test]
    public function the_page_is_told_what_is_stored_what_the_board_gets_and_why(): void
    {
        Sanctum::actingAs($this->admin);

        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('status', 'success')->json('data');

        $this->assertSame(array_fill_keys(TvBoard::SETTINGS, null), $data['settings']);
        $this->assertSame([
            'is_enabled' => true,
            'header_title' => null,
            'carousel_interval_seconds' => 10,
            'show_prayer_panel' => true,
            'show_qr' => true,
            'donate_caption' => 'Scan to Donate',
        ], $data['effective']);
        $this->assertSame([
            'organisation_name' => 'Burlington Test Masjid',
            'is_masjid' => true,
            'has_donation_link' => true,
            'defaults' => ['carousel_interval_seconds' => 10, 'donate_caption' => 'Scan to Donate'],
            'limits' => ['header_title_max' => 60, 'donate_caption_max' => 40, 'carousel_interval_min' => 3, 'carousel_interval_max' => 120],
            'updated_at' => null,
        ], $data['context']);

        // A school with no link is told why its two derived values are off.
        $school = $this->makeOrg('school', 'A School');
        Sanctum::actingAs($this->adminFor($school));
        $this->forgetTenant();

        $this->getJson($this->url($school))->assertOk()
            ->assertJsonPath('data.context.is_masjid', false)
            ->assertJsonPath('data.context.has_donation_link', false)
            ->assertJsonPath('data.effective.show_prayer_panel', false)
            ->assertJsonPath('data.effective.show_qr', false);
    }

    #[Test]
    public function on_the_screen_now_is_exactly_what_the_board_is_sent(): void
    {
        Sanctum::actingAs($this->admin);

        $bodies = [
            [],
            ['is_enabled' => false, 'header_title' => 'Paused for cleaning'],
            ['carousel_interval_seconds' => 30, 'show_qr' => false, 'donate_caption' => 'Give'],
            ['is_enabled' => null, 'header_title' => null, 'show_prayer_panel' => false],
        ];

        foreach ($bodies as $body) {
            $effective = $this->postJson($this->url(), $body)->assertOk()->json('data.effective');
            $sent = $this->board($this->masjid);

            $this->assertSame(array_intersect_key($sent, $effective), $effective, json_encode($body));
            $this->assertSame(TvBoard::SETTINGS, array_keys($effective));
        }
    }

    #[Test]
    public function a_save_round_trips_and_an_organisation_has_one_row(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), ['header_title' => '  Welcome  ', 'carousel_interval_seconds' => 15])
            ->assertOk()
            ->assertJsonPath('data.settings.header_title', 'Welcome')
            ->assertJsonPath('data.settings.carousel_interval_seconds', 15)
            ->assertJsonPath('data.effective.header_title', 'Welcome');

        $this->postJson($this->url(), ['donate_caption' => 'Support the masjid'])->assertOk();

        $data = $this->getJson($this->url())->assertOk()->json('data');
        $this->assertSame('Welcome', $data['settings']['header_title']);
        $this->assertSame(15, $data['settings']['carousel_interval_seconds']);
        $this->assertSame('Support the masjid', $data['settings']['donate_caption']);
        $this->assertNotNull($data['context']['updated_at']);

        $this->assertSame(1, MasjidTvSetting::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->count());
        $this->assertSame($this->admin->id, (int) MasjidTvSetting::withoutMasjidScope()->value('updated_by_user_id'));
    }

    #[Test]
    public function a_key_that_is_not_sent_is_left_alone_and_null_means_not_chosen(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url(), ['is_enabled' => false, 'header_title' => 'Friday', 'carousel_interval_seconds' => 25, 'donate_caption' => 'Give'])->assertOk();

        // A partial save: the other three stay.
        $this->postJson($this->url(), ['carousel_interval_seconds' => 40])
            ->assertOk()
            ->assertJsonPath('data.settings.is_enabled', false)
            ->assertJsonPath('data.settings.header_title', 'Friday')
            ->assertJsonPath('data.settings.carousel_interval_seconds', 40)
            ->assertJsonPath('data.settings.donate_caption', 'Give');

        // Null (and a blank box) puts a setting back to "not chosen".
        $this->postJson($this->url(), ['is_enabled' => null, 'header_title' => '   ', 'carousel_interval_seconds' => null, 'donate_caption' => ''])
            ->assertOk()
            ->assertJsonPath('data.settings', array_fill_keys(TvBoard::SETTINGS, null))
            ->assertJsonPath('data.effective.is_enabled', true)
            ->assertJsonPath('data.effective.header_title', null)
            ->assertJsonPath('data.effective.carousel_interval_seconds', 10)
            ->assertJsonPath('data.effective.donate_caption', 'Scan to Donate');
    }

    #[Test]
    public function switches_are_read_in_the_encodings_a_browser_sends_and_nonsense_is_refused_not_guessed(): void
    {
        Sanctum::actingAs($this->admin);

        // Form-encoded, as a URLSearchParams or FormData body arrives: the STRINGS "false" and "0".
        foreach (['false', '0'] as $off) {
            DB::table('masjid_tv_settings')->delete();

            $this->post($this->url(), ['is_enabled' => $off, 'show_qr' => $off], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('data.settings.is_enabled', false)
                ->assertJsonPath('data.settings.show_qr', false);
            $this->assertFalse($this->board($this->masjid)['is_enabled'], "\"{$off}\" pauses the board");
        }

        foreach (['true', '1'] as $on) {
            $this->post($this->url(), ['is_enabled' => $on, 'show_qr' => $on], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('data.settings.is_enabled', null);
            $this->assertTrue($this->board($this->masjid)['is_enabled'], "\"{$on}\" un-pauses the board");
        }

        // Nonsense must never be read as "off": that would blank a live lobby screen.
        foreach (['maybe', 'off-ish', 2, []] as $nonsense) {
            $this->postJson($this->url(), ['is_enabled' => $nonsense])
                ->assertStatus(422)
                ->assertJsonPath('status', 'failed')
                ->assertJsonStructure(['data' => ['is_enabled']]);
        }

        $this->assertTrue($this->board($this->masjid)['is_enabled']);
        $this->assertDatabaseMissing('masjid_tv_settings', ['masjid_id' => $this->masjid->id, 'is_enabled' => false]);
    }

    #[Test]
    public function the_limits_are_enforced_at_their_edges_and_a_refusal_writes_nothing(): void
    {
        Sanctum::actingAs($this->admin);

        $refused = [
            'carousel_interval_seconds' => [2, 121, 0, -5, 10.5, 'abc', '10 seconds'],
            'header_title' => [str_repeat('a', 61), "two\nlines", "tab\there", ['an', 'array']],
            'donate_caption' => [str_repeat('a', 41), "two\r\nlines"],
        ];

        foreach ($refused as $field => $values) {
            foreach ($values as $value) {
                $this->postJson($this->url(), [$field => $value, 'is_enabled' => false])
                    ->assertStatus(422)
                    ->assertJsonPath('status', 'failed')
                    ->assertJsonStructure(['data' => [$field]]);
            }
        }

        // Nothing was written by any refusal, including the valid field sent beside the bad one.
        $this->assertSame(0, MasjidTvSetting::withoutMasjidScope()->count());

        // The edges themselves are accepted. 60 Arabic letters are 60 characters, not 120 bytes.
        $this->postJson($this->url(), [
            'carousel_interval_seconds' => 3,
            'header_title' => str_repeat('م', 60),
            'donate_caption' => str_repeat('a', 40),
        ])->assertOk();
        $this->postJson($this->url(), ['carousel_interval_seconds' => 120])->assertOk();
        $this->postJson($this->url(), ['carousel_interval_seconds' => '45'])->assertOk()->assertJsonPath('data.settings.carousel_interval_seconds', 45);

        $this->assertSame(str_repeat('م', 60), $this->board($this->masjid)['header_title']);
        $this->assertSame(45, $this->board($this->masjid)['carousel_interval_seconds']);
    }

    #[Test]
    public function one_line_means_no_line_break_of_any_kind_and_nothing_that_reverses_the_text(): void
    {
        Sanctum::actingAs($this->admin);

        // Line breaks that are not ASCII controls, and the bidirectional override controls.
        $refused = [
            'a next-line character' => "Friday\u{0085}prayer",
            'a line separator' => "Friday\u{2028}prayer",
            'a paragraph separator' => "Friday\u{2029}prayer",
            'a right-to-left override' => "Friday \u{202E}reyarp",
            'a directional isolate' => "Friday \u{2067}prayer\u{2069}",
        ];

        foreach ($refused as $what => $text) {
            foreach (['header_title', 'donate_caption'] as $field) {
                $this->postJson($this->url(), [$field => $text])
                    ->assertStatus(422)
                    ->assertJsonPath("data.{$field}.0", 'Keep this to one line.');
            }
        }

        $this->assertSame(0, MasjidTvSetting::withoutMasjidScope()->count(), 'nothing was written by a refusal');

        // What Arabic, Persian and Urdu text really uses is NOT refused: the zero-width
        // non-joiner and the right-to-left mark.
        foreach (["می\u{200C}خواهم", "Jumu'ah \u{200F}الجمعة"] as $title) {
            $this->postJson($this->url(), ['header_title' => $title])->assertOk();
            $this->assertSame($title, $this->board($this->masjid)['header_title']);
        }
    }

    #[Test]
    public function text_that_is_not_valid_utf8_is_refused_in_words_not_with_an_error(): void
    {
        Sanctum::actingAs($this->admin);

        // Form-encoded, because a JSON body cannot carry these bytes at all. A /u pattern
        // FAILS on them, and a failed match reads as "no match": without its own rule this
        // text passes validation and the save dies in the database.
        $this->post($this->url(), ['header_title' => "Friday\xC3\x28prayer"], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['header_title']]);

        $this->assertSame(0, MasjidTvSetting::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_row_holding_text_the_request_would_refuse_is_cleaned_before_the_board_sees_it(): void
    {
        DB::table('masjid_tv_settings')->insert([
            'masjid_id' => $this->masjid->id,
            'header_title' => "Friday\u{2028}prayer \u{202E}reversed",
            // Only blanks, none of them ASCII: a no-break space and an ideographic space.
            'donate_caption' => "\u{00A0}\u{3000}",
        ]);

        $board = $this->board($this->masjid);

        $this->assertDoesNotMatchRegularExpression(TvBoard::NOT_ONE_LINE, $board['header_title']);
        $this->assertSame('Friday prayer  reversed', $board['header_title']);
        // A caption of blanks is no caption: the default, never an empty-looking string.
        $this->assertSame(TvConfigController::DONATE_CAPTION, $board['donate_caption']);
    }

    #[Test]
    public function a_blank_switch_is_not_chosen_even_without_the_frameworks_empty_string_middleware(): void
    {
        Sanctum::actingAs($this->admin);
        // filter_var reads '' as FALSE, and false on is_enabled pauses a lobby screen. The
        // request must not depend on a global middleware to keep a blank from meaning "off".
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class);

        $this->post($this->url(), ['is_enabled' => '', 'show_prayer_panel' => '', 'show_qr' => ''], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.settings.is_enabled', null)
            ->assertJsonPath('data.settings.show_prayer_panel', null)
            ->assertJsonPath('data.effective.is_enabled', true);

        $this->assertTrue($this->board($this->masjid)['is_enabled']);
    }

    #[Test]
    public function a_save_whose_cache_flush_fails_is_still_a_save(): void
    {
        Sanctum::actingAs($this->admin);

        Cache::partialMock()->shouldReceive('forget')->andThrow(new \RuntimeException('the cache store is away'));

        // The row is stored. Answering "Not saved" beside a board that then changes would be the worse answer.
        $this->postJson($this->url(), ['header_title' => 'Stored anyway'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.settings.header_title', 'Stored anyway');

        $this->assertDatabaseHas('masjid_tv_settings', ['masjid_id' => $this->masjid->id, 'header_title' => 'Stored anyway']);
    }

    #[Test]
    public function what_is_not_a_setting_yet_cannot_be_set(): void
    {
        Sanctum::actingAs($this->admin);

        // The light theme is unreadable on the TV build released 2026-08-07, and the
        // donation URL belongs to the Donation Link page. Posting them changes nothing.
        $this->postJson($this->url(), [
            'theme' => 'light',
            'donate_url' => 'https://evil.example.test/steal',
            'announcement_selection' => 'manual',
            'announcement_ids' => [1, 2, 3],
            'header_title' => 'Still saved',
        ])->assertOk();

        $board = $this->board($this->masjid);
        $this->assertSame('dark', $board['theme']);
        $this->assertSame('https://example.org/give', $board['donate_url']);
        $this->assertSame('all_active', $board['announcement_selection']);
        $this->assertNull($board['announcement_ids']);
        $this->assertSame('Still saved', $board['header_title']);
    }

    #[Test]
    public function an_empty_save_changes_nothing_that_was_chosen(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['header_title' => 'Friday', 'is_enabled' => false])->assertOk();

        // No key at all: every setting is left as it is, on a row that already exists.
        $this->postJson($this->url(), [])->assertOk()
            ->assertJsonPath('data.settings.header_title', 'Friday')
            ->assertJsonPath('data.settings.is_enabled', false);

        $this->assertFalse($this->board($this->masjid)['is_enabled']);
    }

    #[Test]
    public function the_derived_values_follow_a_change_of_organisation_type_under_an_existing_row(): void
    {
        $school = $this->makeOrg('school', 'Becoming A Masjid');
        Sanctum::actingAs($this->adminFor($school));
        $this->postJson($this->url($school), ['header_title' => 'Welcome'])->assertOk();
        $this->assertFalse($this->board($school)['show_prayer_panel']);

        // The organisation becomes a masjid: its row chose nothing about the panel, so the panel appears.
        DB::table('masjids')->where('id', $school->id)->update(['org_type' => 'masjid']);
        $this->forgetBoard($school);
        $this->assertTrue($this->board($school)['show_prayer_panel']);

        // And a masjid that turned its panel OFF keeps it off whatever it becomes.
        $this->forgetTenant();
        $this->postJson($this->url($school), ['show_prayer_panel' => false])->assertOk();
        DB::table('masjids')->where('id', $school->id)->update(['org_type' => 'school']);
        $this->forgetBoard($school);
        $this->assertFalse($this->board($school)['show_prayer_panel']);
        DB::table('masjids')->where('id', $school->id)->update(['org_type' => 'masjid']);
        $this->forgetBoard($school);
        $this->assertFalse($this->board($school)['show_prayer_panel']);
    }

    #[Test]
    public function text_made_only_of_control_characters_is_refused_and_a_bare_line_break_clears_the_field(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['header_title' => 'Friday'])->assertOk();

        $this->postJson($this->url(), ['header_title' => "\x01\x02"])
            ->assertStatus(422)
            ->assertJsonPath('data.header_title.0', 'Keep this to one line.');
        $this->assertSame('Friday', $this->board($this->masjid)['header_title']);

        // A lone line break is whitespace: the framework trims it to nothing, and nothing means "not chosen".
        $this->postJson($this->url(), ['header_title' => "\n"])->assertOk()->assertJsonPath('data.settings.header_title', null);
        $this->assertNull($this->board($this->masjid)['header_title']);
    }

    #[Test]
    public function two_first_saves_at_the_same_moment_both_land(): void
    {
        Sanctum::actingAs($this->admin);

        // Between this request finding no row and inserting one, another request inserts it.
        $raced = false;
        MasjidTvSetting::creating(function () use (&$raced): void {
            if (! $raced) {
                $raced = true;
                DB::table('masjid_tv_settings')->insert(['masjid_id' => $this->masjid->id, 'header_title' => 'The other save']);
            }
        });

        $this->postJson($this->url(), ['carousel_interval_seconds' => 20])
            ->assertOk()
            ->assertJsonPath('data.settings.carousel_interval_seconds', 20)
            // The other save's choice is kept: this one did not send a title.
            ->assertJsonPath('data.settings.header_title', 'The other save');

        $this->assertTrue($raced);
        $this->assertSame(1, MasjidTvSetting::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->count());
    }

    #[Test]
    public function a_save_the_database_refuses_is_said_as_a_failure_and_changes_nothing(): void
    {
        Sanctum::actingAs($this->admin);
        $before = $this->rawBoard($this->masjid);

        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'insert into "masjid_tv_settings"')) {
                throw new \Illuminate\Database\QueryException('sqlite', $query, [], new \RuntimeException('the database is away'));
            }
        });

        $this->postJson($this->url(), ['header_title' => 'Never stored'])
            ->assertStatus(500)
            ->assertJsonPath('status', 'failed');

        $this->assertSame(0, MasjidTvSetting::withoutMasjidScope()->count());
        $this->assertSame($before, $this->rawBoard($this->masjid), 'a failed save leaves the board and its cache alone');
    }

    // ============================================================ the deploy window

    #[Test]
    public function before_the_table_exists_the_board_gets_the_defaults_and_they_are_not_kept(): void
    {
        $expected = $this->rawBoard($this->masjid);
        Cache::flush();

        // The deploy has put this code in place and has not run `migrate` yet.
        Schema::drop('masjid_tv_settings');

        $this->assertSame($expected, $this->rawBoard($this->masjid), 'a board polling during the deploy gets what it always got');
        $this->assertFalse(
            Cache::has(MobileCache::masjidKey($this->masjid->id, MobileCache::TV_CONFIG)),
            'an answer built without the settings table must not be served for the next five minutes'
        );
    }

    #[Test]
    public function any_other_failure_to_read_the_settings_is_an_error_not_a_default_board(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['is_enabled' => false])->assertOk();
        Cache::flush();

        // The table is there and the read of it fails (a lock timeout, a lost connection).
        // Forced here, because SQLite cannot be made to refuse this query any other way:
        // it reads an unknown double-quoted column as a string and answers no rows.
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'select * from "masjid_tv_settings"')) {
                throw new \Illuminate\Database\QueryException('sqlite', $query, [], new \RuntimeException('the settings could not be read'));
            }
        });

        // A 500 leaves a paused board paused. Answering the defaults would un-pause it.
        $this->getJson("/api/mobile/masjids/{$this->masjid->id}/tv-config")->assertStatus(500);
        $this->assertFalse(Cache::has(MobileCache::masjidKey($this->masjid->id, MobileCache::TV_CONFIG)));
        $this->assertTrue(Schema::hasTable('masjid_tv_settings'));
    }

    // ============================================================ the schema

    #[Test]
    public function the_schema_is_what_the_code_assumes(): void
    {
        $this->assertSame('varchar', Schema::getColumnType('masjid_tv_settings', 'header_title'));
        $this->assertSame('varchar', Schema::getColumnType('masjid_tv_settings', 'donate_caption'));

        // One row per organisation: the second insert is refused by the database.
        DB::table('masjid_tv_settings')->insert(['masjid_id' => $this->masjid->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('masjid_tv_settings')->insert(['masjid_id' => $this->masjid->id]);
    }

    #[Test]
    public function deleting_an_organisation_takes_its_tv_settings_with_it(): void
    {
        $doomed = $this->makeOrg('masjid', 'Doomed Org');
        DB::table('masjid_tv_settings')->insert(['masjid_id' => $doomed->id, 'header_title' => 'Gone']);

        DB::table('masjids')->where('id', $doomed->id)->delete();

        $this->assertDatabaseMissing('masjid_tv_settings', ['masjid_id' => $doomed->id]);
    }

    // ============================================================ fixtures

    private function url(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjid)->id . '/tv-display';
    }

    /** The public body, decoded. */
    private function board(Masjid $masjid): array
    {
        return $this->getJson("/api/mobile/masjids/{$masjid->id}/tv-config")->assertOk()->json('data');
    }

    /** The public body as the board reads it: raw bytes. */
    private function rawBoard(Masjid $masjid): string
    {
        return $this->getJson("/api/mobile/masjids/{$masjid->id}/tv-config")->assertOk()->getContent();
    }

    private function forgetBoard(Masjid $masjid): void
    {
        MobileCache::flushMasjid((int) $masjid->id, MobileCache::TV_CONFIG);
    }

    private function forgetTenant(): void
    {
        app(TenantContext::class)->forgetTenant();
    }

    /** @return array<string, string> the recorded bodies, by case name */
    private function snapshot(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR)['bodies'];
    }

    /**
     * The three organisations tests/Feature/Studio/TvConfigSnapshotTest.php recorded:
     * a masjid with a donation link, a school with none, a community whose link is blank.
     *
     * @return array<string, Masjid>
     */
    private function snapshotCases(): array
    {
        $masjid = $this->makeOrg('masjid', 'Snapshot Masjid');
        DonationLink::create(['masjid_id' => $masjid->id, 'link' => 'https://example.org/give', 'title' => 'Give', 'message' => 'Give today']);

        $community = $this->makeOrg('community', 'Snapshot Community');
        DonationLink::create(['masjid_id' => $community->id, 'link' => '   ']);

        return [
            'masjid_with_donation_link' => $masjid,
            'school_without_donation_link' => $this->makeOrg('school', 'Snapshot School'),
            'community_with_blank_donation_link' => $community,
        ];
    }

    private function makeOrg(string $orgType, string $name): Masjid
    {
        return Masjid::create([
            'name' => $name,
            'org_type' => $orgType,
            'email' => 'tv-' . uniqid() . '@example.test',
            'phone' => '+1555' . random_int(1000000, 9999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);
    }

    private function giveLink(Masjid $masjid, string $link): void
    {
        DonationLink::create(['masjid_id' => $masjid->id, 'link' => $link, 'title' => 'Give', 'message' => 'Give today']);
    }

    private function adminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }
}
