<?php

namespace App\Console\Commands;

use App\Models\CurriculumWeek;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import a school's weekly pacing guide.
 *
 *   php artisan curriculum:import 14 database/curriculum/al-razi-pacing-2026-27.json
 *
 * IDEMPOTENT. Every row is an upsert against `curriculum_week_cell_unique`, so
 * re-running after the school revises a week corrects that cell rather than
 * duplicating it. Re-importing does NOT touch any lesson plan: prefill copies
 * text onto the plan at the moment a teacher asks for it, so a guide revised in
 * July cannot rewrite what was taught in October.
 *
 * The masjid is named explicitly and never guessed. This writes one tenant's
 * curriculum, and picking the wrong one would put another school's standards in
 * front of a teacher.
 */
class ImportCurriculumWeeks extends Command
{
    protected $signature = 'curriculum:import
        {masjid : The masjid id to import into}
        {file : Path to the pacing-guide JSON}
        {--fresh : Delete this tenant\'s existing rows first}';

    protected $description = "Import a school's weekly pacing guide into curriculum_weeks";

    public function handle(TenantContext $tenant): int
    {
        $masjidId = (int) $this->argument('masjid');
        $path = (string) $this->argument('file');

        $masjid = Masjid::withoutGlobalScopes()->find($masjidId);

        if (! $masjid) {
            $this->error("No masjid with id {$masjidId}.");

            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload) || ! isset($payload['rows']) || ! is_array($payload['rows'])) {
            $this->error('That file has no `rows` array.');

            return self::FAILURE;
        }

        $sourceLabel = (string) ($payload['source_label'] ?? basename($path));

        // Bind the tenant so BelongsToMasjid stamps and scopes exactly as it
        // would in a request. Nothing here writes masjid_id by hand.
        $tenant->set($masjidId);

        if ($this->option('fresh')) {
            $deleted = CurriculumWeek::query()->delete();
            $this->warn("Deleted {$deleted} existing rows for {$masjid->name}.");
        }

        $written = 0;
        $skipped = 0;

        DB::transaction(function () use ($payload, $sourceLabel, &$written, &$skipped) {
            foreach ($payload['rows'] as $row) {
                // A cell with no grade, subject or week cannot be looked up, so
                // it cannot be prefilled from — skip rather than store junk.
                if (empty($row['grade_label']) || empty($row['subject']) || empty($row['week_no'])) {
                    $skipped++;

                    continue;
                }

                CurriculumWeek::updateOrCreate(
                    [
                        'grade_label' => $row['grade_label'],
                        'subject' => $row['subject'],
                        'week_no' => (int) $row['week_no'],
                    ],
                    [
                        'quarter' => $row['quarter'] ?? null,
                        'focus' => (string) ($row['focus'] ?? ''),
                        'standard_code' => $row['standard_code'] ?? null,
                        'assessment_note' => $row['assessment_note'] ?? null,
                        'source_label' => $sourceLabel,
                    ]
                );

                $written++;
            }
        });

        $this->info("Imported {$written} cells into {$masjid->name}" . ($skipped ? " ({$skipped} skipped)" : '') . '.');

        $grades = CurriculumWeek::query()->distinct()->pluck('grade_label')->sort()->values();
        $this->line('  grades: ' . $grades->implode(', '));
        $this->line('  with a standard code: ' . CurriculumWeek::query()->whereNotNull('standard_code')->count());

        $tenant->forgetTenant();

        return self::SUCCESS;
    }
}
