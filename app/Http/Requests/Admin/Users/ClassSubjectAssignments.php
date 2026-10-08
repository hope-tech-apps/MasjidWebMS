<?php

namespace App\Http\Requests\Admin\Users;

use App\Models\ClassSubject;
use App\Models\Group;
use App\Support\ClassSubjectInitializer;
use App\Support\SchoolSettings;
use App\Support\TenantContext;
use Illuminate\Validation\Validator;
use Illuminate\Validation\ValidationException;

trait ClassSubjectAssignments
{
    private function classSubjectsOn(): bool
    {
        return SchoolSettings::classSubjects(SchoolSettings::org(app(TenantContext::class)->get()));
    }

    /** Normalize before validation and extraction; never cast an arbitrary key to a class id. */
    protected function prepareClassSubjectAssignments(): void
    {
        if (! $this->classSubjectsOn()) $this->offsetUnset('class_subject_ids');
        foreach (['class_subjects', ...($this->classSubjectsOn() ? ['class_subject_ids'] : [])] as $field) {
            $given = $this->input($field);
            if (! is_array($given)) continue; // The field's array rule refuses other shapes.
            $out = [];
            foreach ($given as $key => $value) {
                $digits = ltrim((string) $key, '0');
                if (! ctype_digit((string) $key) || $digits === '' || strlen($digits) > strlen((string) PHP_INT_MAX)
                    || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)
                    || array_key_exists((int) $digits, $out)) {
                    throw ValidationException::withMessages([$field => ['Use each positive class id once, without signs, fractions or other text.']]);
                }
                if (! in_array((int) $digits, array_map('intval', (array) $this->input('class_ids', [])), true)) {
                    // Legacy OFF forms retain null entries for classes just unticked.
                    // These express no restriction; a nonempty unmatched restriction is refused.
                    if ($field === 'class_subjects' && ! $this->classSubjectsOn() && ($value === null || $value === [])) continue;
                    throw ValidationException::withMessages([$field => ['A subject restriction must name one of the selected classes.']]);
                }
                $out[(int) $digits] = $value;
            }
            $this->merge([$field => $out]);
        }
    }

    public function subjectsFor(int $classId): ?array
    {
        $map = $this->validated('class_subjects');
        if ($map === null) return null;
        // OFF's legacy contract: no entry means no restriction was offered for this class.
        // Every supplied restriction was canonicalized and matched at the edge above.
        if (! $this->classSubjectsOn() && is_array($map) && ! array_key_exists($classId, $map)) return null;
        if (! is_array($map) || ! array_key_exists($classId, $map)) {
            throw ValidationException::withMessages(['class_subjects' => ['State the subjects for this class explicitly.']]);
        }
        $given = $map[$classId];
        if ($given === null || $given === []) return null;
        if (! is_array($given) || array_diff($given, \App\Models\GroupStaff::SUBJECTS) !== []) {
            throw ValidationException::withMessages(['class_subjects' => ['Choose valid subjects for this class.']]);
        }
        return array_values(array_unique($given));
    }

    private function classSubjectRules(): array
    {
        return $this->classSubjectsOn() ? [
            'class_subject_ids' => ['sometimes', 'array'],
            'class_subject_ids.*' => ['nullable', 'array', 'list'],
            'class_subject_ids.*.*' => ['integer', 'min:1', 'distinct'],
        ] : [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->classSubjectsOn()) return;
            foreach ($this->input('class_subject_ids', []) as $groupId => $ids) {
                $group = Group::where('kind', 'class')->find($groupId);
                if (! \App\Support\SubjectFence::validStoredIds($ids) || $group === null || ! in_array((int) $groupId, array_map('intval', $this->input('class_ids', [])), true)
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
        if ($given !== null && ! array_key_exists($group->id, $given)) {
            throw ValidationException::withMessages(['class_subject_ids' => ['State the subjects for every new class explicitly.']]);
        }
        $legacy = $this->subjectsFor((int) $group->id);
        $ids = $given !== null ? $given[$group->id] : ClassSubjectInitializer::mapLegacy($group, $legacy);
        return ['class_subject_ids' => $ids === null ? null : array_map('intval', $ids),
            'class_subjects_mapped_at' => now(), 'class_subject_legacy_snapshot' => $legacy ?: []];
    }
}
