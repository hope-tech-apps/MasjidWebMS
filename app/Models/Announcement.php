<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Announcement extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes, SearchableTrait;

    protected $fillable = ['masjid_id', 'title', 'details', 'start_date', 'end_date', 'link'];

    protected $searchableFields = ['title', 'details'];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    public function image()
    {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'announcements')
            ->orderBy('created_at', 'desc')
            ->latest();
    }

    /**
     * Search records where any of the specified fields match the search term
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $like Search term
     * @param array|null $fields Fields to search (falls back to model's searchableFields)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    // public function scopeSearchLike($query, ?string $like = null, ?array $fields = null)
    // {

    //     $fields = $fields ?? $this->searchableFields;

    //     if (empty($like) || empty($fields)) {
    //         return $query;
    //     }

    //     return $query->where(function ($q) use ($like, $fields) {
    //         foreach ($fields as $field) {
    //             $q->orWhere($field, 'LIKE', "%{$like}%");
    //         }
    //     });

    // }
}
