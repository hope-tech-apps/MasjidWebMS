<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the money for a lunch order came when staff marked it paid by hand: cash,
 * zelle, terminal (the masjid's card terminal) or stripe (some other Stripe
 * route, such as the organisation's own link), MealOrder::PAID_VIA. The owner
 * asked for it on 2026-09-11 so the office can tell how each order was paid.
 *
 * NULL means the board recorded no method: an unpaid order, an order Stripe
 * marked paid through its own Checkout page (read as paid online by card), or a
 * pay-at-pickup order marked paid before the board asked. Those rows are not
 * backfilled: "cash" would be a guess written into a money record.
 *
 * A short string, because the only values are the model's constants. No index,
 * since nothing filters on it, and no foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->string('paid_via', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->dropColumn('paid_via');
        });
    }
};
