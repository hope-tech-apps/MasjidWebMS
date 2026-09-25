<?php

namespace App\Console\Commands;

use App\Models\ImportLink;
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
 *   php artisan wix:import-contacts --masjid=13 --undo=wix-contacts-20261020-1432 --remove-opt-outs  # …written into the wrong organisation
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
 * - ONE BATCH NAME PER RUN. A --batch already used in the organisation, by this
 *   or any staged importer, is refused before anything is written: undo selects
 *   by batch, so a shared name would let one undo remove two runs.
 */
class ImportWixContacts extends Command
{
    protected $signature = 'wix:import-contacts
        {file? : The Wix contacts export (JSON: a "contacts" array or a bare array of Contacts v4 objects)}
        {--masjid= : Organisation id to import into}
        {--labels= : The Wix label definitions (JSON with a "labels" array), so tags carry their display names}
        {--batch= : Tag for this run (default wix-contacts-YYYYMMDD-HHMMSS); --undo takes it}
        {--execute : Actually write (otherwise a dry run)}
        {--undo= : Remove what the named run created, then exit}
        {--remove-opt-outs : With --undo: also remove the Wix opt-outs the run copied (only for a run written into the wrong organisation or from the wrong file)}';

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
            'Stay mailable (SUBSCRIBED and VALID on Wix, not suppressed in Manara)' => 'mailable',
            'SUBSCRIBED on Wix, but suppressed in Manara (kept suppressed)' => 'suppressed_but_now_subscribed',
            'No email address (phone only)' => 'no_email',
            'Suppress: spam complaint on Wix' => 'suppress_complaint',
            'Suppress: unsubscribed on Wix' => 'suppress_opt_out',
            'Suppress: bounced on Wix' => 'suppress_bounce',
            'Suppress: never opted in, or inactive, on Wix' => 'suppress_not_opted_in',
            '  of which Wix opt-outs on contacts already in Manara' => 'opt_outs_on_existing_contacts',
            '  of which precautions on contacts already in Manara' => 'precautions_on_existing_contacts',
            'Already suppressed in Manara' => 'already_suppressed',
            'Released in Manara by the person or staff (Wix status not applied)' => 'released_in_manara_kept',
        ],
        'Text messages' => [
            'SMS consents written' => 'sms_consents',
            'SMS opt-outs to record' => 'sms_opt_outs',
            'SMS opt-outs released in Manara (Wix status not applied)' => 'sms_released_in_manara_kept',
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
            return $this->undo($import, (string) $this->option('undo'), $masjid, (bool) $this->option('remove-opt-outs'));
        }

        if ($this->option('remove-opt-outs')) {
            $this->error('--remove-opt-outs only goes with --undo.');

            return self::FAILURE;
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

        if ($execute && ImportLink::batchUsed($masjid->id, $batch)) {
            $this->error("Batch {$batch} has already been used in organisation {$masjid->id}; nothing was written. Name a new --batch, so one undo can only ever remove one run.");

            return self::FAILURE;
        }

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

    private function undo(WixContactImport $import, string $batch, Masjid $masjid, bool $removeOptOuts): int
    {
        $result = $import->undo($batch, $removeOptOuts);

        if ($result['refused'] !== []) {
            $this->error("Refusing to undo batch {$batch}: nothing has been removed. These contacts are now the organisation's records:");

            foreach ($result['refused'] as $why) {
                $this->line("  {$why}");
            }

            return self::FAILURE;
        }

        $this->table(['Undone', 'Count'], [
            ['Contacts removed (erased, not archived)', $result['contacts_removed']],
            ['Links later runs held to those contacts, removed', $result['later_links_removed']],
            ['Tag assignments removed', $result['tag_assignments_removed']],
            ['Tags removed', $result['tags_removed']],
            ['Tags kept because contacts still carry them', $result['tags_kept_in_use']],
            ['Precaution suppressions the run wrote (not opted in, bounced), removed', $result['precautions_removed']],
            ['Wix email opt-outs the run wrote, removed (--remove-opt-outs)', $result['opt_outs_removed']],
            ['Wix email opt-outs the run wrote, KEPT', $result['opt_outs_kept']],
            ['Wix SMS opt-outs the run wrote, removed (--remove-opt-outs)', $result['sms_opt_outs_removed']],
            ['Wix SMS opt-outs the run wrote, KEPT', $result['sms_opt_outs_kept']],
            ['Suppressions the run wrote that were released in Manara since, KEPT', $result['released_kept']],
        ]);
        if (! $removeOptOuts && $result['opt_outs_kept'] + $result['sms_opt_outs_kept'] > 0) {
            $this->line('Opt-outs copied from Wix are real requests and stay. Only if this run went into the wrong organisation, undo the same batch again with --remove-opt-outs.');
        }
        $this->info("Batch {$batch} undone in organisation {$masjid->id}.");

        return self::SUCCESS;
    }
}
