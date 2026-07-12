<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stripe_webhook_events — the idempotency ledger for inbound Stripe webhooks.
 *
 * Stripe can deliver the same event more than once and out of order. Before we
 * act on an event we record its id here; the UNIQUE constraint on
 * `stripe_event_id` is what makes "process each event at most once" atomic — a
 * duplicate delivery collides on insert and is acknowledged without re-running
 * side effects (e.g. issuing a second receipt). NOT tenant-scoped: the webhook
 * route is unauthenticated and events span masjids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
