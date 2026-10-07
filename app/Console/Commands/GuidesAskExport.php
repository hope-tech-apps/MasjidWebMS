<?php

namespace App\Console\Commands;

use App\Models\GuideUnansweredQuestion;
use Illuminate\Console\Command;
use Throwable;
use Symfony\Component\Console\Output\OutputInterface;

class GuidesAskExport extends Command
{
    protected $signature = 'guides:ask-export';
    protected $description = 'List/export unanswered guide questions as JSON lines to standard output';

    public function handle(): int
    {
        try {
            foreach (GuideUnansweredQuestion::query()->orderBy('created_at')->cursor() as $row) {
                // JSON escapes line breaks/control characters and preserves all question text.
                $this->output->writeln(json_encode($row->only(['question', 'created_at', 'books', 'release_version']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            }
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Could not export guide questions.');
            return self::FAILURE;
        }
    }
}
