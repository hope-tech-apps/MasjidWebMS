<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DonationLink extends Model implements HasMedia
{
    use InteractsWithMedia, SearchableTrait;
    
    protected $fillable = ['masjid_id', 'link', 'title', 'message'];

    protected $searchableFields = ['link'];

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }

    public function image() {
        return $this->hasOne(Media::class, 'model_id')->where('model_type', 'App\Models\DonationLink');
    }

}
