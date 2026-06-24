<?php

namespace App\Models;

use App\Support\ArabicText;
use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;

class Hadith extends Model
{
    use SearchableTrait;

    protected $fillable = [
        'hadith_category_id',
        'title',
        'title_normalized',
        'isnad',
        'matn',
        'matn_normalized',
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

    /**
     * Keep the diacritic-insensitive shadow columns in sync. Runs on every
     * create/update (including library-copy and admin edits) so search stays
     * correct without callers having to remember to set them. See ArabicText
     * and the *_normalized migration for the why.
     */
    protected static function booted(): void
    {
        static::saving(function (Hadith $hadith) {
            $hadith->title_normalized = ArabicText::normalize($hadith->title);
            $hadith->matn_normalized = ArabicText::normalize($hadith->matn);
        });
    }

    public function getMuhaddithAttribute($value)
    {
        return json_decode($value);
    }

    public function category()
    {
        return $this->belongsTo(HadithCategory::class, 'hadith_category_id', 'id');
    }
}
