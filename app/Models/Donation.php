<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Donation — one donor gift. Tenant-scoped by masjid_id (BelongsToMasjid).
 *
 * All amounts are integer minor units (cents). A row is created `pending`
 * before the Stripe redirect and only advanced to `succeeded`/`failed` by
 * webhooks — the browser redirect is never trusted (see StripeWebhookController
 * and .claude/rules/stripe-payments.md).
 *
 * Note the donation is usually created in the PUBLIC (unbound) mobile context,
 * where the BelongsToMasjid creating hook does NOT stamp masjid_id — the
 * caller (DonationService) sets it explicitly from the validated route masjid.
 * A public UUID is generated on create for use as an opaque external handle.
 */
class Donation extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'uuid',
        'masjid_id',
        'contact_id',
        'fund_id',
        'type',
        'source',
        'payment_method',
        'donated_at',
        'note',
        'import_batch',
        'intended_amount',
        'charged_amount',
        'currency',
        'donor_covers_fees',
        'status',
        'stripe_payment_intent_id',
        'stripe_checkout_session_id',
        'stripe_subscription_id',
        'stripe_invoice_id',
        'stripe_charge_id',
        'stripe_balance_transaction_id',
        'application_fee_amount',
        'stripe_fee_amount',
        'net_amount',
        'receipt_eligible_amount',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'donated_at' => 'date',
            'intended_amount' => 'integer',
            'charged_amount' => 'integer',
            'donor_covers_fees' => 'boolean',
            'application_fee_amount' => 'integer',
            'stripe_fee_amount' => 'integer',
            'net_amount' => 'integer',
            'receipt_eligible_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Assign an opaque public UUID if the caller didn't supply one. Kept
        // separate from the auto-increment id so it can be exposed to clients.
        static::creating(function (Donation $donation): void {
            if (empty($donation->uuid)) {
                $donation->uuid = (string) Str::uuid();
            }
        });
    }

    public function fund()
    {
        return $this->belongsTo(Fund::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function receipt()
    {
        return $this->hasOne(DonationReceipt::class);
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
