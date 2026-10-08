<?php

// tests/Mysql uses RefreshDatabase: its uncommitted rows cannot prove concurrency.
// This directory shares the MySQL/*_test guards, commits fixtures, and cleans them up.
use Illuminate\Support\Facades\DB;

it('admits only one of two concurrent first reservations at a cap of one', function (string $kind) {
    $scope = $kind === 'platform' ? 'platform' : 'org:'.random_int(1000000, 9999999);
    $period = '2099-01-01';
    $workers = [];
    try {
        DB::table('guide_ask_counters')->where('scope_key', $scope)->where('period', $period)->delete();
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/guideAskReserveWorker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            expect(is_resource($process))->toBeTrue();
            $workers[] = [$process, $pipes];
            stream_set_timeout($pipes[1], 10);
            fwrite($pipes[0], json_encode(['root' => base_path(), 'connection' => DB::connection()->getConfig(), 'scope' => $scope, 'period' => $period])."\n");
            expect(trim((string) fgets($pipes[1])))->toBe('ready');
        }
        // Both connections exist before either receives permission to run its statement.
        foreach ($workers as [$process, $pipes]) fwrite($pipes[0], "go\n");
        $results = [];
        foreach ($workers as [$process, $pipes]) $results[] = trim((string) fgets($pipes[1]));
        sort($results);
        expect($results)->toBe(['0', '1']);
        expect((int) DB::table('guide_ask_counters')->where('scope_key', $scope)->where('period', $period)->value('count'))->toBe(1);
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) fclose($pipe);
            proc_close($process);
        }
        DB::table('guide_ask_counters')->where('scope_key', $scope)->where('period', $period)->delete();
    }
})->with(['organisation', 'platform']);
