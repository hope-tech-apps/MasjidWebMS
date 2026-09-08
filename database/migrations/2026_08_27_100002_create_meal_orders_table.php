<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's meal order — order-like, mirroring `registrations`: a public
 * `uuid` handle, money in integer minor units, string status/payment columns
 * (never DB enums), an `idempotency_key` for the Stripe seam, and a local
 * `pending` row written BEFORE Stripe is called and flipped to paid only by the
 * webhook. `contact_id` is set for a signed-in member and null for a guest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            // RESTRICT: a menu with orders against it cannot be deleted.
            $table->foreignId('meal_menu_id')->constrained();
            // A financial record outlives the member it points at.
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            $table->string('order_number', 16)->nullable();   // short pickup code, e.g. "A17"
            $table->string('customer_name');
            $table->string('customer_phone', 32);
            $table->string('customer_email')->nullable();

            $table->string('status', 32)->default('pending');          // pending|confirmed|ready|picked_up|cancelled
            $table->string('payment_method', 32)->default('pickup');   // online|pickup
            $table->string('payment_status', 32)->default('unpaid');   // unpaid|paid|refunded

            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->char('currency', 3)->default('usd');

            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();

            $table->text('customer_notes')->nullable();
            $table->dateTime('placed_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('picked_up_at')->nullable();

            $table->timestamps();

            $table->index(['masjid_id', 'meal_menu_id', 'status']);
            $table->index(['masjid_id', 'status']);
            $table->index(['masjid_id', 'payment_status']);
            // A pickup number is unique within one menu (nulls never collide).
            $table->unique(['masjid_id', 'meal_menu_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_orders');
    }
};
