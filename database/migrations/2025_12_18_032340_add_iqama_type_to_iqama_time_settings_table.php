<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('iqama_time_settings', function (Blueprint $table) {
            $table->string('iqama_type')->default('minutes_after_adhan')->after('masjid_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('iqama_time_settings', function (Blueprint $table) {
            $table->dropColumn('iqama_type');
        });
    }
};
