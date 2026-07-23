<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * masjid_app_publishing.enabled_platforms
 *
 * Which platforms the masjid actually wants an app for — the Super-Admin picks
 * these in the onboarding wizard's "Platforms" step (iOS, Android, tvOS, Web).
 * Stored as a JSON array of platform slugs, e.g. ["ios","tvos","android"].
 *
 * This is the source of truth for platform SELECTION; the per-platform
 * `*_account_mode` columns only describe HOW an enabled platform ships
 * (managed vs BYO). tvOS has no account_mode of its own — it ships under the
 * iOS Apple Developer account, so selecting `tvos` requires `ios` too (enforced
 * in ProvisionMasjidRequest). The provisioning pipeline reads `tvos` presence
 * to set the iOS job's `include_tvos` flag.
 *
 * Backfill: existing rows predate platform selection and implicitly targeted
 * all three original platforms, so they are set to ["ios","android","web"].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_app_publishing', function (Blueprint $table) {
            $table->json('enabled_platforms')->nullable()->after('web_account_mode');
        });

        // Existing rows implicitly targeted iOS + Android + Web (the original
        // hardcoded set) — record that explicitly so reads are unambiguous.
        DB::table('masjid_app_publishing')
            ->whereNull('enabled_platforms')
            ->update(['enabled_platforms' => json_encode(['ios', 'android', 'web'])]);
    }

    public function down(): void
    {
        Schema::table('masjid_app_publishing', function (Blueprint $table) {
            $table->dropColumn('enabled_platforms');
        });
    }
};
