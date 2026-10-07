<?php

namespace App\Console\Commands;

use App\Support\Guides\GuideReleases;
use App\Support\Guides\GuideValidationException;
use Illuminate\Console\Command;

class GuidesStatus extends Command
{
    protected $signature = 'guides:status';
    protected $description = 'Show current, previous and installed private guide versions';

    public function handle(GuideReleases $releases): int
    {
        try { $status = $releases->status(); }
        catch (GuideValidationException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        if (! $status['current']) $this->info('No guide release installed');
        else $this->line('Current: '.$status['current'].'; previous: '.($status['previous'] ?? 'none'));
        foreach ($status['installed'] as $version) $this->line($version);
        return self::SUCCESS;
    }
}
