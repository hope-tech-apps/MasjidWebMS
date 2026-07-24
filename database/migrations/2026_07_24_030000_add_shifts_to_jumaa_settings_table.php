<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a nullable JSON `shifts` column: an ordered array of richer Jumaa
     * khutbah entries — { time (HH:MM 24h), khateeb_name, khateeb_title,
     * khutbah_title }. The existing `athans` (array of time strings) stays for
     * backward-compat; `shifts` is the richer source of truth when present.
     * Nullable so existing rows keep working unchanged (apps fall back to athans).
     */
    public function up(): void
    {
        Schema::table('jumaa_settings', function (Blueprint $table) {
            $table->json('shifts')->nullable()->after('athans');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jumaa_settings', function (Blueprint $table) {
            $table->dropColumn('shifts');
        });
    }
};
