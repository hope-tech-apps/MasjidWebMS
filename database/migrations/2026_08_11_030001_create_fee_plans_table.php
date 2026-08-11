<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fee_plans — how one offering may be paid for: free, one-time, N installments,
 * or open-ended recurring (docs/t006-registration-billing-design.md, T-006a).
 *
 * IMMUTABLE ONCE REFERENCED. A fee plan referenced by any registration is never
 * edited — admins deactivate-and-replace (is_active=false, new row), and
 * in-flight registrations keep their snapshot totals. That kills the
 * price-change-while-open failure mode, which is why there is no softDeletes
 * here: rows are never deleted at all, only deactivated. Enforced by
 * T-006d's FeePlansController (create/deactivate only) — the schema's part of
 * the bargain is the RESTRICT FK pointing here from registrations.
 *
 * All money is integer MINOR UNITS (cents), never floats
 * (.claude/rules/stripe-payments.md). `amount_minor` is 0 for kind=free.
 *
 * `kind` is a plain string, NOT a DB enum — FeePlan::KINDS is the authority
 * (.claude/rules/migrations.md).
 *
 * Conservative choices where the design doc left detail open:
 *  - billing_interval is a nullable string holding Stripe's own interval
 *    vocabulary (day|week|month|year, FeePlan::BILLING_INTERVALS); null for
 *    free/one_time plans. installment_count is null except for kind=installment
 *    (N schedule iterations, end_behavior=cancel). Presence rules are validated
 *    at the request boundary in T-006d, not by the schema, so adding a kind
 *    never needs DDL.
 *  - currency lives HERE (char(3), lowercase ISO, donations convention) and is
 *    deliberately NOT re-snapshotted onto registrations or payments: this row
 *    is immutable once referenced, so registration.fee_plan_id always resolves
 *    the currency the snapshot totals are denominated in.
 *  - label is NOT NULL: it is the admin/public-facing name of the plan
 *    ("Standard", "Monthly — 9 payments"); every plan needs one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 32);

            // Minor units. For installment/recurring this is the amount PER
            // charge (per iteration), matching what the Stripe Price will carry.
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->char('currency', 3)->default('usd');

            // Stripe interval vocabulary; null unless kind is installment/recurring.
            $table->string('billing_interval', 16)->nullable();

            // Number of schedule iterations; null unless kind=installment.
            $table->unsignedInteger('installment_count')->nullable();

            $table->string('label');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['masjid_id', 'offering_id']);
            // The public offering payload lists the ACTIVE plans of one offering.
            $table->index(['offering_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_plans');
    }
};
