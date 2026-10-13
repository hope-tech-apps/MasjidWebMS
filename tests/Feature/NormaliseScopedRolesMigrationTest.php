<?php

namespace Tests\Feature;

use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OneLoginWorld;
use Tests\Support\ScopedRoleRows;
use Tests\TestCase;

/**
 * The one data migration of "one login, a role at each organisation", and the
 * command that lists the rows the feature is made of.
 *
 * `masjid_user.role` was advisory; from this release a `teacher` or `lunch-staff`
 * role says what a teacher's or lunch-staff login IS at that organisation. The
 * migration's whole job is that no row written while the role meant nothing starts
 * to mean something new, AND that it never does that to a row an office wrote on
 * purpose afterwards: the two are the same columns, so when it finds one it stops
 * and asks (NORMALISE_SCOPED_ROLES=rewrite or =keep, for that one run). tests/Mysql/NormaliseScopedRolesMysqlTest.php runs the same
 * fixture on the engine production runs.
 */
class NormaliseScopedRolesMigrationTest extends TestCase
{
    use OneLoginWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // Set here, not inherited: the migration does nothing while the switch is on,
        // and a run of the whole suite with TENANCY_ROLES_PER_ORGANISATION=true (how
        // the flip is rehearsed) must still test what the migration does at release.
        config(['tenancy.roles_per_organisation' => false]);
    }

    #[Test]
    public function told_to_rewrite_it_rewrites_only_a_scoped_logins_row_that_names_the_other_kinds_role(): void
    {
        $rows = ScopedRoleRows::seed();

        $statements = ScopedRoleRows::migrate('rewrite');

        $this->assertSame(ScopedRoleRows::expected($rows), ScopedRoleRows::read($rows));

        // Four rows, of which one read and one UPDATE per target role, by primary key.
        $this->assertCount(3, $statements);
        $this->assertStringStartsWith('select', $statements[0]);
        $this->assertStringContainsString('inner join "users" as "u"', $statements[0]);
        foreach (array_slice($statements, 1) as $update) {
            $this->assertMatchesRegularExpression('/^update "masjid_user" set "role" = \?, "updated_at" = \? where "id" in \(\?(, \?)*\)$/', $update);
        }
    }

    #[Test]
    public function afterwards_every_row_means_what_it_meant_before(): void
    {
        // The property the migration exists for, stated on the rule that reads rows:
        // before it, what a login was at an organisation was its kind, always.
        // After it, kindFor() must say that same thing for every row that existed.
        $rows = ScopedRoleRows::seed();

        ScopedRoleRows::migrate('rewrite');

        $checked = 0;

        foreach (MasjidUser::whereIn('id', array_column($rows, 'id'))->get() as $row) {
            $kind = DB::table('users')->where('id', $row->user_id)->value('type');

            $this->assertSame($kind, $row->kindFor($kind), "row {$row->id} ({$kind}, {$row->role}) would now mean something its login's kind does not");
            $checked++;
        }

        $this->assertSame(count($rows), $checked);
    }

    #[Test]
    public function it_writes_nothing_when_there_is_nothing_to_rewrite_and_running_it_twice_is_the_same_as_once(): void
    {
        $rows = ScopedRoleRows::seed();

        ScopedRoleRows::migrate('rewrite');
        $after = DB::table('masjid_user')->orderBy('id')->get()->all();

        // The expected production case: nothing disagrees, so one read, no write,
        // and no question: this run is told nothing, as every deploy's is.
        $again = ScopedRoleRows::migrate();

        $this->assertCount(1, $again, 'a clean table costs one read');
        $this->assertStringStartsWith('select', $again[0]);
        $this->assertEquals($after, DB::table('masjid_user')->orderBy('id')->get()->all());
        $this->assertSame(ScopedRoleRows::expected($rows), ScopedRoleRows::read($rows));
    }

    #[Test]
    public function it_does_nothing_at_all_once_the_roles_switch_is_on(): void
    {
        // With the switch on a row whose role is the other kind's is a deliberate
        // grant: a lunch login an office made a teacher. Re-running the migration by
        // hand then would turn her teacher row into a lunch row AT THE SCHOOL.
        config(['tenancy.roles_per_organisation' => true]);

        $this->buildOneLoginWorld();
        $rows = ScopedRoleRows::seed();
        $before = DB::table('masjid_user')->orderBy('id')->get()->all();

        $statements = ScopedRoleRows::migrate();

        $this->assertSame([], $statements, 'not even a read');
        $this->assertEquals($before, DB::table('masjid_user')->orderBy('id')->get()->all());
        $this->assertSame('teacher', MasjidUser::where('masjid_id', $this->school->id)->where('user_id', $this->shamina->id)->value('role'));
        $this->assertSame('lunch-staff', MasjidUser::where('masjid_id', $this->masjid->id)->where('user_id', $this->mirror->id)->value('role'));
        $this->assertSame($rows['a lunch login holding a teacher row']['role'], ScopedRoleRows::read($rows)['a lunch login holding a teacher row']['role']);
    }

    #[Test]
    public function it_stops_and_writes_nothing_when_it_finds_such_a_row_and_nobody_has_said_what_it_is(): void
    {
        // It cannot tell a row written by hand while the role meant nothing from a
        // role an office gave on purpose: they are the same two columns. Rewriting
        // the second kind moves a person to the other area at that organisation, so
        // without an answer it does neither. It stops, before any write, and names
        // the rows by id.
        $rows = ScopedRoleRows::seed();
        $before = DB::table('masjid_user')->orderBy('id')->get()->all();
        $found = collect($rows)->filter(fn (array $row): bool => $row['becomes'] !== $row['role'])->pluck('id')->sort()->values()->all();

        foreach ([null, '', 'yes', 'REWRITE', 'true', '1'] as $notAnAnswer) {
            try {
                ScopedRoleRows::migrate($notAnAnswer);
                $this->fail('the migration ran on with '.var_export($notAnAnswer, true).' for an answer');
            } catch (\RuntimeException $stopped) {
                $this->assertStringContainsString('stopped and wrote nothing', $stopped->getMessage());
                $this->assertStringContainsString(count($found).' masjid_user row(s)', $stopped->getMessage());
                $this->assertStringContainsString('ids '.implode(', ', $found).';', $stopped->getMessage(), 'the rows are named by id, so a person can read them');
                $this->assertStringContainsString(ScopedRoleRows::ANSWER.'=rewrite', $stopped->getMessage());
                $this->assertStringContainsString(ScopedRoleRows::ANSWER.'=keep', $stopped->getMessage());
            }

            $this->assertCount(1, ScopedRoleRows::$lastStatements, 'one read and nothing else');
            $this->assertStringStartsWith('select', ScopedRoleRows::$lastStatements[0]);
            $this->assertEquals($before, DB::table('masjid_user')->orderBy('id')->get()->all());
        }

        // No name and no address is in what it says: the message reaches a deploy log.
        foreach (DB::table('users')->get(['name', 'email']) as $login) {
            $this->assertStringNotContainsString($login->email, $stopped->getMessage());
            $this->assertStringNotContainsString($login->name, $stopped->getMessage());
        }
    }

    #[Test]
    public function told_to_keep_them_it_writes_nothing_and_finishes(): void
    {
        $rows = ScopedRoleRows::seed();
        $before = DB::table('masjid_user')->orderBy('id')->get()->all();

        $statements = ScopedRoleRows::migrate('keep');

        $this->assertCount(1, $statements, 'one read and no write');
        $this->assertEquals($before, DB::table('masjid_user')->orderBy('id')->get()->all());
        $this->assertNotContains(true, array_column(ScopedRoleRows::read($rows), 'touched'));
    }

    #[Test]
    public function met_a_second_time_with_the_switch_off_it_does_not_turn_a_role_an_office_gave_back_into_the_logins_own_kind(): void
    {
        // The state the runbook calls the safe way back: the switch OFF, the rows left
        // in place. If the migration is then run again (rolled back and re-run on a
        // staging box, `migrate:refresh`, a roll-forward after the rows were put
        // back), the switch no longer protects them. It used to rewrite each one: her
        // `teacher` row at the school became a `lunch-staff` row AT THE SCHOOL, her
        // teacher area was gone and the lunch area opened there, where no office had
        // given it.
        config(['tenancy.roles_per_organisation' => true]);
        $this->buildOneLoginWorld();
        config(['tenancy.roles_per_organisation' => false]);

        $before = DB::table('masjid_user')->orderBy('id')->get()->all();

        try {
            ScopedRoleRows::migrate();
            $this->fail('the migration rewrote, or passed over, a role an office gave');
        } catch (\RuntimeException $stopped) {
            $this->assertStringContainsString('stopped and wrote nothing', $stopped->getMessage());
        }

        $this->assertEquals($before, DB::table('masjid_user')->orderBy('id')->get()->all());
        $this->assertSame('teacher', MasjidUser::where('masjid_id', $this->school->id)->where('user_id', $this->shamina->id)->value('role'));
        $this->assertSame('lunch-staff', MasjidUser::where('masjid_id', $this->masjid->id)->where('user_id', $this->mirror->id)->value('role'));

        // What the rewrite used to open stays shut, switch off...
        $this->actingAsPerson($this->shamina);
        $this->getJson($this->lunchBase($this->school).'/jummah-lunch/menus')->assertForbidden();
        $this->getJson($this->lunchBase($this->school).'/guides')->assertForbidden();
        $this->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->assertOk();

        // ...and what it used to take away is still hers when the switch is on again.
        config(['tenancy.roles_per_organisation' => true]);
        $this->actingAsPerson($this->shamina);
        $this->getJson($this->teacherBase($this->school).'/school')->assertOk();
        $this->getJson($this->lunchBase($this->school).'/jummah-lunch/menus')->assertForbidden();

        // The person who knows they are deliberate says so, and the run finishes.
        // (Roles only: the requests above stamped when she last opened each place.)
        config(['tenancy.roles_per_organisation' => false]);
        ScopedRoleRows::migrate('keep');
        $this->assertSame(
            array_column($before, 'role', 'id'),
            DB::table('masjid_user')->orderBy('id')->pluck('role', 'id')->all()
        );
    }

    #[Test]
    public function the_migration_names_the_roles_itself_and_they_are_the_ones_the_rule_reads(): void
    {
        // It must not follow a constant that may change after it has run, so it
        // spells the two roles out; this is the check that it spelled them right.
        $source = (string) file_get_contents(base_path(ScopedRoleRows::MIGRATION));

        foreach (MasjidUser::SCOPED_ROLES as $kind => $role) {
            $this->assertStringContainsString("'{$kind}' => '{$role}'", $source);
        }

        $this->assertStringNotContainsString('use App\\', $source, 'the migration imports no application class');
        $this->assertStringNotContainsString('DB::statement', $source);
        $this->assertStringNotContainsString('DB::raw', $source);
    }

    // ------------------------------------------------- staff:cross-kind-roles

    #[Test]
    public function the_listing_command_answers_none_on_a_database_with_no_such_row(): void
    {
        ScopedRoleRows::seed();
        ScopedRoleRows::migrate('rewrite');

        $this->artisan('staff:cross-kind-roles')->expectsOutput('0')->assertExitCode(0);
    }

    #[Test]
    public function the_listing_command_names_exactly_the_rows_the_feature_is_made_of(): void
    {
        config(['tenancy.roles_per_organisation' => true]);
        $this->buildOneLoginWorld();

        // Rows that are NOT the feature's: an administrator holding a scoped role (no
        // code re-reads it), and one-role logins.
        $admin = $this->makeLogin('MasjidAdmin', 'Office');
        $this->giveRole($admin, $this->school, 'teacher', true);
        $teacher = $this->makeLogin('Teacher', 'Plain Teacher');
        $this->giveRole($teacher, $this->school, 'teacher', true);

        $this->artisan('staff:cross-kind-roles')->assertExitCode(3);

        \Illuminate\Support\Facades\Artisan::call('staff:cross-kind-roles', ['--json' => true]);
        $listed = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

        $this->assertSame(
            [
                [(int) $this->shamina->id, (int) $this->school->id, 'LunchStaff', 'teacher'],
                [(int) $this->mirror->id, (int) $this->masjid->id, 'Teacher', 'lunch-staff'],
            ],
            array_map(fn (array $row): array => [(int) $row['user_id'], (int) $row['masjid_id'], $row['login_kind'], $row['role']], $listed)
        );

        // What the export must carry to put a row back: nothing more than the row.
        $this->assertSame(
            ['id', 'masjid_id', 'user_id', 'role', 'is_default', 'last_seen_at', 'created_at', 'updated_at', 'login_kind', 'login_archived_at', 'organisation_archived_at'],
            array_keys($listed[0])
        );
        $this->assertStringNotContainsString($this->shamina->email, \Illuminate\Support\Facades\Artisan::output());
        $this->assertStringNotContainsString($this->shamina->name, \Illuminate\Support\Facades\Artisan::output());
    }

    #[Test]
    public function the_listing_command_still_counts_an_archived_login_and_an_archived_organisation(): void
    {
        // Both come back when restored, and code from before the feature would then
        // bind them by kind alone.
        config(['tenancy.roles_per_organisation' => true]);
        $this->buildOneLoginWorld();

        User::whereKey($this->shamina->id)->delete();
        DB::table('masjids')->where('id', $this->masjid->id)->update(['deleted_at' => now()]);

        \Illuminate\Support\Facades\Artisan::call('staff:cross-kind-roles', ['--json' => true]);
        $listed = collect(json_decode(\Illuminate\Support\Facades\Artisan::output(), true))->keyBy('user_id');

        $this->assertCount(2, $listed);
        $this->assertNotNull($listed[$this->shamina->id]['login_archived_at']);
        $this->assertNotNull($listed[$this->mirror->id]['organisation_archived_at']);
    }
}
