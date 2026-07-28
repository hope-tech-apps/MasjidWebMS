<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen notifications.message from VARCHAR(255) to TEXT so the inbox can carry
 * a full, long-form body (the push still shows a short blurb, but the in-app
 * detail screen renders the whole message).
 *
 * The notification image reuses the Spatie media_library table (collection
 * "notifications"), so there is no image column to add here.
 *
 * doctrine/dbal is not installed, so we issue the ALTER directly rather than
 * relying on Blueprint ->change().
 *
 * MySQL-only. `ALTER TABLE … MODIFY` is not valid SQLite, and the test suite runs on
 * in-memory SQLite (phpunit.xml), so without this guard the migration aborts and EVERY
 * feature test using RefreshDatabase errors before its first assertion — which is
 * exactly what happened between this migration landing and 2026-07-27. Skipping on
 * SQLite is correct rather than merely convenient: SQLite is dynamically typed and
 * imposes no length limit on a VARCHAR column, so the widening this performs is already
 * true there. Guard style matches the pages migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE notifications MODIFY message TEXT NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE notifications MODIFY message VARCHAR(255) NOT NULL');
    }
};
