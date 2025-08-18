<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JumaaSetting extends Model
{
    protected $fillable = [
        'masjid_id',
        'iqama',
        'athans'
    ];

    protected $casts = [
        'athans' => 'array'
    ];

    public function getAthansAttribute($value)
    {
        return json_decode($value);
    }

    public function setAthansAttribute($value)
    {
        $this->attributes['athans'] = json_encode($value);
    }

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }
}
