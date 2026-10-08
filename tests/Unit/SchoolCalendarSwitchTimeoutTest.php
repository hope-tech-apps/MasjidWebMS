<?php

use App\Models\Masjid;
use App\Support\SchoolCalendarCapabilityWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('sets five seconds before the switch and restores the previous MySQL session value on every outcome', function (int $code) {
    $org = new Masjid;
    $org->id = 1;
    $events = [];
    DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
    DB::shouldReceive('scalar')->once()->with('SELECT @@SESSION.innodb_lock_wait_timeout')->andReturn(37);
    DB::shouldReceive('statement')->once()->with('SET SESSION innodb_lock_wait_timeout = 5')->andReturnUsing(function () use (&$events) { $events[] = 'short'; return true; });
    DB::shouldReceive('transaction')->once()->with(Mockery::type(Closure::class))->andReturnUsing(function () use (&$events, $code) {
        $events[] = 'transaction';
        if ($code) {
            $cause = new PDOException('Database refusal');
            $cause->errorInfo = ['HY000', $code, 'Database refusal'];
            throw new QueryException('mysql', 'select * from `school_years` for update', [], $cause);
        }
        return ['changed' => ['school_calendar_terms'], 'unchanged' => []];
    });
    DB::shouldReceive('statement')->once()->with('SET SESSION innodb_lock_wait_timeout = 37')->andReturnUsing(function () use (&$events) { $events[] = 'restored'; return true; });
    try {
        $result = SchoolCalendarCapabilityWriter::apply($org, ['school_calendar_terms' => true], 1);
        expect($code)->toBe(0)->and($result['changed'])->toBe(['school_calendar_terms']);
    } catch (ValidationException $e) {
        expect($code)->toBeIn([1205, 1213])->and($e->errors())->toBe(['capability' => ['The school calendar is being edited. Try again in a few moments.']]);
    } catch (QueryException $e) {
        expect($code)->toBe(1364)->and($e->errorInfo[1])->toBe(1364);
    }
    expect($events)->toBe(['short', 'transaction', 'restored']);
})->with([0, 1205, 1213, 1364]);
