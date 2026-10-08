<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Support\ClassSubjectInitializer;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class InitializeClassSubjects extends Command
{
    protected $signature = 'class-subjects:initialize {--masjid= : Organization id; omitted means every school} {--dry-run : Report without saving changes} {--enable : Enable after clean initialization}';
    protected $description = 'Seed class subjects and map teacher restrictions without replacing existing feature data';

    public function handle(): int
    {
        $query = Masjid::query();
        if ($this->option('masjid') !== null) {
            if (! ctype_digit((string) $this->option('masjid'))) {
                $this->error('--masjid must be an organization id.');
                return self::FAILURE;
            }
            $query->whereKey($this->option('masjid'));
        } else $query->where('org_type', 'school');
        $orgs = $query->orderBy('id')->get();
        if ($orgs->isEmpty()) { $this->error('No matching organization.'); return self::FAILURE; }
        $failed = false;
        foreach ($orgs as $org) {
            try {
                $report = ClassSubjectInitializer::run($org, (bool) $this->option('dry-run'), (bool) $this->option('enable'));
                $blockedReport = collect($report)->contains(fn ($row) => $row['blocked'] !== []);
                $this->line(($this->option('dry-run') ? 'DRY RUN' : ($blockedReport ? 'BLOCKED' : 'ACTIVATED')).": {$org->name}");
                $blocked = 0; $creates = 0; $maps = 0; $losses = 0;
                foreach ($report as $row) {
                    $this->line("Class {$row['class']}: {$row['subjects_added']} subjects added; {$row['assignments_mapped']} assignments mapped.");
                    foreach ($row['creates'] as $subject) {
                        $this->line("  CREATE {$subject['name']} | holds=".($subject['tool'] ?? 'none')." | guide=".($subject['guide_subject'] ?? 'none'));
                        foreach ($subject['attaches_saved_work'] ?? [] as $key => $count) $this->line("    ATTACH saved work: {$key}, {$count} items");
                    }
                    foreach ($row['saved_work_links'] ?? [] as $name => $count) $this->line("  LINK {$name}: {$count} items");
                    foreach ($row['orphaned_work'] ?? [] as $key => $count) {
                        $this->line("  saved work under a subject that is not in this class's list: {$key}, {$count} items");
                        $this->line("    Adding this subject with explicit attach_saved_work confirmation will attach these items.");
                    }
                    foreach ($row['assignments'] as $assignment) {
                        $legacy = $assignment['legacy'] === null || $assignment['legacy'] === [] ? 'all' : implode(', ', $assignment['legacy']);
                        $this->line("  Teacher #{$assignment['teacher_id']}: [{$legacy}] -> [".implode(', ', $assignment['names']).']'.($assignment['will_map'] ? '' : ' (unchanged)'));
                    }
                    foreach ($row['losses'] as $loss) $this->warn('  '.$loss);
                    foreach ($row['blocked'] as $message) $this->error('  BLOCKED '.$message);
                    $blocked += count($row['blocked']); $creates += $row['subjects_added'];
                    $maps += $row['assignments_mapped']; $losses += count($row['losses']);
                }
                $this->line("School summary: ".count($report)." classes (including archived); {$creates} subjects; {$maps} mappings; {$losses} losses; {$blocked} blocked mappings.");
                if ($blocked > 0) {
                    $failed = true;
                    $this->error('No changes saved for this organization.');
                } elseif ($this->option('enable')) $this->line($this->option('dry-run') ? 'Would enable class subjects. No changes saved.' : 'Class subjects enabled.');
            } catch (ValidationException $e) {
                $failed = true;
                foreach ($e->errors() as $messages) foreach ($messages as $message) $this->error($message);
                $this->error('No changes saved for this organization.');
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
