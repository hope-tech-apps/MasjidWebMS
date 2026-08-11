<?php

namespace App\Console\Commands;

use App\Support\DemoSchool;
use App\Support\DemoSchoolSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Stand up (or tear down) the Al-Razi Islamic School demo tenant.
 *
 * ## Why this is a command and not a seeder
 *
 * It writes a whole tenant, and `--rollback` DELETES one. Both are things a
 * human should have to ask for by name. So:
 *
 *  - it lives in `app/Console/Commands`, not `database/seeders`, and is
 *    therefore not reachable through `db:seed --class=`;
 *  - `DatabaseSeeder` does not call it, provisioning does not call it, nothing
 *    scheduled calls it, and no test fixture calls it as a side effect;
 *  - in PRODUCTION it REFUSES outright without `--force`.
 *
 * That last guard is deliberately stricter than Laravel's `ConfirmableTrait`,
 * which this repository does not use anywhere. `confirmToProceed()` accepts a
 * typed "yes" at an interactive prompt as sufficient; a command that can
 * force-delete a tenant root should not be one keystroke away on a machine that
 * also holds paying tenants. The shape here — environment check, explicit flag,
 * loud refusal — is the one `DatabaseSeeder` already establishes in this
 * codebase for the same reason, so an operator meets one convention rather than
 * two.
 *
 * ## What makes the rollback safe
 *
 * Everything the seeder writes is marked by an address under the reserved
 * `al-razi-demo.invalid` domain (RFC 2606 — it can never resolve, so nothing
 * here can email a real person), and everything except the staff accounts hangs
 * off the marker tenant's `masjid_id`. `--rollback` deletes the rows of that one
 * tenant and the accounts at that one domain, so a real tenant sitting in the
 * same database is untouched — pinned by `tests/Feature/DemoSchoolSeederTest`.
 *
 * Re-running is safe: the seeder creates only what is missing and never updates
 * anything, so edits a demoer made between runs survive.
 */
class SeedDemoSchool extends Command
{
    protected $signature = 'demo:seed-school
                            {--rollback : Remove the demo tenant and everything it created, then stop}
                            {--fresh : Remove it first, then seed from scratch}
                            {--city= : Attach the tenant to this city id (default: the lowest-numbered city)}
                            {--admin-password= : Password for the principal account (default: random and discarded)}
                            {--force : Required to run in production}';

    protected $description = 'Seed (or remove) the Al-Razi Islamic School demo tenant — a complete, fictional Schools-vertical organization.';

    public function handle(): int
    {
        // THE PRODUCTION GUARD. Nothing below this line runs on a production
        // database unless the operator said --force in the same breath.
        if (app()->environment('production') && ! $this->option('force')) {
            $this->components->error(
                'Refusing to seed or remove demo data on production. Re-run with --force if you are certain.'
            );

            return self::FAILURE;
        }

        $seeder = new DemoSchoolSeeder(fn (string $message) => $this->line("  <fg=gray>{$message}</>"));

        try {
            if ($this->option('rollback') || $this->option('fresh')) {
                $this->components->info('Removing the Al-Razi demo tenant.');

                $removed = $seeder->rollback();

                if ($removed === []) {
                    $this->components->warn('Nothing to remove — no demo tenant is present.');
                } else {
                    $this->summarize('Removed', $removed);
                }

                if ($this->option('rollback')) {
                    return self::SUCCESS;
                }
            }

            $this->components->info('Seeding the Al-Razi Islamic School demo tenant.');

            $result = $seeder->seed([
                'city_id' => $this->option('city') !== null ? (int) $this->option('city') : null,
                'admin_password' => $this->option('admin-password'),
            ]);

            $created = $result['created'] ?? [];
            unset($result['created']);

            if ($created === []) {
                $this->newLine();
                $this->line('  <options=bold>Created</> nothing — the demo tenant was already complete.');
            } else {
                $this->summarize('Created', $created);
            }

            $this->summarize('Now present', $result);

            $this->newLine();
            $this->components->info('Ready. Sign in as the principal: ' . DemoSchool::email('Khadija', 'Nasser'));

            if (! $this->option('admin-password')) {
                $this->components->warn(
                    'That account has a random, discarded password. Re-run with --admin-password=… to set one you can type.'
                );
            }

            $this->line('  Remove it all again with: <fg=cyan>php artisan demo:seed-school --rollback</>');
            $this->line('  Demo script: <fg=cyan>docs/demo-school.md</>');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string,int|string> $rows */
    private function summarize(string $heading, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->line("  <options=bold>{$heading}</>");

        foreach ($rows as $label => $value) {
            $this->line(sprintf('    %-18s %s', str_replace('_', ' ', (string) $label), $value));
        }
    }
}
