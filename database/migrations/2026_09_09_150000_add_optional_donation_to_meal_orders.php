<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a customer add something on top of what the food costs.
 *
 * Requested by Burlington: people regularly want to pay more than the plate
 * price. Until now the only way to take it was cash in a hand at the table,
 * which nobody recorded.
 *
 * Two columns, deliberately separate:
 *
 *   meal_orders.donation_minor — the extra, in integer minor units, kept APART
 *   from subtotal_minor so the kitchen's food revenue and the extra never get
 *   mixed up in a report. total_minor is subtotal + donation, and every existing
 *   row keeps total == subtotal because this defaults to 0.
 *
 *   meal_menus.allow_donation — whether the order form makes the offer at all,
 *   per MENU like allow_online_payment / collect_customer_email, because it is a
 *   property of how one week's sale is run.
 *
 * allow_donation DEFAULTS TO TRUE: this was asked for, and a masjid that does
 * not want to ask can switch it off in the admin without a deploy. Nothing about
 * an existing ORDER changes either way — donation_minor is 0 on every row
 * written before today.
 *
 * This is an ORDER-LEVEL amount and NOT a donations-ledger entry: it does not
 * create a Donation row, is not designated to a fund, and issues no tax receipt.
 * It settles on the same charge as the food, in the org's own Stripe account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->unsignedInteger('donation_minor')->default(0)->after('subtotal_minor');
        });

        Schema::table('meal_menus', function (Blueprint $table) {
            $table->boolean('allow_donation')->default(true)->after('collect_customer_email');
        });
    }

    public function down(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->dropColumn('donation_minor');
        });

        Schema::table('meal_menus', function (Blueprint $table) {
            $table->dropColumn('allow_donation');
        });
    }
};
