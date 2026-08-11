<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * registration_payments — the per-charge ledger of one registration: exactly
 * one row per Stripe charge, so an installment plan accrues N rows
 * (docs/t006-registration-billing-design.md, T-006a).
 *
 * Rows are written ONLY by webhook handlers (T-006c/e) — dedup via
 * stripe_webhook_events, re-fetch + status guard, order-independent;
 * `checkout.session.completed` and `payment_intent.succeeded` converge to ONE
 * row. `charge.refunded` records on this row only; the roster is untouched.
 *
 * Financial columns mirror `donations` (integer minor units, never floats —
 * .claude/rules/stripe-payments.md): amount_minor is what the card was charged;
 * stripe_fee_minor / net_minor are resolved from the balance transaction.
 *
 * Conservative choices where the design doc left detail open:
 *  - `status` values mirror the donation ledger's PHP constant set
 *    (pending|succeeded|failed|refunded, RegistrationPayment::STATUSES) — the
 *    doc names the column without enumerating it, and matching the sibling
 *    ledger is the least surprising vocabulary. Plain string, never a DB enum.
 *  - no currency column: the charge is denominated by the registration's
 *    immutable fee plan (see create_fee_plans_table).
 *  - stripe id columns are indexed, not unique — webhook idempotency is owned
 *    by stripe_webhook_events + handler guards, and a partial-capture future
 *    must not fight a premature uniqueness constraint.
 *  - idempotency_key is nullable-unique: set when the handler that creates the
 *    row keys it, absent on rows recorded purely from Stripe-side events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();

            // Integer minor units.
            $table->unsignedBigInteger('amount_minor');

            // Stripe identifiers, filled in as webhooks arrive.
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_invoice_id')->nullable();
            $table->string('stripe_charge_id')->nullable();
            $table->string('stripe_balance_transaction_id')->nullable();

            // Fee/settlement breakdown (minor units), resolved on success.
            $table->unsignedBigInteger('stripe_fee_minor')->nullable();
            $table->unsignedBigInteger('net_minor')->nullable();

            $table->string('status', 32)->default('pending');
            $table->timestamp('paid_at')->nullable();

            $table->string('idempotency_key')->nullable()->unique();

            $table->timestamps();

            $table->index(['masjid_id', 'registration_id']);
            $table->index('stripe_payment_intent_id');
            $table->index('stripe_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_payments');
    }
};
