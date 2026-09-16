<?php

namespace Tests\Feature;

use App\Models\AppVersionSetting;
use App\Models\MobileAppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * The phones that are ALREADY out there, asserted as bytes.
 *
 * Three builds cannot be changed by anything in this branch: the Burlington
 * iPhone store build 2.5 (44), Play vc13, and the MEC TestFlight build. They
 * were compiled against the endpoints below and will keep calling them for as
 * long as people leave them installed. S1 adds a menu they know nothing about
 * and a flag they will never read; the promise is that a member who never
 * updates sees nothing at all.
 *
 * Every other S1 suite tests something NEW. This one exists to test that
 * nothing old moved, and it is deliberately written at the level those builds
 * actually see — a status code, a key list, a literal body — rather than
 * through the helpers and derivations the new code shares. A regression here
 * is not a failing feature; it is a phone in somebody's pocket that stops
 * working, with no way to push a fix to it.
 *
 * Scope is S1's four surfaces. `GET /features`, the biggest one, is asserted
 * against the production capture in `LegacyFeaturesContractTest` at S2, when
 * there is a derivation that could break it. Today it is untouched code.
 */
class InstalledBuildsContractTest extends TestCase
{
    use RefreshDatabase;
    use MakesMenuOrganisations;

    /** The block those builds decode. Adding to this list is a client release. */
    private const APP_CONFIG_KEYS = [
        'minimum_version', 'minimum_build', 'force_update', 'update_message',
        'latest_version', 'store_url', 'maintenance_mode', 'maintenance_message',
    ];

    /** The five `/orgs` keys, in the order Org.swift and OrgsResponse.kt read them. */
    private const ORGS_KEYS = ['id', 'name', 'org_type', 'is_home', 'logo_url'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();
    }

    #[Test]
    public function the_global_app_config_answers_the_exact_body_v2_5_b44_fails_open_on(): void
    {
        // Not assertJson — the literal bytes. This endpoint has no data behind
        // it and exists solely because a 404 here once left that build sitting
        // on its splash screen: the per-masjid gate replaced it, the route was
        // served from a stale route cache, and clearing the cache exposed the
        // gap. It answers, and it answers empty.
        $response = $this->getJson('/api/mobile/app-config')->assertOk();

        $this->assertSame('{"status":"success","data":{}}', $response->getContent());
    }

    #[Test]
    public function a_masjid_with_no_app_config_rows_still_gets_an_object_not_an_array(): void
    {
        // `{}` and `[]` are the same thing in PHP and different things in Swift
        // and Kotlin. An org with no rows must read as "no config, proceed",
        // never as a list the client tries to iterate.
        $org = $this->listedOrg('Fresh Masjid');

        $response = $this->getJson("/api/mobile/masjids/{$org->id}/app-config")->assertOk();

        $this->assertSame('{"status":"success","data":{}}', $response->getContent());
    }

    #[Test]
    public function a_platform_block_carries_exactly_the_eight_keys_it_was_compiled_against(): void
    {
        // S1 adds `navigation` to this block, omitted while null — which is
        // every row on production the day of the deploy. So the body an
        // installed build receives is unchanged, and this is the assertion
        // that says so.
        $org = $this->listedOrg('Muslim Education Center');

        AppVersionSetting::create([
            'masjid_id' => $org->id,
            'platform' => 'ios',
            'minimum_version' => '2.5.0',
            'minimum_build' => 44,
            'force_update' => false,
            'maintenance_mode' => false,
        ]);

        $block = $this->getJson("/api/mobile/masjids/{$org->id}/app-config")->assertOk()->json('data.ios');

        $this->assertSame(self::APP_CONFIG_KEYS, array_keys($block));
    }

    #[Test]
    public function an_orgs_row_still_leads_with_the_five_keys_and_the_same_values(): void
    {
        // `theme` is appended in S1 (OrgsThemeAdditiveTest covers what it
        // contains). What matters to an installed build is that the five keys
        // ahead of it are untouched — iOS lists explicit CodingKeys and
        // ignores the rest, Android's Gson does the same.
        $home = $this->listedOrg('Muslim Education Center');
        $this->brand($home, '#0B5FA5');

        $child = $this->listedOrg('Intellicor Academy', ['org_type' => 'school']);
        $child->setParent($home);

        $rows = $this->getJson("/api/mobile/masjids/{$home->id}/orgs")->assertOk()->json('data');

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(self::ORGS_KEYS, array_slice(array_keys($row), 0, 5));
            $this->assertIsInt($row['id']);
            $this->assertIsString($row['name']);
            $this->assertIsString($row['org_type']);
            $this->assertIsBool($row['is_home']);
        }

