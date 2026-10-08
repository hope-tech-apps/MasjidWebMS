<?php

// Guarded throwaway MySQL only. Connection config travels privately on stdin.
require_once __DIR__.'/calendarMysqlDiagnostic.php';
try {
    $input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (($input['connection']['driver'] ?? '') !== 'mysql' || ! str_ends_with($input['connection']['database'] ?? '', '_test')) throw new RuntimeException('Refusing a non-test MySQL database');
    require $input['root'].'/vendor/autoload.php';
    $app = require $input['root'].'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['app.env' => 'testing', 'cache.default' => 'array', 'database.default' => 'calendar_worker', 'database.connections.calendar_worker' => $input['connection']]);
    Illuminate\Support\Facades\DB::beginTransaction();
    // Existing PK only, quoted by the query builder. No INSERTs in this worker.
    $year = Illuminate\Support\Facades\DB::table('school_years')->where('id', $input['year'])->where('masjid_id', $input['org'])->lockForUpdate()->first();
    if (! $year) throw new RuntimeException('Worker year missing');
    fwrite(STDOUT, 'locked '.Illuminate\Support\Facades\DB::scalar('SELECT CONNECTION_ID()')."\n"); fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'release') throw new RuntimeException('Worker handshake failed');
    Illuminate\Support\Facades\DB::commit();
    fwrite(STDOUT, "released\n");
} catch (Throwable $e) {
    fwrite(STDOUT, 'failed '.get_class($e).' '.calendarMysqlFailureContext($e, 'school_years', 'id')."\n");
    exit(2);
}
