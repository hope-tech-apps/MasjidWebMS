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
