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
