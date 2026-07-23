<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `development_team` to masjid_app_publishing (additive, nullable).
 *
 * This is the Apple Developer Team ID used to sign this masjid's iOS build. It is
 * NOT a secret — the team id is embedded in every distributed build — so it is a
 * plain (unencrypted, non-hidden) column, unlike the ASC .p8 / Play JSON above.
 *
 * It feeds the `development_team` field of the app-provisioning dispatch payload:
 *   - `byo` masjids that publish under their own Apple team store it here;
 *   - `managed` masjids leave it null and the dispatch falls back to the platform
 *     default (config('services.github.development_team')).
 *
 * Nullable + default-null: purely additive; existing rows are unaffected and the
 * managed-tier fallback keeps working with the column empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_app_publishing', function (Blueprint $table) {
            $table->string('development_team')->nullable()->after('asc_issuer_id');
        });
    }

    public function down(): void
    {
        Schema::table('masjid_app_publishing', function (Blueprint $table) {
            $table->dropColumn('development_team');
        });
    }
};
