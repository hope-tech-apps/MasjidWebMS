<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change made to a lunch order after it was placed — by the customer on
 * their own order link, or by staff on the board.
 *
 * It exists because an order's items and its total can now move after money has
 * changed hands. "Who changed this paid order, and what did it say before?" must
 * have an answer that does not depend on anyone remembering, so the row is
 * written in the same transaction as the change: no edit commits without it.
 *
 * `before` and `after` hold only the lines and the money (items with their
 * quantity and unit price, subtotal, donation, covered fee, total) — enough to
 * reconstruct what moved, small enough to keep for every edit, and free of the
 * customer's contact details, which an edit does not touch.
 *
 * `actor` is a plain string ('customer' | 'staff'), never a DB enum, like every
 * other status column in this module. `user_id` is the staff login that made
 * the change and is NULL for a customer, who has no login — the uuid on their
 * order link is what they hold.
 *
 * Tenant-scoped by `masjid_id` like every other table here (BelongsToMasjid on
 * App\Models\MealOrderEdit); the cross-tenant guardrail is in
 * MealOrderTenantIsolationTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_order_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_order_id')->constrained()->cascadeOnDelete();

            $table->string('actor', 16);                                     // customer | staff
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->json('before');
            $table->json('after');

            // Written once and never updated: there is no `updated_at`, because
            // an audit row that could be revised would not be one.
            $table->timestamp('created_at')->nullable();

            $table->index(['masjid_id', 'meal_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_order_edits');
    }
};
