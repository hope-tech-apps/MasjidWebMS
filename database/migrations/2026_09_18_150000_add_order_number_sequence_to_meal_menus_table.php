<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The highest order number a menu has ever HANDED OUT, which is not the same as
 * the highest number it still holds.
 *
 * Numbers used to be derived from the rows present: first by counting them, and
 * a removed order then pointed the next customer at a number already on the
 * board, which the unique index refused — on 2026-09-18 that stopped Burlington
 * taking any further order, at both doors, permanently. Reading the highest
 * number instead fixes the middle of the list, but not the end of it: remove the
 * last order and its number is free again, so two people can still be told the
 * same one.
 *
 * A number is what the kitchen calls out and what the customer answers to, so it
 * must never be given twice. This column remembers, so removing an order can
 * never move the numbering backwards.
 *
 * It starts at 0 on every existing menu on purpose: `nextOrderNumber` takes
 * whichever is higher, this counter or the highest number actually on the menu,
 * so a menu that pre-dates the column carries on from where its orders are
 * without a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_menus', function (Blueprint $table) {
            $table->unsignedInteger('order_number_sequence')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('meal_menus', function (Blueprint $table) {
            $table->dropColumn('order_number_sequence');
        });
    }
};
