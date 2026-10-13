<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a login with ONE role is told, and what its requests run, recorded before
 * "one login, a role at each organisation" was built and compared literally since.
 *
 * Nearly every login holds one role. The feature's second rule is that such a login
 * signs in where it did, is re-identified as it was and runs the statements it ran,
 * whether the switch (`tenancy.roles_per_organisation`) is off or on. This file is
 * that rule as a measurement: tests/fixtures/staff-one-role-pins-15759078.json was
 * written by this test on commit 15759078, the last one before the feature, and
 * every case below must reproduce it in BOTH switch states.
 *
 * Per login it holds seven requests: the sign-in, the three `/user` routes (its own
 * and the two that refuse it), and one organisation-bound request in each area. For
 * each: the status, the SQL statement list, and either the refusal's body or, for an
 * answer that describes the login, the ordered key list of the user, its `type`,
 * its organisation and the whole `memberships` array. A new key (`areas`) on a
 * one-role login would change the key list, and a new read would change the
 * statement list.
 *
 * The statement lists are the reason TeacherTimetableTest and ClassSubjectsOffSqlTest
 * could not be the only pins: they hold `/api/teacher/user` and two office forms,
 * and nothing held the sign-in, the lunch or the administrator's `/user`.
 *
 * To re-record (only on a commit whose answers are known to be right):
 *   STAFF_PINS_RECORD=1 php -d memory_limit=-1 vendor/bin/pest tests/Feature/StaffLoginPayloadPinTest.php
 */
class StaffLoginPayloadPinTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'tests/fixtures/staff-one-role-pins-15759078.json';

    private Masjid $masjid;

    private Masjid $school;

    private Masjid $secondSchool;

    /** @var array<string, User> */
    private array $logins = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // The order is the fixture: ids are read back in the payloads and inlined in
        // eager-load statements, so every case builds the same world the same way.
        $this->masjid = $this->makeOrganisation('Pin Masjid', 'masjid');
        $this->school = $this->makeOrganisation('Pin School', 'school');
        $this->secondSchool = $this->makeOrganisation('Pin Second School', 'school');

        $this->logins['owner administrator'] = $this->makeLogin('MasjidAdmin', 'Pin Owner');
        $this->masjid->user_id = $this->logins['owner administrator']->id;
        $this->masjid->save();

        $this->logins['second administrator'] = $this->makeLogin('MasjidAdmin', 'Pin Second Administrator');
        $this->join('second administrator', $this->masjid, 'masjid-admin', true);

        $this->logins['super administrator'] = $this->makeLogin('SuperAdmin', 'Pin Super');

        $this->logins['teacher of one school'] = $this->makeLogin('Teacher', 'Pin Teacher');
        $this->join('teacher of one school', $this->school, 'teacher', true);

        $this->logins['teacher of two schools'] = $this->makeLogin('Teacher', 'Pin Two School Teacher');
        $this->join('teacher of two schools', $this->school, 'teacher', true);
        $this->join('teacher of two schools', $this->secondSchool, 'teacher', false);

        $this->logins['lunch staff'] = $this->makeLogin(User::TYPE_LUNCH_STAFF, 'Pin Lunch');
        $this->join('lunch staff', $this->masjid, 'lunch-staff', true);

        // The three kinds that are told they belong nowhere.
        $this->logins['administrator of nothing'] = $this->makeLogin('MasjidAdmin', 'Pin Orphan Administrator');
        $this->logins['teacher of nothing'] = $this->makeLogin('Teacher', 'Pin Orphan Teacher');
        $this->logins['lunch staff of nothing'] = $this->makeLogin(User::TYPE_LUNCH_STAFF, 'Pin Orphan Lunch');

        app(TenantContext::class)->forgetTenant();
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function loginsAndSwitchStates(): array
    {
        $cases = [];

        foreach ([
            'owner administrator', 'second administrator', 'super administrator',
            'teacher of one school', 'teacher of two schools', 'lunch staff',
            'administrator of nothing', 'teacher of nothing', 'lunch staff of nothing',
        ] as $login) {
            $cases["{$login}, switch off"] = [$login, false];
            $cases["{$login}, switch on"] = [$login, true];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('loginsAndSwitchStates')]
    public function a_login_with_one_role_is_told_what_it_was_told_and_runs_what_it_ran(string $login, bool $switchOn): void
    {
        config(['tenancy.roles_per_organisation' => $switchOn]);

        $observed = $this->observe($login);

        if (getenv('STAFF_PINS_RECORD')) {
            $this->record($login, $observed);
            $this->markTestSkipped('Recorded; run again without STAFF_PINS_RECORD to compare.');
        }

        $recorded = json_decode((string) file_get_contents(base_path(self::FIXTURE)), true)[$login] ?? null;

        $this->assertNotNull($recorded, "No recording for \"{$login}\" in ".self::FIXTURE.'.');
        $this->assertSame(array_keys($recorded), array_keys($observed));

        foreach ($recorded as $request => $expected) {
            $state = $switchOn ? 'on' : 'off';

            foreach ($expected as $part => $value) {
                $this->assertSame(
                    $value,
                    $observed[$request][$part] ?? null,
                    "\"{$login}\", switch {$state}: the {$part} of [{$request}] is not what it was before the feature."
                );
            }

            $this->assertSame(array_keys($expected), array_keys($observed[$request]));
        }
    }

    /** @return array<string, array<string, mixed>> the seven requests, in the order they are always sent */
    private function observe(string $login): array
    {
        $user = $this->logins[$login];
        $observed = [];

        $observed['POST /api/admin/login'] = $this->observeOne(
            fn () => $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'password']),
            'data.user'
        );

        // A model read back from the table, as the guard builds one from a token.
        Sanctum::actingAs(User::findOrFail($user->id), ['staff']);

        foreach (['admin', 'teacher', 'lunch'] as $area) {
            $observed["GET /api/{$area}/user"] = $this->observeOne(fn () => $this->getJson("/api/{$area}/user"), 'data');
        }

        $observed['GET /api/teacher/masjids/{school}/school'] = $this->observeOne(
            fn () => $this->getJson("/api/teacher/masjids/{$this->school->id}/school")
        );
        $observed['GET /api/lunch/masjids/{masjid}/jummah-lunch/menus'] = $this->observeOne(
            fn () => $this->getJson("/api/lunch/masjids/{$this->masjid->id}/jummah-lunch/menus")
        );
        $observed['GET /api/admin/masjids/{masjid}'] = $this->observeOne(
            fn () => $this->getJson("/api/admin/masjids/{$this->masjid->id}")
        );

        return $observed;
    }

    /**
     * One request: its status, its statements, and what it said about the login.
     *
     * @param  \Closure(): TestResponse  $send
     * @return array<string, mixed>
     */
    private function observeOne(\Closure $send, ?string $userPath = null): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $send();
        $sql = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $entry = ['status' => $response->getStatusCode(), 'sql' => $sql];
        $user = $userPath === null ? null : $response->json($userPath);

        if ($response->getStatusCode() === 200 && is_array($user) && array_key_exists('type', $user)) {
            // Not the whole body: it carries timestamps. The key ORDER is kept, so a
            // key added anywhere, or moved, is seen.
            $entry['user'] = [
                'keys' => array_keys($user),
                'type' => $user['type'],
                'masjid_id' => $user['masjid']['id'] ?? null,
                'masjid_keys' => isset($user['masjid']) ? array_keys($user['masjid']) : null,
                'memberships' => $user['memberships'] ?? null,
            ];
        } elseif ($response->getStatusCode() !== 200 || $userPath !== null) {
            // A refusal, or a 200 that describes nobody ("not linked to a masjid yet").
            $entry['body'] = $response->json();
        }

        return $entry;
    }

    private function record(string $login, array $observed): void
    {
        $path = base_path(self::FIXTURE);
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all[$login] = $observed;

        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function makeOrganisation(string $name, string $orgType): Masjid
    {
        return Masjid::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@pins.test',
            'phone' => '+1555000'.str_pad((string) (Masjid::withoutGlobalScopes()->count() + 1), 4, '0', STR_PAD_LEFT),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
        ]);
    }

    private function makeLogin(string $type, string $name): User
    {
        return User::factory()->create([
            'type' => $type,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@pins.test',
            'phone' => '+1555100'.str_pad((string) (count($this->logins) + 1), 4, '0', STR_PAD_LEFT),
        ]);
    }

    private function join(string $login, Masjid $organisation, string $role, bool $default): void
    {
        MasjidUser::create([
            'masjid_id' => $organisation->id,
            'user_id' => $this->logins[$login]->id,
            'role' => $role,
            'is_default' => $default,
        ]);
    }
}
