<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MobileAppFeature extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = ['name', 'key', 'is_available'];

    /**
     * A feature key reduced to its lower-case ASCII letters and digits, so
     * `qur’an`, `qur'an` and `quran` are one key.
     *
     * Production's Qur'an row is keyed `qur’an` (U+2019): the 2025-12-09
     * backfill looked the row up by an ASCII-apostrophe name, missed it, and
     * generated the key from the curly-quoted name instead. Every other row,
     * the seeder and config/verticals.php spell it `quran`, so anything that
     * matches a configured key against the catalogue must compare this form,
     * never the raw key. Deliberately byte-wise and not `/u`: each byte of a
     * multi-byte character is dropped, which is what collapses U+2019.
     * resources/vue-app/core/helpers/featureKey.ts mirrors it for the wizard.
     */
    public static function normaliseKey(?string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $key));
    }

    /**
     * Each key rewritten to the catalogue's own spelling of it, matched by
     * normaliseKey(). A key the catalogue does not ship, or a value that is
     * not a string, comes back unchanged so validation still rejects it.
     *
     * @param  array<array-key, mixed>  $keys
     * @return array<array-key, mixed>
     */
    public static function toCatalogueKeys(array $keys): array
    {
        $catalogue = self::query()->pluck('key')
            ->mapWithKeys(fn ($key) => [self::normaliseKey($key) => $key])
            ->all();

        return array_map(
            fn ($key) => is_string($key) ? ($catalogue[self::normaliseKey($key)] ?? $key) : $key,
            $keys
        );
    }

    public function icon()
    {
        // model_type is pinned so a `featuresIcons` row that some other model
        // type happened to write under a colliding model_id can never satisfy
        // this — the same predicate Masjid::logo() carries. Without it the
        // relation is one id-collision away from serving the wrong (or a
        // deleted-then-null) icon.
        return $this->hasOne(Media::class, 'model_id')
            ->where('model_type', self::class)
            ->where('collection_name', 'featuresIcons')
            ->orderBy('created_at', 'desc')
            ->latest();
    }
}
