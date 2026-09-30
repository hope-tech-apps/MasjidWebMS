<?php

/*
|--------------------------------------------------------------------------
| The MySQL-only suite (group "mysql")
|--------------------------------------------------------------------------
|
| Properties SQLite cannot show: STORED generated columns and their unique
| indexes, real column types (text vs mediumText), the 64-character identifier
| cap, collation, row locks and FK RESTRICT. The ordinary suite pins SQLite in
| memory; this directory runs only in CI's migrations-mysql job, as
| `pest --group=mysql`, against a throwaway database named *_test.
|
| Excluded by default in phpunit.xml, and guarded twice (tests/Pest.php): off
| MySQL every test here is skipped, and against a database whose name does
| not end in "_test" it fails before touching anything. The droplet runner is
| the production box; nothing here may ever meet its database.
*/

use Illuminate\Support\Facades\DB;

it('runs on the MySQL major version production runs', function () {
    // Production: 8.4 on DigitalOcean's managed cluster (read 2026-09-30:
    // 8.4.8). A CI service on another major version would test the wrong
    // server; bump both together.
    $version = DB::selectOne('SELECT VERSION() AS v')->v;

    expect(version_compare($version, '8.4.0', '>='))->toBeTrue("MySQL {$version} is older than production's 8.4")
        ->and(version_compare($version, '8.5.0', '<'))->toBeTrue("MySQL {$version} is newer than production's 8.4");
});

it('keeps contacts.email accent-insensitive, which is why addresses are matched in PHP', function () {
    // Pins the reason ContactIdentity's exact helpers exist: under the column's
    // collation SQL `=` treats "é" and "e" as the same letter, so a lookup by
    // address in SQL can match someone else's account. If this ever fails the
    // collation changed, and the exact-helper rule should be re-read, not
    // silently relied on.
    $collation = DB::selectOne(
        "SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'email'"
    )->c;

    $equal = DB::selectOne("SELECT ('gmaíl@example.test' COLLATE {$collation}) = ('gmail@example.test' COLLATE {$collation}) AS eq")->eq;

    expect((int) $equal)->toBe(1);
});
