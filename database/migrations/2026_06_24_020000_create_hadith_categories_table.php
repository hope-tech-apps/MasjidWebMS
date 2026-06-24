<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * hadith_categories
 *
 * Global, admin-managed list of hadith categories. Hadiths reference a category
 * via the nullable `hadith_category_id` FK (added below). Deleting a category
 * detaches its hadiths (set null) rather than cascading the delete.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hadith_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::table('hadiths', function (Blueprint $table) {
            $table->foreignId('hadith_category_id')
                ->nullable()
                ->after('id')
                ->constrained('hadith_categories')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hadiths', function (Blueprint $table) {
            $table->dropForeign(['hadith_category_id']);
            $table->dropColumn('hadith_category_id');
        });

        Schema::dropIfExists('hadith_categories');
    }
};
