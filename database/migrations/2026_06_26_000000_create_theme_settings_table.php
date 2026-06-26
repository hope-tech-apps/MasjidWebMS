<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * theme_settings
 *
 * Per-masjid color theme. One row per masjid (unique masjid_id, cascade on
 * delete). The same four colors are surfaced — identically shaped — on both the
 * web (/api/v1/settings) and mobile (/api/mobile/masjids/{id}) surfaces so the
 * Nuxt site and the apps read one source of truth.
 *
 * Columns are string(9) so they can hold a hex value with optional alpha
 * (#RRGGBBAA) as well as the common #RRGGBB. All nullable: when a column (or the
 * whole row) is absent the API returns null for `theme` and clients fall back to
 * their built-in defaults — zero regression for masjids without a configured
 * theme.
 *
 * Mirrors prayer_calculation_settings (schema style) and app_version_settings
 * (inline seed) — see those migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->unique()->constrained()->onDelete('cascade');
            $table->string('primary_color', 9)->nullable();
            $table->string('secondary_color', 9)->nullable();
            $table->string('accent_color', 9)->nullable();
            $table->string('background_color', 9)->nullable();
            $table->timestamps();
        });

        // Seed masjid_id 1 with the current brand palette so the live masjid has
        // a theme immediately. Guarded so the migration is safe on a fresh DB
        // (masjid 1 may not exist yet, e.g. in tests) and never double-inserts.
        if (
            DB::table('masjids')->where('id', 1)->exists() &&
            ! DB::table('theme_settings')->where('masjid_id', 1)->exists()
        ) {
            $now = now();
            DB::table('theme_settings')->insert([
                'masjid_id' => 1,
                'primary_color' => '#01b151',
                'secondary_color' => '#1b1b2e',
                'accent_color' => '#ffba63',
                'background_color' => '#f3f8fb',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_settings');
    }
};
