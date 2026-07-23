<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Guarantee the Spatie roles/permissions exist after every deploy.
 *
 * There is no CI deploy pipeline (deploys are a manual `git pull`), and the
 * permission seeder was once missed on prod — which 403'd the whole CRM until it
 * was run by hand. Migrations DO run on every deploy, so running the (idempotent)
 * seeder from a migration makes the authorization layer self-healing: any new
 * permission added to the seeder is synced on the next `php artisan migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolesAndPermissionsSeeder())->run();
    }

    public function down(): void
    {
        // Intentionally irreversible — roles/permissions are baseline data, not
        // something a rollback should strip.
    }
};
