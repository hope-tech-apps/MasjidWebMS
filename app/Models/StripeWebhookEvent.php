<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * StripeWebhookEvent — the idempotency ledger row for one inbound Stripe event.
 *
 * NOT tenant-scoped (no BelongsToMasjid): the webhook route is unauthenticated
 * and events span every masjid. The unique index on `stripe_event_id` is what
 * makes duplicate/at-least-once deliveries safe — see StripeWebhookController.
 */
class StripeWebhookEvent extends Model
{
    protected $fillable = [
        'stripe_event_id',
        'type',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
