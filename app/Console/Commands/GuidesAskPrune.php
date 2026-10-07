<?php

namespace App\Console\Commands;

use App\Models\GuideUnansweredQuestion;
use Illuminate\Console\Command;
use Throwable;

class GuidesAskPrune extends Command
{
    protected $signature = 'guides:ask-prune';
    protected $description = 'Delete unanswered guide questions older than the configured retention age';

    public function handle(): int
    {
        $days = (int) config('guide_ask.retention_days');
        if ($days < 1) { $this->error('GUIDE_ASK_RETENTION_DAYS must be positive.'); return self::FAILURE; }
        try {
            $count = GuideUnansweredQuestion::query()->where('created_at', '<', now()->subDays($days))->delete();
            $this->info('Removed '.$count.' unanswered guide questions.');
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Could not prune guide questions.');
            return self::FAILURE;
        }
    }
}
