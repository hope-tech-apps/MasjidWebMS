<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing in a basket. A REFERENCE to what was chosen, never a claim on it and
 * never a trusted price — CartPricer re-asks the source at checkout.
 *
 * `buyable_id` names a row in another table, and on the public pages it is shaped
 * by the browser. So it is never loaded on its own: CartPricer always loads it
 * within the basket's organisation, and an id belonging to anybody else resolves
 * to "gone", exactly as if it had been deleted.
 */
class CartItem extends Model
{
    use BelongsToMasjid;

    /** A place on a form: tickets, Zakat-ul-Fitr, iftar, Qurbani. Payload = the answers. */
    public const TYPE_FORM = 'form';

    /** A dish on a MealMenu — Halal Kitchen or Friday lunch. Payload = {pickup_at?}. */
    public const TYPE_MEAL = 'meal_item';

    /** A gift to a Fund. The amount is the donor's: unit_amount_shown_minor, quantity 1. */
    public const TYPE_DONATION = 'donation';

    /**
     * A size of a product in the online shop: `buyable_id` is a ProductVariant (the size, which
     * carries the price override and the stock), never the Product. Payload = {product_id}. Behind
     * the `shop` capability (ProductLineSource).
     */
    public const TYPE_PRODUCT = 'product_variant';

    public const TYPES = [self::TYPE_FORM, self::TYPE_MEAL, self::TYPE_DONATION, self::TYPE_PRODUCT];

    /**
     * The vocabulary historical_orders.lines already established, so a receipt can
     * separate a gift from a purchase and imported orders list beside new ones.
     */
    public const RECORDED_AS_DONATION = 'donation';
    public const RECORDED_AS_REGISTRATION = 'registration';
    public const RECORDED_AS_ORDER_ONLY = 'order_only';
    /** A product sold from the shop (`recorded_as` is string(16)). */
    public const RECORDED_AS_SALE = 'sale';

    protected $fillable = [
        'cart_id',
        'masjid_id',
        'buyable_type',
        'buyable_id',
        'recorded_as',
        'label',
        'quantity',
        'unit_amount_shown_minor',
        'currency',
        'payload',
        'client_line_key',
        'client_line_hash',
    ];

    /** The replay guard's digest is derived from the answers, and nothing serialises it. */
    protected $hidden = ['client_line_hash'];

    protected function casts(): array
    {
        return [
            'buyable_id' => 'integer',
            'quantity' => 'integer',
            'unit_amount_shown_minor' => 'integer',
            'payload' => 'array',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
