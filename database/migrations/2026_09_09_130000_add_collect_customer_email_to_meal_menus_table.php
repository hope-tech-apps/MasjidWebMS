<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the order form asks for an email at all.
 *
 * Requested by Burlington: some weeks they would rather not ask. The address is
 * only ever stored — nothing in this application mails from
 * `meal_orders.customer_email` — so switching it off costs no functionality,
 * and an address you do not collect is one you cannot leak.
 *
 * Per MENU rather than per masjid, matching `allow_online_payment` and
 * `allow_pay_at_pickup`: this is a property of how one week's sale is run.
 *
 * DEFAULTS TO TRUE so every existing and future menu behaves exactly as it does
 * today. A flag that silently removed a field from live order forms on deploy
 * would be a worse bug than the one it solves.
 *
 * Note this does NOT affect paying online: Stripe Checkout collects its own
 * email for the receipt regardless of what this form asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_menus', function (Blueprint $table) {
            $table->boolean('collect_customer_email')->default(true)->after('allow_pay_at_pickup');
        });
    }

    public function down(): void
    {
        Schema::table('meal_menus', function (Blueprint $table) {
            $table->dropColumn('collect_customer_email');
        });
    }
};
