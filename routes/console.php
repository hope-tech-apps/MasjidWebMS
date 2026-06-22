<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune expired Sanctum personal access tokens daily so the
// personal_access_tokens table stays bounded (tokens expire after 8h via
// config/sanctum.php, but expired rows linger until pruned). Requires the
// system cron to run `php artisan schedule:run` every minute — see
// deploy/README.md.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
