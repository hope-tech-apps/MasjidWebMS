<?php

namespace Tests\Feature;

use App\Support\ScrubStrategies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The META-TEST for the staging scrub, in the shape of `TenantScopingCoverageTest`.
 *
 * ## Why this file exists
 *
 * `config/staging_scrub.php` is a transcription of a PII inventory taken on one
 * particular day against one particular schema. Nothing about a config file
 * notices when the schema moves underneath it. Six months from now somebody
 * will add `contacts.emergency_contact_phone` in a migration, the scrub will
 * not know about it, `staging:scrub` will exit 0, and a real phone number will
 * sit on a box that a contractor has ssh access to — with no error anywhere,
 * because an UPDATE that was never written cannot fail.
 *
 * So the schema itself is the source of truth here, not the config. This test
 * walks every table and column the migrations actually create, matches each
 * column name against a list of personal-data-shaped tokens, and requires that
 * each match be accounted for in one of four ways:
 *
 *   - its table is in `drop_rows` (the rows are deleted, so every column goes);
 *   - the column is in `null_columns` or `encrypted_null`;
 *   - the column is in `anonymise`;
 *   - or the column is in `reviewed_keep` WITH A ONE-LINE REASON — the escape
 *     hatch, which exists so that "we looked at it and it is fine" is a
 *     recorded decision rather than an omission that looks identical to an
 *     oversight.
 *
 * Adding a column tomorrow puts it in scope tomorrow, with no edit to this
 * file. When the suite goes red on a new migration, DECIDE — do not delete the
 * test, and do not reach for `reviewed_keep` without meaning the sentence you
 * write in it.
 *
 * ## Why the tokens are matched per underscore-delimited segment
 *
 * The brief's token list contains `ip`, and `ip` as a bare substring matches
 * `description`, `recipient`, `equipment`, `participant` and `multiple`. Matched
 * that way the guard would demand a `reviewed_keep` entry for several hundred
 * obviously-harmless columns, and a guard that cries wolf three hundred times
 * is a guard somebody switches off. Segments (`ip_address` matches, `description`
 * does not) keep every genuine hit and drop the noise.
 *
 * `note` is added to the brief's list because this schema spells the column
 * singular far more often than plural, and every one of those is free text
 * about a person. Adding a token only ever makes the guard stricter.
 */
class StagingScrubCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Column-name segments that mean "this may be personal data".
     *
     * @var list<string>
     */
    private const PII_TOKENS = [
        'email', 'phone', 'first_name', 'last_name', 'name', 'address', 'dob',
        'birth', 'token', 'secret', 'ip', 'user_agent', 'evidence', 'notes',
        'note', 'message', 'body', 'content', 'data', 'payload',
        'subscription_id', 'device',
    ];

    /**
     * Tables that are not part of the application schema and carry no rows a
     * scrub could reach.
     *
     * @var list<string>
     */
    private const NON_APPLICATION_TABLES = ['migrations', 'sqlite_sequence'];

    #[Test]
    public function every_pii_shaped_column_is_dropped_nulled_anonymised_or_reviewed(): void
    {
        $config = config('staging_scrub');

        $uncovered = [];

        foreach ($this->applicationTables() as $table) {
            if (array_key_exists($table, $config['drop_rows'])) {
                // Every row goes, so every column goes with it.
                continue;
            }

            foreach (Schema::getColumnListing($table) as $column) {
                if (! $this->looksLikePii($column)) {
                    continue;
                }

                if ($this->isCovered($config, $table, $column)) {
                    continue;
                }

                $uncovered[] = "{$table}.{$column}";
            }
        }

        sort($uncovered);

        $this->assertSame([], $uncovered, sprintf(
            "%d personal-data-shaped column(s) are invisible to config/staging_scrub.php.\n\n"
            ."Each must be added to `drop_rows`, `null_columns`, `encrypted_null` or `anonymise`,\n"
            ."or to `reviewed_keep` with a one-line reason saying why its CONTENT is not personal\n"
            ."data even though its NAME looks like it.\n\n  - %s\n",
            count($uncovered),
            implode("\n  - ", $uncovered),
        ));
    }

    #[Test]
    public function the_reviewed_keep_list_does_not_outlive_its_reason(): void
    {
        $config = config('staging_scrub');

        foreach ($config['reviewed_keep'] as $target => $reason) {
            [$table, $column] = array_pad(explode('.', (string) $target, 2), 2, null);

            $this->assertNotNull($column, "`{$target}` in reviewed_keep must be spelled `table.column`.");
            $this->assertNotEmpty(trim((string) $reason), "`{$target}` in reviewed_keep must carry a one-line reason.");

            $this->assertTrue(
                Schema::hasTable($table) && Schema::hasColumn($table, $column),
                "`{$target}` is listed in reviewed_keep but no longer exists in the schema. Delete the entry."
            );

            $this->assertFalse(
                array_key_exists($table, $config['drop_rows']),
                "`{$target}` is in reviewed_keep, but `{$table}` is also in drop_rows. Its rows are deleted, so the entry is dead; remove it."
            );

            $this->assertFalse(
                $this->isScrubbed($config, $table, $column),
                "`{$target}` is in reviewed_keep AND is scrubbed. One of the two is wrong — decide which."
            );
        }
    }

    #[Test]
    public function every_configured_table_and_column_still_exists(): void
    {
        $config = config('staging_scrub');

        foreach (array_keys($config['drop_rows']) as $table) {
            $this->assertTrue(Schema::hasTable($table), "config/staging_scrub.php drops `{$table}`, which no longer exists.");
        }

        foreach (['encrypted_null', 'null_columns'] as $section) {
            foreach ($config[$section] as $table => $columns) {
                $this->assertTrue(Schema::hasTable($table), "config/staging_scrub.php `{$section}` names `{$table}`, which no longer exists.");

                foreach ($columns as $column) {
                    $this->assertTrue(
                        Schema::hasColumn($table, $column),
                        "config/staging_scrub.php `{$section}` names `{$table}.{$column}`, which no longer exists."
                    );
                }
            }
        }

        foreach ($config['anonymise'] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "config/staging_scrub.php `anonymise` names `{$table}`, which no longer exists.");

            foreach ($columns as $column => $spec) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "config/staging_scrub.php `anonymise` names `{$table}.{$column}`, which no longer exists."
                );

                $strategy = is_array($spec) ? ($spec['strategy'] ?? '') : $spec;

                $this->assertTrue(
                    in_array($strategy, ScrubStrategies::SIMPLE_STRATEGIES, true)
                        || in_array($strategy, ScrubStrategies::PHP_STRATEGIES, true)
                        || str_starts_with((string) $strategy, 'fixed:')
                        || str_starts_with((string) $strategy, 'label:'),
                    "`{$table}.{$column}` uses unknown strategy `{$strategy}`."
                );
            }
        }
    }

    /**
     * The generated-column trap, pinned.
     *
     * `masjids.active_owner_user_id`, `masjids.active_stripe_account_id` and
     * `masjid_user.default_key` back CONDITIONAL unique indexes — "unique among
     * the rows that still count". MySQL has no partial indexes at any version,
     * so the predicate is expressed as a generated column plus a plain unique
     * index; sqlite has real partial indexes and therefore has no such column at
     * all (`.claude/rules/migrations.md`). That divergence is by design, so this
     * test asserts the part that is true on BOTH drivers — the config never
     * targets one of these — and asserts the column's existence only on the
     * driver that has it. Asserting existence unconditionally would fail the
     * whole suite on sqlite for a schema that is correct.
     */
    #[Test]
    public function generated_columns_are_never_written(): void
    {
        $config = config('staging_scrub');

        $this->assertNotEmpty($config['never_write'], 'never_write must list the generated columns backing the conditional unique indexes.');

        foreach ($config['never_write'] as $target => $reason) {
            [$table, $column] = array_pad(explode('.', (string) $target, 2), 2, null);

            $this->assertNotEmpty(trim((string) $reason), "`{$target}` in never_write must carry a reason.");

            $this->assertTrue(
                Schema::hasTable($table),
                "`{$target}` is in never_write but table `{$table}` no longer exists. Delete the entry — and check the unique index it backed."
            );

            $this->assertFalse(
                $this->isScrubbed($config, $table, $column),
                "`{$target}` is a generated column and must never be written, but the scrub config targets it."
            );

            // On MySQL the column is really there and really unwritable. On
            // sqlite the same guarantee is carried by a partial index instead.
            if (DB::connection()->getDriverName() === 'mysql') {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "`{$target}` is in never_write but the generated column is gone from MySQL. Delete the entry — and check the unique index it backed."
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** @return list<string> */
    private function applicationTables(): array
    {
        $tables = array_map(
            static fn (array $t) => (string) $t['name'],
            Schema::getTables(),
        );

        $tables = array_values(array_diff($tables, self::NON_APPLICATION_TABLES));

        // A schema that came back empty would make every assertion below pass
        // vacuously, which is the one way this test could lie.
        $this->assertGreaterThan(50, count($tables), 'The schema walk found almost no tables — the migrations did not run.');

        sort($tables);

        return $tables;
    }

    private function looksLikePii(string $column): bool
    {
        $pattern = '/(?:^|_)(?:'.implode('|', self::PII_TOKENS).')(?:$|_)/i';

        return preg_match($pattern, $column) === 1;
    }

    private function isCovered(array $config, string $table, string $column): bool
    {
        return $this->isScrubbed($config, $table, $column)
            || (
                array_key_exists("{$table}.{$column}", $config['reviewed_keep'])
                && trim((string) $config['reviewed_keep']["{$table}.{$column}"]) !== ''
            );
    }

    private function isScrubbed(array $config, string $table, string $column): bool
    {
        return in_array($column, $config['null_columns'][$table] ?? [], true)
            || in_array($column, $config['encrypted_null'][$table] ?? [], true)
            || array_key_exists($column, $config['anonymise'][$table] ?? []);
    }
}
