<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Support\ClassSubjectDisabler;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class DisableClassSubjects extends Command
{
    protected $signature = 'class-subjects:disable {--masjid= : School id (required)} {--dry-run : Plain-read preview without changes} {--accept-unrestricted= : Comma-separated staff row ids explicitly allowed unrestricted legacy access}';
    protected $description = 'Review legacy restrictions and deliberately disable class subjects without deleting data';

    public function handle(): int
    {
        $id = (string) $this->option('masjid');
        if (! ctype_digit($id) || (int) $id < 1) { $this->error('--masjid must be a school id.'); return self::FAILURE; }
        $raw = (string) $this->option('accept-unrestricted');
        if ($raw !== '' && ! preg_match('/\A[1-9][0-9]*(?:,[1-9][0-9]*)*\z/', $raw)) {
            $this->error('--accept-unrestricted must be a comma-separated list of staff row ids.'); return self::FAILURE;
        }
        $accept = $raw === '' ? [] : array_values(array_unique(array_map('intval', explode(',', $raw))));
        $org = Masjid::find($id);
        if ($org === null) { $this->error('No matching organization.'); return self::FAILURE; }
        try {
            $report = ClassSubjectDisabler::run($org, (bool) $this->option('dry-run'), $accept);
            $this->line(($this->option('dry-run') ? 'DRY RUN' : ($report['blocked'] !== [] ? 'BLOCKED' : 'DISABLED')).": School #{$org->id}");
            foreach ($report['assignments'] as $row) {
                $before = $row['ids'] === null ? 'all subjects' : implode(', ', $row['ids']);
                $after = $row['legacy'] === null ? 'all subjects' : implode(', ', $row['legacy']);
                $state = $row['expressible'] ? 'exact legacy choice' : ($row['accepted'] ? 'accepted unrestricted' : 'INEXPRESSIBLE');
                $this->line("Assignment #{$row['id']} | class #{$row['class_id']} | teacher #{$row['teacher_id']}: [{$before}] -> [{$after}] ({$state})");
            }
            foreach ($report['blocked'] as $message) $this->error('BLOCKED '.$message);
            $this->line('School summary: '.count($report['assignments']).' assignments; '.count($report['blocked']).' blockers.');
            $this->line($this->option('dry-run') || $report['blocked'] !== [] ? 'No changes saved.' : 'Legacy authority restored; subjects, IDs and saved-work links retained.');
            if (! $report['enabled']) $this->line('School already OFF. No changes saved.');
            return $report['blocked'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) foreach ($messages as $message) $this->error($message);
            $this->error('No changes saved.'); return self::FAILURE;
        }
    }
}
