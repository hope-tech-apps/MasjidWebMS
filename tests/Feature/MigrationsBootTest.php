<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Does the migration stack still run end to end?
 *
 * This exists because of a specific, expensive failure. On 2026-07-24 a migration
 * shipped with a raw `ALTER TABLE notifications MODIFY …`, which is valid MySQL and a
 * syntax error in SQLite. The suite runs on in-memory SQLite, so RefreshDatabase aborted
 * part-way through and EVERY feature test errored before its first assertion. Nothing
 * went red in a way anyone noticed: the tests did not "fail", they never ran. The
 * cross-tenant isolation guarantee was unverified for three days.
 *
 * A test that only asserts behaviour cannot catch that, because it never gets to run.
 * So this one asserts the precondition instead: migrations complete, and the schema
 * that results is the one the rest of the suite assumes.
 *
 * Deliberately does NOT use RefreshDatabase — that trait is the thing under test.
 */
class MigrationsBootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    #[Test]
    public function the_full_migration_stack_runs_on_sqlite(): void
    {
        // Throws on the first migration that fails, with the offending SQL — which is
        // the signal that was missing.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $this->assertTrue(
            Schema::hasTable('migrations'),
            'migrate:fresh reported success but produced no migrations table.'
        );
    }

    /**
     * A guard against a migration silently doing nothing. `migrate:fresh` exits 0 even
     * if a migration returns early, so also assert the tables the suite depends on
     * actually exist.
     */
    #[Test]
    public function the_core_tables_exist_after_migrating(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        foreach ([
            'users', 'masjids', 'pages', 'sections', 'page_section',
            'forms', 'form_responses',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table `{$table}` to exist after migrating.");
        }
    }

    /**
     * Raw SQL in a migration is the one thing that can be valid on production's MySQL
     * and fatal on the suite's SQLite. Every `DB::statement` in a migration must
     * therefore be driver-guarded.
     *
     * This is a lint rather than an execution test on purpose: it fails on the PR that
     * introduces the problem, naming the file, instead of surfacing as 200 unrelated
     * errors later.
     */
    #[Test]
    public function every_raw_sql_migration_is_driver_guarded(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $source = file_get_contents($path);

            if (! preg_match('/DB::statement\s*\(/', $source)) {
                continue;
            }

            if (! preg_match('/getDriverName\s*\(\s*\)/', $source)) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These migrations issue raw SQL without checking DB::getDriverName(). Raw DDL that "
            . "works on MySQL can be a syntax error on the SQLite the test suite runs on, which "
            . "takes the whole feature suite down silently:\n  - " . implode("\n  - ", $offenders)
        );
    }

    /**
     * `email_suppressions.email_normalized` becomes byte-exact on MySQL, and
     * nothing changes on SQLite.
     *
     * The collation itself cannot be observed here (SQLite compares bytes
     * already; the MySQL CI job runs the migration and its rollback), so this
     * pins what can be: the exact statements, that they are guarded to the MySQL
     * family, that the column they restate is the create migration's, and that
     * the two index names are still there, under MySQL's 64-character limit.
     */
    #[Test]
    public function the_email_suppression_key_is_made_byte_exact_on_mysql_and_left_alone_on_sqlite(): void
    {
        $migration = (string) file_get_contents(database_path('migrations/2026_10_01_130000_make_email_suppression_key_byte_exact.php'));
        $create = (string) file_get_contents(database_path('migrations/2026_09_12_100000_create_email_suppressions_table.php'));

        $this->assertStringContainsString(
            "'ALTER TABLE email_suppressions MODIFY email_normalized VARCHAR(191) COLLATE utf8mb4_bin NOT NULL'",
            $migration,
        );
        $this->assertStringContainsString(
            "'ALTER TABLE email_suppressions MODIFY email_normalized VARCHAR(191) COLLATE utf8mb4_unicode_ci NOT NULL'",
            $migration,
            'down() must restore the collation the column was created with.',
        );
        $this->assertStringContainsString("in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)", $migration);
        $this->assertStringContainsString(
            '$table->string(\'email_normalized\', 191);',
            $create,
            'The MODIFY restates the create migration\'s type, length and nullability; this test must move with it.',
        );

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $indexNames = array_column(Schema::getIndexes('email_suppressions'), 'name');

        foreach (['email_suppressions_tenant_address_unique', 'email_suppressions_address_index'] as $name) {
            $this->assertContains($name, $indexNames);
            $this->assertLessThanOrEqual(64, strlen($name));
        }
    }

    /** The connection under test really is SQLite, so the guarantees above mean something. */
    #[Test]
    public function the_suite_runs_on_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }
}
