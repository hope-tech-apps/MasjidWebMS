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
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications MODIFY message TEXT NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications MODIFY message VARCHAR(255) NOT NULL');
    }
};
