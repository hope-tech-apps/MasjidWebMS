<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * donations — one donor gift. Tenant-scoped by masjid_id (BelongsToMasjid).
 *
 * Money is stored ONLY in integer minor units (cents); never floats. Stripe is
 * the source of truth: a row is written `pending` BEFORE the redirect, then
 * driven to `succeeded`/`failed` by webhooks (never by the browser redirect).
 * `idempotency_key` de-dupes the Checkout Session create against Stripe; the
 * `stripe_*` columns are filled in from webhook events as they arrive.
 *
 *  - intended_amount  : what the donor chose to give.
 *  - charged_amount   : what the card is actually charged (== intended, unless
 *                       donor_covers_fees grossed it up so the org nets intended).
 *  - application_fee_amount : the platform's cut taken via Connect (may be null).
 *  - stripe_fee_amount / net_amount : Stripe's processing fee and the org's net,
 *                       resolved from the balance transaction on success.
 *  - receipt_eligible_amount : the tax-eligible portion (gross − advantage),
 *                       mirrored onto the issued donation_receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fund_id')->constrained();

            $table->enum('type', ['one_time', 'recurring'])->default('one_time');

            // All amounts are integer minor units (cents).
            $table->unsignedBigInteger('intended_amount');
            $table->unsignedBigInteger('charged_amount');
            $table->char('currency', 3)->default('usd');
            $table->boolean('donor_covers_fees')->default(false);

            $table->enum('status', ['pending', 'succeeded', 'failed', 'refunded'])
                ->default('pending');

            // Stripe identifiers, filled in as webhooks arrive (source of truth).
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_charge_id')->nullable();
            $table->string('stripe_balance_transaction_id')->nullable();

            // Fee/settlement breakdown (minor units), resolved on success.
            $table->unsignedBigInteger('application_fee_amount')->nullable();
            $table->unsignedBigInteger('stripe_fee_amount')->nullable();
            $table->unsignedBigInteger('net_amount')->nullable();
            $table->unsignedBigInteger('receipt_eligible_amount')->nullable();

            // De-dupes the Checkout Session create against Stripe.
            $table->string('idempotency_key')->unique();

            $table->timestamps();

            $table->index(['masjid_id', 'id']);
            $table->index(['masjid_id', 'status']);
            $table->index('stripe_payment_intent_id');
            $table->index('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
