<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An office-chosen display order for groups (owner, 2026-09-21: "sort them in
 * grade order"). Alphabetical put "PreK" and "Kindergarten" after "9th-11th
 * Grade"; no name sorts a school's grades correctly, and guessing an order from
 * the name would break the moment a class is renamed. NULL = no position, which
 * sorts after every positioned group and then by name, so every existing
 * organisation lists exactly as before until someone sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedSmallInteger('position')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
