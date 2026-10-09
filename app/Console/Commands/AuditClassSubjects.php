<?php

namespace App\Console\Commands;

use App\Models\{ClassSubject, CurriculumWeek, Group, GroupMembership, GroupStaff, Masjid};
use App\Support\{ClassSubjectCurriculum, ClassSubjectInitializer, TenantContext};
use Illuminate\Console\Command;

/** Read-only assignment drift and curriculum coverage, using persisted subject choices. */
class AuditClassSubjects extends Command
{
    protected $signature = 'class-subjects:audit {--masjid= : School id (required)}';
    protected $description = 'Report assignment drift and curriculum coverage without changing subject authority';

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
            $guide = CurriculumWeek::where('masjid_id', $id)->select('subject', 'grade_label')->distinct()->get();
            $subjects = ClassSubject::where('masjid_id', $id)->orderBy('position')->orderBy('id')->get()->groupBy('group_id');
            $roster = GroupMembership::where('masjid_id', $id)->participants()->current()->get(['group_id', 'grade_label'])->groupBy('group_id');
            foreach (Group::withTrashed()->where('masjid_id', $id)->where(fn ($query) => $query->where('kind', Group::KIND_CLASS)
                ->orWhereIn('id', ClassSubject::where('masjid_id', $id)->select('group_id'))
                ->orWhereIn('id', GroupStaff::where('masjid_id', $id)->select('group_id')))->orderBy('id')->get() as $group) {
                $coverage = ClassSubjectCurriculum::coverage($subjects->get($group->id, collect()), $guide, $roster->get($group->id, collect()));
                $this->line("Class {$group->name}: Curriculum coverage: ".count($coverage['unfollowed_subjects']).' subjects follow nothing; '.count($coverage['uncovered_guides']).' guide subjects not followed.');
                foreach ($coverage['unfollowed_subjects'] as $name) $this->line("  follows no curriculum: {$name}");
                foreach ($coverage['uncovered_guides'] as $name) $this->line("  not followed: {$name}");
            }
            $this->line("School #{$id}: {$count} legacy assignment differences. No changes saved.");
            return self::SUCCESS;
        });
    }
}
