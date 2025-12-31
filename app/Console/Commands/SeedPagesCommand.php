<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Database\Seeders\PagesSeeder;

class SeedPagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seed:pages {--masjid_id= : The ID of the masjid to seed pages for (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed pages and sections for all masjids or a specific masjid';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $masjidId = $this->option('masjid_id');

        if ($masjidId) {
            $this->info("Seeding pages for masjid ID: {$masjidId}");
        } else {
            $this->info("Seeding pages for all masjids");
        }

        $seeder = new PagesSeeder();
        $seeder->setCommand($this);
        $seeder->run($masjidId ? (int) $masjidId : null);

        return Command::SUCCESS;
    }
}

