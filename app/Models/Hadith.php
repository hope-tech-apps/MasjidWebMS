<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;

class Hadith extends Model
{
    use SearchableTrait;
    
    protected $fillable = [
        'hadith_category_id',
        'title',
        'isnad',
        'matn',
        'strength',
        'muhaddith',
        'references',
        'description',
        'show_date'
    ];

    protected $casts = [
        'strength' => 'array',
        'muhaddith' => 'array',
        'references' => 'array'
    ];

    protected $searchableFields = [
        'title',
        'isnad',
        'matn',
        'muhaddith',
        'references',
        'description'
    ];

    public function getMuhaddithAttribute($value)
    {
        return json_decode($value);
    }

    public function category()
    {
        return $this->belongsTo(HadithCategory::class, 'hadith_category_id', 'id');
    }
}
