<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MasjidAbout extends Model implements HasMedia
{
    use InteractsWithMedia, SearchableTrait;

    protected $fillable = ['masjid_id', 'about', 'mission', 'vision'];

    protected $searchableFields = ['about', 'mission', 'vision'];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    public function aboutImage()
    {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'aboutImages')
            ->orderBy('created_at', 'desc')
            ->latest();
    }

    public function missionIcon()
    {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'missionIcons')
            ->orderBy('created_at', 'desc')
            ->latest();
    }

    public function visionIcon()
    {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'visionIcons')
            ->orderBy('created_at', 'desc')
            ->latest();
    }

}
