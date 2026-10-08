<?php

use App\Models\{Masjid, SchoolYear, User};
use App\Support\{CapabilityWriter, SchoolSettings};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/calendarMysqlDiagnostic.php';

it('fails enabling promptly and atomically behind a worker year lock then succeeds after release', function () {
    return calendarMysqlDiagnostic(function () {
        // Audited against real migrations and later additions in mysql-insert-audit.md.
        $org = Masjid::create(['name' => 'Busy School '.uniqid(), 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
        $actor = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $first = SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Earlier year', 'first_day' => '2025-10-12', 'last_day' => '2025-10-26']);
        $year = SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Locked year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-25']);
        $before = $org->fresh()->getRawOriginal();
        $beforeYears = [$first->fresh()->getRawOriginal(), $year->fresh()->getRawOriginal()];
        $previous = (int) DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout');
        $process = null; $pipes = [];
        try {
            // A non-default prior value proves the writer restores its own connection.
            DB::statement('SET SESSION innodb_lock_wait_timeout = 37');
            $process = proc_open([PHP_BINARY, base_path('tests/Support/schoolCalendarSwitchWorker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            expect(is_resource($process))->toBeTrue();
            stream_set_timeout($pipes[1], 15);
            fwrite($pipes[0], json_encode(['root' => base_path(), 'connection' => DB::connection()->getConfig(), 'org' => $org->id, 'year' => $year->id])."\n");
            expect(trim((string) fgets($pipes[1])))->toMatch('/^locked [0-9]+$/');
            $started = microtime(true);
            try {
                CapabilityWriter::apply($org, ['school_calendar_terms' => true], $actor->id);
                $this->fail('Enable must refuse while the worker holds the year');
            } catch (ValidationException $e) {
                expect($e->errors())->toBe(['capability' => ['The school calendar is being edited. Try again in a few moments.']]);
            }
            expect(microtime(true) - $started)->toBeGreaterThan(4.0)->toBeLessThan(9.0);
            expect((int) DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout'))->toBe(37)
                ->and(DB::transactionLevel())->toBe(0)
                ->and($org->fresh()->getRawOriginal())->toBe($before)
                ->and([$first->fresh()->getRawOriginal(), $year->fresh()->getRawOriginal()])->toBe($beforeYears)
                ->and(SchoolSettings::calendarTerms($org->fresh()))->toBeFalse()
                ->and(DB::table('masjid_capability_changes')->where('masjid_id', $org->id)->count())->toBe(0)
                ->and(DB::table('school_closures')->where('masjid_id', $org->id)->count())->toBe(0)
                ->and(DB::table('school_terms')->where('masjid_id', $org->id)->count())->toBe(0);
            fwrite($pipes[0], "release\n");
            expect(trim((string) fgets($pipes[1])))->toBe('released');
            CapabilityWriter::apply($org, ['school_calendar_terms' => true], $actor->id);
            expect(SchoolSettings::calendarTerms($org->fresh()))->toBeTrue()
                ->and($first->fresh()->meeting_weekdays)->toBe([0])
                ->and($year->fresh()->meeting_weekdays)->toBe([0])
                ->and(DB::table('masjid_capability_changes')->where('masjid_id', $org->id)->count())->toBe(1)
                ->and((int) DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout'))->toBe(37);
        } finally {
            if (is_resource($process)) {
                foreach ($pipes as $pipe) fclose($pipe);
                proc_terminate($process);
                proc_close($process);
            }
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.$previous);
            DB::table('masjid_capability_changes')->where('masjid_id', $org->id)->delete();
            DB::table('school_years')->where('masjid_id', $org->id)->delete();
            DB::table('masjids')->where('id', $org->id)->delete();
            DB::table('users')->where('id', $actor->id)->delete();
        }
    }, 'school_years', 'id');
});
