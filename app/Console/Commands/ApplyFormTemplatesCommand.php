<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Support\FormTemplates;
use Illuminate\Console\Command;

/**
 * (Re)apply a tenant's vertical form templates to an EXISTING tenant.
 *
 * Provisioning already seeds these (OnboardingController@provision), so this
 * command exists for the tenant that predates its templates — Al-Razi's, once
 * T-012 provisions it — and for a vertical that gains a template later.
 *
 *   php artisan form:apply-templates 7
 *   php artisan form:apply-templates 7 --dry-run
 *
 * Idempotent and never destructive: any form (live OR soft-deleted) already
 * holding a template's slug for this tenant is skipped and reported, so an
 * admin-edited form is never overwritten and a deliberately deleted one is
 * never resurrected. See App\Support\FormTemplates.
 */
class ApplyFormTemplatesCommand extends Command
{
    protected $signature = 'form:apply-templates
                            {masjid : The tenant id to apply templates to}
                            {--dry-run : Report what would happen without writing anything}';

    protected $description = "Seed a tenant's vertical form templates, skipping any slug the tenant already has";

    public function handle(): int
    {
        $masjid = Masjid::find((int) $this->argument('masjid'));

        if (! $masjid) {
            $this->error('No masjid with id ' . $this->argument('masjid') . '.');

            return self::FAILURE;
        }

        $orgType = $masjid->orgType();
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Applying {$orgType} form templates to \"{$masjid->name}\" (id {$masjid->id})");

        if (FormTemplates::forOrgType($orgType) === []) {
            $this->comment("The {$orgType} vertical defines no form templates. Nothing to do.");

            return self::SUCCESS;
        }

        $result = FormTemplates::applyTo($masjid, $dryRun);

        foreach ($result['created'] as $slug) {
            $this->line('  created: ' . $slug . ($dryRun ? ' (dry run — not written)' : ''));
        }

        foreach ($result['skipped'] as $slug) {
            $this->line("  skipped: {$slug} (a form with this slug already exists — left untouched)");
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d, skipped %d.',
            $dryRun ? 'Would create' : 'Created',
            count($result['created']),
            count($result['skipped'])
        ));

        return self::SUCCESS;
    }
}
