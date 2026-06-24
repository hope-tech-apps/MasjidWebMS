<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * azkar_categories.order
 *
 * Adds an explicit display order so the admin-managed azkar category list can be
 * sorted the same way hadith categories are (ordered ascending by `order`). The
 * azkar_categories table already exists with the nullable `azkar_category_id` FK
 * on the azkar table; this only adds the ordering column.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('azkar_categories', function (Blueprint $table) {
            $table->integer('order')->default(0)->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('azkar_categories', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }
};
