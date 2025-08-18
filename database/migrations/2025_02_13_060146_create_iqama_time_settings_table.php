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
        Schema::create('iqama_time_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->unique()->constrained()->onDelete('cascade');
            $table->unsignedInteger('fajr')->default(0);
            $table->unsignedInteger('dhuhr')->default(0);
            $table->unsignedInteger('asr')->default(0);
            $table->unsignedInteger('maghrib')->default(0);
            $table->unsignedInteger('isha')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iqama_time_settings');
    }
};
