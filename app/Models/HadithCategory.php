<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;

class HadithCategory extends Model
{
    use SearchableTrait;

    protected $fillable = [
        'name',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    protected $searchableFields = ['name'];

    public function hadiths()
    {
        return $this->hasMany(Hadith::class, 'hadith_category_id', 'id');
    }
}
