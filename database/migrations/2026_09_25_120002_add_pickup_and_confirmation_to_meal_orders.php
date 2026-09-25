<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an order on a CATALOGUE menu carries that a Friday-lunch order does not.
 *
 *  - `pickup_at`: when the customer will collect (a UTC instant, read and shown
 *    in the organisation's timezone). NULL on every dated order.
 *  - `preferred_payment`: how the customer SAID they will pay when they chose an
 *    offline method (App\Support\PaymentMethods — cash, check, zelle,
 *    bank_transfer, other). It is a promise, not a payment: `payment_status`
 *    still moves only through the webhook or Mark paid, and Mark paid records
 *    how the money actually came in `paid_via`. 16 characters fits every key.
 *  - `confirmed_at` / `confirmed_by_user_id`: the office's confirmation (the
 *    owner's "office confirms"). A catalogue order stays `pending` until then,
 *    paid or not. A plain column, not a foreign key: adding a constrained FK to
 *    an existing table makes SQLite rebuild it, and the user who confirmed is
 *    kept even if their login is later removed.
 *  - `office_notified_at`: the office's "new order" email, claimed with a
 *    conditional UPDATE before it is queued, the way `confirmation_sent_at` is,
 *    so the two success events Stripe sends for one payment notify once.
 *  - `customer_confirmed_sent_at`: the same claim for the customer's "your order
 *    is confirmed" email, so un-confirming and confirming again cannot send it
 *    twice.
 *
 * None is personal data and no name matches the staging scrub's PII tokens.
 * No index: each is read with its row, never filtered on across a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->dateTime('pickup_at')->nullable();
            $table->string('preferred_payment', 16)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $table->dateTime('office_notified_at')->nullable();
            $table->dateTime('customer_confirmed_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->dropColumn([
                'pickup_at',
                'preferred_payment',
                'confirmed_at',
                'confirmed_by_user_id',
                'office_notified_at',
                'customer_confirmed_sent_at',
            ]);
        });
    }
};
