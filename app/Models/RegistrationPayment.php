<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RegistrationPayment — one Stripe charge against one registration; an
 * installment plan accrues N rows (docs/t006-registration-billing-design.md).
 *
 * Rows are written ONLY by the webhook handlers (T-006c/e): dedup via
 * stripe_webhook_events, re-fetch + status guard, order-independent;
 * checkout.session.completed and payment_intent.succeeded converge to ONE row.
 * charge.refunded records here only — the roster is untouched.
 *
 * Financial columns mirror the donations ledger: integer minor units, with
 * stripe_fee_minor / net_minor resolved from the balance transaction on
 * success. Currency is not re-stated here; it is denominated by the
 * registration's immutable fee plan.
 *
 * Tenant-scoped via BelongsToMasjid — but the webhook runs UNBOUND, so the
 * handlers set masjid_id explicitly (derived from event.account, cross-checked
 * against the registration's masjid_id — metadata never decides tenancy).
 */
class RegistrationPayment extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * Mirrors the donation ledger's vocabulary. PHP constants over a plain
     * string column, never a DB enum (.claude/rules/migrations.md).
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
    ];

    protected $fillable = [
        'masjid_id',
        'registration_id',
        'amount_minor',
        'stripe_payment_intent_id',
        'stripe_invoice_id',
        'stripe_charge_id',
        'stripe_balance_transaction_id',
        'stripe_fee_minor',
        'net_minor',
        'status',
        'paid_at',
        'idempotency_key',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'stripe_fee_minor' => 'integer',
            'net_minor' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }
}
