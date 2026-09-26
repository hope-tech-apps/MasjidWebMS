<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Services\Crm\WixOrderHistoryImporter;
use App\Support\TenantContext;
use App\Support\WixOrderExport;
use Illuminate\Console\Command;

/**
 * Import an organisation's Wix order history — store orders and Wix Events
 * ticket orders — as donation and registration HISTORY (DECISIONS.md
 * 2026-09-25, "Wix order history").
 *
 * The rules live in App\Services\Crm\WixOrderHistoryImporter and the file
 * format in App\Support\WixOrderExport; this command reads options, binds the
 * tenant, prints, and exits with a status.
 *
 *   php artisan crm:import-wix-orders /path/to/raw --masjid=13            # dry run
 *   php artisan crm:import-wix-orders /path/to/raw --masjid=13 --execute  # write
 *   php artisan crm:import-wix-orders --masjid=13 --undo=<batch>          # preview undo
 *   php artisan crm:import-wix-orders --masjid=13 --undo=<batch> --execute
 *
 * Run `wix:import-contacts` FIRST. A plan that would create contacts before
 * the contact import has run blocks (their addresses would be held, and that
 * import never lifts a hold), unless `--without-contact-import` says the
 * holds are wanted anyway.
 *
 * `/path/to/raw` is the export directory the read-only Wix pull writes, holding
 * `stores/orders.json` and `events/orders.json` + `events/events.json`.
 *
 * THE OUTPUT IS COUNTS AND MONEY ONLY. The export holds buyers' names, emails
 * and addresses; nothing here prints one, so the output can be pasted into a
 * ticket or a chat. Problems name an order by its Wix order number and nothing
 * else.
 *
 * Exit status: 0 when the dry run is clean or the write/undo happened; 1 when
 * the options are wrong, or when anything blocks the import (it then writes
 * nothing, dry run or not, so a scripted run cannot mistake a blocked plan for
 * a finished one).
 */
class ImportWixOrderHistory extends Command
{
    protected $signature = 'crm:import-wix-orders
        {export? : The Wix export directory (stores/orders.json, events/orders.json, events/events.json)}
        {--masjid= : Organisation id to import into}
        {--batch= : Import batch tag (default: wix_orders_<timestamp>)}
        {--execute : Actually write (otherwise dry-run)}
        {--undo= : Remove what this batch created (dry-run unless --execute)}
        {--without-contact-import : Create buyers as held contacts even though the Wix contact import has not run}';

    protected $description = 'Import Wix store and event orders as historical donations and registrations (reversible).';

    public function handle(WixOrderHistoryImporter $importer): int
    {
        $masjidId = (int) $this->option('masjid');
        $masjid = $masjidId > 0 ? Masjid::find($masjidId) : null;

        if ($masjid === null) {
            $this->error($masjidId > 0 ? "No organisation {$masjidId}." : '--masjid is required.');

            return self::FAILURE;
        }

        // Bind the tenant EXPLICITLY: a console command has no request and so no
        // ResolveMasjidTenant, and every BelongsToMasjid read or write here must
        // be this organisation's. The importer refuses to run unbound. Released
        // afterwards, so a caller in the same process (a test, `Artisan::call`)
        // is not left scoped to this organisation.
        $tenant = app(TenantContext::class);
        $tenant->set($masjid->id);

        try {
            return $this->perform($importer, $masjid);
        } finally {
            $tenant->forgetTenant();
        }
    }

