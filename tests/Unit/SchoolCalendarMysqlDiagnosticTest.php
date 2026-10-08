<?php

require_once __DIR__.'/../Support/calendarMysqlDiagnostic.php';

it('prints SQLSTATE driver table and column when the lock worker fails before bootstrap', function () {
    $process = proc_open([PHP_BINARY, base_path('tests/Support/schoolCalendarSwitchWorker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    fwrite($pipes[0], "invalid-json\n"); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    expect(proc_close($process))->toBe(2)
        ->and($errors)->toBe('')
        ->and($output)->toContain('SQLSTATE=not-applicable', 'driver=not-applicable', 'table=school_years', 'column=id');
});

it('identifies the actual failing table and column from a query exception', function () {
    $driver = new PDOException("Field 'reason' doesn't have a default value");
    $driver->errorInfo = ['HY000', 1364, "Field 'reason' doesn't have a default value"];
    $query = new Illuminate\Database\QueryException('mysql', 'insert into `school_closures` (`closed_on`) values (?)', ['2026-10-18'], $driver);
    try {
        calendarMysqlDiagnostic(fn () => throw $query, 'school_calendar', 'unknown');
        $this->fail('Expected diagnostic');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('SQLSTATE=HY000 driver=1364 table=school_closures column=reason');
    }
});
