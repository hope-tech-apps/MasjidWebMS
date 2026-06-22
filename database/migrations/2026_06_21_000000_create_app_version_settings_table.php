<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * app_version_settings
 *
 * Global (per-platform) emergency app-control config. One row per platform
 * (ios / android). Read on app launch via GET /mobile/app-config; clients
 * hard-gate when below minimum_version/minimum_build with force_update on.
 *
 * Three layered controls, all admin-editable (no app rebuild, no store
 * review wait — the whole point in an emergency):
 *   - force_update      → hard "Update Required" wall below minimum
 *   - maintenance_mode  → "we'll be right back" kill switch
 *   - latest_version    → soft, dismissible "update recommended" prompt
 *
 * Global (not per-masjid) because version is a property of the app build,
 * not masjid content. If white-labeled to multiple App Store listings
 * later, add a masjid_id column + composite key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_version_settings', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20)->unique();   // 'ios' | 'android'

            // Hard gate.
            $table->string('minimum_version', 20)->default('0.0.0'); // semantic, e.g. 2.5.0
            $table->unsignedInteger('minimum_build')->default(0);    // CFBundleVersion / versionCode
            $table->boolean('force_update')->default(false);
            $table->string('update_message', 500)->nullable();

            // Soft prompt (dismissible).
            $table->string('latest_version', 20)->nullable();

            // Where "Update Now" sends the user. Admin-editable so it can
            // point at TestFlight pre-launch and the store post-launch.
            $table->string('store_url', 500)->nullable();

            // Kill switch.
            $table->boolean('maintenance_mode')->default(false);
            $table->string('maintenance_message', 500)->nullable();

            $table->timestamps();
        });

        // Seed both platforms with safe defaults (nothing gated).
        $now = now();
        DB::table('app_version_settings')->insert([
            [
                'platform' => 'ios',
                'minimum_version' => '0.0.0',
                'minimum_build' => 0,
                'force_update' => false,
                'update_message' => 'A new version is required to continue. Please update to the latest version.',
                'latest_version' => null,
                'store_url' => 'https://apps.apple.com/app/id1514502928',
                'maintenance_mode' => false,
                'maintenance_message' => 'We are performing scheduled maintenance. Please check back shortly, in shaa Allah.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'platform' => 'android',
                'minimum_version' => '0.0.0',
                'minimum_build' => 0,
                'force_update' => false,
                'update_message' => 'A new version is required to continue. Please update to the latest version.',
                'latest_version' => null,
                'store_url' => 'https://play.google.com/store/apps/details?id=com.app.masajid',
                'maintenance_mode' => false,
                'maintenance_message' => 'We are performing scheduled maintenance. Please check back shortly, in shaa Allah.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_version_settings');
    }
};
