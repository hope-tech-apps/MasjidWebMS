<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;

class Azkar extends Model
{
    use SearchableTrait;
    
    protected $table = 'azkar';

    protected $fillable = [
        'azkar_category_id',
        'title',
        'text',
        'bless',
        'pronunciation',
        'frequency',
        'reference'
    ];

    protected $casts = [
        'title' => 'array',
        'text' => 'array',
        'bless' => 'array'
    ];

    protected $searchableFields = [
        'title',
        'text',
        'bless',
        'pronunciation',
        'reference'
    ];

    public function getTitleAttribute($value)
    {
        return json_decode($value);
    }

    public function getTextAttribute($value)
    {
        return json_decode($value);
    }

    public function getBlessAttribute($value)
    {
        return json_decode($value);
    }

    public function azkarCategory()
    {
        return $this->belongsTo(AzkarCategory::class, 'azkar_category_id', 'id');
    }

}
