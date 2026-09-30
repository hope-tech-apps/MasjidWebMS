<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind the application TestCase to every test under Feature/ and Unit/ so
| Pest boots the Laravel application (and the `config`, `db`, etc. container
| bindings) before each test's own setUp() runs.
|
*/

uses(TestCase::class)->in('Feature', 'Unit');

/*
| The MySQL-only suite (tests/Mysql). Skipped anywhere but MySQL, and refused
| outright on a database whose name does not end in "_test": these tests run
| DDL and fixtures, and the only MySQL they may ever meet is CI's throwaway one.
*/
uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->group('mysql')
    ->beforeEach(function () {
        if (Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only; runs in CI as pest --group=mysql.');
        }

        $database = (string) Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run the MySQL suite against '{$database}': the database name must end in _test.");
        }
    })
    ->in('Mysql');
