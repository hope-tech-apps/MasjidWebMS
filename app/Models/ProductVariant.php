<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A size (or other option) of a Product, with its own optional price and its own stock (shop
 * slice B1). This, not the product, is what a basket line buys: `cart_items.buyable_id` and
 * `order_items.buyable_id` name a variant (buyable_type `product_variant`).
 *
 * - `price_minor` NULL means "the product's base price".
 * - `stock` NULL means UNLIMITED. Otherwise it is the units put on sale in all, and `sold_count`
 *   is how many PAID lines have taken. What can still be bought (stock - sold_count - what other
 *   pending baskets hold) is computed by App\Services\Cart\ProductStock, in one place, and
 *   `sold_count` moves only at settlement, under this row's lock
 *   (CartSettlementService::settleProduct). A stock edit never rewrites history.
 * - `enabled` is the owner's per-size switch; a size is retired with it or soft-deleted, and a
 *   paid line keeps its snapshot either way.
 *
 * `label` is unique per product among LIVE rows: a generated column on MySQL (`live_label`,
 * hidden here), a partial index on SQLite.
 *
 * Tenant-scoped (BelongsToMasjid); the cross-tenant test is tests/Feature/Shop/ProductTenantIsolationTest.php.
 */
class ProductVariant extends Model
{
    use BelongsToMasjid, SoftDeletes;

    protected $fillable = [
        'masjid_id',
        'product_id',
        'label',
        'enabled',
        'price_minor',
        'stock',
        'sold_count',
        'sort',
    ];

    /** The MySQL-only generated column behind the live-label unique index; see the migration. */
    protected $hidden = ['live_label'];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'enabled' => 'boolean',
            'price_minor' => 'integer',
            'stock' => 'integer',
            'sold_count' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
