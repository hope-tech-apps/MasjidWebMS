<?php

namespace App\Models;

use App\Enums\HighLatitudeRule;
use App\Enums\Madhab;
use App\Enums\PrayerCalculationMethod;
use Illuminate\Database\Eloquent\Model;

class PrayerCalculationSetting extends Model
{
    protected $fillable = [
        'masjid_id',
        'method',
        'madhab',
        'high_latitude_rule'
    ];

    protected $casts = [
        'method' => PrayerCalculationMethod::class,
        'madhab' => Madhab::class,
        'high_latitude_rule' => HighLatitudeRule::class,
    ];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }
}
