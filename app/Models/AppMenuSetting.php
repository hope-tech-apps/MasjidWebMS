<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row behind the app-menu kill switch.
 *
 * Read by the endpoint through App\Support\AppMenu::killed(), which caches it
 * for 60 s and treats ANY read failure as "not killed". The endpoint must keep
 * answering when this table does not exist yet, which is every moment between
 * deploying the code and running the migration. Read uncached by
 * tenancy:canary through AppMenu::killSwitchRow()
 * (App\Support\Canary\AppMenuKillSwitch), which must not write.
 *
 * Written only by `app-menu:kill` and `app-menu:restore`. There is no admin
 * screen for it (plan v3, OQ-21).
 */
class AppMenuSetting extends Model
{
    protected $fillable = [
        'menu_disabled',
        'reason',
        'updated_by',
    ];

    protected $casts = [
        'menu_disabled' => 'boolean',
    ];

    /** The row, or a fresh unsaved one — there is only ever meant to be one. */
    public static function row(): self
    {
        return static::query()->orderBy('id')->first() ?? new static();
    }
}
