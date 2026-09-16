<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Support\AppClientHeader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * Build telemetry: what the three device verbs record, and what they must never
 * erase.
 *
 * The retirement of the legacy `/features` list turns on one number — how many
 * handsets are still running a build that reads it — and that number is only
 * worth anything if it can move in one direction for one reason. So most of
 * this file is about the ways the reading could quietly become wrong:
 *
 *   - a pre-R1 build calling the heartbeat NULLING what an R1 build recorded,
 *     which would make "everybody has updated" true one stale reinstall at a
 *     time, with no error anywhere;
 *   - a body value longer than the column, which SQLite stores happily and
 *     MySQL refuses with a 1406 in production only;
 *   - a malformed or hostile header being stored instead of ignored;
 *   - and the heartbeat being REFUSED over telemetry, which would leave the
 *     prayer backstop treating a live phone as dark.
 *
 * What it does not cover: that any real build actually sends the header. That
 * is a client-lane fact, checked on a real device (plan v3, D-checks), and no
 * assertion here should be read as covering it.
 */
class HeartbeatBuildTelemetryTest extends TestCase
{
    use RefreshDatabase;
    use MakesMenuOrganisations;

    private Masjid $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();

        $this->org = $this->listedOrg('Muslim Education Center');
    }

    // ------------------------------------------------------------- the columns

    #[Test]
    public function the_three_columns_are_nullable_strings(): void
    {
        foreach (['app_platform', 'app_version', 'app_build'] as $column) {
            $this->assertSame('varchar', Schema::getColumnType('mobile_app_users', $column), $column);

            $this->assertTrue(
                collect(Schema::getColumns('mobile_app_users'))->firstWhere('name', $column)['nullable'],
                "{$column} must be nullable: NULL is the pre-R1 reading, not a gap"
            );
        }
    }

    /**
     * SQLite enforces no VARCHAR length at all, so the widths MySQL will be
     * given are read off the migration's own Blueprint. Without this the suite
     * cannot tell a varchar(20) from a varchar(2000), and the first time it
     * matters is on the production box (memory: sqlite-hides-mysql-column-limits).
     */
    #[Test]
    public function the_migration_declares_the_widths_mysql_will_enforce(): void
    {
        $migration = require database_path('migrations/2026_09_17_000200_add_app_build_to_mobile_app_users_table.php');

        $connection = DB::connection();
        $connection->useDefaultSchemaGrammar();
        $blueprint = new Blueprint($connection, 'mobile_app_users');

        Schema::shouldReceive('table')
            ->once()
            ->with('mobile_app_users', Mockery::type(\Closure::class))
            ->andReturnUsing(fn (string $table, \Closure $callback) => $callback($blueprint));

        $migration->up();

        $columns = collect($blueprint->getAddedColumns())->keyBy('name');

        $this->assertSame(
            ['app_platform', 'app_version', 'app_build'],
            $columns->keys()->all(),
            'three columns, in the order the migration declares them'
        );

        foreach (['app_platform' => 10, 'app_version' => 20, 'app_build' => 20] as $name => $length) {
            $this->assertSame('string', $columns[$name]->type, $name);
            $this->assertSame($length, $columns[$name]->length, $name);
            $this->assertTrue($columns[$name]->nullable, $name);
        }

        $this->assertSame([], array_values(array_filter(
            $blueprint->getCommands(),
            fn ($command) => in_array($command->name, ['index', 'unique', 'foreign'], true)
        )), 'plain columns: nothing looks a device up by its build');
    }

    /**
     * The caps this class enforces must BE the column widths, not numbers near
     * them, and they are now the only thing standing between a hostile body and
     * a varchar — no verb validates these three fields any more.
     */
    #[Test]
    public function the_enforced_caps_are_the_column_widths(): void
    {
        // The same three numbers the migration declares, asserted one test
        // above against the Blueprint. If a later migration widens a column,
        // both tests have to be edited together — which is the point.
        $this->assertSame(
            ['app_platform' => 10, 'app_version' => 20, 'app_build' => 20],
            AppClientHeader::limits()
        );
    }

    /**
     * No device verb may refuse a request over these three fields.
     *
     * Asserted against the FormRequests themselves rather than by firing
     * requests, so it fails the moment somebody adds a rule back, whatever
     * shape of value would have tripped it. The behavioural half —
     * registration and re-point surviving every unusable body — is in
     * InstalledBuildsContractTest.
     */
    #[Test]
    public function no_device_verb_validates_the_telemetry_fields(): void
    {
        $requests = [
            \App\Http\Requests\Mobile\Users\StoreMobileAppUserRequest::class,
            \App\Http\Requests\Mobile\Users\UpdateMobileAppUserRequest::class,
        ];

        foreach ($requests as $class) {
            $rules = (new $class)->rules();

            foreach (array_keys(AppClientHeader::limits()) as $field) {
                $this->assertArrayNotHasKey(
                    $field,
                    $rules,
                    "{$class} must not be able to 422 over {$field}: a telemetry field nobody "
                    . 'authorises on must never stop a phone from registering'
                );
            }

            // ...and it still requires the two the handler cannot work without.
            $this->assertArrayHasKey('masjid_id', $rules, $class);
            $this->assertArrayHasKey('device_id', $rules, $class);
        }
    }

    // -------------------------------------------------------------- the parser

    #[Test]
    public function the_header_parses_only_the_exact_shape_the_apps_send(): void
    {
        $this->assertSame(
            ['app_platform' => 'ios', 'app_version' => '1.0', 'app_build' => '47'],
            AppClientHeader::parseValue('ios/1.0/47')
        );

        $this->assertSame(
            ['app_platform' => 'android', 'app_version' => '1.0.0', 'app_build' => '14'],
            AppClientHeader::parseValue('android/1.0.0/14')
        );

        // A proxy adding whitespace is not a malformed client.
        $this->assertSame(
            ['app_platform' => 'ios', 'app_version' => '2026.09.1-rc1', 'app_build' => '480'],
            AppClientHeader::parseValue('  ios/2026.09.1-rc1/480  ')
        );
    }

    #[Test]
    public function anything_else_in_the_header_is_ignored_rather_than_stored(): void
    {
        $refused = [
            null,
            '',
            'ios',
            'ios/1.0',
            'ios/1.0/47/extra',
            'IOS/1.0/47',                       // not a spelling this app ships
            'web/1.0/47',                       // not a platform with a device row
            'ios/1.0/' . str_repeat('9', 21),   // one character past the column
            'ios/' . str_repeat('1', 21) . '/47',
            "ios/1.0/47\nX-Injected: 1",
            'ios/1.0/47; DROP TABLE mobile_app_users',
            'ios/1 0/47',
        ];

        foreach ($refused as $value) {
            $this->assertNull(AppClientHeader::parseValue($value), var_export($value, true));
        }
    }

    #[Test]
    public function the_body_wins_over_the_header_and_an_unusable_body_value_is_dropped(): void
    {
        // Body wins: it is what the app chose to say about itself.
        $this->assertSame(
            ['app_platform' => 'android', 'app_version' => '9.9', 'app_build' => '99'],
            AppClientHeader::resolve($this->requestWith(
                ['app_platform' => 'android', 'app_version' => '9.9', 'app_build' => '99'],
                ['app_platform' => 'ios', 'app_version' => '1.0', 'app_build' => '47']
            ))
        );

        // A body value the column cannot hold is dropped, and does NOT fall
        // through to the header — the record would otherwise mix two claims
        // about one handset.
        $resolved = AppClientHeader::resolve($this->requestWith(
            ['app_version' => str_repeat('9', 21)],
            ['app_platform' => 'ios', 'app_version' => '1.0', 'app_build' => '47']
        ));

        $this->assertArrayNotHasKey('app_version', $resolved);
        $this->assertSame('ios', $resolved['app_platform']);
        $this->assertSame('47', $resolved['app_build']);

        // A platform nobody ships is dropped the same way.
        $this->assertArrayNotHasKey(
            'app_platform',
            AppClientHeader::resolve($this->requestWith(['app_platform' => 'windows']))
        );

        // Nothing at all resolves to nothing at all — not three nulls.
        $this->assertSame([], AppClientHeader::resolve($this->requestWith()));
    }

    // -------------------------------------------------------- the three verbs

    #[Test]
    public function registering_records_the_build_from_the_header(): void
    {
        $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->org->id,
            'device_id' => 'device-a',
        ], [AppClientHeader::HEADER => 'ios/1.0/47'])->assertOk();

        $this->assertDeviceRuns('device-a', 'ios', '1.0', '47');
    }

    #[Test]
    public function registering_records_the_build_from_the_body(): void
    {
        $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->org->id,
            'device_id' => 'device-b',
            'app_platform' => 'android',
            'app_version' => '1.0.0',
            'app_build' => '14',
        ])->assertOk();

        $this->assertDeviceRuns('device-b', 'android', '1.0.0', '14');
    }

    #[Test]
    public function a_pre_r1_registration_records_nothing_and_stays_null(): void
    {
        $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->org->id,
            'device_id' => 'device-c',
        ])->assertOk();

        $this->assertDeviceRuns('device-c', null, null, null);
    }

    #[Test]
    public function the_heartbeat_records_the_build_and_keeps_it_current(): void
    {
        $this->device('device-d');

        $this->postJson('/api/mobile/user/heartbeat', [
            'device_id' => 'device-d',
        ], [AppClientHeader::HEADER => 'ios/1.0/47'])->assertOk();

        $this->assertDeviceRuns('device-d', 'ios', '1.0', '47');

        // The same handset, updated.
        $this->postJson('/api/mobile/user/heartbeat', [
            'device_id' => 'device-d',
        ], [AppClientHeader::HEADER => 'ios/1.1/52'])->assertOk();

        $this->assertDeviceRuns('device-d', 'ios', '1.1', '52');
    }

    /**
     * THE RULE THE WHOLE READING RESTS ON.
     *
     * A handset that reported R1 and then calls from a build that sends
     * nothing keeps what it reported. Without this, every downgrade,
     * reinstall-from-an-old-TestFlight and pre-R1 background refresh would walk
     * the "still on the old build" count downwards, silently, and the S3b
     * decision would be made on a number that had been quietly erasing its own
     * evidence.
     */
    #[Test]
    public function an_absent_value_never_nulls_a_stored_one_on_any_verb(): void
    {
        $this->device('device-e', ['app_platform' => 'ios', 'app_version' => '1.0', 'app_build' => '47']);

        // Heartbeat with no header and no body fields.
        $this->postJson('/api/mobile/user/heartbeat', ['device_id' => 'device-e'])->assertOk();
        $this->assertDeviceRuns('device-e', 'ios', '1.0', '47');

        // Registration again (an install that never saw its first reply).
        $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->org->id,
            'device_id' => 'device-e',
        ])->assertOk();
        $this->assertDeviceRuns('device-e', 'ios', '1.0', '47');

        // Re-pointing the handset at another organisation.
        $other = $this->listedOrg('Al-Razi School');

        $this->putJson('/api/mobile/user', [
            'masjid_id' => $other->id,
            'device_id' => 'device-e',
        ])->assertOk();

        $this->assertDeviceRuns('device-e', 'ios', '1.0', '47');
        $this->assertSame($other->id, MobileAppUser::where('device_id', 'device-e')->value('masjid_id'));
    }

    /** An explicit null is absence, not an instruction to erase. */
    #[Test]
    public function an_explicit_null_in_the_body_does_not_erase_what_is_stored(): void
    {
        $this->device('device-f', ['app_platform' => 'android', 'app_version' => '1.0.0', 'app_build' => '14']);

        $this->postJson('/api/mobile/user/heartbeat', [
            'device_id' => 'device-f',
            'app_platform' => null,
            'app_version' => null,
            'app_build' => null,
        ])->assertOk();

        $this->assertDeviceRuns('device-f', 'android', '1.0.0', '14');
    }

    /**
     * A refused heartbeat is a live phone the server-side prayer backstop
     * treats as dark and double-notifies. Telemetry must never be able to cause
     * that, so the heartbeat drops what it cannot use instead of answering 422.
     */
    #[Test]
    public function the_heartbeat_is_never_refused_over_telemetry(): void
    {
        $this->device('device-g');

        $this->postJson('/api/mobile/user/heartbeat', [
            'device_id' => 'device-g',
            'app_platform' => 'windows',
            'app_version' => str_repeat('9', 400),
            'app_build' => str_repeat('8', 400),
        ], [AppClientHeader::HEADER => 'garbage'])->assertOk();

        $this->assertDeviceRuns('device-g', null, null, null);
        $this->assertNotNull(MobileAppUser::where('device_id', 'device-g')->value('last_active_at'));
    }

    /**
     * Registration gets the SAME trade as the heartbeat, and this test used to
     * assert the opposite.
     *
     * It asserted that registration 422s on an over-width `app_version` or an
     * unknown platform, on the reasoning that a 422 there is loud, recoverable
     * by a retry, and the body is the app's own. That reasoning does not
     * survive the question "recoverable by whom": the client that would be
     * refused is one already shipped to the store, it cannot be changed, and a
     * refused FIRST-launch registration was measured stranding a new iPhone on
     * its splash screen. Nothing establishes that no installed build already
     * sends a body key spelled `app_version`.
     *
     * So no device verb refuses over these fields now. The signal the 422 was
     * meant to give is not lost — `app-telemetry:builds` prints a device with
     * no recorded build as `pre-R1`, which is where an unusable value shows up.
     */
    #[Test]
    public function registration_records_what_it_can_and_refuses_nothing(): void
    {
        $this->postJson('/api/mobile/user', [
            'masjid_id' => $this->org->id,
            'device_id' => 'device-h',
            'app_version' => str_repeat('9', 21),
            'app_platform' => 'windows',
        ])->assertSuccessful();

        // The phone is registered — that is the whole point — and neither
        // unusable value reached a column.
        $this->assertDeviceRuns('device-h', null, null, null);
    }

    // -------------------------------------------------- app-telemetry:builds

    #[Test]
    public function the_builds_command_counts_active_devices_and_names_the_silent_ones(): void
    {
        $this->device('r1-a', ['app_platform' => 'ios', 'app_version' => '1.0', 'app_build' => '47']);
        $this->device('r1-b', ['app_platform' => 'ios', 'app_version' => '1.0', 'app_build' => '47']);
        $this->device('old-a');
        $this->device('old-b');
        $this->device('old-c');

        // Outside the window: counted by nothing.
        $this->device('dormant', ['last_active_at' => now()->subDays(90)]);

        $this->assertSame(0, Artisan::call('app-telemetry:builds', ['--json' => true]));

        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(5, $report['devices']);
        $this->assertSame(3, $report['devices_pre_r1']);

        // Biggest cohort first, and a NULL build reads as `pre-R1` rather than
        // an empty cell somebody dismisses as missing data.
        $this->assertSame('pre-R1', $report['builds'][0]['build']);
        $this->assertSame(3, $report['builds'][0]['devices']);
        $this->assertFalse($report['builds'][0]['tagged']);

        $this->assertSame('47', $report['builds'][1]['build']);
        $this->assertSame('ios', $report['builds'][1]['platform']);
        $this->assertSame(2, $report['builds'][1]['devices']);
        $this->assertTrue($report['builds'][1]['tagged']);
    }

    #[Test]
    public function the_builds_command_writes_nothing(): void
    {
        $this->device('r1-a', ['app_platform' => 'ios', 'app_version' => '1.0', 'app_build' => '47']);

        $before = MobileAppUser::query()->get()->toArray();

        $this->assertSame(0, Artisan::call('app-telemetry:builds'));

        $this->assertEquals($before, MobileAppUser::query()->get()->toArray());
    }

    /** The window is a window: a device silent for months is not "active". */
    #[Test]
    public function the_window_is_honoured(): void
    {
        $this->device('recent', ['last_active_at' => now()->subDays(5)]);
        $this->device('older', ['last_active_at' => now()->subDays(20)]);

        Artisan::call('app-telemetry:builds', ['--json' => true, '--days' => 7]);
        $this->assertSame(1, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['devices']);

        Artisan::call('app-telemetry:builds', ['--json' => true, '--days' => 30]);
        $this->assertSame(2, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['devices']);
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $attributes */
    private function device(string $deviceId, array $attributes = []): MobileAppUser
    {
        return MobileAppUser::create(array_merge([
            'masjid_id' => $this->org->id,
            'device_id' => $deviceId,
            'user_agent' => 'okhttp/4.12.0',
            'last_active_at' => now(),
        ], $attributes));
    }

    private function assertDeviceRuns(string $deviceId, ?string $platform, ?string $version, ?string $build): void
    {
        $row = MobileAppUser::where('device_id', $deviceId)->firstOrFail();

        $this->assertSame($platform, $row->app_platform, "{$deviceId} app_platform");
        $this->assertSame($version, $row->app_version, "{$deviceId} app_version");
        $this->assertSame($build, $row->app_build, "{$deviceId} app_build");
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string>|null $header
     */
    private function requestWith(array $body = [], ?array $header = null): Request
    {
        $request = Request::create('/api/mobile/user/heartbeat', 'POST', $body);

        if ($header !== null) {
            $request->headers->set(
                AppClientHeader::HEADER,
                "{$header['app_platform']}/{$header['app_version']}/{$header['app_build']}"
            );
        }

        return $request;
    }
}
