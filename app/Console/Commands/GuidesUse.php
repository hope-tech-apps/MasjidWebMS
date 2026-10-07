<?php

namespace App\Console\Commands;

use App\Support\Guides\GuideReleases;
use App\Support\Guides\GuideValidationException;
use Illuminate\Console\Command;

class GuidesUse extends Command
{
    protected $signature = 'guides:use {version}';
    protected $description = 'Atomically select an installed private guide release';

    public function handle(GuideReleases $releases): int
    {
        try {
            $releases->useVersion($this->argument('version'));
            $this->info('Current '.$this->argument('version'));
            return self::SUCCESS;
        } catch (GuideValidationException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
