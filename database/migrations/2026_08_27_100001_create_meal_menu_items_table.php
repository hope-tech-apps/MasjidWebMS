<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The items on a meal menu — "Chicken Biryani Plate", "$8". Price is integer
 * minor units (cents), never a float. `max_quantity` is an optional per-item
 * cap the kitchen can set; null = unlimited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_menu_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor')->default(0); // cents
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('max_quantity')->nullable();   // null = no cap
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['masjid_id', 'meal_menu_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_menu_items');
    }
};
