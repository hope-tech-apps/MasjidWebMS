<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring (monthly / yearly) giving.
 *
 * Data model, chosen to keep receipts and year-end statements uniform:
 *
 *   - `donation_subscriptions` is the standing commitment — "this donor gives
 *     $50/month to the Zakat fund." One row per Stripe subscription.
 *   - Each successful charge still lands as an ordinary `donations` row
 *     (type = 'recurring') linked back by `stripe_subscription_id`. So a receipt
 *     is issued per payment exactly as for a one-time gift, and a year-end
 *     statement is just "sum this contact's succeeded donations" — recurring and
 *     one-time alike, no special-casing.
 *
 * The `donations.type` enum already had 'recurring'; only the subscription link
 * column was missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            // Links a per-invoice donation row back to its standing commitment.
            $table->string('stripe_subscription_id')->nullable()->after('stripe_checkout_session_id');
            $table->index('stripe_subscription_id');
        });

        Schema::create('donation_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                 // opaque external handle
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fund_id')->constrained();

            // What the donor committed to, in integer minor units (cents).
            $table->unsignedBigInteger('intended_amount');
            $table->unsignedBigInteger('charged_amount');
            $table->char('currency', 3)->default('usd');
            $table->boolean('donor_covers_fees')->default(false);
            $table->enum('interval', ['month', 'year'])->default('month');

            // 'pending' until the first invoice is paid; then 'active'. Mirrors the
            // Stripe subscription lifecycle we care about.
            $table->enum('status', ['pending', 'active', 'past_due', 'canceled'])->default('pending');

            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_customer_id')->nullable();

            // Connect fee for subscriptions is a PERCENT (application_fee_percent),
            // not the fixed application_fee_amount one-time charges use. Stored for
            // auditing what was actually sent to Stripe.
            $table->decimal('application_fee_percent', 5, 2)->nullable();

            $table->string('idempotency_key')->unique();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['masjid_id', 'status']);
            $table->index('stripe_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_subscriptions');

        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropColumn('stripe_subscription_id');
        });
    }
};
