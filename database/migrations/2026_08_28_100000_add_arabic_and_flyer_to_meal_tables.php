<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional Arabic for a menu item (shown on the public page when the visitor
 * picks Arabic; falls back to the English name when absent), and an optional
 * flyer image for the whole menu. All nullable and additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_menu_items', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('name');
            $table->text('description_ar')->nullable()->after('description');
        });

        Schema::table('meal_menus', function (Blueprint $table) {
            $table->string('flyer_image_url')->nullable()->after('pickup_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('meal_menu_items', function (Blueprint $table) {
            $table->dropColumn(['name_ar', 'description_ar']);
        });

        Schema::table('meal_menus', function (Blueprint $table) {
            $table->dropColumn('flyer_image_url');
        });
    }
};
