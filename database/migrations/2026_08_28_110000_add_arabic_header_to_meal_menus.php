<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional Arabic for a menu's header text (its title and pickup instructions),
 * shown on the public page when the visitor picks Arabic and falling back to the
 * English fields otherwise. Nullable and additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_menus', function (Blueprint $table) {
            $table->string('title_ar')->nullable()->after('title');
            $table->string('pickup_instructions_ar')->nullable()->after('pickup_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('meal_menus', function (Blueprint $table) {
            $table->dropColumn(['title_ar', 'pickup_instructions_ar']);
        });
    }
};
