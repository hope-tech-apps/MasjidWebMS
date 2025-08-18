<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IqamaTimeSetting extends Model
{
    protected $fillable = [
        'masjid_id',
        'fajr',
        'dhuhr',
        'asr',
        'maghrib',
        'isha'
    ];

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }
}
