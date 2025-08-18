<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MobileAppFeature extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = ['name', 'is_available'];

    public function icon()
    {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'featuresIcons')
            ->orderBy('created_at', 'desc')
            ->latest();
    }
}
