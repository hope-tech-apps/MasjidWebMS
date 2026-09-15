<?php

namespace App\Console\Commands;

use App\Models\AppMenuSetting;
use App\Support\AppMenu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Give the app menu back.
 *
 * The other half of `app-menu:kill`. /menu starts answering 200 again within a
 * minute, with the same body and the same ETag it had before the kill — the
 * payload is derived from the switches, so nothing about it depended on the
 * lever.
 *
 * The reason the kill was set is left on the row rather than blanked, so the
 * history of the incident survives the fix.
 */
class AppMenuRestore extends Command
{
    protected $signature = 'app-menu:restore {--by= : The name of the human being restoring it}';

    protected $description = 'Bring the mobile app menu endpoint back after app-menu:kill.';

    public function handle(): int
    {
        $by = trim((string) $this->option('by')) ?: null;

        $row = AppMenuSetting::row();
        $wasKilled = (bool) $row->menu_disabled;

        $row->fill([
            'menu_disabled' => false,
            'updated_by' => $by,
        ])->save();

        Cache::forget(AppMenu::KILL_CACHE_KEY);

        Log::warning('app menu RESTORED: /menu answers again', [
            'by' => $by,
            'was_killed' => $wasKilled,
            'kill_reason' => $row->reason,
        ]);

        $this->info('The app menu endpoint is back ON.');
        $this->line('  /mobile/masjids/{id}/menu answers 200 within ' . AppMenu::KILL_CACHE_TTL . ' s.');

        if (! $wasKilled) {
            $this->line('  (it was not off — nothing changed for anybody)');
        }

        if ($row->reason !== null) {
            $this->line('  The reason recorded when it was killed is kept: ' . $row->reason);
        }

        return self::SUCCESS;
    }
}
