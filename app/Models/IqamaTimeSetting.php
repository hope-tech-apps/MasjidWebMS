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

    /**
     * Ordered by id, i.e. the order the ranges were saved in.
     *
     * The order is part of the iqama rule: when two ranges for one prayer both
     * cover a day, the FIRST in this list wins (App\Support\IqamaResolver), and
     * the apps and the website receive this same list and take the first too.
     * The admin save does not refuse overlapping ranges, so without an ORDER BY
     * which one wins was whatever order the database happened to return.
     * Within one prayer, MySQL (through the (setting, salah) index or the
     * primary key) and SQLite already return id order, so no winner changes;
     * only how the prayers interleave in the list can, and every consumer
     * groups by prayer. A caller that wants another order must reorder() first.
     */
    public function timeRanges()
    {
        return $this->hasMany(IqamaTimeRange::class)->orderBy('id');
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
