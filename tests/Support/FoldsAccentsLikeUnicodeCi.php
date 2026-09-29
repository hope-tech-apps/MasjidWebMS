<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Make the SQLite the suite runs on compare an address the way production's
 * `utf8mb4_unicode_ci` columns do, so a look-alike test can start from the
 * premise that actually caused the defect.
 *
 * SQLite compares bytes. Production's `contacts.login_email`, `contacts.email`
 * and `app_signup_codes.email` do not: there `'victim@gmail.com' =
 * 'victim@gmaíl.com'` is TRUE, and so is `'strasse' = 'straße'`. Every lookup
 * that ends in "and the database said it matched" therefore passes against a
 * look-alike on production and fails to notice one on SQLite, which is how a
 * test suite can be green over a hole. Three seams, each restoring itself:
 *
 *  - `foldAccentsLikeUnicodeCi()` overrides the connection's `LOWER()`, which
 *    every `whereRaw('LOWER(column) = ?')` lookup uses. Store an address in its
 *    ACCENTED form, submit the plain one, and the SQL now returns the row,
 *    as MySQL would. Only a re-check in PHP can refuse it.
 *  - `collateColumnLikeUnicodeCi()` gives a column the same collation, for a
 *    lookup that compares with `where('column', $value)` and never calls LOWER.
 *  - `collateContactLoginEmailIndexLikeUnicodeCi()` does it for the
 *    `(masjid_id, login_email)` unique index, so creating a contact at a
 *    look-alike address collides, as it does on production.
 *
 * The SQLite connection outlives a test (RefreshDatabase keeps the in-memory
 * PDO), so `stopFoldingAccents()` puts `LOWER()` back in `tearDown()`. The
 * schema changes are DDL inside the test's own transaction, which SQLite rolls
 * back with it.
 */
trait FoldsAccentsLikeUnicodeCi
{
    /**
     * What the collation treats as one letter, for the few characters the
     * tests use. Not a Unicode table: a stand-in with the same effect on them.
     *
     * @return array<string, string>
     */
    private static function accentFolds(): array
    {
        return [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss',
        ];
    }

    protected static function foldLikeUnicodeCi(string $value): string
    {
        return strtr(mb_strtolower($value), self::accentFolds());
    }

    /** MySQL has the real collation; only SQLite needs (and can take) the stand-in. */
    private function onSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    protected function foldAccentsLikeUnicodeCi(): void
    {
        if (! $this->onSqlite()) {
            $this->markTestSkipped('This test builds production\'s collation on SQLite; the connection is not SQLite.');
        }

        DB::connection()->getPdo()->sqliteCreateFunction(
            'lower',
            fn ($value) => $value === null ? null : self::foldLikeUnicodeCi((string) $value),
            1,
        );
    }

    /** SQLite's own `LOWER()` folds ASCII only, which `strtolower()` matches since PHP 8.2. */
    protected function stopFoldingAccents(): void
    {
        if (! $this->onSqlite()) {
            return;
        }

        DB::connection()->getPdo()->sqliteCreateFunction(
            'lower',
            fn ($value) => $value === null ? null : strtolower((string) $value),
            1,
        );
    }

    private function registerUnicodeCiCollation(): void
    {
        $pdo = DB::connection()->getPdo();

        if (! method_exists($pdo, 'sqliteCreateCollation')) {
            $this->markTestSkipped('This PDO cannot register a SQLite collation, so the column collation cannot be simulated.');
        }

        $pdo->sqliteCreateCollation(
            'UNICODE_CI_LIKE',
            fn ($a, $b) => strcmp(self::foldLikeUnicodeCi((string) $a), self::foldLikeUnicodeCi((string) $b)),
        );
    }

    /**
     * Rebuild an EMPTY table with `$column` collated like production's. Call it
     * before the test writes a row: the table is dropped and recreated from its
     * own DDL, and its indexes with it.
     */
    protected function collateColumnLikeUnicodeCi(string $table, string $column): void
    {
        $this->registerUnicodeCiCollation();

        $this->recollateEmptyColumn($table, $column, 'UNICODE_CI_LIKE');
    }

    /**
     * Give a column the collation `utf8mb4_bin` has: byte for byte, so a
     * look-alike spelling is a different value and the unique index over the
     * column lets both spellings hold a row. This is what production's
     * `email_suppressions.email_normalized` is after the
     * `make_email_suppression_key_byte_exact` migration. SQLite's own default is
     * already BINARY, so the rebuild changes nothing today; it is done anyway so
     * the test states the shape it depends on instead of inheriting it, and so a
     * change to the suite's default cannot move it silently.
     *
     * On MySQL the migration has made the column byte-exact, so there is nothing
     * to rebuild: the test's own premise check then reads the real column.
     */
    protected function collateColumnLikeUtf8mb4Bin(string $table, string $column): void
    {
        if (! $this->onSqlite()) {
            return;
        }

        $this->recollateEmptyColumn($table, $column, 'BINARY');
    }

    /** Drop and recreate an EMPTY table from its own DDL with `$collation` on `$column`, indexes included. */
    private function recollateEmptyColumn(string $table, string $column, string $collation): void
    {
        $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty before its column is re-collated.");

        $create = (string) DB::selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table])->sql;
        $indexes = array_map(
            fn ($row) => (string) $row->sql,
            DB::select('select sql from sqlite_master where type = ? and tbl_name = ? and sql is not null', ['index', $table]),
        );

        // An existing `collate` on the column is replaced, not added to: SQLite
        // accepts two, and which one wins is not something a test should rely on.
        $collated = preg_replace('/("' . preg_quote($column, '/') . '"\s+varchar)(?:\s+collate\s+\w+)?/i', '$1 collate ' . $collation, $create, 1, $replaced);

        $this->assertSame(1, $replaced, "Could not find the {$table}.{$column} column definition in: {$create}");

        DB::statement('drop table "' . $table . '"');
        DB::statement($collated);

        foreach ($indexes as $index) {
            DB::statement($index);
        }
    }

    protected function collateContactLoginEmailIndexLikeUnicodeCi(): void
    {
        $this->registerUnicodeCiCollation();

        DB::statement('drop index "contacts_masjid_login_email_unique"');
        DB::statement('create unique index "contacts_masjid_login_email_unique" on "contacts" ("masjid_id", "login_email" collate UNICODE_CI_LIKE)');
    }
}
