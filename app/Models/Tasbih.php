<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;

class Tasbih extends Model
{
    use SearchableTrait;
    
    protected $table = 'tasabih';

    protected $fillable = ['text', 'pronunciation', 'reference'];

    protected $casts = [
        'text' => 'array'
    ];

    protected $searchableFields = ['text', 'pronunciation', 'reference'];

    public function getTextAttribute($value)
    {
        return json_decode($value);
    }

}
