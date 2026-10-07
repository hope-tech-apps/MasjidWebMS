<?php

namespace App\Console\Commands;

use App\Support\Guides\GuideReleases;
use App\Support\Guides\GuideValidationException;
use Illuminate\Console\Command;

class GuidesCommand extends Command
{
    protected $signature = 'guides:install {folder : Release folder containing manifest.json}';
    protected $description = 'Validate and atomically install a private guide release';

    public function handle(GuideReleases $releases): int
    {
        try {
            $this->info('Installed '.$releases->install($this->argument('folder')));
            return self::SUCCESS;
        } catch (GuideValidationException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
