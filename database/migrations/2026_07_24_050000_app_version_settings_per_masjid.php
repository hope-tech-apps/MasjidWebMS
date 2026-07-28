<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make app_version_settings PER-MASJID.
 *
 * The table was originally global (one row per platform) because version was
 * treated as a property of the app build. With white-labeled per-masjid App
 * Store / Play listings, the emergency gate must be scoped per masjid.
 *
 * Adds a masjid_id FK, backfills the existing global rows to masjid 1
 * (Burlington), and swaps the unique(platform) constraint for a composite
 * unique(masjid_id, platform).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the column nullable first so existing rows can be backfilled.
        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('masjid_id')->nullable()->after('id');
        });

        // 2. Backfill existing global rows to Burlington (masjid 1) — but ONLY if masjid 1
        //    exists. On an established database it does, which is what this migration was
        //    written for. On a FRESH one (a new environment, or CI) the masjids table is
        //    empty, so pointing rows at a masjid that does not exist made step 3's foreign
        //    key fail with a 1452 and aborted the whole migration stack. Provisioning a
        //    new environment from scratch was therefore impossible; found 2026-07-28 by
        //    the MySQL leg of the tests workflow.
        if (DB::table('masjids')->where('id', 1)->exists()) {
            DB::table('app_version_settings')->whereNull('masjid_id')->update(['masjid_id' => 1]);
        }

        // Any row still without a real owner is a leftover global default that cannot be
        // expressed per-masjid. Drop it rather than block the FK: the app writes a fresh
        // row for a masjid the first time one is configured.
        DB::table('app_version_settings')
            ->whereNull('masjid_id')
            ->orWhereNotIn('masjid_id', function ($query) {
                $query->select('id')->from('masjids');
            })
            ->delete();

        // 3. Drop the old unique(platform) index, then make masjid_id NOT NULL,
        //    add the FK, and add the composite unique(masjid_id, platform).
        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->dropUnique('app_version_settings_platform_unique');
        });

        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('masjid_id')->nullable(false)->change();
            $table->foreign('masjid_id')->references('id')->on('masjids')->cascadeOnDelete();
            $table->unique(['masjid_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->dropUnique('app_version_settings_masjid_id_platform_unique');
            $table->dropForeign('app_version_settings_masjid_id_foreign');
            $table->dropColumn('masjid_id');
        });

        // Restore the original global unique(platform) constraint.
        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->unique('platform');
        });
    }
};
