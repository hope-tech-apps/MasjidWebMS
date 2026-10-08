<?php

// Separate MySQL connection, without app providers, HTTP, keys, or a test transaction.
// The parent sends only its guarded throwaway DB config on stdin; nothing is logged.
try {
    $input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $config = $input['connection'];
    if (($config['driver'] ?? '') !== 'mysql' || ! str_ends_with($config['database'] ?? '', '_test')) exit(2);
    require $input['root'].'/vendor/autoload.php';
    $container = new Illuminate\Container\Container;
    $container->instance('config', new Illuminate\Config\Repository(['database' => ['default' => 'mysql', 'connections' => ['mysql' => $config]]]));
    $factory = new Illuminate\Database\Connectors\ConnectionFactory($container);
    $container->instance('db', new Illuminate\Database\DatabaseManager($container, $factory));
    Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
    fwrite(STDOUT, "ready\n");
    if (trim(fgets(STDIN)) !== 'go') exit(2);
    $ok = (new App\Support\Guides\GuideAskLimits)->reserveBucket($input['scope'], $input['period'], 1);
    fwrite(STDOUT, $ok ? "1\n" : "0\n");
} catch (Throwable) { exit(2); }
