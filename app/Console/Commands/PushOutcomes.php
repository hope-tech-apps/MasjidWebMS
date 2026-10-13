<?php

namespace App\Console\Commands;

use App\Models\PushSend;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/** Operator-only read surface: no web permission or credential disclosure. */
class PushOutcomes extends Command
{
    protected $signature = 'push:outcomes {--masjid= : Restrict to an organisation id} {--since= : ISO date/time; defaults to seven days ago}';

    protected $description = 'List recent push sends and per-account delivery outcomes without credentials.';

    public function handle(): int
    {
        if (!Schema::hasTable('push_sends')) {
            $this->error('Push outcome storage is not installed.');
            return self::FAILURE;
        }
        $org = $this->option('masjid');
        if ($org !== null && (!ctype_digit((string) $org) || (int) $org < 1)) {
            $this->error('--masjid must be a positive organisation id.');
            return self::FAILURE;
        }
        try {
            $since = $this->option('since') ? Carbon::parse($this->option('since')) : now()->subDays(7);
        } catch (\Throwable $e) {
            $this->error('--since must be a date/time.');
            return self::FAILURE;
        }
        // The shell operator is the platform administrator. Deliberate cross-tenant query, optionally narrowed.
        $query = PushSend::withoutMasjidScope()->where('created_at', '>=', $since)->orderBy('id');
        if ($org !== null) {
            $query->where('masjid_id', (int) $org);
        }
        foreach ($query->cursor() as $send) {
            $this->line("Send {$send->id} | organisation {$send->masjid_id} | {$send->kind} | ".$send->wording());
            $groups = $send->groups()->withoutGlobalScopes()->where('masjid_id', $send->masjid_id)->orderBy('id')->get();
            foreach ($groups as $group) {
                // Labels are display text, never terminal control sequences or credentials.
                $label = preg_replace('/[\x00-\x1F\x7F]/', '', $group->app_label);
                $this->line("  {$label} | {$group->status} | targeted {$group->targeted} | delivered "
                    .($group->delivered ?? 'unknown').' | errored '.($group->errored ?? 'unknown'));
                foreach ($group->platform_counts ?? [] as $platform => $counts) {
                    $this->line('    '.$platform.' | delivered '.($counts['delivered'] ?? 'unknown')
                        .' | errored '.($counts['errored'] ?? 'unknown'));
                }
            }
        }
        return self::SUCCESS;
    }
}
