<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design-tokenization phase 1: store an optional JSON `tokens` override tree on
 * theme_settings. The served token set is DERIVED from the four base colors by
 * App\Support\DesignTokens; this column holds any per-masjid overrides that a
 * Brand Studio deep-merges over the derived defaults. Additive + nullable — the
 * legacy {primary,secondary,accent,background} payload is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theme_settings', function (Blueprint $table) {
            $table->json('tokens')->nullable()->after('background_color');
        });
    }

    public function down(): void
    {
        Schema::table('theme_settings', function (Blueprint $table) {
            $table->dropColumn('tokens');
        });
    }
};
