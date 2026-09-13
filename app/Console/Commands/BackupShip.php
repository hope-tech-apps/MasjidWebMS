<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupSet;
use App\Support\Backup\OffsiteShipper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Copy a verified set to the off-site store.
 *
 * `backup:run` already does this for the set it has just written — see the ship
 * step at the end of its handle() — so this command exists for the three cases
 * that one does not cover:
 *
 *   - the sets that already exist locally and predate the off-site copy, which
 *     is every set on this platform today;
 *   - a night whose local set was written and whose upload failed, once the
 *     store is back;
 *   - `--dry-run`, so an operator wiring credentials for the first time can see
 *     exactly which bucket, which region and which keys it will use before any
 *     bytes leave the machine.
 *
 * IT SHIPS A SET, NEVER A FILE. Both halves and the manifest that binds them, in
 * that order, with the manifest LAST so a killed run leaves litter rather than
 * something that reads as a complete backup and is not. The refusals and the
 * ordering live in App\Support\Backup\OffsiteShipper, where the reasoning is
 * written down; this command is the way a person invokes them.
 *
 * IT IS SAFE TO RUN TWICE. A set that is already up, in one piece, is reported
 * as `already` and nothing is re-uploaded, so an operator can put this in a loop
 * over every set in the destination without thinking about it.
 *
 * EXIT CODES
 *   0  the set is off-site (shipped now, or already was), or there is nothing
 *      configured to ship to and that is the known state of this platform
 *   1  the set was not shipped — refused locally, or the store said no
 */
class BackupShip extends Command
{
    protected $signature = 'backup:ship
                            {--set= : Set id, or an absolute path. Default: the newest set in the destination}
                            {--all : Ship every finished set in the destination, oldest first}
                            {--dry-run : Print the destination and what would be uploaded, and send nothing}
                            {--json : Emit the result as JSON on stdout}';

    protected $description = 'Copy verified backup sets to the off-site store, both halves or neither.';

    private const EXIT_OK = 0;

    private const EXIT_FAILED = 1;

    public function handle(): int
    {
        $config = (array) config('backup');
        $destination = rtrim((string) ($config['destination'] ?? ''), '/');
        $shipper = OffsiteShipper::forConfig($config);
        $target = $shipper->target();

        $this->line(sprintf('off-site target: %s', $target->describe()));

        if (! $target->enabled) {
            // Not an error. There are no credentials for this yet and that is a
            // known, stated position — `backup:check` prints it on every run.
            // Exiting non-zero here would make an operator's first exploratory
            // run look like a fault.
            $this->newLine();
            $this->warn('Nothing was shipped, and nothing is being shipped nightly either.');
            $this->line('  Turn it on: set BACKUP_OFFSITE_ENABLED=true with BACKUP_OFFSITE_BUCKET, _REGION, _ENDPOINT, _KEY and _SECRET in .env, then re-run with --dry-run.');

            return self::EXIT_OK;
        }

        $sets = $this->resolveSets($destination);

        if ($sets === []) {
            $this->error(sprintf('No finished backup set to ship in %s.', $destination));

            return self::EXIT_FAILED;
        }

        if ($this->option('dry-run')) {
            foreach ($sets as $set) {
                $this->line(sprintf('  would ship %s (%d bytes) to:', $set->id(), $set->bytes()));

                foreach ([BackupSet::DATABASE_FILE, BackupSet::MEDIA_FILE, BackupSet::MANIFEST_FILE] as $file) {
                    $this->line(sprintf('      %s', $target->urlFor($target->keyFor($set->id(), $file))));
                }

                $problems = $set->problems();

                foreach ($problems as $problem) {
                    $this->warn('    A real run would REFUSE: '.$problem);
                }
            }

            $this->newLine();
            $this->info('Dry run — nothing left this machine.');

            return self::EXIT_OK;
        }

        $results = [];
        $failed = false;

        foreach ($sets as $set) {
            $result = $shipper->ship($set);
            $results[] = $result;

            if (in_array($result['status'], [OffsiteShipper::REFUSED, OffsiteShipper::FAILED], true)) {
                $failed = true;

                $this->error(sprintf('%s: %s', $set->id(), (string) $result['reason']));

                foreach ($result['detail'] as $line) {
                    $this->line('  '.$line);
                }

                // The watchdog's channel on purpose: a set that failed to ship
                // is the same operator's problem as a set that is not off-site,
                // and it should arrive by the same route rather than inventing
                // a second one to configure.
                Log::channel(config('backup.check.log_channel'))->error('backup:ship did not ship a set', $result);

                continue;
            }

            $this->info(sprintf('%s: %s', $set->id(), (string) ($result['reason'] ?? $result['status'])));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $failed ? self::EXIT_FAILED : self::EXIT_OK;
    }

    /** @return list<BackupSet> */
    private function resolveSets(string $destination): array
    {
        if ($this->option('all')) {
            // Oldest first, so an interrupted bulk upload has made progress
            // through the history rather than repeatedly re-shipping the newest.
            return array_reverse(BackupSet::all($destination));
        }

        $selector = trim((string) ($this->option('set') ?? ''));

        if ($selector === '') {
            $latest = $destination === '' ? null : BackupSet::latest($destination);

            return $latest === null ? [] : [$latest];
        }

        foreach ([$selector, $destination.'/'.$selector] as $candidate) {
            if (is_dir($candidate)) {
                return [BackupSet::at($candidate)];
            }
        }

        return [];
    }
}
