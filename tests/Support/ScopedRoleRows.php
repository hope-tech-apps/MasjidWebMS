<?php

namespace Tests\Support;

use App\Models\Masjid;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The rows the `normalise_scoped_roles_on_masjid_user` migration is judged on, and
 * the migration itself, for the two suites that run it: tests/Feature on SQLite and
 * tests/Mysql on the engine production runs. One fixture, so the two cannot drift.
 *
 * Every row is written with a timestamp in the past, so "untouched" can be asserted
 * as "its role AND its updated_at are what they were".
 */
final class ScopedRoleRows
{
    public const MIGRATION = 'database/migrations/2026_10_14_120000_normalise_scoped_roles_on_masjid_user.php';

    private const WRITTEN_AT = '2026-01-01 00:00:00';

    /**
     * Seed one row per case.
     *
     * @return array<string, array{id: int, role: string, becomes: string}> by case name;
     *         `becomes` equals `role` for every row that must be left alone
     */
    public static function seed(): array
    {
        $organisations = collect(range(1, 3))->map(fn (int $n) => Masjid::create([
            'name' => "Role Rows Org {$n} ".uniqid(),
            'email' => 'rr'.uniqid().'@example.test',
            'phone' => '+1555'.random_int(1000000, 9999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'school',
        ]));
        $archivedOrganisation = $organisations[2];

        $cases = [
            // Written by hand at some point: the role names the OTHER kind's role.
            // Today each means "the login's kind decides", and must go on meaning it.
            'a teacher holding a lunch-staff row' => ['Teacher', 'lunch-staff', 'teacher', 0, false],
            'a lunch login holding a teacher row' => ['LunchStaff', 'teacher', 'lunch-staff', 0, false],
            'an archived lunch login holding a teacher row' => ['LunchStaff', 'teacher', 'lunch-staff', 1, true],
            'a teacher holding a lunch-staff row at an archived organisation' => ['Teacher', 'lunch-staff', 'teacher', 2, false],

            // What every door writes: nothing to do.
            'a teacher holding a teacher row' => ['Teacher', 'teacher', 'teacher', 0, false],
            'a lunch login holding a lunch-staff row' => ['LunchStaff', 'lunch-staff', 'lunch-staff', 0, false],

            // A role that is neither: the fallback line of kindFor() keeps its meaning.
            'a teacher holding an owner row' => ['Teacher', 'owner', 'owner', 1, false],
            'a lunch login holding a member row' => ['LunchStaff', 'member', 'member', 1, false],

            // Never re-read, whatever the row says, so never rewritten.
            'an administrator holding a teacher row' => ['MasjidAdmin', 'teacher', 'teacher', 0, false],
            'an administrator holding a lunch-staff row' => ['MasjidAdmin', 'lunch-staff', 'lunch-staff', 1, false],
            'an administrator holding a masjid-admin row' => ['MasjidAdmin', 'masjid-admin', 'masjid-admin', 2, false],
            'a member holding a teacher row' => ['User', 'teacher', 'teacher', 0, false],
            'a SuperAdmin holding a lunch-staff row' => ['SuperAdmin', 'lunch-staff', 'lunch-staff', 0, false],
        ];

        $rows = [];

        foreach ($cases as $name => [$type, $role, $becomes, $organisation, $archiveLogin]) {
            $user = User::factory()->create(['type' => $type, 'phone' => '+1555'.random_int(1000000, 9999999)]);

            $id = DB::table('masjid_user')->insertGetId([
                'masjid_id' => $organisations[$organisation]->id,
                'user_id' => $user->id,
                'role' => $role,
                'is_default' => false,
                'created_at' => self::WRITTEN_AT,
                'updated_at' => self::WRITTEN_AT,
            ]);

            if ($archiveLogin) {
                DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()]);
            }

            $rows[$name] = ['id' => (int) $id, 'role' => $role, 'becomes' => $becomes];
        }

        DB::table('masjids')->where('id', $archivedOrganisation->id)->update(['deleted_at' => now()]);

        return $rows;
    }

    /** The variable the migration reads its answer from (see the migration's docblock). */
    public const ANSWER = 'NORMALISE_SCOPED_ROLES';

    /** The statements of the last run, kept for a run that stopped by throwing. */
    public static array $lastStatements = [];

    /**
     * Run the migration's up(), and hand back the statements it ran.
     *
     * $answer is what the person running it said the rows it finds are: `rewrite`,
     * `keep`, or nothing at all (null), which is how every deploy runs it. It is
     * set in the process environment for this one run, exactly as an operator sets
     * it for one `artisan migrate`, and cleared again whatever happens.
     */
    public static function migrate(?string $answer = null): array
    {
        self::$lastStatements = [];

        putenv($answer === null ? self::ANSWER : self::ANSWER.'='.$answer);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            (require base_path(self::MIGRATION))->up();
        } finally {
            self::$lastStatements = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();
            putenv(self::ANSWER);
        }

        return self::$lastStatements;
    }

    /**
     * What each seeded row holds now.
     *
     * @param  array<string, array{id: int, role: string, becomes: string}>  $rows
     * @return array<string, array{role: string, touched: bool}>
     */
    public static function read(array $rows): array
    {
        $stored = DB::table('masjid_user')->whereIn('id', array_column($rows, 'id'))->get()->keyBy('id');

        return array_map(fn (array $row): array => [
            'role' => (string) $stored[$row['id']]->role,
            'touched' => (string) $stored[$row['id']]->updated_at !== self::WRITTEN_AT,
        ], $rows);
    }

    /**
     * What read() must answer after the migration has run with the switch off and
     * was told to rewrite.
     *
     * @param  array<string, array{id: int, role: string, becomes: string}>  $rows
     * @return array<string, array{role: string, touched: bool}>
     */
    public static function expected(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'role' => $row['becomes'],
            'touched' => $row['becomes'] !== $row['role'],
        ], $rows);
    }
}
