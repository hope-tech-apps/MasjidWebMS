<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a teacher login and assign the classes they lead, in one step.
 *
 * Deliberately does NOT accept a `type` and does NOT use the shared
 * `UserTypeRule`: the type is forced to 'Teacher' server-side (TeachersController),
 * never from input. Widening UserTypeRule to admit 'Teacher' would also open the
 * general Store/Update user forms to minting teachers, which must stay a
 * deliberate, class-scoped action — a MasjidAdmin can create a teacher for their
 * OWN school's classes and nothing more.
 */
class TeacherInviteRequest extends BaseFormRequest
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
            // Archived users are ignored so a retired address can be reused,
            // matching InviteUserRequest.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ]+$/'],
            // At least one class — a teacher with no classes has nothing to sign
            // in for. That the ids name classes IN THE BOUND SCHOOL is verified in
            // the controller against the tenant-scoped Group query, not here (a
            // plain exists rule cannot see the tenant scope).
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
