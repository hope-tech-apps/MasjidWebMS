<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JumaaSetting extends Model
{
    protected $fillable = [
        'masjid_id',
        'iqama',
        'athans',
        'shifts'
    ];

    protected $casts = [
        'athans' => 'array',
        // Ordered array of richer Jumaa entries:
        // [{ time: "HH:MM", khateeb_name: ?string, khateeb_title: ?string, khutbah_title: ?string }].
        // The richer source of truth when present; `athans` stays for backward-compat.
        'shifts' => 'array'
    ];

    public function getAthansAttribute($value)
    {
        // Never emit null. The app parses this as `(json['athans'] as List).cast
        // <String>()`, so a null aborts the entire prayers/settings decode — the
        // Jummah section and every iqama offset silently fail to load. An unset
        // value is an empty list, not the absence of the field.
        return json_decode($value) ?? [];
    }

    public function setAthansAttribute($value)
    {
        $this->attributes['athans'] = json_encode($value);
    }

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }
}
