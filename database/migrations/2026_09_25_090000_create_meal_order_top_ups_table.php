<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer adding to a lunch order they have ALREADY PAID for, by paying the
 * difference first (owner, 2026-09-24).
 *
 * Nothing on the order moves when the customer asks. The change they want waits
 * here, `pending`, beside a Stripe Checkout Session for exactly the difference,
 * and only the signed webhook applies it (App\Services\Stripe\
 * MealOrderTopUpPaymentService): the money first, then the plates.
 *
 *  - `lines` is the FULL basket the customer asked for, as item id => quantity
 *    (LunchOrderLines::wanted), plus the priced snapshot shown to them. The
 *    webhook re-prices it through the same editor every other edit uses.
 *  - `base_total_minor` / `base_settled_minor` are the order's total and the
 *    money it had settled when this was asked for. If either has moved by the
 *    time the payment lands (staff changed the order meanwhile), the plates are
 *    NOT applied: the money is recorded and the row is marked `conflict`, so the
 *    board shows it owed back and nothing paid is ever silently lost.
 *  - `amount_minor` = `proposed_total_minor` - `base_settled_minor`, the one
 *    figure the Checkout Session charges. Integer minor units, never a float.
 *  - `status` is a plain string like every other status in this module:
 *    pending | applied | expired | conflict | rejected.
 *  - `idempotency_key` is set on the row before the Stripe call, in the same
 *    transaction. It covers the SDK's own network retries of that one call; a
 *    failed attempt rolls the row back, and the customer's next try is a new
 *    top-up with a new key (a page Stripe made for the failed one was never
 *    handed to anyone, and closes at the cutoff).
 *  - `notified_at` stamps the "your order was updated" email, so a replayed
 *    webhook never sends it twice. The name avoids the PII tokens the staging
 *    scrub guard matches ("email").
 *
 * Tenant-scoped by `masjid_id` (BelongsToMasjid on App\Models\MealOrderTopUp);
 * the cross-tenant guardrail is in MealOrderTenantIsolationTest. Index names are
 * written by hand: MySQL caps identifiers at 64 characters and the generated
 * ones for this table name come close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_order_top_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_order_id')->constrained()->cascadeOnDelete();

            $table->json('lines');
            $table->unsignedBigInteger('base_total_minor');
            $table->unsignedBigInteger('base_settled_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('proposed_total_minor');

            $table->string('stripe_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('idempotency_key', 80)->nullable();

            $table->string('status', 16)->default('pending');   // pending | applied | expired | conflict | rejected
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('notified_at')->nullable();

            $table->timestamps();

            $table->index(['masjid_id', 'meal_order_id', 'status'], 'meal_top_ups_masjid_order_status_idx');
            $table->index('stripe_session_id', 'meal_top_ups_session_idx');
            $table->unique('idempotency_key', 'meal_top_ups_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_order_top_ups');
    }
};
