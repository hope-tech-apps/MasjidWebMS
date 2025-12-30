<?php

namespace App\Models;

use App\Enums\IqamaType;
use Illuminate\Database\Eloquent\Model;

class IqamaTimeSetting extends Model
{
    protected $fillable = [
        'masjid_id',
        'iqama_type',
        'show_iqama_times',
        'fajr',
        'dhuhr',
        'asr',
        'maghrib',
        'isha'
    ];

    protected $casts = [
        'iqama_type' => IqamaType::class,
        'show_iqama_times' => 'boolean',
    ];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    public function timeRanges()
    {
        return $this->hasMany(IqamaTimeRange::class);
    }

    public function fajrRanges()
    {
        return $this->hasMany(IqamaTimeRange::class)->where('salah', 'fajr');
    }

    public function dhuhrRanges()
    {
        return $this->hasMany(IqamaTimeRange::class)->where('salah', 'dhuhr');
    }

    public function asrRanges()
    {
        return $this->hasMany(IqamaTimeRange::class)->where('salah', 'asr');
    }

    public function maghribRanges()
    {
        return $this->hasMany(IqamaTimeRange::class)->where('salah', 'maghrib');
    }

    public function ishaRanges()
    {
        return $this->hasMany(IqamaTimeRange::class)->where('salah', 'isha');
    }
}
