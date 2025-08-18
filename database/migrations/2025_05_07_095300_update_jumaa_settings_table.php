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
        Schema::table('jumaa_settings', function (Blueprint $table) {
            $table->json('athans')->nullable()->after('masjid_id');
            $table->dropColumn('additional_athans');
            $table->dropColumn('begins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jumaa_settings', function (Blueprint $table) {
            $table->time('begins')->after('masjid_id');
            $table->json('additional_athans')->nullable()->after('iqama');
            $table->dropColumn('athans');
        });
    }
};
