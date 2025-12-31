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
            $table->boolean('show_iqama_times')->default(true)->after('iqama_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('iqama_time_settings', function (Blueprint $table) {
            $table->dropColumn('show_iqama_times');
        });
    }
};
