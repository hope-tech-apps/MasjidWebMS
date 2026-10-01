<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index on order_items (buyable_type, buyable_id), for the shop's stock hold (shop slice B1).
 *
 * A product's units that are "held" are the quantities on the order lines of PENDING orders for
 * that variant (CartCheckoutService, CartPricer: there is no hold table). That read asks
 * `order_items` for one `buyable_type` and a handful of `buyable_id`s on every priced basket and
 * every checkout of a product. The table had only `order_id` and `(record_type, record_id)`
 * indexes, so without this the question scans every line ever sold, for every shopper.
 *
 * Blueprint only, additive, no data touched. The name is written by hand (MySQL caps an
 * identifier at 64 characters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['buyable_type', 'buyable_id'], 'order_items_buyable_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_buyable_index');
        });
    }
};
