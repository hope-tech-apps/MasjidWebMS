<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each recurring charge arrives as its own `invoice.payment_succeeded` webhook and
 * books its own Donation row. Storing the Stripe invoice id lets us dedup by
 * invoice — a second delivery of the same invoice can never book a second
 * donation — and gives a clean audit trail from a donation back to its invoice.
 *
 * (The controller already dedups by event id; this is defence in depth on the
 * money path, plus traceability.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('stripe_invoice_id')->nullable()->after('stripe_subscription_id');
            $table->index('stripe_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex(['stripe_invoice_id']);
            $table->dropColumn('stripe_invoice_id');
        });
    }
};
