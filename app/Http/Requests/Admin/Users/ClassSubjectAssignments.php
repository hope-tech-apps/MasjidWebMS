<?php

namespace App\Http\Requests\Admin\Users;

use App\Models\ClassSubject;
use App\Models\Group;
use App\Support\ClassSubjectInitializer;
use App\Support\SchoolSettings;
use App\Support\TenantContext;
use Illuminate\Validation\Validator;

trait ClassSubjectAssignments
{
    private function classSubjectsOn(): bool
    {
        return SchoolSettings::classSubjects(SchoolSettings::org(app(TenantContext::class)->get()));
    }

    private function classSubjectRules(): array
    {
        return $this->classSubjectsOn() ? [
            'class_subject_ids' => ['sometimes', 'array'],
            'class_subject_ids.*' => ['nullable', 'array'],
            'class_subject_ids.*.*' => ['integer', 'min:1', 'distinct'],
        ] : ['class_subject_ids' => ['prohibited']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->classSubjectsOn()) return;
            foreach ($this->input('class_subject_ids', []) as $groupId => $ids) {
                $group = Group::where('kind', 'class')->find($groupId);
                if ($group === null || ! in_array((int) $groupId, array_map('intval', $this->input('class_ids', [])), true)
                    || ($ids !== null && count($ids) !== ClassSubject::where('group_id', $groupId)->whereIn('id', $ids)->count())) {
                    $validator->errors()->add('class_subject_ids', 'Choose subjects belonging to the named class in this school.');
                }
            }
        });
    }

    /** New assignments may still come from a legacy client. Map its restriction without widening it. */
    public function subjectAssignmentFields(Group $group): array
    {
        if (! $this->classSubjectsOn() || ! $group->teachesStudents()) return [];
        $given = $this->validated('class_subject_ids');
        $ids = is_array($given) && array_key_exists($group->id, $given)
            ? $given[$group->id] : ClassSubjectInitializer::mapLegacy($group, $this->subjectsFor((int) $group->id));
        return ['class_subject_ids' => empty($ids) ? null : array_map('intval', $ids), 'class_subjects_mapped_at' => now()];
    }
}
