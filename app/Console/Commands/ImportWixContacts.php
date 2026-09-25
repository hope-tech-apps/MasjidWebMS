<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Services\Imports\WixContactImport;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Import a Wix contacts export into one organisation.
 *
 *   php artisan wix:import-contacts contacts.json --masjid=13 --labels=labels.json            # dry run
 *   php artisan wix:import-contacts contacts.json --masjid=13 --labels=labels.json --execute  # write
 *   php artisan wix:import-contacts --masjid=13 --undo=wix-contacts-20261020-1432             # reverse one run
 *
 * The console face of App\Services\Imports\WixContactImport, which holds every
 * rule (the consent rule, matching, re-runs, undo). This reads options, prints
 * and exits; it decides nothing about the file.
 *
 * - DRY RUN BY DEFAULT. Nothing is written without --execute.
 * - COUNTS ONLY. The output never prints a name, an address or a number, so
 *   running it over the real export — in a terminal, a log or a chat — shows
 *   nobody. Refusals name contact ids and table names, never a person.
 * - SENDS NOTHING. No email, text, push or notification, and no invitation.
 * - ONE ORGANISATION. The tenant is bound before anything is read, so matching
 *   sees only that organisation's contacts and every write lands in it.
 */
class ImportWixContacts extends Command
{
    protected $signature = 'wix:import-contacts
        {file? : The Wix contacts export (JSON: a "contacts" array or a bare array of Contacts v4 objects)}
        {--masjid= : Organisation id to import into}
        {--labels= : The Wix label definitions (JSON with a "labels" array), so tags carry their display names}
        {--batch= : Tag for this run (default wix-contacts-YYYYMMDD-HHMMSS); --undo takes it}
        {--execute : Actually write (otherwise a dry run)}
        {--undo= : Remove what the named run created, then exit}';

    protected $description = 'Import a Wix contacts export into one organisation (dry run by default; reversible).';

    /** Row label => key in WixContactImport::counts(). */
    private const ROWS = [
        'Contacts' => [
            'Wix contacts in the file' => 'records',
            '  of which old-website members (imported as contacts only, no invitation)' => 'site_members',
            'People after merging Wix duplicates' => 'people',
            'Wix duplicates merged into another record' => 'duplicates_merged',
            'New contacts to create' => 'create',
            '  of which a phone matched several contacts, so created separately' => 'phone_matched_several',
            'Matched to an existing contact by email (left as they are)' => 'matched_email',
            'Matched to an existing contact by phone (left as they are)' => 'matched_phone',
            'Already imported, unchanged' => 'already_imported_unchanged',
            'Already imported, updated from this file' => 'already_imported_updated',
            'Already imported, edited in Manara since (edit kept)' => 'already_imported_edited_kept',
            'Deleted in Manara, not recreated' => 'deleted_in_manara_skipped',
        ],
        'Email' => [
            'Stay mailable (SUBSCRIBED and VALID on Wix)' => 'mailable',
            'No email address (phone only)' => 'no_email',
            'Suppress: spam complaint on Wix' => 'suppress_complaint',
            'Suppress: unsubscribed on Wix' => 'suppress_opt_out',
            'Suppress: bounced on Wix' => 'suppress_bounce',
            'Suppress: never opted in, or inactive, on Wix' => 'suppress_not_opted_in',
            '  of which Wix opt-outs applied to existing contacts' => 'opt_outs_on_existing_contacts',
            'Already suppressed in Manara' => 'already_suppressed',
            'Existing Manara contacts Wix never opted in (left as they are)' => 'existing_left_mailable_by_rule',
            'Suppressed earlier, SUBSCRIBED on Wix now (not released)' => 'suppressed_but_now_subscribed',
        ],
        'Text messages' => [
            'SMS consents written' => 'sms_consents',
            'SMS opt-outs to record' => 'sms_opt_outs',
        ],
        'Tags' => [
            'Wix labels in the file' => 'labels',
            'Tags to create' => 'tags_create',
            'Existing tags reused' => 'tags_reused',
            'Tags deleted in Manara, not recreated' => 'tags_deleted_in_manara',
            'Tag assignments in the file' => 'tag_assignments',
        ],
    ];

    public function handle(WixContactImport $import): int
    {
        $masjid = Masjid::find((int) $this->option('masjid'));

        if (! $masjid) {
            $this->error('--masjid must name an existing organisation.');

            return self::FAILURE;
        }

        // Bind the tenant EXPLICITLY: a console command has no request and so
        // no ResolveMasjidTenant, and every BelongsToMasjid query would
        // otherwise run across all organisations (.claude/rules/tenant-scoping.md).
        app(TenantContext::class)->set($masjid->id);

        if (filled($this->option('undo'))) {
            return $this->undo($import, (string) $this->option('undo'), $masjid);
        }

        $path = (string) $this->argument('file');

        if ($path === '' || ! is_readable($path)) {
            $this->error('Give the path of a readable Wix contacts export.');

            return self::FAILURE;
        }

        $labelsPath = $this->option('labels');

        if (filled($labelsPath) && ! is_readable((string) $labelsPath)) {
            $this->error('The --labels file cannot be read.');

            return self::FAILURE;
        }

        ['records' => $records, 'problem' => $problem] = $import->read($path);

        if ($records === null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $labelNames = $import->readLabels(filled($labelsPath) ? (string) $labelsPath : null);
        $plan = $import->plan($masjid->id, $records, $labelNames);
        $counts = $import->counts($plan);
        $execute = (bool) $this->option('execute');
        $batch = (string) ($this->option('batch') ?: 'wix-contacts-' . now()->format('Ymd-His'));

        $this->info(($execute ? "Importing Wix contacts (batch {$batch})" : 'Wix contact import — DRY RUN') . " into organisation {$masjid->id}");

        foreach (self::ROWS as $section => $rows) {
            $this->newLine();
            $this->table([$section, 'Count'], array_map(
                fn (string $label, string $key) => [$label, $counts[$key]],
                array_keys($rows),
                array_values($rows),
            ));
        }

        if ($counts['labels'] > 0 && $labelNames === []) {
            $this->warn('No --labels file: tags will be named from the Wix label keys.');
        }

        if (! $execute) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        $written = $import->apply($plan, $batch);

        $this->newLine();
        $this->table(['Written', 'Count'], collect($written)->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());
        $this->info("Imported. Batch: {$batch}");
        $this->line("Undo with: php artisan wix:import-contacts --masjid={$masjid->id} --undo={$batch}");

        return self::SUCCESS;
    }

    private function undo(WixContactImport $import, string $batch, Masjid $masjid): int
    {
        $result = $import->undo($batch);

        if ($result['refused'] !== []) {
            $this->error("Refusing to undo batch {$batch}: nothing has been removed. These contacts are now the organisation's records:");

            foreach ($result['refused'] as $why) {
                $this->line("  {$why}");
            }

            return self::FAILURE;
        }

        $this->table(['Undone', 'Count'], [
            ['Contacts removed (erased, not archived)', $result['contacts_removed']],
            ['Tag assignments removed', $result['tag_assignments_removed']],
            ['Tags removed', $result['tags_removed']],
            ['Tags kept because contacts still carry them', $result['tags_kept_in_use']],
        ]);
        $this->line('Email and SMS suppressions written by the run are KEPT: an opt-out is never deleted.');
        $this->info("Batch {$batch} undone in organisation {$masjid->id}.");

        return self::SUCCESS;
    }
}
