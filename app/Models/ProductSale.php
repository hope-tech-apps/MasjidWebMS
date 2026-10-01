<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record a PAID basket line of a product leaves (shop slice B1): what the pickup list
 * reads, as form_responses, meal_orders and donations are the record of their own line types.
 * Written ONLY by CartSettlementService::settleProduct, from the order item's frozen
 * `price_snapshot`, so the product name, the size and the price are what the buyer paid
 * whatever the catalogue says now; `order_items.record_type` is `product_sale` and `record_id`
 * is this row's id.
 *
 * It holds NO buyer personal data: the pickup list reads the buyer from the order
 * (`orders.buyer_name`, `buyer_email`, `buyer_phone`), which MemberAccountDeletion and the
 * staging scrub already classify. So it has no contact column and needs no classification of
 * its own. `product_id` and `variant_id` are plain ids with no foreign key, because either may
 * be soft-deleted (or removed) after it sold.
 *
 * `oversold` marks a line paid after the last unit had gone, which only a webhook later than
 * the hold's grace can cause. It is recorded and flagged, never refused and never refunded by
 * code: money taken is a record.
 *
 * Tenant-scoped (BelongsToMasjid); the webhook runs UNBOUND, so the writer stamps `masjid_id`
 * itself. The cross-tenant test is tests/Feature/Shop/ProductTenantIsolationTest.php.
 */
class ProductSale extends Model
{
    use BelongsToMasjid;

    /**
     * What the office did about a sale (migration 2026_10_06_100200): the money went back, or a
     * replacement is handed over instead. NULL is "nobody has decided". A refunded sale is never
     * "to hand out"; a substituted one is, because the substitute is what is handed over.
     */
    public const RESOLUTION_REFUNDED = 'refunded';

    public const RESOLUTION_SUBSTITUTED = 'substituted';

    public const RESOLUTIONS = [self::RESOLUTION_REFUNDED, self::RESOLUTION_SUBSTITUTED];

    protected $fillable = [
        'masjid_id',
        'order_id',
        'order_item_id',
        'product_id',
        'variant_id',
        'product_name',
        'variant_label',
        'quantity',
        'unit_minor',
        'total_minor',
        'oversold',
        'collected_at',
        'collected_by_user_id',
        'resolution',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'order_item_id' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'quantity' => 'integer',
            'unit_minor' => 'integer',
            'total_minor' => 'integer',
            'oversold' => 'boolean',
            'collected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
