<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JumaaSetting extends Model
{
    protected $fillable = [
        'masjid_id',
        'iqama',
        'athans',
        'shifts',
        'is_default',
    ];

    protected $casts = [
        'athans' => 'array',
        // Ordered array of richer Jumaa entries:
        // [{ time: "HH:MM", khateeb_name: ?string, khateeb_title: ?string, khutbah_title: ?string }].
        // The richer source of truth when present; `athans` stays for backward-compat.
        'shifts' => 'array',
        'is_default' => 'boolean',
    ];

    /**
     * `is_default` (true = the provisioning placeholder nobody supplied) never
     * rides the raw row: this model is serialized as-is into /prayers/settings,
     * the admin screen and `prayers.jumaa_data`, and a new key there would
     * change every live organisation's bytes. /prayers/settings says it once,
     * as `jumaa_is_default: true`, and only when it is true.
     */
    protected $hidden = ['is_default'];

    /** True only for the placeholder a client never supplied; NULL rows predate the flag. */
    public function isPlaceholder(): bool
    {
        return $this->is_default === true;
    }

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
