<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AzkarCategory extends Model
{
    protected $casts = [
        'title' => 'array',
        'description' => 'array'
    ];

    public function azkar() {
        return $this->hasMany(Azkar::class, 'azkar_category_id', 'id');
    }
}
