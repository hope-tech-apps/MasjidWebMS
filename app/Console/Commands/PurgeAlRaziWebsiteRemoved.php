<?php

namespace App\Console\Commands;

use App\Models\Form;
use App\Models\FormResponse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delete the imported school-website rows the website no longer holds.
 *
 *   php artisan alrazi:purge-website-removed --dry-run   # count, delete nothing
 *   php artisan alrazi:purge-website-removed
 *
 * alrazi:sync-website never deletes a row: when a complete export stops returning
 * one, it only writes `data.websiteRemoved` ("Removed from the website on
 * YYYY-MM-DD"). This command is the deliberate erasure path for those rows — a
 * withdrawn application, a family's erasure request honoured on the website — and
 * the ONLY code that deletes an imported row: the admin refuses to
 * (FormResponsesController::destroy), because the next sync would write it back.
 *
 * It deletes a row only when ALL of these hold, re-checked on the locked row:
 *  - it belongs to one of the two website forms (by slug) of the configured
 *    organisation (services.alrazi_export.masjid_id), and carries that
 *    organisation's masjid_id;
 *  - it was imported (external_ref is set);
 *  - it carries the websiteRemoved marker.
 *
 * Each row is deleted THROUGH THE MODEL, so its attachments are deleted through
 * theirs and the bytes leave the private disk. Not scheduled: someone runs it. It
 * prints and logs counts only — never a name, a field or an id's contents.
 */
class PurgeAlRaziWebsiteRemoved extends Command
{
    protected $signature = 'alrazi:purge-website-removed
                            {--dry-run : Count the rows and documents that would be deleted, and delete nothing}';

    protected $description = 'Delete the imported Al-Razi website rows the website no longer returns (marked websiteRemoved by alrazi:sync-website)';

    public function handle(): int
    {
        $masjidId = (int) config('services.alrazi_export.masjid_id', 14);
        $dryRun = (bool) $this->option('dry-run');

        $formIds = Form::query()
            ->where('masjid_id', $masjidId)
            ->whereIn('slug', array_values(SyncAlRaziWebsiteSubmissions::TABLES))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($formIds === []) {
            $this->line("Neither website form exists in organisation {$masjidId}. Nothing to do.");

            return self::SUCCESS;
        }

        $candidates = $this->scope(FormResponse::query(), $formIds, $masjidId)
            ->orderBy('id')
            ->get(['id', 'data'])
            ->filter(fn (FormResponse $row) => SyncAlRaziWebsiteSubmissions::isMarkedRemoved($row->data))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($dryRun) {
            $documents = $candidates === []
                ? 0
                : DB::table('form_response_attachments')->whereIn('form_response_id', $candidates)->count();

            $this->info('Dry run — nothing deleted. rows=' . count($candidates) . " documents={$documents}");

            return self::SUCCESS;
        }

        $deleted = 0;
        $documents = 0;
        $failed = 0;

        foreach ($candidates as $id) {
            try {
                $removed = DB::transaction(function () use ($id, $formIds, $masjidId): ?int {
                    $row = $this->scope(FormResponse::query(), $formIds, $masjidId)
                        ->whereKey($id)
                        ->lockForUpdate()
                        ->first();

                    // A sync may have seen the row come back and cleared the marker
                    // since the list above was read.
                    if ($row === null || ! SyncAlRaziWebsiteSubmissions::isMarkedRemoved($row->data)) {
                        return null;
                    }

                    $count = $row->attachments()->count();
                    // The model's `deleting` hook deletes each attachment through
                    // ITS model, which takes the file off the disk.
                    $row->delete();

                    return $count;
                });
            } catch (\Throwable $e) {
                $failed++;
                Log::error('alrazi:purge-website-removed: a row could not be deleted.', [
                    'response_id' => $id,
                    'exception' => get_class($e),
                ]);

                continue;
            }

            if ($removed !== null) {
                $deleted++;
                $documents += $removed;
            }
        }

        if ($deleted > 0 || $failed > 0) {
            // An erasure leaves a trace at the level production keeps: counts only.
            Log::warning('alrazi:purge-website-removed: deleted rows the school website no longer holds.', [
                'masjid_id' => $masjidId,
                'rows' => $deleted,
                'documents' => $documents,
                'failed' => $failed,
            ]);
        }

        $this->info("rows={$deleted} documents={$documents} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<FormResponse>  $query
     * @param  list<int>  $formIds
     * @return \Illuminate\Database\Eloquent\Builder<FormResponse>
     */
    private function scope($query, array $formIds, int $masjidId)
    {
        return $query
            ->whereIn('form_id', $formIds)
            ->where('masjid_id', $masjidId)
            ->whereNotNull('external_ref');
    }
}
