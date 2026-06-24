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
        Schema::table('services', function (Blueprint $table) {
            $table->string('summary')->nullable()->after('title');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->string('summary')->nullable()->after('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('summary');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
