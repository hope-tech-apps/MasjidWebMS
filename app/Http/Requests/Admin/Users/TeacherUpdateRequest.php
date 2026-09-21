<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\BaseFormRequest;

/**
 * Edit an existing teacher: their name, phone, and the classes they lead.
 *
 * The email is deliberately NOT here — it is the login identity, and changing it
 * is a re-invite, not an in-place edit. `class_ids` is the FULL new set (the
 * controller syncs against it); requiring at least one keeps "a teacher leads no
 * classes" from being an accidental state — removing a teacher entirely is the
 * DELETE action, not an empty update.
 */
class TeacherUpdateRequest extends BaseFormRequest
{
    /**
     * The subjects for one class, as the assignment should store them: a unique,
     * ordered list, or NULL for "everything". Empty means everything too, so an
     * admin who unticks every box cannot lock a teacher out of their own class.
     *
     * @return list<string>|null
     */
    public function subjectsFor(int $classId): ?array
    {
        $given = $this->validated('class_subjects')[$classId] ?? ($this->validated('class_subjects')[(string) $classId] ?? null);

        if (! is_array($given) || $given === []) {
            return null;
        }

        return array_values(array_intersect(\App\Models\GroupStaff::SUBJECTS, $given));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ]+$/'],
            'class_ids' => ['required', 'array', 'min:1'],
            'class_ids.*' => ['integer'],
            // Which subjects the teacher teaches in each class, keyed by class id
            // (owner, 2026-09-21). OPTIONAL, and a class left out — or given an
            // empty list — teaches everything, which is what every full-time
            // teacher is and what every assignment before this was.
            'class_subjects' => ['sometimes', 'array'],
            'class_subjects.*' => ['array'],
            'class_subjects.*.*' => ['string', \Illuminate\Validation\Rule::in(\App\Models\GroupStaff::SUBJECTS)],
        ];
    }
}