    private function perform(WixOrderHistoryImporter $importer, Masjid $masjid): int
    {
        $execute = (bool) $this->option('execute');

        if ($batch = $this->option('undo')) {
            return $this->undo($importer, $masjid, (string) $batch, $execute);
        }

        $dir = (string) $this->argument('export');
        if ($dir === '' || ! is_dir($dir)) {
            $this->error('Give the Wix export directory (holding stores/ and events/).');

            return self::FAILURE;
        }

        $batch = (string) ($this->option('batch') ?: 'wix_orders_' . now()->format('Ymd_His'));
        if (strlen($batch) > 64) {
            $this->error('--batch must be 64 characters or fewer.');

            return self::FAILURE;
        }

        $export = WixOrderExport::fromDirectory($dir);
        $summary = $importer->run(
            $masjid, $export['orders'], $export['problems'], $batch, $execute,
            allowHeldContacts: (bool) $this->option('without-contact-import'),
        );

        $this->render($masjid, $batch, $summary, $execute);

        if ($summary['blocking'] !== []) {
            $this->error("\nBLOCKED — nothing was written. Resolve the " . count($summary['blocking']) . ' item(s) above and run again.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn("\nDRY RUN — nothing written. Re-run with --execute --batch={$batch} to apply.");

            return self::SUCCESS;
        }

        $this->info("\nIMPORTED (batch {$batch}). No receipt, email, SMS or push was sent.");
        $this->line("Undo with: php artisan crm:import-wix-orders --masjid={$masjid->id} --undo={$batch} --execute");

        return self::SUCCESS;
    }

    private function render(Masjid $masjid, string $batch, array $s, bool $execute): void
    {
        $this->info(($execute ? 'EXECUTE' : 'DRY RUN') . " — organisation #{$masjid->id}, batch {$batch}");

        $stores = $s['orders']['wix_stores'];
        $events = $s['orders']['wix_events'];
        $this->table(['In the export', 'Orders', 'Paid', 'Not paid', 'Paid total'], [
            ['Wix store orders', $stores['count'], $stores['paid'], $stores['unpaid'], $this->money($stores['paid_minor'])],
            ['Wix Events orders', $events['count'], $events['paid'], $events['unpaid'], $this->money($events['paid_minor'])],
        ]);
        $this->line("  Already imported (skipped): {$s['already_imported']}   To import: {$s['to_import']}");

        $rows = [];
        foreach ($s['donations']['by_fund'] as $fund => $row) {
            $rows[] = ["Donations → {$fund}", $row['count'], $this->money($row['minor'])];
        }
        foreach ($s['registrations']['by_offering'] as $offering => $row) {
            $rows[] = ["Registrations → {$offering}", $row['count'], $this->money($row['paid_minor'])];
        }
        $rows[] = ['Order-only lines (food tickets, prayer rugs)', $s['order_only']['lines'], $this->money($s['order_only']['minor'])];
        $this->table(['Recorded as', 'Rows', 'Paid'], $rows);

        $this->line(sprintf(
            '  Donations: %d, %s.  Registrations: %d confirmed, %d cancelled (never paid), %s paid incl. %s of Wix fees.',
            $s['donations']['count'], $this->money($s['donations']['minor']),
            $s['registrations']['confirmed'], $s['registrations']['cancelled'],
            $this->money($s['registrations']['paid_minor']), $this->money($s['registrations']['wix_fee_minor']),
        ));
        $this->line('  Paid orders to import: ' . $this->money($s['import_paid_minor'] ?? 0)
            . ' — reconciles with the rows above: ' . ($s['reconciles'] ? 'yes' : 'NO'));

        $providers = [];
        foreach ($s['providers'] as $provider => $row) {
            $providers[] = [$provider, $row['orders'], $this->money($row['minor'])];
        }
        if ($providers !== []) {
            $this->table(['Paid through', 'Orders', 'Total'], $providers);
        }

        $c = $s['contacts'];
        $this->line("  Contacts: {$c['linked']} linked by email, {$c['linked_deleted']} linked to a contact deleted in "
            . "Manara (left deleted), {$c['created']} to create (email held from broadcasts "
            . "unless already on the suppression list), {$c['ambiguous']} addresses shared by several contacts "
            . "(oldest linked), {$c['no_email']} orders with no email.");
        $this->line('  Wix contact import has run for this organisation: ' . ($s['contact_import_ran'] ? 'yes' : 'NO'));

        if ($s['funds_to_create'] !== []) {
            $this->line('  Funds to create (inactive, not receiptable): ' . implode(', ', $s['funds_to_create']));
        }
        if ($s['offerings_to_create'] !== []) {
            $this->line('  Offerings to create (unpublished): ' . count($s['offerings_to_create']));
        }

        foreach ($s['blocking'] as $reason) {
            $this->error("  ! {$reason}");
        }
    }

    private function undo(WixOrderHistoryImporter $importer, Masjid $masjid, string $batch, bool $execute): int
    {
        $s = $importer->undo($masjid, $batch, $execute);

        $this->info(($execute ? 'UNDO' : 'UNDO DRY RUN') . " — organisation #{$masjid->id}, batch {$batch}");
        $this->line("  Orders: {$s['orders']}, donations: {$s['donations']}, registrations: {$s['registrations']} "
            . "(with {$s['registration_payments']} ledger rows and {$s['registration_adjustments']} coupon adjustments).");

        foreach (['removed' => 'Removed', 'kept' => 'Kept'] as $key => $label) {
            foreach ($s[$key] as $type => $count) {
                $this->line("  {$label} {$type}: {$count}");
            }
        }
        foreach ($s['kept_reasons'] ?? [] as $reason => $count) {
            $this->warn("  kept {$count}: {$reason}");
        }

        if (! $execute) {
            $this->warn("\nDRY RUN — nothing removed. Re-run with --execute to undo.");
        }

        return self::SUCCESS;
    }

    /** Integer cents as dollars, by integer maths only. */
    private function money(int $minor): string
    {
        return '$' . number_format(intdiv($minor, 100)) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
