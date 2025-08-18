<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prayer extends Model
{
    protected $fillable = ['masjid_id', 'prayers_data', 'iqama_times_data', 'jumaa_data', 'date'];
    protected $casts = [
        'prayers_data' => 'array', 
        'iqama_times_data' => 'array',
        'jumaa_data' => 'array'
    ];

    public function getPrayersDataAttribute($value) {
        return json_decode($value);
    }

    public function getIqamaTimesDataAttribute($value) {
        return json_decode($value);
    }

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }
}
