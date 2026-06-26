<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThemeSetting extends Model
{
    protected $fillable = [
        'masjid_id',
        'primary_color',
        'secondary_color',
        'accent_color',
        'background_color',
    ];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }
}
