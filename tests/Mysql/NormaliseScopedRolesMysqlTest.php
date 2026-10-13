<?php

/*
|--------------------------------------------------------------------------
| The role-normalising migration, on the engine production runs
|--------------------------------------------------------------------------
|
| tests/Feature/NormaliseScopedRolesMigrationTest.php runs the same fixture on
| SQLite, which cannot show the three things that are MySQL's alone:
|
|  - the statements are valid there (aliases, an inner join, `IN (...)` by primary
|    key) under the server's own sql_mode, ANSI_QUOTES included: the query builder
|    quotes identifiers with backticks and binds every string;
|  - the comparison that decides a rewrite is PHP's exact one, not the column's
|    collation, which calls `Teacher` and `teacher ` the same as `teacher`. A row the
|    `IN (...)` returns only because of the collation is normalised to the exact
|    spelling, and a row the application would not read as a scoped role is never
|    given the OTHER kind's meaning;
|  - the expected production case (nothing disagrees) is one plain SELECT and no
|    write, so it takes no row lock on `users` or `masjid_user`.
|
| Runs only in CI's migrations-mysql job, as `pest --group=mysql`.
*/

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\ScopedRoleRows;

// Set here, not inherited: the migration does nothing while the switch is on.
beforeEach(fn () => config(['tenancy.roles_per_organisation' => false]));

it('told to rewrite, rewrites only a scoped login\'s row that names the other kind\'s role', function () {
    $rows = ScopedRoleRows::seed();

    $statements = ScopedRoleRows::migrate('rewrite');

    expect(ScopedRoleRows::read($rows))->toBe(ScopedRoleRows::expected($rows));

    // One read, then one UPDATE per target role, each by primary key. No subquery on
    // `users` (which would lock every login row it read) and no locking read.
    expect($statements)->toHaveCount(3)
        ->and($statements[0])->toStartWith('select')
        ->and($statements[0])->toContain('inner join `users` as `u`')
        ->and($statements[0])->not->toContain('for update');

    foreach (array_slice($statements, 1) as $update) {
        expect($update)->toMatch('/^update `masjid_user` set `role` = \?, `updated_at` = \? where `id` in \(\?(, \?)*\)$/');
    }
});

it('leaves every row meaning what it meant, and writes nothing the second time', function () {
    $rows = ScopedRoleRows::seed();

    ScopedRoleRows::migrate('rewrite');

    foreach (MasjidUser::whereIn('id', array_column($rows, 'id'))->get() as $row) {
        $kind = DB::table('users')->where('id', $row->user_id)->value('type');
        expect($row->kindFor($kind))->toBe($kind);
    }

    $again = ScopedRoleRows::migrate();

    expect($again)->toHaveCount(1)
        ->and($again[0])->toStartWith('select')
        ->and(ScopedRoleRows::read($rows))->toBe(ScopedRoleRows::expected($rows));
});

it('decides by the exact spelling, whatever the column\'s collation calls equal', function () {
    $org = Masjid::create([
        'name' => 'Role Spelling Org '.uniqid(), 'org_type' => 'school',
        'email' => 'rs'.uniqid().'@example.test', 'phone' => '+1555'.random_int(1000000, 9999999),
        'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
    ]);

    $row = function (string $type, string $role) use ($org): int {
        $user = User::factory()->create(['type' => $type, 'phone' => '+1555'.random_int(1000000, 9999999)]);

        return (int) DB::table('masjid_user')->insertGetId([
            'masjid_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'is_default' => false,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    };

    // To the application neither spelling is a scoped role, so each row means "the
    // login's kind decides" before and must mean it after, whichever way the
    // collation compares them.
    $capitalised = $row('LunchStaff', 'Teacher');
    $padded = $row('Teacher', 'lunch-staff ');

    // Told nothing, as a deploy runs it: a row that only LOOKS like the other
    // kind's role to the collation is not one the migration stops for.
    ScopedRoleRows::migrate();

    foreach ([$capitalised => 'LunchStaff', $padded => 'Teacher'] as $id => $kind) {
        $stored = MasjidUser::findOrFail($id);

        expect($stored->kindFor($kind))->toBe($kind)
            // Either left exactly as it was, or normalised to its own kind's role.
            ->and(in_array($stored->role, ['Teacher', 'lunch-staff ', MasjidUser::SCOPED_ROLES[$kind]], true))->toBeTrue();
    }
});

it('does nothing once the roles switch is on', function () {
    config(['tenancy.roles_per_organisation' => true]);

    $rows = ScopedRoleRows::seed();

    expect(ScopedRoleRows::migrate())->toBe([])
        ->and(array_column(ScopedRoleRows::read($rows), 'touched'))->not->toContain(true);
});

it('stops and writes nothing until it is told what the rows are', function () {
    $rows = ScopedRoleRows::seed();

    expect(fn () => ScopedRoleRows::migrate())->toThrow(RuntimeException::class, 'stopped and wrote nothing');

    // One plain read, no write, no locking read.
    expect(ScopedRoleRows::$lastStatements)->toHaveCount(1)
        ->and(ScopedRoleRows::$lastStatements[0])->toStartWith('select')
        ->and(ScopedRoleRows::$lastStatements[0])->not->toContain('for update')
        ->and(array_column(ScopedRoleRows::read($rows), 'touched'))->not->toContain(true);

    expect(ScopedRoleRows::migrate('keep'))->toHaveCount(1)
        ->and(array_column(ScopedRoleRows::read($rows), 'touched'))->not->toContain(true);
});

it('lists the cross-kind rows with the command, by id and kind only', function () {
    $rows = ScopedRoleRows::seed();

    $this->artisan('staff:cross-kind-roles')->assertExitCode(3);

    ScopedRoleRows::migrate('rewrite');

    $this->artisan('staff:cross-kind-roles')->expectsOutput('0')->assertExitCode(0);
});
