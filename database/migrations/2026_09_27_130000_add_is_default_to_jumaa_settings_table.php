<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the stored Jumu'ah time is one nobody gave (Studio W2 S18).
     *
     * Provisioning writes a 13:30 placeholder when the client supplied no
     * Jumu'ah time, and the TV board and the phone apps must not show it.
     * `true` = that placeholder; `false` = supplied (by provisioning or by an
     * admin save); NULL = every row that predates this column, which stays as
     * it is: no backfill, so no live organisation's payload changes.
     *
     * The column is hidden from the model's serialization (JumaaSetting
     * `$hidden`), because the row is emitted raw in /prayers/settings, the
     * admin Jumu'ah screen and every Friday's `prayers.jumaa_data`. It reaches
     * clients only as `jumaa_is_default: true` in /prayers/settings.
     */
    public function up(): void
    {
        Schema::table('jumaa_settings', function (Blueprint $table) {
            $table->boolean('is_default')->nullable()->after('shifts');
        });
    }

    public function down(): void
    {
        Schema::table('jumaa_settings', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
