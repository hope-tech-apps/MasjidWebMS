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
        Schema::table('masjids', function (Blueprint $table) {
            $table->text('copyright_text')->nullable()->after('website_link');
            $table->string('app_store_link')->nullable()->after('copyright_text');
            $table->string('google_play_link')->nullable()->after('app_store_link');
            $table->string('google_maps_key')->nullable()->after('google_play_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->dropColumn(['copyright_text', 'app_store_link', 'google_play_link', 'google_maps_key']);
        });
    }
};

