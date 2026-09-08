<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The line items of a meal order. Name and unit price are SNAPSHOTTED onto the
 * row so an order stays legible after a menu item is renamed, repriced, or
 * deleted (`meal_menu_item_id` then goes null but the history stands).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_menu_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('item_name');                    // snapshot
            $table->unsignedBigInteger('unit_price_minor'); // snapshot, cents
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total_minor');

            $table->timestamps();

            $table->index(['masjid_id', 'meal_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_order_items');
    }
};
