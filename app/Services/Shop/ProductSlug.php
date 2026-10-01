<?php

namespace App\Services\Shop;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * The slug a new product gets (shop slice B2): the name, slugged, with a `-2`, `-3` suffix when a
 * LIVE product of the same organisation already holds it.
 *
 * Uniqueness is among LIVE rows only, as B1's index is (a partial index on SQLite, the `live_slug`
 * generated column on MySQL): a soft-deleted product frees its slug, so a product re-created
 * under the same name gets the plain slug again. `Product::withoutMasjidScope()` drops only the
 * tenant scope; the soft-delete scope stays, which is what leaves trashed rows out. The
 * organisation is the argument, never the bound tenant, so what is checked is exactly what the
 * unique index will check.
 *
 * Two saves that generate the same slug at once both pass this check; the unique index refuses
 * the second, and ProductWriter::create() retries with a fresh read.
 */
final class ProductSlug
{
    /** `products.slug` is a string(140); the name is at most 120 characters, so a suffix always fits. */
    public const MAX_LENGTH = 140;

    /** A name that slugs to nothing (all punctuation) still gets a handle. */
    private const FALLBACK = 'product';

    /** The base is cut short enough that "-99999" still fits the column. */
    private const BASE_MAX = 120;

    public static function unique(string $name, int $masjidId): string
    {
        $base = Str::slug($name);
        $base = rtrim(mb_substr($base, 0, self::BASE_MAX), '-');

        if ($base === '') {
            $base = self::FALLBACK;
        }

        $taken = Product::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where(static fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', $base . '-%'))
            ->pluck('slug')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        for ($suffix = 2; ; $suffix++) {
            $candidate = $base . '-' . $suffix;

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }
}
