<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one checkout of a basket charged — the organisation's record of a sale.
 * See the orders migration for why it outlives both the basket and the buyer, why
 * the account is pinned, and why only the webhook moves its status.
 *
 * Tenant-scoped (BelongsToMasjid). The webhook runs UNBOUND and resolves the
 * organisation from `event.account`, then finds the order by uuid WITHIN it.
 */
class Order extends Model
{
    use BelongsToMasjid;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';

    /**
     * What a refund or dispute on the basket's one charge did (CartPaymentService::handleChargeFlag).
     * Flagged on the ORDER: the event names an amount, never a line.
     */
    public const CHARGE_FLAG_REFUNDED = 'refunded';
    public const CHARGE_FLAG_PARTIALLY_REFUNDED = 'partially_refunded';
    public const CHARGE_FLAG_DISPUTED = 'disputed';

    protected $fillable = [
        'masjid_id',
        'uuid',
        'order_number',
        'cart_id',
        'contact_id',
        'buyer_email',
        'status',
        'total_minor',
        'fee_minor',
        'currency',
        'charge_account_id',
        'basket_fingerprint',
        'charge_ref',
        'idempotency_key',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'checkout_expires_at',
        'paid_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'fee_minor' => 0,
        'charge_refunded_minor' => 0,
    ];

    /**
     * The idempotency key and the pinned account never leave the server: the key
     * would let a caller replay a page, and the account id belongs to the org.
     */
    protected $hidden = ['idempotency_key', 'charge_account_id', 'basket_fingerprint', 'charge_ref'];

    protected function casts(): array
    {
        return [
            'total_minor' => 'integer',
            'fee_minor' => 'integer',
            'checkout_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'charge_refunded_minor' => 'integer',
            'charge_flagged_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function masjid(): BelongsTo
    {
        return $this->belongsTo(Masjid::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
