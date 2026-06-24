<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;

class AzkarCategory extends Model
{
    use SearchableTrait;

    protected $fillable = [
        'title',
        'description',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    protected $searchableFields = ['title', 'description'];

    public function azkar()
    {
        return $this->hasMany(Azkar::class, 'azkar_category_id', 'id');
    }
}
