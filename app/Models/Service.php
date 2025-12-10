<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Service extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes, SearchableTrait;

    protected $fillable = ['masjid_id', 'title', 'description', 'text'];

    protected $searchableFields = ['title', 'description', 'text'];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    public function image()
    {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'services')
            ->orderBy('created_at', 'desc')
            ->latest();
    }

    public function icon()
    {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'servicesIcons')
            ->orderBy('created_at', 'desc')
            ->latest();
    }


}
