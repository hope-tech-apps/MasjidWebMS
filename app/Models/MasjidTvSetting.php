<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/**
 * What one organisation chose for its lobby TV board: at most ONE row per
 * organisation (unique `masjid_id`), written by the "TV Display" page
 * (TvDisplaySettingsController) and read by the public tv-config endpoint.
 *
 * Every setting is nullable and null means "not chosen": the board then gets
 * what it got before this table existed. Nothing here is the value a board
 * receives. That is App\Support\TvBoard::resolve(), the one place a row meets
 * the organisation's type, its donation link and the documented defaults, and
 * it is defensive about a row somebody edited by hand.
 *
 * TENANT SCOPING. `BelongsToMasjid` scopes every query to the bound tenant and
 * stamps `masjid_id` on create, so the admin controller never filters by hand.
 * The PUBLIC read is the opposite case: routes/api.php binds no tenant, the
 * scope adds nothing there, and a bare `first()` would return whichever
 * organisation's row comes first. TvBoard::storedFor() therefore filters by
 * `masjid_id` explicitly. Both are pinned in tests/Feature/TvDisplaySettingsTest.php.
 *
 * `masjid_id` is deliberately NOT fillable: no request body can aim a save at
 * another organisation.
 */
class MasjidTvSetting extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'is_enabled',
        'header_title',
        'carousel_interval_seconds',
        'show_prayer_panel',
        'show_qr',
        'donate_caption',
    ];

    /**
     * The casts are load-bearing: the tvOS decoder is strict, and a boolean
     * column read back as `1` (or an interval as "10") makes the whole config
     * fail to decode, which freezes the board on its previous settings with no
     * error anywhere.
     */
    protected $casts = [
        'is_enabled' => 'boolean',
        'carousel_interval_seconds' => 'integer',
        'show_prayer_panel' => 'boolean',
        'show_qr' => 'boolean',
        'updated_by_user_id' => 'integer',
    ];
}
