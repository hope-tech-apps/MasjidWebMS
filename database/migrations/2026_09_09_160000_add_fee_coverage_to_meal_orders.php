<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a customer absorb Stripe's processing fee so the masjid nets the full
 * price of the food.
 *
 * These are Connect DIRECT charges, so Stripe's 2.9% + 30¢ comes out of the
 * ORG's balance: an $8 plate settles at $7.47. The flat 30¢ is what hurts at
 * lunch prices — it is 3.75% of a single plate on its own. The donations module
 * has offered this since it was built (`donations.donor_covers_fees`); the order
 * form is simply catching up.
 *
 *   meal_orders.fee_covered_minor — the surcharge the customer accepted, in
 *   integer minor units, kept in its OWN column so it is never mistaken for
 *   food revenue or for the optional extra. total_minor is
 *   subtotal + donation + fee_covered.
 *
 *   meal_menus.allow_fee_coverage — whether the form makes the offer, per MENU
 *   like every other option on a sale. Defaults to true because it was asked
 *   for; a masjid that would rather not put a surcharge in front of customers
 *   switches it off in the admin.
 *
 * ONLINE ORDERS ONLY. A pay-at-pickup order never touches Stripe, so there is no
 * fee to cover and the column stays 0 — offering it there would be charging for
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->unsignedInteger('fee_covered_minor')->default(0)->after('donation_minor');
        });

        Schema::table('meal_menus', function (Blueprint $table) {
            $table->boolean('allow_fee_coverage')->default(true)->after('allow_donation');
        });
    }

    public function down(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->dropColumn('fee_covered_minor');
        });

        Schema::table('meal_menus', function (Blueprint $table) {
            $table->dropColumn('allow_fee_coverage');
        });
    }
};
