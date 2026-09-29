<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\BaseFormRequest;

/**
 * Add a teacher to this school and assign the classes they lead, in one step.
 *
 * "Add" is create-or-attach (TeachersController::store): an email nobody has
 * used makes a new login, and an email that already belongs to a Teacher at
 * another school attaches THIS school to that login. So the email is deliberately
 * NOT validated as unique on `users` any more. The controller's branch table
 * replaces the rule, and the database's unique index remains the backstop.
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

    /**
     * The address, trimmed and lowercased, BEFORE it is validated or looked up.
     *
     * Emails are compared case-insensitively by MySQL and case-sensitively by the
     * SQLite the suite runs on, and `User` has no mutator. An un-normalised
     * "Moneeb@HopeTechApps.com" would miss the existing row on one driver and
     * throw a unique-index 500 on the other, so the controller looks up by the
     * lowercased form and stores it lowercased.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
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