        $this->assertSame([$home->id, $child->id], array_column($rows, 'id'));
        $this->assertTrue($rows[0]['is_home']);
        $this->assertFalse($rows[1]['is_home']);
    }

    #[Test]
    public function the_device_heartbeat_still_answers_the_body_every_build_decodes(): void
    {
        $org = $this->listedOrg('Muslim Education Center');

        MobileAppUser::create([
            'device_id' => 'contract-device-1',
            'masjid_id' => $org->id,
            'user_agent' => 'Manara/2.5 (iPhone; build 44)',
        ]);

        $response = $this->postJson('/api/mobile/user/heartbeat', ['device_id' => 'contract-device-1'])
            ->assertOk();

        $this->assertSame('{"status":"success","data":{"updated":true}}', $response->getContent());
    }

    #[Test]
    public function a_refusal_on_the_leave_routes_still_carries_a_data_key(): void
    {
        // The iPhone app decodes EVERY mobile body, errors included, through
        // one Response<T> whose `data` is non-optional. A refusal without the
        // key fails to decode on the device, and the member sees a generic
        // error where the server sent a sentence — with no server-side symptom
        // at all, because the server behaved correctly. This was the S1a
        // regression; MobileErrorEnvelope is what fixed it, and it is easy to
        // lose to an unrelated change in the exception handler.
        $org = $this->listedOrg('Muslim Education Center');

        foreach (["/api/mobile/masjids/{$org->id}/me", "/api/mobile/masjids/{$org->id}/me/device"] as $route) {
            $body = $this->deleteJson($route)->assertUnauthorized()->json();

            $this->assertArrayHasKey('data', $body, $route);
            $this->assertSame('error', $body['status'], $route);
        }
    }

    #[Test]
    public function nothing_in_s1_put_the_new_menu_in_front_of_an_old_build(): void
    {
        // The last line of defence for the compatibility claim: an installed
        // build asks for what it knows, and gets only what it knows. Nothing
        // the S1 work added reaches it unless it asks for /menu by name, which
        // no shipped build does.
        $org = $this->listedOrg('Muslim Education Center');

        $orgs = $this->getJson("/api/mobile/masjids/{$org->id}/orgs")->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('sections', $orgs);
        $this->assertArrayNotHasKey('tabs', $orgs);
        $this->assertArrayNotHasKey('home', $orgs);
    }

    // ------------------------------------------ registration cannot be refused

    /**
     * The shapes a shipped build could plausibly already be sending in a body
     * key named after one of the three new telemetry columns — an integer build
     * number, a value with a space, a platform nobody ships, a string past the
     * column width. None of them is a reason to refuse a launch.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unusableTelemetryBodies(): array
    {
        return [
            'an integer build number' => [['app_version' => 44]],
            'an integer build' => [['app_build' => 44]],
            'a boolean' => [['app_version' => true]],
            'an array' => [['app_version' => ['1.0']]],
            'a version with a space' => [['app_version' => '2.5 (44)']],
            'a platform nobody ships' => [['app_platform' => 'tvos']],
            'a platform in the wrong case' => [['app_platform' => 'iOS']],
            'past the column width' => [['app_version' => str_repeat('9', 300)]],
            'all three at once' => [[
                'app_platform' => 'windows',
                'app_version' => 44,
                'app_build' => str_repeat('x', 64),
            ]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableTelemetryBodies')]
    #[Test]
    public function a_first_launch_is_never_refused_over_a_telemetry_field(array $extra): void
    {
        // THE launch-critical verb. A refused registration is a new handset
        // stranded on its splash screen, and the refusal is well-formed so
        // there is no server-side symptom to find it by.
        //
        // The two registration verbs used to carry `string|max:|in:` rules for
        // these three fields. Nothing establishes that no shipped build already
        // sends a body key spelled `app_version` — it would have been ignored
        // until now — and `"app_version": 44` under a `string` rule is a 422.
        // The rules were removed; AppClientHeader::resolve() is the only gate
        // and it DROPS what it cannot use.
        $org = $this->listedOrg('Muslim Education Center');

        $deviceId = 'contract-launch-' . md5(serialize($extra));

        $this->postJson('/api/mobile/user', [
            'masjid_id' => $org->id,
            'device_id' => $deviceId,
        ] + $extra)->assertSuccessful();

        $user = MobileAppUser::where('device_id', $deviceId)->firstOrFail();

        // Dropped, not written and not truncated — the column must never see a
        // value it cannot hold (MySQL 1406; SQLite would take it silently).
        foreach (['app_platform' => 10, 'app_version' => 20, 'app_build' => 20] as $column => $width) {
            if (! array_key_exists($column, $extra)) {
                continue;
            }

            $stored = $user->{$column};

            $this->assertNull($stored, "{$column} must be dropped, never coerced or truncated");
            $this->assertLessThanOrEqual($width, strlen((string) $stored));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableTelemetryBodies')]
    #[Test]
    public function re_pointing_an_install_is_never_refused_over_a_telemetry_field(array $extra): void
    {
        // The same rule on PUT /user, which runs on every organisation switch.
        $org = $this->listedOrg('Muslim Education Center');
        $other = $this->listedOrg('Al-Razi School', ['org_type' => 'school']);

        MobileAppUser::create([
            'device_id' => 'contract-repoint-1',
            'masjid_id' => $org->id,
            'user_agent' => 'Manara/2.5 (iPhone; build 44)',
        ]);

        $this->putJson('/api/mobile/user', [
            'masjid_id' => $other->id,
            'device_id' => 'contract-repoint-1',
        ] + $extra)->assertSuccessful();
    }

    #[Test]
    public function a_usable_telemetry_body_is_still_recorded(): void
    {
        // Removing the rules must not have removed the reading. This is the
        // evidence S3b turns on.
        $org = $this->listedOrg('Muslim Education Center');

        $this->postJson('/api/mobile/user', [
            'masjid_id' => $org->id,
            'device_id' => 'contract-telemetry-ok',
            'app_platform' => 'ios',
            'app_version' => '1.0',
            'app_build' => '47',
        ])->assertSuccessful();

        $user = MobileAppUser::where('device_id', 'contract-telemetry-ok')->firstOrFail();

        $this->assertSame('ios', $user->app_platform);
        $this->assertSame('1.0', $user->app_version);
        $this->assertSame('47', $user->app_build);
    }

    #[Test]
    public function registration_still_refuses_what_it_actually_needs(): void
    {
        // The other half: dropping the telemetry rules must not have dropped
        // the two the handler cannot work without.
        $this->postJson('/api/mobile/user', ['device_id' => 'contract-missing-org'])
            ->assertStatus(422);

        $org = $this->listedOrg('Muslim Education Center');

        $this->postJson('/api/mobile/user', ['masjid_id' => $org->id])
            ->assertStatus(422);
    }
}
