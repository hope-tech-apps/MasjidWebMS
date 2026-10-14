<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Applied curriculum versions share the tenant-owned history and its existing primary key. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_card_criteria_histories', function (Blueprint $table) {
            $table->string('effective_period', 80)->nullable();
            $table->date('effective_on')->nullable();
            $table->json('bands')->nullable();
            $table->index(['masjid_id', 'effective_on', 'id'], 'rc_history_school_effective');
        });
    }

    public function down(): void
    {
        if (DB::table('report_card_criteria_histories')->whereNotNull('bands')->exists()) {
            throw new RuntimeException('Criteria versions exist. Switch off report-card criteria instead.');
        }
        Schema::table('report_card_criteria_histories', function (Blueprint $table) {
            $table->dropIndex('rc_history_school_effective');
            $table->dropColumn(['effective_period', 'effective_on', 'bands']);
        });
    }
};
