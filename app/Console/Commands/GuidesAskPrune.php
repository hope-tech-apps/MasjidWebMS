<?php

namespace App\Console\Commands;

use App\Models\GuideUnansweredQuestion;
use App\Models\GuideAskCounter;
use Illuminate\Console\Command;
use Throwable;

class GuidesAskPrune extends Command
{
    protected $signature = 'guides:ask-prune';
    protected $description = 'Delete expired guide spending periods and unanswered questions older than the retention age';

    public function handle(): int
    {
        $days = (int) config('guide_ask.retention_days');
        if ($days < 1) { $this->error('GUIDE_ASK_RETENTION_DAYS must be positive.'); return self::FAILURE; }
        try {
            $count = GuideUnansweredQuestion::query()->where('created_at', '<', now()->subDays($days))->delete();
            $utc = now()->utc();
            $counters = GuideAskCounter::query()->where(function ($q) use ($utc) {
                $q->where('scope_key', 'like', 'org:%')->where('period', '<', $utc->format('Y-m-d'));
            })->orWhere(function ($q) use ($utc) {
                $q->where('scope_key', 'platform')->where('period', '<', $utc->startOfMonth()->format('Y-m-d'));
            })->delete();
            $this->info('Removed '.$count.' unanswered guide questions and '.$counters.' expired spending counters.');
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Could not prune guide questions.');
            return self::FAILURE;
        }
    }
}
