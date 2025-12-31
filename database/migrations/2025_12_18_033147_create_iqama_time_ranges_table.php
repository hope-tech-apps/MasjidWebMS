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
        Schema::create('iqama_time_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iqama_time_setting_id')->constrained('iqama_time_settings')->onDelete('cascade');
            $table->enum('salah', ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha']);
            $table->date('start_date');
            $table->date('end_date');
            $table->time('specific_time');
            $table->timestamps();

            // Index for faster queries
            $table->index(['iqama_time_setting_id', 'salah']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iqama_time_ranges');
    }
};
