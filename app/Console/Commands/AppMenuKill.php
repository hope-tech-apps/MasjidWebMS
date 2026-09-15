<?php

namespace App\Console\Commands;

use App\Models\AppMenuSetting;
use App\Support\AppMenu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Take the new app menu away from every phone, now.
 *
 * `GET /mobile/masjids/{id}/menu` starts answering 404 within a minute. Both
 * apps read that as "menu unavailable" and fall back to the menu they build
 * from the legacy /features — the same entries, because the derivation behind
 * /menu is switch-only for exactly this reason.
 *
 * What this does NOT roll back: the app shell itself. A build shipped with the
 * side menu still has the side menu, still has the single-activity navigation
 * on Android and the in-place organisation switch on both platforms — it just
 * fills the drawer from the old endpoint. The lever for the SHELL is the
 * `navigation` app-config flag, and the only rollback for the merges underneath
 * it is a new build (plan v3 [R11]).
 *
 * No .env edit and no config:cache: a bad .env plus config:cache is how every
 * request on this box once returned 500, and an emergency lever must not be
 * able to cause a bigger outage than the one it is fixing.
 */
class AppMenuKill extends Command
{
    protected $signature = 'app-menu:kill
                            {--reason= : Why, in a sentence, recorded on the row}
                            {--by= : The name of the human being pulling the lever}';

    protected $description = 'Make the mobile app menu endpoint 404 for every organisation, so the apps fall back to the legacy feature list.';

    public function handle(): int
    {
        $reason = trim((string) $this->option('reason')) ?: null;
        $by = trim((string) $this->option('by')) ?: null;

        $row = AppMenuSetting::row();
        $wasKilled = (bool) $row->menu_disabled;

        $row->fill([
            'menu_disabled' => true,
            'reason' => $reason,
            'updated_by' => $by,
        ])->save();

        Cache::forget(AppMenu::KILL_CACHE_KEY);

        Log::warning('app menu KILLED: /menu will answer 404 for every organisation', [
            'reason' => $reason,
            'by' => $by,
            'was_already_killed' => $wasKilled,
        ]);

        $this->warn('The app menu endpoint is now OFF.');
        $this->line('  /mobile/masjids/{id}/menu answers 404; the apps fall back to /features + /orgs.');
        $this->line('  In flight for up to ' . AppMenu::KILL_CACHE_TTL . ' s while the cached answer expires.');
        $this->line('  Restore with: php artisan app-menu:restore');

        if ($wasKilled) {
            $this->line('  (it was already off — the reason and the name have been updated)');
        }

        if ($reason === null) {
            $this->warn('  No --reason was given. Nothing records why this happened; say so somewhere a person will read.');
        }

        return self::SUCCESS;
    }
}
