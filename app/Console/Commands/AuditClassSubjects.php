<?php

namespace App\Console\Commands;

use App\Models\{GroupStaff, Masjid};
use App\Support\{ClassSubjectInitializer, TenantContext};
use Illuminate\Console\Command;

/** Read-only notice of a legacy writer that completed after the activation boundary. */
class AuditClassSubjects extends Command
{
    protected $signature = 'class-subjects:audit {--masjid= : School id (required)}';
    protected $description = 'Report assignment legacy drift without changing ON subject authority';

    public function handle(): int
    {
        $id = (string) $this->option('masjid');
        if (! ctype_digit($id) || ! Masjid::where('org_type', 'school')->whereKey($id)->exists()) {
            $this->error('Supply a school id with --masjid.');
            return self::FAILURE;
        }
        return app(TenantContext::class)->runWithout(function () use ($id) {
            $count = 0;
            foreach (GroupStaff::where('masjid_id', $id)->orderBy('id')->cursor() as $row) {
                if ($row->getRawOriginal('class_subjects_translated_from') === null) {
                    if ($row->class_subjects_mapped_at === null && $row->class_subject_ids_edited_at === null) $this->line("Assignment #{$row->id}, class #{$row->group_id}, teacher #{$row->user_id}: no activation or office ID choice. No subject access is granted while ON.");
                    continue;
                }
                if (ClassSubjectInitializer::sameLegacy($row->subjects, $row->class_subjects_translated_from)) continue;
                $this->line("Assignment #{$row->id}, class #{$row->group_id}, teacher #{$row->user_id}: legacy value differs from activation. ON access still uses IDs.");
                $count++;
            }
            $this->line("School #{$id}: {$count} legacy assignment differences. No changes saved.");
            return self::SUCCESS;
        });
    }
}
