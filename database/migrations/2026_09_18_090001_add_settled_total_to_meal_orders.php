<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a PAID lunch order's total was when the money settled.
 *
 * Until orders could be edited, `total_minor` answered both "what does this
 * order cost?" and "what was paid for it?", because a paid order's total never
 * moved. Staff can now change a paid order's items — a customer who turns up
 * wanting an extra plate — and the two questions come apart: the new total is
 * what the food costs, and this column is what the masjid actually has.
 *
 * Written ONCE, by the first edit of an order that is already paid, with the
 * total as it stood before that edit. Never written for an unpaid order (nothing
 * has settled), never rewritten afterwards, and never touched by Stripe or by
 * Mark paid. NULL therefore means "nothing has been edited since the money came",
 * and the order's own total is the amount settled — which is what
 * `MealOrder::balance_minor` falls back to, so no row has to be backfilled.
 *
 * The difference (total_minor - settled) is the balance the board shows: positive
 * is still owed, negative is owed back. Nothing in the system settles it; a
 * balance is a fact staff act on, not a payment.
 *
 * No index: it is read with the row, never filtered on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('settled_total_minor')->nullable()->after('total_minor');
        });
    }

    public function down(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->dropColumn('settled_total_minor');
        });
    }
};
