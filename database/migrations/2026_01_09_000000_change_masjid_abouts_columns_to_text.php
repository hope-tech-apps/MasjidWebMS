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
        Schema::table('masjid_abouts', function (Blueprint $table) {
            $table->text('about')->change();
            $table->text('mission')->change();
            $table->text('vision')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('masjid_abouts', function (Blueprint $table) {
            $table->string('about')->change();
            $table->string('mission')->change();
            $table->string('vision')->change();
        });
    }
};

