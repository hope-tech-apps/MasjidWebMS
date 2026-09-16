<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-platform emergency app-control config. One row per platform.
 * Read by GET /mobile/app-config; edited by super-admins in the portal.
 */
class AppVersionSetting extends Model
{
    protected $fillable = [
        'masjid_id',
        'platform',
        'minimum_version',
        'minimum_build',
        'force_update',
        'update_message',
        'latest_version',
        'store_url',
        'maintenance_mode',
        'maintenance_message',
        // Which shell the app draws: `menu` (the R1 side menu + tab bar) or
        // `legacy` (the layout the build shipped with). Null means the
        // client's compiled default and is NOT emitted. The clients also
        // understand `side_menu` and `tabs_drawer`; see config/app_menu.php.
        'navigation',
    ];

    protected $casts = [
        'minimum_build' => 'integer',
        'force_update' => 'boolean',
        'maintenance_mode' => 'boolean',
    ];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }
}
