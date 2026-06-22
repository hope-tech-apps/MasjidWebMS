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
        'platform',
        'minimum_version',
        'minimum_build',
        'force_update',
        'update_message',
        'latest_version',
        'store_url',
        'maintenance_mode',
        'maintenance_message',
    ];

    protected $casts = [
        'minimum_build' => 'integer',
        'force_update' => 'boolean',
        'maintenance_mode' => 'boolean',
    ];
}
