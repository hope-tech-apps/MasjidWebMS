<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_weeks', function (Blueprint $table) {
            $table->text('standard_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_weeks', function (Blueprint $table) {
            $table->dropColumn('standard_description');
        });
    }
};
