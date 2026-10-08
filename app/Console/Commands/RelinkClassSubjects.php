<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Support\ClassSubjectRelinker;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/** Repair old unlinked work explicitly, leaving every existing subject link intact. */
class RelinkClassSubjects extends Command
{
    protected $signature = 'class-subjects:relink {--masjid= : School id (required)} {--dry-run : Plain-read preview without changes}';
    protected $description = 'Link unlinked saved work to its unique current class subject in an ON school';

    public function handle(): int
    {
        $id = (string) $this->option('masjid');
        if (! ctype_digit($id) || (int) $id < 1) { $this->error('--masjid must be a school id.'); return self::FAILURE; }
        $org = Masjid::find($id);
        if ($org === null) { $this->error('No matching organization.'); return self::FAILURE; }
        $dryRun = (bool) $this->option('dry-run');
        try {
            $report = ClassSubjectRelinker::run($org, $dryRun);
            $this->line(($dryRun ? 'DRY RUN' : 'RELINKED').": School #{$org->id}");
            $linked = 0; $unlinked = 0;
            foreach ($report as $row) {
                $this->line("Class {$row['class']} (#{$row['class_id']}):");
                foreach ($row['linked'] as $subjectId => $subject) {
                    $this->line("  LINK {$subject['name']}: {$subject['count']} items (subject #{$subjectId})");
                    $linked += $subject['count'];
                }
                foreach ($row['unlinked'] as $text => $count) {
                    $this->line("  UNLINKED {$text}: {$count} items (general or no unique same-class subject)");
                    $unlinked += $count;
                }
            }
            $this->line('School summary: '.count($report).' classes; '.$linked.($dryRun ? ' items would be linked' : ' items linked')."; {$unlinked} items stay unlinked.");
            if ($dryRun) $this->line('No changes saved.');
            return self::SUCCESS;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) foreach ($messages as $message) $this->error($message);
            $this->error('No changes saved.'); return self::FAILURE;
        }
    }
}
