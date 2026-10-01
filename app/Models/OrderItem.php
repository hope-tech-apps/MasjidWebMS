<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order, FROZEN at checkout — a snapshot, never a live reference,
 * so a later price edit or a deleted dish cannot change what a receipt says was
 * bought. `record_type`/`record_id` point at the real record the line's own
 * service created; `recorded_as` keeps the historical_orders vocabulary.
 *
 * `payload` and `price_snapshot` are what the webhook writes the record from once the
 * basket is paid (CartSettlementService): frozen at checkout so a tier boundary, a
 * price edit or a deleted dish between the page opening and the payment landing
 * cannot change or block the record of money already taken. Neither is serialised —
 * `payload` holds attendee names and both are internal.
 */
class OrderItem extends Model
{
    use BelongsToMasjid;

    /** The values `record_type` takes: the real record the line's own service wrote. */
    public const RECORD_FORM_RESPONSE = 'form_response';
    public const RECORD_MEAL_ORDER = 'meal_order';
    public const RECORD_DONATION = 'donation';
    /**
     * A shop line's record: a ProductSale. Not listed in the member portal (MemberPurchases has no
     * source for it: the sale reaches a member only as a line of the cart order that holds it).
     */
    public const RECORD_PRODUCT_SALE = 'product_sale';

    protected $fillable = [
        'order_id',
        'masjid_id',
        'buyable_type',
        'buyable_id',
        'recorded_as',
        'label',
        'quantity',
        'unit_amount_minor',
        'total_minor',
        'currency',
        'payload',
        'price_snapshot',
        'record_type',
        'record_id',
        'cart_payload_hash',
    ];

    protected $hidden = ['payload', 'price_snapshot'];

    protected function casts(): array
    {
        return [
            'buyable_id' => 'integer',
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'total_minor' => 'integer',
            'payload' => 'array',
            'price_snapshot' => 'array',
            'record_id' => 'integer',
            'receipt_claimed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
