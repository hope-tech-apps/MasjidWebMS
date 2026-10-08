<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

final class ClassSubjectWorkerConnection
{
    public static function configure(array $source): void
    {
        // Laravel preserves a copied config's name. Hydrated models must use THIS PDO.
        config(['database.default' => 'class_subject_worker',
            'database.connections.class_subject_worker' => array_replace($source, ['name' => 'class_subject_worker']),
            'cache.default' => 'array']);
        DB::purge('class_subject_worker');
        DB::connection()->getPdo();
    }
}
