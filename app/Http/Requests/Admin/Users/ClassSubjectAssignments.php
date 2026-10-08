<?php

namespace App\Http\Requests\Admin\Users;

use App\Models\ClassSubject;
use App\Models\Group;
use App\Support\SchoolSettings;
use App\Support\TenantContext;
use Illuminate\Validation\Validator;
use Illuminate\Validation\ValidationException;

trait ClassSubjectAssignments
{
    private function classSubjectsOn(): bool
    {
        return \App\Support\ClassSubjectMode::enabled(app(TenantContext::class)->get());
    }

    /** Normalize before validation and extraction; never cast an arbitrary key to a class id. */
    protected function prepareClassSubjectAssignments(): void
    {
        foreach (['class_subject_ids'] as $field) {
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
                    throw ValidationException::withMessages([$field => ['A subject restriction must name one of the selected classes.']]);
                }
                $out[(int) $digits] = $value;
            }
            $this->merge([$field => $out]);
        }
    }

    private function subjectsForWithClassSubjects(int $classId): ?array
    {
        // The legacy field is not an ON assignment input.
        return null;
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
        if (! $this->classSubjectsOn()) return;
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) return;
            foreach ($this->input('class_subject_ids', []) as $groupId => $ids) {
                $group = Group::where('kind', 'class')->find($groupId);
                if (! \App\Support\SubjectFence::validStoredIds($ids) || $group === null || ! in_array((int) $groupId, array_map('intval', $this->input('class_ids', [])), true)
                    || ($ids !== null && count($ids) !== ClassSubject::where('group_id', $groupId)->whereIn('id', $ids)->count())) {
                    $validator->errors()->add('class_subject_ids.'.$groupId, 'Choose subjects belonging to the named class in this school.');
                }
            }
        });
    }

    /** Every new assignment needs the office's own explicit ID selection, including NULL for all. */
    public function subjectAssignmentFields(Group $group): array
    {
        $given = $this->validated('class_subject_ids');
        if (! is_array($given) || ! array_key_exists($group->id, $given)) {
            throw ValidationException::withMessages(['class_subject_ids.'.$group->id => ['State the subjects for every new class explicitly; choose all subjects explicitly when intended.']]);
        }
        $ids = $given[$group->id];
        return ['class_subject_ids' => $ids === null ? null : array_map('intval', $ids)];
    }
}
