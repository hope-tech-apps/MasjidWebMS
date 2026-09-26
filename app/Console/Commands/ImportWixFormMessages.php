<?php

namespace App\Console\Commands;

use App\Models\ImportLink;
use App\Models\Masjid;
use App\Services\Imports\WixFormMessageImport;
use App\Support\MasjidTime;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Import a Wix form's submissions (its CSV export) into one organisation's
 * contact messages, marked answered.
 *
 *   php artisan wix:import-form-messages contact.csv --masjid=13 --form="Contact"             # dry run
 *   php artisan wix:import-form-messages contact.csv --masjid=13 --form="Contact" --execute   # write
 *   php artisan wix:import-form-messages --masjid=13 --undo=wix-messages-20261020-1432        # reverse one run
 *
 * The console face of App\Services\Imports\WixFormMessageImport, which holds
 * the rules. DRY RUN by default; the output is the column mapping (header
 * names, never a cell) and counts, and a refused row is named by its row
 * number only. It sends nothing: no notification to the office, no reply to
 * the sender.
 */
class ImportWixFormMessages extends Command
{
    protected $signature = 'wix:import-form-messages
        {file? : The Wix form submissions export (CSV with a header row)}
        {--masjid= : Organisation id to import into}
        {--form= : The Wix form\'s name ("Contact", "Get Subscribers 2"); becomes the messages\' reason}
        {--map=* : Map a column by hand: --map="Your Question=message" (fields: submission_id, submitted_at, name, first_name, last_name, email, phone, subject, message)}
        {--timezone= : Timezone the export\'s dates are written in (default: the organisation\'s)}
        {--date-format= : PHP date format of the date column, when it is ambiguous (e.g. "m/d/Y g:i A")}
        {--batch= : Tag for this run (default wix-messages-YYYYMMDD-HHMMSS); --undo takes it}
        {--execute : Actually write (otherwise a dry run)}
        {--undo= : Remove what the named run imported, then exit}';

    protected $description = 'Import a Wix form\'s submission CSV into contact messages, marked answered (dry run by default; reversible).';

    public function handle(WixFormMessageImport $import): int
    {
        $masjid = Masjid::find((int) $this->option('masjid'));

        if (! $masjid) {
            $this->error('--masjid must name an existing organisation.');

            return self::FAILURE;
        }

        // Bound for ImportLink's tenant scope; the message tables are
        // hand-scoped and the service names masjid_id on every query itself.
        app(TenantContext::class)->set($masjid->id);

        if (filled($this->option('undo'))) {
            return $this->undo($import, (int) $masjid->id, (string) $this->option('undo'));
        }

        $path = (string) $this->argument('file');
        $form = trim((string) $this->option('form'));

        if ($path === '' || ! is_readable($path)) {
            $this->error('Give the path of a readable CSV export.');

            return self::FAILURE;
        }

        if ($form === '') {
            $this->error('--form is required: the Wix form\'s name, which files the messages under a reason.');

            return self::FAILURE;
        }

        $overrides = [];
        foreach ((array) $this->option('map') as $pair) {
            [$header, $field] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $overrides[trim($header)] = trim($field);
        }

        ['rows' => $rows, 'headers' => $headers, 'mapping' => $mapping, 'problem' => $problem] = $import->read($path, $overrides);

        $this->table(['Column in the file', 'Read as'], array_map(
            fn (string $header) => [$header, $mapping[$header] ?? '(kept in the message text)'],
            $headers,
        ));

        if ($rows === null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $timezone = (string) ($this->option('timezone') ?: MasjidTime::zoneFor($masjid->id));
        $plan = $import->plan($masjid->id, $form, $rows, $mapping, $timezone, $this->option('date-format') ?: null);
        $counts = $import->counts($plan);
        $execute = (bool) $this->option('execute');
        $batch = (string) ($this->option('batch') ?: 'wix-messages-' . now()->format('Ymd-His'));

        // Undo selects by batch, so a name any staged importer already used
        // here would let one undo remove two runs.
        if ($execute && ImportLink::batchUsed($masjid->id, $batch)) {
            $this->error("Batch {$batch} has already been used in organisation {$masjid->id}; nothing was written. Name a new --batch.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(($execute ? "Importing \"{$form}\" submissions (batch {$batch})" : "\"{$form}\" submissions — DRY RUN")
            . " into organisation {$masjid->id}, dates read as {$timezone}");
        $this->table(['Submissions', 'Count'], [
            ['Rows in the file', $counts['rows']],
            ['To import, marked answered', $counts['import']],
            ['Already imported (skipped)', $counts['already']],
            ['Repeated in this file (imported once)', $counts['duplicates']],
            ['Refused (see below)', $counts['refused']],
            ['New senders', $counts['new_senders']],
            ['Notifications sent', $counts['notifications']],
        ]);

        foreach ($plan['refused'] as [$row, $why]) {
            $this->line("  row {$row}: {$why}");
        }

        if ($masjid->moduleIsOff('contact_requests')) {
            $this->warn('Contact Requests is switched off for this organisation, so its admins cannot see these messages until it is on.');
        }

        if (! $execute) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        if ($plan['refused'] !== []) {
            $this->error('Refusing to write: fix or remove the refused rows and re-run. A half-imported inbox is harder to check than none.');

            return self::FAILURE;
        }

        $written = $import->apply($plan, $batch);

        $this->info("Imported {$written['messages']} message(s) from {$written['senders']} new sender(s). Batch: {$batch}");
        $this->line("Undo with: php artisan wix:import-form-messages --masjid={$masjid->id} --undo={$batch}");

        return self::SUCCESS;
    }

    private function undo(WixFormMessageImport $import, int $masjidId, string $batch): int
    {
        $result = $import->undo($masjidId, $batch);

        if ($result['refused'] !== []) {
            $this->error("Refusing to undo batch {$batch}: staff have replied to message(s) " . implode(', ', $result['refused'])
                . '. Nothing has been removed.');

            return self::FAILURE;
        }

        $this->info("Removed {$result['messages_removed']} message(s) and {$result['senders_removed']} sender(s) from batch {$batch}.");

        return self::SUCCESS;
    }
}
