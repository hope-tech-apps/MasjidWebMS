<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change a customer asked for on a lunch order they had already paid for,
 * waiting on the payment of the difference (owner, 2026-09-24).
 *
 * Nothing on the order moves until the signed webhook says the difference was
 * paid (App\Services\Stripe\MealOrderTopUpPaymentService). A redirect back from
 * Stripe is not a payment, and this row is not one either: it is the question
 * "add these plates for this much?", and the webhook is the answer.
 *
 * Every column is server-computed, so nothing is fillable: the row is written
 * only by MealOrderCheckoutService::openTopUp and moved only by the webhook.
 * `masjid_id` is stamped from the ORDER on the unbound public path, where the
 * BelongsToMasjid hook has nothing to stamp.
 */
class MealOrderTopUp extends Model
{
    use BelongsToMasjid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_EXPIRED = 'expired';

    /** Paid, but the order moved underneath it: the money is recorded, the plates are not. */
    public const STATUS_CONFLICT = 'conflict';

    /**
     * Its page completed, but not with a payment this app can record: another
     * amount or currency than the difference, or not paid. Nothing was recorded on
     * the order (a warning names it for the organisation to check in Stripe), and
     * the row is closed so the order is not held waiting on it for ever. A later
     * success for the same page is recorded as a conflict, never applied.
     */
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPLIED,
        self::STATUS_EXPIRED,
        self::STATUS_CONFLICT,
        self::STATUS_REJECTED,
    ];

    /** The metadata `kind` on a top-up's Checkout Session and payment intent. */
    public const STRIPE_KIND = 'lunch_top_up';

    protected $guarded = ['*'];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'base_total_minor' => 'integer',
            'base_settled_minor' => 'integer',
            'amount_minor' => 'integer',
            'proposed_total_minor' => 'integer',
            'expires_at' => 'datetime',
            'applied_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MealOrder::class, 'meal_order_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * The basket the customer asked for, as LunchOrderLines::wanted() returns it
     * ([item id => quantity]). JSON keeps object keys as strings, so they are
     * turned back into integers here, once.
     *
     * @return array<int,int>
     */
    public function wanted(): array
    {
        $wanted = [];

        foreach ((array) ($this->lines['wanted'] ?? []) as $id => $qty) {
            if ((int) $id > 0 && (int) $qty > 0) {
                $wanted[(int) $id] = (int) $qty;
            }
        }

        return $wanted;
    }
}
