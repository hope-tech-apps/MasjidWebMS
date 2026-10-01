<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One thing an organisation sells in its online shop: a school polo, a hoodie (shop slice B1).
 *
 * The product holds what is common to all its sizes (name, slug, category, description, the
 * base price); a size, its own price and its stock live on ProductVariant, which is what a
 * basket line points at. Prices are integer minor units. A product is retired with `active =
 * false` or soft-deleted, never removed, because a paid line names it (the sale itself keeps a
 * snapshot, so the pricing and settlement code never trusts this row after the fact).
 *
 * `slug` is unique per organisation among LIVE rows: a generated column on MySQL (`live_slug`,
 * which does not exist on SQLite, where the same rule is a partial index), hidden here so a
 * serialised product reads the same on both drivers.
 *
 * Images are Spatie media in the `product_images` collection (many). The upload endpoints are
 * slice B2's; this slice adds the trait, the collection name and `images()`.
 *
 * Tenant-scoped (BelongsToMasjid); the cross-tenant test is tests/Feature/Shop/ProductTenantIsolationTest.php.
 * The public basket runs UNBOUND, so CartPricer and CartLineAdder load a product with the scope
 * bypassed and `masjid_id` filtered by hand, never by id alone.
 */
class Product extends Model implements HasMedia
{
    use BelongsToMasjid, InteractsWithMedia, SoftDeletes;

    /** The Spatie collection a product's pictures live in. */
    public const IMAGES = 'product_images';

    protected $fillable = [
        'masjid_id',
        'name',
        'slug',
        'category',
        'description',
        'base_price_minor',
        'currency',
        'active',
        'sort',
    ];

    /** The MySQL-only generated column behind the live-slug unique index; see the migration. */
    protected $hidden = ['live_slug'];

    protected function casts(): array
    {
        return [
            'base_price_minor' => 'integer',
            'active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * The product's pictures, in their order.
     *
     * `model_type` IS PART OF THE KEY, exactly as Masjid::logo() and Masjid::gallery() carry it
     * (DECISIONS.md 2026-09-27, "reads media by the whole key"): Spatie's `media.model_id` is half
     * a key, so without it another model's `product_images` row whose id equalled this product's
     * would be this product's picture, and a relation that is also written through could delete
     * another organisation's file. Every reader of a product's media goes through this relation.
     */
    public function images(): HasMany
    {
        return $this->hasMany(Media::class, 'model_id')
            ->where('model_type', self::class)
            ->where('collection_name', self::IMAGES)
            ->orderBy('order_column')
            ->orderBy('id');
    }
}
