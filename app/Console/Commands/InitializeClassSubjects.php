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
                $this->line(($this->option('dry-run') ? 'DRY RUN' : 'INITIALIZED').": {$org->name}");
                foreach ($report as $row) $this->line("Class {$row['class']}: {$row['subjects_added']} subjects added; {$row['assignments_mapped']} assignments mapped.");
                if ($this->option('enable')) $this->line($this->option('dry-run') ? 'Would enable class subjects. No changes saved.' : 'Class subjects enabled.');
            } catch (ValidationException $e) {
                $failed = true;
                foreach ($e->errors() as $messages) foreach ($messages as $message) $this->error($message);
                $this->error('No changes saved for this organization.');
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
