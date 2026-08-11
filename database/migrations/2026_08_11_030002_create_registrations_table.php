<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * registrations — one household's signup for one offering
 * (docs/t006-registration-billing-design.md, T-006a).
 *
 * TWO INDEPENDENT STATE MACHINES, deliberately separate columns:
 *  - status         : the seat  (pending|confirmed|waitlisted|cancelled)
 *  - payment_status : the money (none|awaiting|active|paid|past_due|canceled)
 * `invoice.payment_failed` moves payment_status to past_due and NEVER touches
 * status — un-enrolling a mid-semester child is an explicit admin action.
 * Note the spellings are doc-exact and intentionally differ: seat "cancelled"
 * (en-GB, ours) vs payment "canceled" (en-US, Stripe's subscription vocabulary).
 * Both sets are PHP constants on the model, never DB enums
 * (.claude/rules/migrations.md).
 *
 * SNAPSHOT PRICING: list_total_minor / adjusted_total_minor are computed once,
 * at creation, from the immutable fee plan minus admin-granted adjustments.
 * adjusted_total_minor IS the charged amount, always — Checkout charges exactly
 * this snapshot, and a later price change never restates what somebody agreed
 * to pay. Integer minor units (.claude/rules/stripe-payments.md).
 *
 * `uuid` is the opaque public handle (donation pattern). Lookups by uuid are
 * ALWAYS masjid-filtered (Registration::findByUuidForMasjid) because the public
 * endpoints run UNBOUND — the BelongsToMasjid creating hook does not stamp
 * masjid_id there, the caller sets/filters it explicitly.
 *
 * Seats are reserved at `pending` inside T-006b's lockForUpdate transaction and
 * bounded by checkout_expires_at: `checkout.session.expired` releases them, and
 * the T-006f reaper sweeps pendings whose webhook never arrived (hence the
 * (status, checkout_expires_at) index — that sweep is cross-tenant by design).
 *
 * Conservative choices where the design doc left detail open:
 *  - fee_plan_id FK is RESTRICT: fee plans are immutable-once-referenced, so a
 *    referenced plan can never be hard-deleted out from under its snapshot.
 *  - contact_id (payer/guardian) and form_response_id are nullable and null on
 *    delete — a financial record must outlive a purged contact or response
 *    (mirrors donations.contact_id). The public register path always sets both.
 *  - idempotency_key is nullable-unique (donations make it NOT NULL): it is
 *    minted only when a Checkout Session is created, and the free/100%-aid path
 *    never has a Stripe leg to key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_plan_id')->constrained();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('form_response_id')->nullable()
                ->constrained('form_responses')->nullOnDelete();

            $table->string('status', 32)->default('pending');
            $table->string('payment_status', 32)->default('none');

            // Snapshots at creation, integer minor units. adjusted = list minus
            // adjustments; it is the charged amount, always.
            $table->unsignedBigInteger('list_total_minor');
            $table->unsignedBigInteger('adjusted_total_minor');

            // Stripe identifiers, filled in as the checkout/webhooks progress.
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_subscription_schedule_id')->nullable();

            // The held seat's deadline while payment is outstanding.
            $table->timestamp('checkout_expires_at')->nullable();

            // De-dupes Checkout Session creation against Stripe (re-mint
            // endpoint included). Null until a session is first minted.
            $table->string('idempotency_key')->nullable()->unique();

            $table->timestamps();

            $table->index(['masjid_id', 'id']);
            $table->index(['masjid_id', 'offering_id', 'status']);
            $table->index(['masjid_id', 'status']);
            $table->index('stripe_checkout_session_id');
            $table->index('stripe_subscription_id');
            // The T-006f reaper: WHERE status='pending' AND checkout_expires_at < now.
            $table->index(['status', 'checkout_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
