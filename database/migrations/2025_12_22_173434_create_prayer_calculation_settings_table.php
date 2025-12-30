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
        Schema::create('prayer_calculation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->unique()->constrained()->onDelete('cascade');
            $table->string('method')->default('MoonsightingCommittee');
            $table->string('madhab')->default('Shafi');
            $table->string('high_latitude_rule')->default('MiddleOfTheNight');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prayer_calculation_settings');
    }
};
