<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * registration_adjustments — auditable reductions to what one registration is
 * charged: financial aid, discounts, promo codes
 * (docs/t006-registration-billing-design.md, T-006a).
 *
 * STRICTLY PRE-CHECKOUT. Adjustments are applied when computing
 * registrations.adjusted_total_minor BEFORE the Checkout Session is created;
 * the session charges exactly that snapshot. No post-hoc money movement, ever.
 * They are granted SERVER-SIDE by admins (T-006d) — there is no self-service
 * discount hole; the public quote endpoint only *validates* codes server-side.
 * Any adjustment that takes the total to 0 (or below Stripe's minimum) branches
 * to the free path — never a $0 session.
 *
 * `kind` is a plain string, NOT a DB enum — RegistrationAdjustment::KINDS is
 * the authority (.claude/rules/migrations.md).
 *
 * Conservative choices where the design doc left detail open:
 *  - amount_minor is UNSIGNED: an adjustment is the magnitude of a reduction,
 *    subtracted from the list total. Surcharges are not a thing this table
 *    models — a schema that cannot express negative aid is the safer shape.
 *  - reason is nullable here; whether it is required is a request-boundary
 *    policy for T-006d, not a schema commitment.
 *  - granted_by_user_id is nullable + nullOnDelete: the audit row must outlive
 *    the admin account that granted it. The grant paths always set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 32);

            // Magnitude of the reduction, integer minor units.
            $table->unsignedBigInteger('amount_minor');

            $table->string('reason')->nullable();
            $table->foreignId('granted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['masjid_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_adjustments');
    }
};
