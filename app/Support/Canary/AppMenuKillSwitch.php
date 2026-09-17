<?php

namespace App\Support\Canary;

use App\Support\AppMenu;

/**
 * Is the app menu dark on purpose? It is while `app-menu:kill` has set the kill row:
 * AppMenuController::show() then answers every organisation 404 with `data: {}` before it reads anything else.
 *
 * Reads the row through AppMenu::killSwitchRow(), the query AppMenu::killed() wraps, WITHOUT killed()'s cache
 * (Cache::remember writes) and without its fail-open (a throw here must reach the canary, which probes on doubt).
 */
final class AppMenuKillSwitch implements DarkLaunchSwitch
{
    public static function darkBecause(): ?string
    {
        $row = AppMenu::killSwitchRow();

        if ($row === null || ! $row->menu_disabled) {
            return null;
        }

        return 'app_menu_settings row '.$row->id.' has menu_disabled set — last written '.
            ($row->updated_at?->toIso8601String() ?? 'at an unrecorded time').' by '.
            ($row->updated_by ?? 'nobody recorded').', reason: '.($row->reason ?? 'none recorded');
    }
}
