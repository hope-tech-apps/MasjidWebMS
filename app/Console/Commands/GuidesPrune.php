<?php

namespace App\Console\Commands;

use App\Support\Guides\GuideReleases;
use App\Support\Guides\GuideValidationException;
use Illuminate\Console\Command;

class GuidesPrune extends Command
{
    protected $signature = 'guides:prune';
    protected $description = 'Remove guide releases except the current and previous versions';

    public function handle(GuideReleases $releases): int
    {
        try {
            foreach ($releases->prune() as $version) $this->line('Pruned '.$version);
            return self::SUCCESS;
        } catch (GuideValidationException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
